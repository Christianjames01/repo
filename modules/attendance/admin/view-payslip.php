<?php
/**
 * View Payslip - Enhanced Single Page Print
 * modules/attendance/admin/view-payslip.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('/barangaylink1/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
$current_user_id = getCurrentUserId();

// Allow staff to view their own payslips
$is_admin = in_array($user_role, ['Admin', 'Super Admin']);

$payslip_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$payslip_id) {
    redirect('/barangaylink1/modules/attendance/admin/payslip-list.php', 'Invalid payslip', 'error');
}

// Get payslip details
$payslip = fetchOne($conn,
    "SELECT p.*, 
            CONCAT(r.first_name, ' ', r.last_name) as staff_name,
            u.username, u.role,
            r.profile_photo, r.contact_number, r.address,
            CONCAT(cr.first_name, ' ', cr.last_name) as created_by_name
    FROM tbl_payslips p
    LEFT JOIN tbl_users u ON p.user_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_users cu ON p.generated_by = cu.user_id
    LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
    WHERE p.payslip_id = ?",
    [$payslip_id], 'i'
);

if (!$payslip) {
    redirect('/barangaylink1/modules/attendance/admin/payslip-list.php', 'Payslip not found', 'error');
}

// Check permission - staff can only view their own payslips
if (!$is_admin && $payslip['user_id'] != $current_user_id) {
    redirect('/barangaylink1/modules/dashboard/index.php', 'Access denied', 'error');
}

// Get attendance records for this period - ONLY DAYS WITH ACTUAL TIME IN/OUT
$attendance_records = fetchAll($conn,
    "SELECT attendance_date, status, time_in, time_out, notes
    FROM tbl_attendance
    WHERE user_id = ? 
    AND attendance_date BETWEEN ? AND ?
    AND time_in IS NOT NULL 
    AND time_out IS NOT NULL
    AND status IN ('Present', 'Late')
    ORDER BY attendance_date",
    [$payslip['user_id'], $payslip['pay_period_start'], $payslip['pay_period_end']], 'iss'
);

$page_title = 'Payslip - ' . $payslip['staff_name'];

include '../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h1 class="h3 mb-2">
            <i class="fas fa-file-invoice-dollar text-success me-2"></i>
            Payslip Details
        </h1>
        <p class="text-muted mb-0">
            Period: <?php echo date('F d', strtotime($payslip['pay_period_start'])); ?> - 
            <?php echo date('F d, Y', strtotime($payslip['pay_period_end'])); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <a href="payslip-list.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to List
        </a>
    </div>
</div>

<?php echo displayMessage(); ?>

<!-- Payslip Document -->
<div class="card border-0 shadow-lg" id="payslip-document">
    <!-- Header -->
    <div class="card-header bg-primary text-white py-3">
        <div class="row align-items-center">
            <div class="col-auto">
                <i class="fas fa-building fa-2x"></i>
            </div>
            <div class="col">
                <h4 class="mb-0">Barangay Management System</h4>
                <small>Official Payslip</small>
            </div>
            <div class="col-auto text-end">
                <h6 class="mb-0">Payslip #<?php echo str_pad($payslip['payslip_id'], 6, '0', STR_PAD_LEFT); ?></h6>
                <small><?php echo date('F Y', strtotime($payslip['pay_period_start'])); ?></small>
            </div>
        </div>
    </div>

    <div class="card-body p-3">
        <!-- Employee Information - Compact -->
        <div class="row mb-3">
            <div class="col-md-7">
                <div class="d-flex align-items-start">
                    <?php if ($payslip['profile_photo'] && file_exists('../../../uploads/profiles/' . $payslip['profile_photo'])): ?>
                        <img src="<?php echo '../../../uploads/profiles/' . $payslip['profile_photo']; ?>" 
                             class="rounded-circle me-2" width="60" height="60" alt="Profile">
                    <?php else: ?>
                        <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                             style="width: 60px; height: 60px; font-size: 1.5rem;">
                            <?php echo strtoupper(substr($payslip['staff_name'] ?? $payslip['username'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="small">
                        <h5 class="mb-1"><?php echo htmlspecialchars($payslip['staff_name'] ?? $payslip['username']); ?></h5>
                        <span class="badge bg-secondary"><?php echo $payslip['role']; ?></span>
                        <?php if ($payslip['contact_number']): ?>
                            <div class="text-muted"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payslip['contact_number']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-5 text-end small">
                <div class="mb-1">
                    <strong>Pay Period:</strong>
                    <?php echo date('M d', strtotime($payslip['pay_period_start'])); ?> - 
                    <?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?>
                </div>
                <div class="text-muted">
                  <strong>Generated:</strong> <?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?>
                </div>
            </div>
        </div>

        <hr class="my-2">

        <!-- Attendance Summary - Compact with Overtime Details -->
        <div class="row mb-3 g-2">
            <div class="col-12">
                <h6 class="mb-2"><i class="fas fa-calendar-check me-1"></i>Attendance Summary</h6>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-success bg-opacity-10 rounded small">
                    <div class="h4 text-success mb-0"><?php echo $payslip['days_present']; ?></div>
                    <small class="text-muted">Present</small>
                </div>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-warning bg-opacity-10 rounded small">
                    <div class="h4 text-warning mb-0"><?php echo $payslip['days_late']; ?></div>
                    <small class="text-muted">Late</small>
                </div>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-danger bg-opacity-10 rounded small">
                    <div class="h4 text-danger mb-0"><?php echo $payslip['days_absent']; ?></div>
                    <small class="text-muted">Absent</small>
                </div>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-info bg-opacity-10 rounded small">
                    <div class="h4 text-info mb-0"><?php echo number_format($payslip['overtime_hours'], 1); ?></div>
                    <small class="text-muted">OT Hours</small>
                </div>
            </div>
        </div>

        <?php if ($payslip['overtime_hours'] > 0): ?>
        <!-- Overtime Details Box -->
        <div class="alert alert-info py-2 mb-3 small">
            <strong><i class="fas fa-business-time me-1"></i>Overtime Details:</strong><br>
            <?php echo number_format($payslip['overtime_hours'], 2); ?> hours × ₱<?php echo number_format($payslip['hourly_rate'], 2); ?>/hr × 1.25 = 
            <strong>₱<?php echo number_format($payslip['overtime_pay'], 2); ?></strong>
        </div>
        <?php endif; ?>

        <hr class="my-2">

        <!-- Earnings and Deductions - Side by Side -->
        <div class="row mb-3">
            <!-- Earnings -->
            <div class="col-md-6">
                <h6 class="mb-2 text-success"><i class="fas fa-plus-circle me-1"></i>Earnings</h6>
                <table class="table table-sm table-borderless mb-0 small">
                    <tbody>
                        <tr>
                            <td>Basic Salary</td>
                            <td class="text-end fw-bold">₱<?php echo number_format($payslip['basic_salary'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Allowances</td>
                            <td class="text-end fw-bold">₱<?php echo number_format($payslip['allowances'], 2); ?></td>
                        </tr>
                        <?php if ($payslip['overtime_hours'] > 0): ?>
                        <tr class="table-success">
                            <td>
                                <strong>Overtime Pay</strong>
                                <small class="text-muted d-block">
                                    <?php echo number_format($payslip['overtime_hours'], 2); ?> hrs × 
                                    ₱<?php echo number_format($payslip['hourly_rate'], 2); ?> × 1.25
                                </small>
                            </td>
                            <td class="text-end fw-bold text-success">₱<?php echo number_format($payslip['overtime_pay'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="border-top">
                            <td><strong>GROSS PAY</strong></td>
                            <td class="text-end fw-bold text-success">₱<?php echo number_format($payslip['gross_pay'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Deductions -->
            <div class="col-md-6">
                <h6 class="mb-2 text-danger"><i class="fas fa-minus-circle me-1"></i>Deductions</h6>
                <table class="table table-sm table-borderless mb-0 small">
                    <tbody>
                        <?php if ($payslip['late_minutes'] > 0): ?>
                        <tr class="table-warning">
                            <td>
                                <strong>Late Deductions</strong>
                                <small class="text-muted d-block">
                                    <?php echo $payslip['late_minutes']; ?> mins × 
                                    ₱<?php echo number_format($payslip['late_deductions'] / max($payslip['late_minutes'], 1), 2); ?>/min
                                </small>
                            </td>
                            <td class="text-end fw-bold text-warning">-₱<?php echo number_format($payslip['late_deductions'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Other Deductions <small class="text-muted d-block">SSS, PhilHealth, etc.</small></td>
                            <td class="text-end fw-bold">₱<?php echo number_format($payslip['other_deductions'], 2); ?></td>
                        </tr>
                        <tr class="border-top">
                            <td><strong>TOTAL DEDUCTIONS</strong></td>
                            <td class="text-end fw-bold text-danger">₱<?php echo number_format($payslip['total_deductions'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <hr class="my-2">

        <!-- Net Pay - Compact -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="bg-success bg-opacity-10 p-3 rounded text-center">
                    <h6 class="text-muted mb-1">NET PAY</h6>
                    <h2 class="text-success mb-1">₱<?php echo number_format($payslip['net_pay'], 2); ?></h2>
                    <small class="text-muted">Gross: ₱<?php echo number_format($payslip['gross_pay'], 2); ?> - Deductions: ₱<?php echo number_format($payslip['total_deductions'], 2); ?></small>
                </div>
            </div>
        </div>

        <!-- Detailed Attendance Records - Compact Table with Overtime Column -->
        <?php if ($is_admin && count($attendance_records) > 0): ?>
        <hr class="my-2">
        <div class="row">
            <div class="col-12">
                <h6 class="mb-2"><i class="fas fa-list-alt me-1"></i>Working Days (<?php echo count($attendance_records); ?> days)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size: 0.7rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Worked</th>
                                <th>OT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_ot_displayed = 0;
                            $total_worked_hours = 0;
                            foreach ($attendance_records as $record): 
                                // Calculate worked hours and overtime for this record
                                $worked_hours = 0;
                                $record_ot = 0;
                                
                                if ($record['time_in'] && $record['time_out']) {
                                    $time_in = strtotime($record['time_in']);
                                    $time_out = strtotime($record['time_out']);
                                    $worked_seconds = $time_out - $time_in;
                                    $worked_hours = $worked_seconds / 3600;
                                    
                                    // Subtract lunch break if worked > 6 hours
                                    if ($worked_hours > 6) {
                                        $worked_hours -= 1;
                                    }
                                    
                                    $total_worked_hours += $worked_hours;
                                    
                                    // Get schedule for overtime calculation
                                    $day_of_week = date('l', strtotime($record['attendance_date']));
                                    
                                    $schedule = fetchOne($conn,
                                        "SELECT time_in, time_out FROM tbl_special_duty_schedules 
                                        WHERE user_id = ? AND schedule_date = ?",
                                        [$payslip['user_id'], $record['attendance_date']], 'is'
                                    );
                                    
                                    if (!$schedule) {
                                        $schedule = fetchOne($conn,
                                            "SELECT ss.custom_time_in as time_in, ss.custom_time_out as time_out
                                            FROM tbl_special_schedules ss
                                            INNER JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id = ssa.schedule_id
                                            WHERE ssa.user_id = ? AND ss.schedule_date = ? AND ss.is_working_day = 1",
                                            [$payslip['user_id'], $record['attendance_date']], 'is'
                                        );
                                    }
                                    
                                    if (!$schedule) {
                                        $schedule = fetchOne($conn,
                                            "SELECT time_in, time_out FROM tbl_duty_schedules 
                                            WHERE user_id = ? AND day_of_week = ? AND is_active = 1",
                                            [$payslip['user_id'], $day_of_week], 'is'
                                        );
                                    }
                                    
                                    if ($schedule && $schedule['time_in'] && $schedule['time_out']) {
                                        $scheduled_in = strtotime($record['attendance_date'] . ' ' . $schedule['time_in']);
                                        $scheduled_out = strtotime($record['attendance_date'] . ' ' . $schedule['time_out']);
                                        $scheduled_seconds = $scheduled_out - $scheduled_in;
                                        $scheduled_hours = $scheduled_seconds / 3600;
                                        
                                        if ($scheduled_hours > 6) {
                                            $scheduled_hours -= 1;
                                        }
                                        
                                        if ($worked_hours > $scheduled_hours) {
                                            $record_ot = $worked_hours - $scheduled_hours;
                                            $total_ot_displayed += $record_ot;
                                        }
                                    }
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo date('M d', strtotime($record['attendance_date'])); ?></strong></td>
                                    <td><?php echo getStatusBadge($record['status']); ?></td>
                                    <td><?php echo date('h:i A', strtotime($record['time_in'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($record['time_out'])); ?></td>
                                    <td class="text-center">
                                        <small><?php echo number_format($worked_hours, 1); ?>h</small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($record_ot > 0): ?>
                                            <span class="badge bg-success" style="font-size: 0.65rem;">
                                                <?php echo number_format($record_ot, 1); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Totals:</th>
                                <th class="text-center">
                                    <span class="badge bg-primary">
                                        <?php echo number_format($total_worked_hours, 1); ?>h
                                    </span>
                                </th>
                                <th class="text-center">
                                    <span class="badge bg-info">
                                        <?php echo number_format($total_ot_displayed, 1); ?>h
                                    </span>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="alert alert-light py-1 mt-2 mb-0" style="font-size: 0.7rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong>Note:</strong> Showing only days with complete time in/out records. 
                    Absent days and incomplete attendance are excluded from calculations.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer - Compact -->
<div class="row mt-3 pt-2 border-top">
    <div class="col-6 small">
        <div class="text-muted">Generated by: <?php echo htmlspecialchars($payslip['created_by_name']); ?></div>
        <div class="text-muted"><?php echo date('M d, Y h:i A', strtotime($payslip['generated_at'])); ?></div>
    </div>
    <div class="col-6 text-end">
        <div class="border-top border-dark d-inline-block px-4 pt-1">
            <small><strong>Authorized Signature</strong></small>
        </div>
    </div>
</div>
        </div>

        <div class="alert alert-info py-1 mt-2 mb-0 small">
            <i class="fas fa-info-circle me-1"></i>
            <small>This is a computer-generated payslip. For discrepancies, contact HR department.</small>
        </div>
    </div>
</div>

<!-- Enhanced Print Styles for Single Page -->
<style>
@media print {
    /* Hide everything except payslip */
    body * {
        visibility: hidden;
    }
    
    #payslip-document, 
    #payslip-document * {
        visibility: visible;
    }
    
    #payslip-document {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 0;
    }
    
    /* Remove unnecessary elements */
    .btn, .no-print, nav, footer, .sidebar {
        display: none !important;
    }
    
    /* Remove shadows and borders */
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    
    /* Optimize page layout */
    @page {
        size: A4;
        margin: 10mm;
    }
    
    body {
        margin: 0;
        padding: 0;
    }
    
    /* Reduce spacing */
    .card-body {
        padding: 10px !important;
    }
    
    .card-header {
        padding: 10px 15px !important;
    }
    
    h1, h2, h3, h4, h5, h6 {
        margin-bottom: 5px !important;
    }
    
    .mb-1, .mb-2, .mb-3, .mb-4, .mb-5 {
        margin-bottom: 5px !important;
    }
    
    .mt-1, .mt-2, .mt-3, .mt-4, .mt-5 {
        margin-top: 5px !important;
    }
    
    .py-1, .py-2, .py-3, .py-4, .py-5 {
        padding-top: 5px !important;
        padding-bottom: 5px !important;
    }
    
    hr {
        margin: 8px 0 !important;
    }
    
    /* Compact table */
    .table-sm {
        font-size: 10px !important;
    }
    
    .table-sm td, .table-sm th {
        padding: 3px !important;
    }
    
    /* Compact text */
    .small, small {
        font-size: 9px !important;
    }
    
    /* Ensure profile image fits */
    img.rounded-circle {
        width: 50px !important;
        height: 50px !important;
    }
    
    /* Background colors for print */
    .bg-success {
        background-color: #d4edda !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    
    .bg-warning {
        background-color: #fff3cd !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    
    .bg-danger {
        background-color: #f8d7da !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    
    .bg-info {
        background-color: #d1ecf1 !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    
    .bg-primary {
        background-color: #0d6efd !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    
    .text-white {
        color: white !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    
    /* Prevent page breaks */
    .row, .col-12, .col-md-6, .col-md-3 {
        page-break-inside: avoid;
    }
    
    /* Force single page */
    html, body {
        height: 100%;
        overflow: hidden;
    }
}

/* Screen view improvements */
@media screen {
    #payslip-document {
        max-width: 210mm;
        margin: 0 auto;
    }
}
</style>

<?php include '../../../includes/footer.php'; ?>