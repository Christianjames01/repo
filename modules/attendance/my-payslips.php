<?php
/**
 * Staff My Payslips View
 * modules/attendance/my-payslips.php
 * Allows staff to view their generated payslips
 */

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /barangaylink/modules/auth/login.php');
    exit();
}

$page_title = 'My Payslips';
$current_user_id = getCurrentUserId();

// Get filter parameters
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 = all months

// Build query - FIXED: Changed created_by to generated_by
$sql = "SELECT p.*, 
        CONCAT(r.first_name, ' ', r.last_name) as generated_by_name
        FROM tbl_payslips p
        LEFT JOIN tbl_users u ON p.generated_by = u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE p.user_id = ?";

$params = [$current_user_id];
$types = 'i';

// Add year filter
if ($year > 0) {
    $sql .= " AND YEAR(p.pay_period_start) = ?";
    $params[] = $year;
    $types .= 'i';
}

// Add month filter
if ($month > 0) {
    $sql .= " AND MONTH(p.pay_period_start) = ?";
    $params[] = $month;
    $types .= 'i';
}

$sql .= " ORDER BY p.pay_period_start DESC";

$payslips = fetchAll($conn, $sql, $params, $types);

// Get available years
$years_result = fetchAll($conn,
    "SELECT DISTINCT YEAR(pay_period_start) as year 
    FROM tbl_payslips 
    WHERE user_id = ?
    ORDER BY year DESC",
    [$current_user_id], 'i'
);

// Calculate year-to-date summary
$ytd_summary = fetchOne($conn,
    "SELECT 
        COUNT(*) as total_payslips,
        SUM(gross_pay) as total_gross,
        SUM(net_pay) as total_net,
        SUM(overtime_pay) as total_overtime,
        SUM(late_deductions) as total_late_deductions,
        SUM(overtime_hours) as total_overtime_hours
    FROM tbl_payslips 
    WHERE user_id = ? AND YEAR(pay_period_start) = ?",
    [$current_user_id, $year], 'ii'
);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-file-invoice-dollar text-success me-2"></i>
                My Payslips
            </h1>
            <p class="text-muted mb-0">View your salary history and payment details</p>
        </div>
        <div class="d-flex gap-2">
            <a href="my-schedule.php" class="btn btn-outline-primary">
                <i class="fas fa-calendar-week me-1"></i> My Schedule
            </a>
            <a href="my-attendance.php" class="btn btn-outline-primary">
                <i class="fas fa-clipboard-list me-1"></i> My Attendance
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Year Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Payslips</h6>
                            <h2 class="mb-0 text-primary"><?php echo $ytd_summary['total_payslips'] ?? 0; ?></h2>
                            <small class="text-muted">in <?php echo $year; ?></small>
                        </div>
                        <div class="fs-1 text-primary opacity-50">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Earnings (Gross)</h6>
                            <h2 class="mb-0 text-success">₱<?php echo number_format($ytd_summary['total_gross'] ?? 0, 2); ?></h2>
                            <small class="text-muted">before deductions</small>
                        </div>
                        <div class="fs-1 text-success opacity-50">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Net Pay Received</h6>
                            <h2 class="mb-0 text-info">₱<?php echo number_format($ytd_summary['total_net'] ?? 0, 2); ?></h2>
                            <small class="text-muted">after deductions</small>
                        </div>
                        <div class="fs-1 text-info opacity-50">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Overtime</h6>
                            <h2 class="mb-0 text-warning"><?php echo number_format($ytd_summary['total_overtime_hours'] ?? 0, 1); ?></h2>
                            <small class="text-muted">hours (₱<?php echo number_format($ytd_summary['total_overtime'] ?? 0, 2); ?>)</small>
                        </div>
                        <div class="fs-1 text-warning opacity-50">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="year" class="form-label fw-bold">
                        <i class="fas fa-calendar-alt me-1"></i> Year
                    </label>
                    <select class="form-select" id="year" name="year" onchange="this.form.submit()">
                        <?php if (!empty($years_result)): ?>
                            <?php foreach ($years_result as $y): ?>
                                <option value="<?php echo $y['year']; ?>" <?php echo $year == $y['year'] ? 'selected' : ''; ?>>
                                    <?php echo $y['year']; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="month" class="form-label fw-bold">
                        <i class="fas fa-calendar me-1"></i> Month
                    </label>
                    <select class="form-select" id="month" name="month" onchange="this.form.submit()">
                        <option value="0" <?php echo $month == 0 ? 'selected' : ''; ?>>All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <a href="my-payslips.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo me-1"></i> Reset Filters
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Payslips List -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>
                Payslip History
                <?php if ($month > 0): ?>
                    <span class="badge bg-primary ms-2">
                        <?php echo date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year; ?>
                    </span>
                <?php else: ?>
                    <span class="badge bg-primary ms-2"><?php echo $year; ?></span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($payslips)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="120">Pay Period</th>
                                <th>From - To</th>
                                <th class="text-end">Basic Salary</th>
                                <th class="text-end">Overtime</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Gross Pay</th>
                                <th class="text-end">Net Pay</th>
                                <th width="120">Status</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payslips as $payslip): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M Y', strtotime($payslip['pay_period_start'])); ?></strong>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo date('M d', strtotime($payslip['pay_period_start'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-primary">₱<?php echo number_format($payslip['basic_salary'], 2); ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($payslip['overtime_pay'] > 0): ?>
                                            <span class="text-success">
                                                +₱<?php echo number_format($payslip['overtime_pay'], 2); ?>
                                            </span>
                                            <br>
                                            <small class="text-muted"><?php echo number_format($payslip['overtime_hours'], 1); ?> hrs</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($payslip['total_deductions'] > 0): ?>
                                            <span class="text-danger">
                                                -₱<?php echo number_format($payslip['total_deductions'], 2); ?>
                                            </span>
                                            <?php if ($payslip['late_deductions'] > 0): ?>
                                                <br>
                                                <small class="text-muted">
                                                    Late: <?php echo $payslip['late_minutes']; ?> mins
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-success">₱<?php echo number_format($payslip['gross_pay'], 2); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-info fs-6">₱<?php echo number_format($payslip['net_pay'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = 'secondary';
                                        if ($payslip['status'] == 'Approved') $status_class = 'success';
                                        if ($payslip['status'] == 'Paid') $status_class = 'primary';
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>">
                                            <?php echo $payslip['status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="view-my-payslip.php?id=<?php echo $payslip['payslip_id']; ?>" 
                                           class="btn btn-sm btn-primary" title="View Details">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                        <a href="print-payslip.php?id=<?php echo $payslip['payslip_id']; ?>" 
                                           class="btn btn-sm btn-outline-secondary" 
                                           target="_blank" title="Print">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">TOTALS:</td>
                                <td class="text-end text-primary">
                                    ₱<?php echo number_format(array_sum(array_column($payslips, 'basic_salary')), 2); ?>
                                </td>
                                <td class="text-end text-success">
                                    +₱<?php echo number_format(array_sum(array_column($payslips, 'overtime_pay')), 2); ?>
                                </td>
                                <td class="text-end text-danger">
                                    -₱<?php echo number_format(array_sum(array_column($payslips, 'total_deductions')), 2); ?>
                                </td>
                                <td class="text-end text-success">
                                    ₱<?php echo number_format(array_sum(array_column($payslips, 'gross_pay')), 2); ?>
                                </td>
                                <td class="text-end text-info">
                                    ₱<?php echo number_format(array_sum(array_column($payslips, 'net_pay')), 2); ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice-dollar fa-5x text-muted opacity-25 mb-3"></i>
                    <h5 class="text-muted">No Payslips Found</h5>
                    <p class="text-muted mb-0">
                        You don't have any payslips 
                        <?php if ($month > 0): ?>
                            for <?php echo date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year; ?>
                        <?php else: ?>
                            for <?php echo $year; ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info mt-4">
        <div class="d-flex align-items-start">
            <i class="fas fa-info-circle fa-2x me-3"></i>
            <div>
                <strong>About Your Payslips</strong>
                <ul class="mb-0 mt-2">
                    <li><strong>Basic Salary:</strong> Your monthly base pay</li>
                    <li><strong>Overtime:</strong> Additional pay for hours worked beyond your schedule (1.25x hourly rate)</li>
                    <li><strong>Deductions:</strong> Includes late deductions and other deductions (taxes, SSS, etc.)</li>
                    <li><strong>Gross Pay:</strong> Total earnings before deductions</li>
                    <li><strong>Net Pay:</strong> Your actual take-home pay</li>
                    <li><strong>Status:</strong> Draft (under review), Approved (verified), Paid (payment released)</li>
                </ul>
                <p class="mb-0 mt-2">
                    <i class="fas fa-question-circle me-1"></i>
                    If you have questions about your payslip, please contact HR or your administrator.
                </p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>