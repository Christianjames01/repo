<?php
/**
 * Enhanced Attendance Reports Dashboard with Payslip Generation
 * modules/attendance/admin/attendance-reports.php
 */

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

require_once '../../../config/config.php';
require_once '../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has permission
if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Attendance Reports';

// Date range for reports
$current_month = date('n');
$current_year = date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : $current_month;
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

$first_day = date('Y-m-01', strtotime("$selected_year-$selected_month-01"));
$last_day = date('Y-m-t', strtotime("$selected_year-$selected_month-01"));

// Get overall statistics
$overall_stats = fetchOne($conn, 
    "SELECT 
        COUNT(DISTINCT user_id) as total_users,
        COUNT(*) as total_records,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as total_present,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as total_late,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as total_absent,
        SUM(CASE WHEN status = 'On Leave' THEN 1 ELSE 0 END) as total_leave,
        AVG(CASE WHEN status IN ('Present', 'Late') THEN 1 ELSE 0 END) * 100 as attendance_rate
    FROM tbl_attendance
    WHERE attendance_date BETWEEN ? AND ?",
    [$first_day, $last_day], 'ss'
);

// Get user-wise attendance summary with payslip readiness
$user_summary = fetchAll($conn,
    "SELECT 
        u.user_id,
        CONCAT(r.first_name, ' ', r.last_name) as full_name,
        u.username,
        u.role,
        COUNT(*) as total_days,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN a.status = 'On Leave' THEN 1 ELSE 0 END) as leave_days,
        ROUND((SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage,
        SUM(CASE 
            WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, a.time_in, a.time_out) 
            ELSE 0 
        END) as total_minutes,
        (SELECT COUNT(*) FROM tbl_payslips 
         WHERE user_id = u.user_id 
         AND pay_period_start = ? 
         AND pay_period_end = ?) as payslip_generated
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_attendance a ON u.user_id = a.user_id AND a.attendance_date BETWEEN ? AND ?
    WHERE u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
    GROUP BY u.user_id
    ORDER BY attendance_percentage DESC",
    [$first_day, $last_day, $first_day, $last_day], 'ssss'
);

// Get daily attendance trend
$daily_trend = fetchAll($conn,
    "SELECT 
        attendance_date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent
    FROM tbl_attendance
    WHERE attendance_date BETWEEN ? AND ?
    GROUP BY attendance_date
    ORDER BY attendance_date",
    [$first_day, $last_day], 'ss'
);

// Get top performers
$top_performers = fetchAll($conn,
    "SELECT 
        u.user_id,
        CONCAT(r.first_name, ' ', r.last_name) as full_name,
        u.role,
        COUNT(*) as total_days,
        SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) as attended_days,
        ROUND((SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as rate
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_attendance a ON u.user_id = a.user_id AND a.attendance_date BETWEEN ? AND ?
    WHERE u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
    GROUP BY u.user_id
    HAVING attended_days > 0
    ORDER BY rate DESC
    LIMIT 5",
    [$first_day, $last_day], 'ss'
);

// Get payslip statistics
$payslip_stats = fetchOne($conn,
    "SELECT 
        COUNT(*) as total_payslips,
        SUM(gross_pay) as total_gross,
        SUM(net_pay) as total_net,
        SUM(overtime_pay) as total_overtime,
        SUM(late_deductions) as total_late_deductions
    FROM tbl_payslips
    WHERE pay_period_start = ? AND pay_period_end = ?",
    [$first_day, $last_day], 'ss'
);

// Get status distribution
$status_distribution = [
    'present' => $overall_stats['total_present'] ?? 0,
    'late' => $overall_stats['total_late'] ?? 0,
    'absent' => $overall_stats['total_absent'] ?? 0,
    'leave' => $overall_stats['total_leave'] ?? 0
];

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-chart-bar text-primary me-2"></i>
                Attendance Reports & Payslip Management
            </h1>
            <p class="text-muted mb-0">Comprehensive attendance analytics and payroll insights</p>
        </div>
        <div>
            <div class="d-flex gap-2">
                <select class="form-select" id="monthSelector" onchange="changeDate()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select class="form-select" id="yearSelector" onchange="changeDate()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <button onclick="window.print()" class="btn btn-outline-primary">
                    <i class="fas fa-print"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Overall Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Records</h6>
                            <h2 class="mb-0"><?php echo number_format($overall_stats['total_records'] ?? 0); ?></h2>
                            <small class="text-success">
                                <i class="fas fa-users me-1"></i>
                                <?php echo $overall_stats['total_users'] ?? 0; ?> users
                            </small>
                        </div>
                        <div class="fs-1 text-primary opacity-50">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Attendance Rate</h6>
                            <h2 class="mb-0 text-success"><?php echo number_format($overall_stats['attendance_rate'] ?? 0, 1); ?>%</h2>
                            <small class="text-muted">Overall performance</small>
                        </div>
                        <div class="fs-1 text-success opacity-50">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Late Arrivals</h6>
                            <h2 class="mb-0 text-warning"><?php echo number_format($overall_stats['total_late'] ?? 0); ?></h2>
                            <small class="text-muted">This period</small>
                        </div>
                        <div class="fs-1 text-warning opacity-50">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Absences</h6>
                            <h2 class="mb-0 text-danger"><?php echo number_format($overall_stats['total_absent'] ?? 0); ?></h2>
                            <small class="text-muted">This period</small>
                        </div>
                        <div class="fs-1 text-danger opacity-50">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payslip Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-success bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Payslips Generated</h6>
                            <h2 class="mb-0 text-success"><?php echo $payslip_stats['total_payslips'] ?? 0; ?></h2>
                            <small class="text-muted">of <?php echo $overall_stats['total_users'] ?? 0; ?> staff</small>
                        </div>
                        <div class="fs-1 text-success opacity-50">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-info bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Gross Pay</h6>
                            <h2 class="mb-0 text-info">₱<?php echo number_format($payslip_stats['total_gross'] ?? 0, 2); ?></h2>
                            <small class="text-muted">Before deductions</small>
                        </div>
                        <div class="fs-1 text-info opacity-50">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-primary bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Net Pay</h6>
                            <h2 class="mb-0 text-primary">₱<?php echo number_format($payslip_stats['total_net'] ?? 0, 2); ?></h2>
                            <small class="text-muted">After deductions</small>
                        </div>
                        <div class="fs-1 text-primary opacity-50">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-warning bg-opacity-10">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Overtime Pay</h6>
                            <h2 class="mb-0 text-warning">₱<?php echo number_format($payslip_stats['total_overtime'] ?? 0, 2); ?></h2>
                            <small class="text-muted">Total overtime</small>
                        </div>
                        <div class="fs-1 text-warning opacity-50">
                            <i class="fas fa-business-time"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Status Distribution -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie text-primary me-2"></i>
                        Status Distribution
                    </h5>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <?php if (array_sum($status_distribution) > 0): ?>
                        <canvas id="statusChart" style="max-height: 300px;"></canvas>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-chart-pie fa-3x mb-3"></i>
                            <p>No data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Daily Trend -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-bar text-primary me-2"></i>
                        Daily Attendance Overview
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($daily_trend)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-chart-bar fa-3x mb-3"></i>
                            <p>No attendance data for selected period</p>
                        </div>
                    <?php else: ?>
                        <canvas id="attendanceTrendChart" height="80"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Top Performers -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-trophy text-warning me-2"></i>
                        Top Performers
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($top_performers)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-2x mb-2"></i>
                            <p class="mb-0">No data available</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($top_performers as $index => $performer): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <div class="badge bg-<?php echo $index === 0 ? 'warning' : ($index === 1 ? 'secondary' : 'info'); ?> rounded-circle" 
                                                 style="width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: bold;">
                                                <?php if ($index === 0): ?>
                                                    <i class="fas fa-trophy"></i>
                                                <?php else: ?>
                                                    <?php echo $index + 1; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold"><?php echo htmlspecialchars($performer['full_name']); ?></div>
                                            <small class="text-muted"><?php echo $performer['role']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="badge bg-success fs-6"><?php echo $performer['rate']; ?>%</div>
                                            <div><small class="text-muted"><?php echo $performer['attended_days']; ?>/<?php echo $performer['total_days']; ?> days</small></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Attendance Rate by Role -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-users-cog text-info me-2"></i>
                        Attendance Rate by Role
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="roleChart" height="60"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- User Summary with Payslip Generation -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-users text-info me-2"></i>
                Staff Attendance & Payslip Summary
            </h5>
            <div class="d-flex gap-2">
                <a href="generate-payslip.php?month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                   class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Generate New Payslip
                </a>
                <a href="payslip-list.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list me-1"></i> View All Payslips
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="staffTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th class="text-center">Present</th>
                            <th class="text-center">Late</th>
                            <th class="text-center">Absent</th>
                            <th class="text-center">Leave</th>
                            <th class="text-center">Total Hours</th>
                            <th>Rate</th>
                            <th class="text-center">Payslip</th>
                            <th class="text-center no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($user_summary)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">No data available for selected period</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($user_summary as $user): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                            <?php echo $user['role']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-success fw-bold"><?php echo $user['present_days']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-warning fw-bold"><?php echo $user['late_days']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-danger fw-bold"><?php echo $user['absent_days']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-info fw-bold"><?php echo $user['leave_days']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark">
                                            <?php echo floor($user['total_minutes'] / 60) . 'h ' . ($user['total_minutes'] % 60) . 'm'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 25px; min-width: 100px;">
                                            <div class="progress-bar <?php echo $user['attendance_percentage'] >= 90 ? 'bg-success' : ($user['attendance_percentage'] >= 75 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                 style="width: <?php echo $user['attendance_percentage']; ?>%">
                                                <strong><?php echo number_format($user['attendance_percentage'], 1); ?>%</strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($user['payslip_generated'] > 0): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                Generated
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-clock me-1"></i>
                                                Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center no-print">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($user['payslip_generated'] > 0): ?>
                                                <a href="payslip-list.php?user_id=<?php echo $user['user_id']; ?>&month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                                                   class="btn btn-outline-info" title="View Payslip">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="generate-payslip.php?user_id=<?php echo $user['user_id']; ?>&month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                                                   class="btn btn-outline-success" title="Generate Payslip">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="index.php?user_id=<?php echo $user['user_id']; ?>&month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                                               class="btn btn-outline-primary" title="View Details">
                                                <i class="fas fa-info-circle"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Quick Actions Card -->
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-header bg-gradient bg-primary text-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-bolt me-2"></i>
                Quick Payroll Actions
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card border-success h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-file-invoice-dollar fa-3x text-success mb-3"></i>
                            <h5>Generate Payslips</h5>
                            <p class="text-muted small mb-3">Create payslips for staff members who haven't received one yet</p>
                            <a href="generate-payslip.php?month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                               class="btn btn-success">
                                <i class="fas fa-plus me-1"></i> Generate
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-info h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-list-alt fa-3x text-info mb-3"></i>
                            <h5>View All Payslips</h5>
                            <p class="text-muted small mb-3">Access and manage all generated payslips for this period</p>
                            <a href="payslip-list.php?month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                               class="btn btn-info">
                                <i class="fas fa-eye me-1"></i> View List
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-warning h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-download fa-3x text-warning mb-3"></i>
                            <h5>Export Report</h5>
                            <p class="text-muted small mb-3">Download attendance and payroll data for external use</p>
                            <button onclick="exportToExcel()" class="btn btn-warning">
                                <i class="fas fa-file-excel me-1"></i> Export Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Payslips Alert -->
            <?php 
            $pending_count = 0;
            foreach ($user_summary as $user) {
                if ($user['payslip_generated'] == 0) {
                    $pending_count++;
                }
            }
            ?>
            <?php if ($pending_count > 0): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Pending Payslips</h6>
                            <p class="mb-0">
                                <strong><?php echo $pending_count; ?></strong> staff member(s) haven't received their payslip for this period yet.
                            </p>
                        </div>
                        <a href="generate-payslip.php?month=<?php echo $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT); ?>" 
                           class="btn btn-warning">
                            <i class="fas fa-plus me-1"></i> Generate Now
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payslip Summary by Status -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-chart-line text-success me-2"></i>
                Payslip Generation Progress
            </h5>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">Generation Progress</span>
                        <span class="fw-bold">
                            <?php 
                            $total_staff = count($user_summary);
                            $generated = $total_staff - $pending_count;
                            $progress_percent = $total_staff > 0 ? ($generated / $total_staff) * 100 : 0;
                            echo $generated . ' / ' . $total_staff . ' (' . number_format($progress_percent, 1) . '%)';
                            ?>
                        </span>
                    </div>
                    <div class="progress" style="height: 30px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $progress_percent; ?>%">
                            <strong><?php echo number_format($progress_percent, 1); ?>% Complete</strong>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="row text-center">
                            <div class="col">
                                <div class="border rounded p-2">
                                    <h4 class="text-success mb-0"><?php echo $generated; ?></h4>
                                    <small class="text-muted">Generated</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="border rounded p-2">
                                    <h4 class="text-warning mb-0"><?php echo $pending_count; ?></h4>
                                    <small class="text-muted">Pending</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="border rounded p-2">
                                    <h4 class="text-primary mb-0"><?php echo $total_staff; ?></h4>
                                    <small class="text-muted">Total Staff</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <canvas id="payslipProgressChart" width="200" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Chart colors
const chartColors = {
    present: '#28a745',
    late: '#ffc107',
    absent: '#dc3545',
    leave: '#17a2b8'
};

// Status Distribution Doughnut Chart
<?php if (array_sum($status_distribution) > 0): ?>
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Present', 'Late', 'Absent', 'On Leave'],
        datasets: [{
            data: [
                <?php echo $status_distribution['present']; ?>,
                <?php echo $status_distribution['late']; ?>,
                <?php echo $status_distribution['absent']; ?>,
                <?php echo $status_distribution['leave']; ?>
            ],
            backgroundColor: [
                chartColors.present,
                chartColors.late,
                chartColors.absent,
                chartColors.leave
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                        return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
<?php endif; ?>

// Daily Trend Stacked Bar Chart
<?php if (!empty($daily_trend)): ?>
const trendCtx = document.getElementById('attendanceTrendChart').getContext('2d');
const trendData = <?php echo json_encode($daily_trend); ?>;

new Chart(trendCtx, {
    type: 'bar',
    data: {
        labels: trendData.map(d => {
            const date = new Date(d.attendance_date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }),
        datasets: [
            {
                label: 'Present',
                data: trendData.map(d => d.present),
                backgroundColor: chartColors.present,
                borderRadius: 5
            },
            {
                label: 'Late',
                data: trendData.map(d => d.late),
                backgroundColor: chartColors.late,
                borderRadius: 5
            },
            {
                label: 'Absent',
                data: trendData.map(d => d.absent),
                backgroundColor: chartColors.absent,
                borderRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            x: {
                stacked: true,
                grid: {
                    display: false
                }
            },
            y: {
                stacked: true,
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        },
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    usePointStyle: true,
                    padding: 15
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        }
    }
});
<?php endif; ?>

// Attendance Rate by Role (Horizontal Bar Chart)
<?php 
$role_stats = [];
foreach ($user_summary as $user) {
    $role = $user['role'];
    if (!isset($role_stats[$role])) {
        $role_stats[$role] = ['total' => 0, 'count' => 0];
    }
    $role_stats[$role]['total'] += $user['attendance_percentage'];
    $role_stats[$role]['count']++;
}

$role_labels = [];
$role_percentages = [];
foreach ($role_stats as $role => $data) {
    $role_labels[] = $role;
    $role_percentages[] = round($data['total'] / $data['count'], 2);
}
?>

const roleCtx = document.getElementById('roleChart').getContext('2d');
new Chart(roleCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($role_labels); ?>,
        datasets: [{
            label: 'Average Attendance Rate (%)',
            data: <?php echo json_encode($role_percentages); ?>,
            backgroundColor: [
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 99, 132, 0.8)',
                'rgba(255, 206, 86, 0.8)',
                'rgba(75, 192, 192, 0.8)',
                'rgba(153, 102, 255, 0.8)'
            ],
            borderColor: [
                'rgba(54, 162, 235, 1)',
                'rgba(255, 99, 132, 1)',
                'rgba(255, 206, 86, 1)',
                'rgba(75, 192, 192, 1)',
                'rgba(153, 102, 255, 1)'
            ],
            borderWidth: 2,
            borderRadius: 8
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            x: {
                beginAtZero: true,
                max: 100,
                ticks: {
                    callback: function(value) {
                        return value + '%';
                    }
                }
            }
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.parsed.x.toFixed(1) + '%';
                    }
                }
            }
        }
    }
});

// Payslip Progress Doughnut Chart
const payslipProgressCtx = document.getElementById('payslipProgressChart').getContext('2d');
new Chart(payslipProgressCtx, {
    type: 'doughnut',
    data: {
        labels: ['Generated', 'Pending'],
        datasets: [{
            data: [
                <?php echo $generated; ?>,
                <?php echo $pending_count; ?>
            ],
            backgroundColor: [
                'rgba(40, 167, 69, 0.8)',
                'rgba(255, 193, 7, 0.8)'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 10,
                    font: {
                        size: 11
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                        return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// Change date function
function changeDate() {
    const month = document.getElementById('monthSelector').value;
    const year = document.getElementById('yearSelector').value;
    window.location.href = `?month=${month}&year=${year}`;
}

// Export to Excel function
function exportToExcel() {
    const table = document.getElementById('staffTable');
    const month = document.getElementById('monthSelector').value;
    const year = document.getElementById('yearSelector').value;
    
    // Create workbook
    let html = '<table>';
    
    // Add header with period info
    html += '<tr><th colspan="10" style="text-align:center; font-size:16px; font-weight:bold;">Attendance & Payslip Report</th></tr>';
    html += '<tr><th colspan="10" style="text-align:center;">Period: ' + getMonthName(month) + ' ' + year + '</th></tr>';
    html += '<tr></tr>'; // Empty row
    
    // Add table headers
    const headers = table.querySelectorAll('thead th');
    html += '<tr>';
    headers.forEach((header, index) => {
        if (index < headers.length - 1) { // Skip Actions column
            html += '<th style="background-color:#4CAF50; color:white; font-weight:bold;">' + header.textContent + '</th>';
        }
    });
    html += '</tr>';
    
    // Add table data
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 1) { // Skip empty state row
            html += '<tr>';
            cells.forEach((cell, index) => {
                if (index < cells.length - 1) { // Skip Actions column
                    html += '<td>' + cell.textContent.trim() + '</td>';
                }
            });
            html += '</tr>';
        }
    });
    
    html += '</table>';
    
    // Create download link
    const uri = 'data:application/vnd.ms-excel;base64,';
    const template = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--><meta http-equiv="content-type" content="text/plain; charset=UTF-8"/></head><body>' + html + '</body></html>';
    
    const base64 = function(s) { return window.btoa(unescape(encodeURIComponent(s))); };
    const filename = 'Attendance_Report_' + getMonthName(month) + '_' + year + '.xls';
    
    const link = document.createElement('a');
    link.href = uri + base64(template);
    link.download = filename;
    link.click();
}

// Helper function to get month name
function getMonthName(month) {
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                    'July', 'August', 'September', 'October', 'November', 'December'];
    return months[parseInt(month) - 1];
}

// DataTable initialization (if you have DataTables library)
document.addEventListener('DOMContentLoaded', function() {
    // Add search functionality
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control mb-3';
    searchInput.placeholder = 'Search staff...';
    searchInput.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll('#staffTable tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
    
    const tableContainer = document.querySelector('#staffTable').parentElement;
    tableContainer.insertBefore(searchInput, document.querySelector('#staffTable'));
});
</script>

<style>
@media print {
    .btn, nav, .sidebar, .header, .card-header button, select, .no-print { 
        display: none !important; 
    }
    .main-content { 
        margin: 0 !important; 
        padding: 20px !important; 
    }
    .card { 
        page-break-inside: avoid; 
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    .card-header {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* Enhance table styling */
#staffTable tbody tr:hover {
    background-color: #f8f9fa;
}

/* Progress bar animations */
.progress-bar {
    transition: width 1s ease-in-out;
}

/* Badge styling */
.badge {
    font-weight: 500;
    padding: 0.35em 0.65em;
}

/* Card hover effect */
.card:hover {
    transform: translateY(-2px);
    transition: transform 0.2s ease-in-out;
}

/* Button group styling */
.btn-group .btn {
    border-radius: 0;
}

.btn-group .btn:first-child {
    border-top-left-radius: 0.25rem;
    border-bottom-left-radius: 0.25rem;
}

.btn-group .btn:last-child {
    border-top-right-radius: 0.25rem;
    border-bottom-right-radius: 0.25rem;
}

/* Gradient background for header */
.bg-gradient {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

/* Responsive table */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-group-sm .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
}
</style>

<?php include '../../../includes/footer.php'; ?>