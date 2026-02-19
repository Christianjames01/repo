<?php
/**
 * Staff View Individual Payslip Details
 * modules/attendance/view-my-payslip.php
 * Detailed view of a single payslip with breakdown
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

$current_user_id = getCurrentUserId();

// Get payslip ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirect('/barangaylink/modules/attendance/my-payslips.php', 'Invalid payslip ID', 'error');
}

$payslip_id = intval($_GET['id']);

// Get payslip details - ensure it belongs to current user
// FIXED: Changed created_by to generated_by and created_at to generated_at
$payslip = fetchOne($conn,
    "SELECT p.*, 
            CONCAT(r.first_name, ' ', r.last_name) as staff_name,
            r.profile_photo,
            r.contact_number,
            r.address as staff_address,
            u.role as staff_role,
            CONCAT(cr.first_name, ' ', cr.last_name) as generated_by_name
    FROM tbl_payslips p
    LEFT JOIN tbl_users u ON p.user_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_users cu ON p.generated_by = cu.user_id
    LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
    WHERE p.payslip_id = ? AND p.user_id = ?",
    [$payslip_id, $current_user_id], 'ii'
);

if (!$payslip) {
    redirect('/barangaylink/modules/attendance/my-payslips.php', 'Payslip not found or access denied', 'error');
}

$page_title = 'Payslip Details - ' . date('F Y', strtotime($payslip['pay_period_start']));

// Get attendance records for this pay period
$attendance_records = fetchAll($conn,
    "SELECT attendance_date, status, time_in, time_out, notes
    FROM tbl_attendance
    WHERE user_id = ? 
    AND attendance_date BETWEEN ? AND ?
    AND time_in IS NOT NULL 
    AND time_out IS NOT NULL
    ORDER BY attendance_date",
    [$current_user_id, $payslip['pay_period_start'], $payslip['pay_period_end']], 'iss'
);

// Calculate attendance details
$attendance_details = [];
foreach ($attendance_records as $record) {
    $time_in = strtotime($record['time_in']);
    $time_out = strtotime($record['time_out']);
    $worked_seconds = $time_out - $time_in;
    $worked_hours = $worked_seconds / 3600;
    
    // Subtract 1 hour lunch if worked > 6 hours
    if ($worked_hours > 6) {
        $worked_hours -= 1;
    }
    
    $attendance_details[] = [
        'date' => $record['attendance_date'],
        'status' => $record['status'],
        'time_in' => $record['time_in'],
        'time_out' => $record['time_out'],
        'worked_hours' => round($worked_hours, 2),
        'notes' => $record['notes']
    ];
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-file-invoice-dollar text-success me-2"></i>
                Payslip Details
            </h1>
            <p class="text-muted mb-0">
                Pay Period: <?php echo date('F d', strtotime($payslip['pay_period_start'])); ?> - 
                <?php echo date('F d, Y', strtotime($payslip['pay_period_end'])); ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="my-payslips.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
            <a href="print-payslip.php?id=<?php echo $payslip_id; ?>" 
               class="btn btn-primary" target="_blank">
                <i class="fas fa-print me-1"></i> Print Payslip
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="row g-4">
        <!-- Left Column: Payslip Summary -->
        <div class="col-lg-8">
            <!-- Staff Info Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-id-card me-2"></i>
                        Employee Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <?php if ($payslip['profile_photo'] && file_exists('../../uploads/profiles/' . $payslip['profile_photo'])): ?>
                                <img src="<?php echo '../../uploads/profiles/' . $payslip['profile_photo']; ?>" 
                                     class="rounded-circle" width="80" height="80" alt="Profile">
                            <?php else: ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                     style="width: 80px; height: 80px; font-size: 2rem;">
                                    <?php echo strtoupper(substr($payslip['staff_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col">
                            <h4 class="mb-1"><?php echo htmlspecialchars($payslip['staff_name']); ?></h4>
                            <p class="mb-1">
                                <span class="badge bg-secondary"><?php echo $payslip['staff_role']; ?></span>
                            </p>
                            <?php if ($payslip['contact_number']): ?>
                                <p class="mb-0 text-muted">
                                    <i class="fas fa-phone me-1"></i>
                                    <?php echo htmlspecialchars($payslip['contact_number']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto text-end">
                            <p class="text-muted mb-1">Payslip ID</p>
                            <h5 class="mb-0">#<?php echo str_pad($payslip['payslip_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                            <small class="text-muted">
                                <!-- FIXED: Changed created_at to generated_at -->
                                Generated: <?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Earnings Breakdown -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-success bg-opacity-10">
                    <h5 class="mb-0">
                        <i class="fas fa-plus-circle text-success me-2"></i>
                        Earnings
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td class="ps-0">
                                    <strong>Basic Salary</strong>
                                    <br><small class="text-muted">Monthly base pay</small>
                                </td>
                                <td class="text-end pe-0">
                                    <h5 class="mb-0 text-primary">₱<?php echo number_format($payslip['basic_salary'], 2); ?></h5>
                                </td>
                            </tr>
                            <tr>
                                <td class="ps-0">
                                    <strong>Allowances</strong>
                                    <br><small class="text-muted">Transportation, meal, etc.</small>
                                </td>
                                <td class="text-end pe-0">
                                    <h5 class="mb-0 text-primary">₱<?php echo number_format($payslip['allowances'], 2); ?></h5>
                                </td>
                            </tr>
                            <?php if ($payslip['overtime_hours'] > 0): ?>
                                <tr class="table-success">
                                    <td class="ps-0">
                                        <strong>Overtime Pay</strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo number_format($payslip['overtime_hours'], 2); ?> hours 
                                            @ ₱<?php echo number_format($payslip['hourly_rate'] * 1.25, 2); ?>/hr
                                        </small>
                                    </td>
                                    <td class="text-end pe-0">
                                        <h5 class="mb-0 text-success">+₱<?php echo number_format($payslip['overtime_pay'], 2); ?></h5>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr class="border-top border-2">
                                <td class="ps-0 pt-3">
                                    <h5 class="mb-0">Gross Pay</h5>
                                    <small class="text-muted">Total before deductions</small>
                                </td>
                                <td class="text-end pe-0 pt-3">
                                    <h3 class="mb-0 text-success">₱<?php echo number_format($payslip['gross_pay'], 2); ?></h3>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Deductions Breakdown -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-danger bg-opacity-10">
                    <h5 class="mb-0">
                        <i class="fas fa-minus-circle text-danger me-2"></i>
                        Deductions
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <?php if ($payslip['late_minutes'] > 0): ?>
                                <tr class="table-warning">
                                    <td class="ps-0">
                                        <strong>Late Deductions</strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo $payslip['late_minutes']; ?> minutes late 
                                            (<?php echo $payslip['days_late']; ?> days)
                                        </small>
                                    </td>
                                    <td class="text-end pe-0">
                                        <h5 class="mb-0 text-danger">-₱<?php echo number_format($payslip['late_deductions'], 2); ?></h5>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($payslip['other_deductions'] > 0): ?>
                                <tr>
                                    <td class="ps-0">
                                        <strong>Other Deductions</strong>
                                        <br><small class="text-muted">SSS, PhilHealth, taxes, etc.</small>
                                    </td>
                                    <td class="text-end pe-0">
                                        <h5 class="mb-0 text-danger">-₱<?php echo number_format($payslip['other_deductions'], 2); ?></h5>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr class="border-top border-2">
                                <td class="ps-0 pt-3">
                                    <h5 class="mb-0">Total Deductions</h5>
                                </td>
                                <td class="text-end pe-0 pt-3">
                                    <h4 class="mb-0 text-danger">-₱<?php echo number_format($payslip['total_deductions'], 2); ?></h4>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Net Pay -->
            <div class="card border-0 shadow-lg border-start border-4 border-success">
                <div class="card-body bg-success bg-opacity-10">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1 text-success">NET PAY</h3>
                            <p class="text-muted mb-0">Your take-home pay</p>
                        </div>
                        <div class="text-end">
                            <h1 class="mb-0 text-success display-4">₱<?php echo number_format($payslip['net_pay'], 2); ?></h1>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Attendance Summary -->
        <div class="col-lg-4">
            <!-- Attendance Summary -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-info bg-opacity-10">
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-check text-info me-2"></i>
                        Attendance Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 p-3 bg-success bg-opacity-10 rounded">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Days Present</h6>
                                <h2 class="mb-0 text-success"><?php echo $payslip['days_present']; ?></h2>
                            </div>
                            <div class="fs-1 text-success opacity-50">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>

                    <?php if ($payslip['days_late'] > 0): ?>
                        <div class="mb-3 p-3 bg-warning bg-opacity-10 rounded">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1">Days Late</h6>
                                    <h2 class="mb-0 text-warning"><?php echo $payslip['days_late']; ?></h2>
                                    <small class="text-muted"><?php echo $payslip['late_minutes']; ?> total minutes</small>
                                </div>
                                <div class="fs-1 text-warning opacity-50">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($payslip['days_absent'] > 0): ?>
                        <div class="mb-3 p-3 bg-danger bg-opacity-10 rounded">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1">Days Absent</h6>
                                    <h2 class="mb-0 text-danger"><?php echo $payslip['days_absent']; ?></h2>
                                </div>
                                <div class="fs-1 text-danger opacity-50">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($payslip['overtime_hours'] > 0): ?>
                        <div class="mb-0 p-3 bg-primary bg-opacity-10 rounded">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1">Overtime Hours</h6>
                                    <h2 class="mb-0 text-primary"><?php echo number_format($payslip['overtime_hours'], 1); ?></h2>
                                    <small class="text-muted">Beyond schedule</small>
                                </div>
                                <div class="fs-1 text-primary opacity-50">
                                    <i class="fas fa-business-time"></i>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pay Period Info -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Payslip Information
                    </h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td class="text-muted">Pay Period:</td>
                                <td class="text-end">
                                    <strong><?php echo date('M d', strtotime($payslip['pay_period_start'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Generated By:</td>
                                <td class="text-end">
                                    <!-- FIXED: Changed created_by_name to generated_by_name -->
                                    <strong><?php echo htmlspecialchars($payslip['generated_by_name'] ?? 'System'); ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Generated On:</td>
                                <td class="text-end">
                                    <!-- FIXED: Changed created_at to generated_at -->
                                    <strong><?php echo date('M d, Y h:i A', strtotime($payslip['generated_at'])); ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Hourly Rate:</td>
                                <td class="text-end">
                                    <strong>₱<?php echo number_format($payslip['hourly_rate'], 2); ?></strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Attendance Details -->
    <?php if (!empty($attendance_details)): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-check me-2"></i>
                    Daily Attendance Record
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Status</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th class="text-end">Hours Worked</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_details as $detail): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($detail['date'])); ?></td>
                                    <td><?php echo date('l', strtotime($detail['date'])); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = $detail['status'] === 'Present' ? 'bg-success' : 'bg-warning';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $detail['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('h:i A', strtotime($detail['time_in'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($detail['time_out'])); ?></td>
                                    <td class="text-end">
                                        <strong><?php echo number_format($detail['worked_hours'], 2); ?> hrs</strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($detail['notes'])): ?>
                                            <small class="text-muted" title="<?php echo htmlspecialchars($detail['notes']); ?>">
                                                <?php echo substr($detail['notes'], 0, 40) . (strlen($detail['notes']) > 40 ? '...' : ''); ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Help Section -->
    <div class="alert alert-info mt-4">
        <div class="d-flex align-items-start">
            <i class="fas fa-question-circle fa-2x me-3"></i>
            <div>
                <h5 class="alert-heading">Need Help?</h5>
                <p class="mb-0">
                    If you have questions about your payslip or notice any discrepancies, 
                    please contact HR or your administrator. Keep this payslip for your records.
                </p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>