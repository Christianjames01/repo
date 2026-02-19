<?php
/**
 * Payslip Generation Module - WITH MODALS
 * modules/attendance/admin/generate-payslip.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Generate Payslip';
$current_user_id = getCurrentUserId();

// Get filter parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

/**
 * Calculate attendance summary with overtime and late deductions
 */
function calculateAttendanceSummary($conn, $user_id, $start_date, $end_date, $hourly_rate, $overtime_multiplier, $late_deduction_rate) {
    // Get ONLY attendance records where staff actually worked
    $attendance_records = fetchAll($conn,
        "SELECT attendance_date, status, time_in, time_out, notes
        FROM tbl_attendance
        WHERE user_id = ? 
        AND attendance_date BETWEEN ? AND ?
        AND time_in IS NOT NULL 
        AND time_out IS NOT NULL
        AND time_in != '00:00:00'
        AND time_out != '00:00:00'
        AND status IN ('Present', 'Late')
        ORDER BY attendance_date",
        [$user_id, $start_date, $end_date], 'iss'
    );
    
    // Also get absent and leave days for summary
    $non_working_records = fetchAll($conn,
        "SELECT status
        FROM tbl_attendance
        WHERE user_id = ? 
        AND attendance_date BETWEEN ? AND ?
        AND status IN ('Absent', 'On Leave')
        ORDER BY attendance_date",
        [$user_id, $start_date, $end_date], 'iss'
    );
    
    $summary = [
        'present_days' => 0,
        'late_days' => 0,
        'absent_days' => 0,
        'leave_days' => 0,
        'total_late_minutes' => 0,
        'overtime_hours' => 0,
        'late_deductions' => 0,
        'overtime_pay' => 0,
        'total_working_days' => count($attendance_records),
        'records' => []
    ];
    
    // Count non-working days
    foreach ($non_working_records as $record) {
        if ($record['status'] === 'Absent') {
            $summary['absent_days']++;
        } elseif ($record['status'] === 'On Leave') {
            $summary['leave_days']++;
        }
    }
    
    $grace_period_minutes = 15;
    
    foreach ($attendance_records as $record) {
        $record_detail = [
            'date' => $record['attendance_date'],
            'status' => $record['status'],
            'time_in' => $record['time_in'],
            'time_out' => $record['time_out'],
            'late_minutes' => 0,
            'overtime_hours' => 0,
            'worked_hours' => 0
        ];
        
        $summary['present_days']++;
        
        $day_of_week = date('l', strtotime($record['attendance_date']));
        
        // Check for special schedule first
        $schedule = fetchOne($conn,
            "SELECT time_in, time_out FROM tbl_special_duty_schedules 
            WHERE user_id = ? AND schedule_date = ?",
            [$user_id, $record['attendance_date']], 'is'
        );
        
        if (!$schedule) {
            $schedule = fetchOne($conn,
                "SELECT ss.custom_time_in as time_in, ss.custom_time_out as time_out
                FROM tbl_special_schedules ss
                INNER JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id = ssa.schedule_id
                WHERE ssa.user_id = ? AND ss.schedule_date = ? AND ss.is_working_day = 1",
                [$user_id, $record['attendance_date']], 'is'
            );
        }
        
        if (!$schedule) {
            $schedule = fetchOne($conn,
                "SELECT time_in, time_out FROM tbl_duty_schedules 
                WHERE user_id = ? AND day_of_week = ? AND is_active = 1",
                [$user_id, $day_of_week], 'is'
            );
        }
        
        // Calculate late minutes
        if ($schedule && $schedule['time_in']) {
            $time_in = strtotime($record['time_in']);
            $scheduled_time_in = strtotime($record['attendance_date'] . ' ' . $schedule['time_in']);
            
            if ($time_in > $scheduled_time_in) {
                $late_seconds = $time_in - $scheduled_time_in;
                $late_minutes = floor($late_seconds / 60);
                
                if ($late_minutes > $grace_period_minutes) {
                    $late_minutes -= $grace_period_minutes;
                    $record_detail['late_minutes'] = $late_minutes;
                    $summary['total_late_minutes'] += $late_minutes;
                    $summary['late_days']++;
                }
            }
        }
        
        // Calculate worked hours
        $time_in_stamp = strtotime($record['time_in']);
        $time_out_stamp = strtotime($record['time_out']);
        $worked_seconds = $time_out_stamp - $time_in_stamp;
        $worked_hours = $worked_seconds / 3600;
        
        if ($worked_hours > 6) {
            $worked_hours -= 1;
        }
        
        $record_detail['worked_hours'] = round($worked_hours, 2);
        
        // Calculate overtime
        if ($schedule && $schedule['time_out']) {
            $scheduled_in = strtotime($record['attendance_date'] . ' ' . $schedule['time_in']);
            $scheduled_out = strtotime($record['attendance_date'] . ' ' . $schedule['time_out']);
            $scheduled_seconds = $scheduled_out - $scheduled_in;
            $scheduled_hours = $scheduled_seconds / 3600;
            
            if ($scheduled_hours > 6) {
                $scheduled_hours -= 1;
            }
            
            if ($worked_hours > $scheduled_hours) {
                $overtime = $worked_hours - $scheduled_hours;
                $record_detail['overtime_hours'] = round($overtime, 2);
                $summary['overtime_hours'] += $overtime;
            }
        } else {
            $standard_hours = 8;
            if ($worked_hours > $standard_hours) {
                $overtime = $worked_hours - $standard_hours;
                $record_detail['overtime_hours'] = round($overtime, 2);
                $summary['overtime_hours'] += $overtime;
            }
        }
        
        $summary['records'][] = $record_detail;
    }
    
    $summary['late_deductions'] = $summary['total_late_minutes'] * $late_deduction_rate;
    $summary['overtime_pay'] = $summary['overtime_hours'] * ($hourly_rate * $overtime_multiplier);
    
    $summary['overtime_hours'] = round($summary['overtime_hours'], 2);
    $summary['late_deductions'] = round($summary['late_deductions'], 2);
    $summary['overtime_pay'] = round($summary['overtime_pay'], 2);
    
    return $summary;
}

// Handle payslip generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payslip'])) {
    $user_id = intval($_POST['user_id']);
    $pay_period_start = sanitizeInput($_POST['pay_period_start']);
    $pay_period_end = sanitizeInput($_POST['pay_period_end']);
    $basic_salary = floatval($_POST['basic_salary']);
    $hourly_rate = floatval($_POST['hourly_rate']);
    $overtime_rate_multiplier = floatval($_POST['overtime_rate_multiplier']);
    $late_deduction_per_minute = floatval($_POST['late_deduction_per_minute']);
    $allowances = floatval($_POST['allowances']);
    $deductions = floatval($_POST['deductions']);
    
    $attendance_summary = calculateAttendanceSummary($conn, $user_id, $pay_period_start, $pay_period_end, $hourly_rate, $overtime_rate_multiplier, $late_deduction_per_minute);
    
    $gross_pay = $basic_salary + $allowances + $attendance_summary['overtime_pay'];
    $total_deductions = $deductions + $attendance_summary['late_deductions'];
    $net_pay = $gross_pay - $total_deductions;
    
    // Fixed SQL - Including ALL required columns from the database table
    $sql = "INSERT INTO tbl_payslips (
        user_id, pay_period_start, pay_period_end, basic_salary, 
        hourly_rate, overtime_hours, overtime_pay, gross_pay,
        late_minutes, late_deductions, allowances, 
        absences, absence_deductions, other_deductions, 
        total_deductions, net_pay, days_present, days_late, 
        days_absent, generated_by, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    // Type string: 21 parameters
    // i=integer, s=string, d=double/decimal
    $types = 'issddddddiididddiiiis';

    $success = executeQuery($conn, $sql, [
        $user_id,                                           // i - user_id
        $pay_period_start,                                  // s - pay_period_start
        $pay_period_end,                                    // s - pay_period_end
        $basic_salary,                                      // d - basic_salary
        $hourly_rate,                                       // d - hourly_rate
        $attendance_summary['overtime_hours'],              // d - overtime_hours
        $attendance_summary['overtime_pay'],                // d - overtime_pay
        $gross_pay,                                         // d - gross_pay
        intval($attendance_summary['total_late_minutes']),  // i - late_minutes
        $attendance_summary['late_deductions'],             // d - late_deductions
        $allowances,                                        // i - allowances (changed to d)
        $attendance_summary['absent_days'],                 // d - absences
        0.00,                                               // i - absence_deductions
        $deductions,                                        // d - other_deductions
        $total_deductions,                                  // d - total_deductions
        $net_pay,                                           // d - net_pay
        $attendance_summary['present_days'],                // i - days_present
        $attendance_summary['late_days'],                   // i - days_late
        $attendance_summary['absent_days'],                 // i - days_absent
        $current_user_id,                                   // i - generated_by
        'Approved'                                          // s - status
    ], $types);

    if ($success) {
        $payslip_id = $conn->insert_id;
        logActivity($conn, $current_user_id, 'Generated payslip', 'tbl_payslips', $payslip_id, "Period: $pay_period_start to $pay_period_end");
        $_SESSION['success_message'] = 'Payslip generated successfully';
        header("Location: view-payslip.php?id=$payslip_id");
        exit();
    } else {
        // Log the actual error for debugging
        error_log("Payslip generation failed: " . $conn->error);
        $_SESSION['error_message'] = 'Failed to generate payslip. Please try again.';
    }
}

// Get all active staff
$staff = fetchAll($conn,
    "SELECT u.user_id, u.username, u.role, 
            CONCAT(r.first_name, ' ', r.last_name) as full_name,
            r.profile_photo
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
    ORDER BY r.last_name, r.first_name"
);

// Get attendance summary if user is selected
$attendance_summary = null;
$user_info = null;
$pay_period_start = null;
$pay_period_end = null;

if ($selected_user > 0) {
    list($year, $month) = explode('-', $selected_month);
    $pay_period_start = date('Y-m-01', strtotime($selected_month));
    $pay_period_end = date('Y-m-t', strtotime($selected_month));
    
    $user_info = fetchOne($conn,
        "SELECT u.user_id, u.username, u.role, 
                CONCAT(r.first_name, ' ', r.last_name) as full_name,
                r.profile_photo, r.contact_number
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?",
        [$selected_user], 'i'
    );
    
    $hourly_rate = 75;
    $overtime_rate_multiplier = 1.25;
    $late_deduction_per_minute = 2;
    
    $attendance_summary = calculateAttendanceSummary($conn, $selected_user, $pay_period_start, $pay_period_end, $hourly_rate, $overtime_rate_multiplier, $late_deduction_per_minute);
}

include '../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-2">
            <i class="fas fa-file-invoice-dollar text-success me-2"></i>
            Generate Payslip
        </h1>
        <p class="text-muted mb-0">Calculate and generate staff payslips with attendance data</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Attendance
        </a>
        <a href="payslip-list.php" class="btn btn-outline-primary">
            <i class="fas fa-list me-1"></i> View All Payslips
        </a>
    </div>
</div>

<?php echo displayMessage(); ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <label for="user_id" class="form-label fw-bold">
                    <i class="fas fa-user me-1"></i> Select Staff Member
                </label>
                <select class="form-select" id="user_id" name="user_id" required onchange="this.form.submit()">
                    <option value="">-- Select Staff --</option>
                    <?php foreach ($staff as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>" <?php echo $selected_user == $s['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['full_name'] ?? $s['username']) . ' (' . $s['role'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="month" class="form-label fw-bold">
                    <i class="fas fa-calendar me-1"></i> Pay Period (Month)
                </label>
                <input type="month" class="form-control" id="month" name="month" 
                       value="<?php echo $selected_month; ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <a href="generate-payslip.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-redo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<?php if ($user_info && $attendance_summary): ?>
    <!-- Staff Info Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary bg-opacity-10">
            <h5 class="mb-0">
                <i class="fas fa-user-tie me-2"></i>
                Staff Information
            </h5>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php if ($user_info['profile_photo'] && file_exists('../../../uploads/profiles/' . $user_info['profile_photo'])): ?>
                        <img src="<?php echo '../../../uploads/profiles/' . $user_info['profile_photo']; ?>" 
                             class="rounded-circle" width="80" height="80" alt="Profile">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                             style="width: 80px; height: 80px; font-size: 2rem;">
                            <?php echo strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_info['full_name'] ?? $user_info['username']); ?></h4>
                    <p class="text-muted mb-1">
                        <span class="badge bg-secondary"><?php echo $user_info['role']; ?></span>
                    </p>
                    <?php if ($user_info['contact_number']): ?>
                        <p class="mb-0">
                            <i class="fas fa-phone me-1"></i>
                            <?php echo htmlspecialchars($user_info['contact_number']); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="col-auto">
                    <div class="text-end">
                        <p class="text-muted mb-1">Pay Period</p>
                        <h5 class="mb-0"><?php echo date('F Y', strtotime($selected_month)); ?></h5>
                        <small class="text-muted">
                            <?php echo date('M d', strtotime($pay_period_start)) . ' - ' . date('M d, Y', strtotime($pay_period_end)); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Summary -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Working Days</h6>
                            <h2 class="mb-0 text-primary"><?php echo $attendance_summary['total_working_days']; ?></h2>
                            <small class="text-muted">
                                <?php echo $attendance_summary['present_days']; ?> on time, 
                                <?php echo $attendance_summary['late_days']; ?> late
                            </small>
                        </div>
                        <div class="fs-1 text-primary opacity-50">
                            <i class="fas fa-calendar-check"></i>
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
                            <h6 class="text-muted mb-1">Late Minutes</h6>
                            <h2 class="mb-0 text-warning"><?php echo $attendance_summary['total_late_minutes']; ?></h2>
                            <small class="text-muted">
                                <?php echo $attendance_summary['late_days']; ?> day(s) late
                            </small>
                        </div>
                        <div class="fs-1 text-warning opacity-50">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Absent</h6>
                            <h2 class="mb-0 text-danger"><?php echo $attendance_summary['absent_days']; ?></h2>
                            <small class="text-muted">
                                <?php echo $attendance_summary['leave_days']; ?> on leave
                            </small>
                        </div>
                        <div class="fs-1 text-danger opacity-50">
                            <i class="fas fa-times-circle"></i>
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
                            <h6 class="text-muted mb-1">Overtime Hours</h6>
                            <h2 class="mb-0 text-info"><?php echo number_format($attendance_summary['overtime_hours'], 2); ?></h2>
                            <small class="text-muted">Beyond schedule</small>
                        </div>
                        <div class="fs-1 text-info opacity-50">
                            <i class="fas fa-business-time"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Card -->
    <div class="card border-info border-2 mb-4">
        <div class="card-body">
            <div class="d-flex align-items-start">
                <i class="fas fa-info-circle fa-2x text-info me-3"></i>
                <div>
                    <h6 class="fw-bold text-info mb-2">Calculation Based on Actual Working Days</h6>
                    <p class="mb-0 small">
                        Payslip calculations include only days where the staff member actually clocked in and out 
                        (<?php echo $attendance_summary['total_working_days']; ?> working days). 
                        Absent days (<?php echo $attendance_summary['absent_days']; ?>) and approved leave days 
                        (<?php echo $attendance_summary['leave_days']; ?>) are excluded from salary calculations.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Payslip Generation Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-success bg-opacity-10">
            <h5 class="mb-0">
                <i class="fas fa-calculator me-2"></i>
                Payslip Calculation
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" id="payslipForm" onsubmit="return validatePayslip(event)">
                <input type="hidden" name="generate_payslip" value="1">
                <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">
                <input type="hidden" name="pay_period_start" value="<?php echo $pay_period_start; ?>">
                <input type="hidden" name="pay_period_end" value="<?php echo $pay_period_end; ?>">
                
                <div class="row g-4">
                    <!-- Salary Information -->
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Salary Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="basic_salary" class="form-label fw-bold">Basic Salary (Monthly)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="basic_salary" name="basic_salary" 
                                               value="15000" step="0.01" required onchange="calculatePayslip()">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="hourly_rate" class="form-label fw-bold">Hourly Rate</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="hourly_rate" name="hourly_rate" 
                                               value="75" step="0.01" required onchange="calculatePayslip()">
                                    </div>
                                    <small class="text-muted">Used for overtime calculation</small>
                                </div>
                                <div class="mb-3">
                                    <label for="overtime_rate_multiplier" class="form-label fw-bold">Overtime Rate Multiplier</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="overtime_rate_multiplier" name="overtime_rate_multiplier" 
                                               value="1.25" step="0.01" min="1" required onchange="calculatePayslip()">
                                        <span class="input-group-text">x</span>
                                    </div>
                                    <small class="text-muted">1.25 = 125% of hourly rate</small>
                                </div>
                                <div class="mb-3">
                                    <label for="late_deduction_per_minute" class="form-label fw-bold">Late Deduction (per minute)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="late_deduction_per_minute" name="late_deduction_per_minute" 
                                               value="2" step="0.01" required onchange="calculatePayslip()">
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <label for="allowances" class="form-label fw-bold">Allowances</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="allowances" name="allowances" 
                                               value="2000" step="0.01" required onchange="calculatePayslip()">
                                    </div>
                                    <small class="text-muted">Transportation, meal, etc.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calculations Summary -->
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>Payslip Summary</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tbody>
                                        <tr>
                                            <td>Basic Salary:</td>
                                            <td class="text-end fw-bold" id="display_basic">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Allowances:</td>
                                            <td class="text-end fw-bold" id="display_allowances">₱0.00</td>
                                        </tr>
                                        <tr class="table-success">
                                            <td>
                                                Overtime Pay:
                                                <br><small class="text-muted"><?php echo $attendance_summary['overtime_hours']; ?> hrs × rate</small>
                                            </td>
                                            <td class="text-end fw-bold text-success" id="display_overtime">₱0.00</td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="fw-bold">Gross Pay:</td>
                                            <td class="text-end fw-bold fs-5" id="display_gross">₱0.00</td>
                                        </tr>
                                        <tr class="table-light">
                                            <td colspan="2"><strong>Deductions:</strong></td>
                                        </tr>
                                        <tr class="table-warning">
                                            <td>
                                                Late Deductions:
                                                <br><small class="text-muted"><?php echo $attendance_summary['total_late_minutes']; ?> mins × rate</small>
                                            </td>
                                            <td class="text-end fw-bold text-danger" id="display_late">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <label for="deductions" class="form-label mb-0">Other Deductions:</label>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">₱</span>
                                                    <input type="number" class="form-control text-end" id="deductions" name="deductions" 
                                                           value="500" step="0.01" required onchange="calculatePayslip()">
                                                </div>
                                            </td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="fw-bold">Total Deductions:</td>
                                            <td class="text-end fw-bold text-danger" id="display_total_deductions">₱0.00</td>
                                        </tr>
                                        <tr class="table-success border-top">
                                            <td class="fw-bold fs-5">NET PAY:</td>
                                            <td class="text-end fw-bold fs-4 text-success" id="display_net">₱0.00</td>
                                        </tr>
                                    </tbody>
                                </table>
                                
                                <div class="card border-info mt-3 mb-0">
                                    <div class="card-body p-2">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <strong>Note:</strong> Overtime is calculated based on hours worked beyond the employee's scheduled time.
                                            Calculation from <?php echo date('M d', strtotime($pay_period_start)) . ' to ' . date('M d, Y', strtotime($pay_period_end)); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Generate Payslip
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Attendance Details Table -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-list-alt me-2"></i>
                Attendance Details
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Status</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th class="text-end">Hours Worked</th>
                            <th class="text-end">Late (mins)</th>
                            <th class="text-end">Overtime (hrs)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($attendance_summary['records'])): ?>
                            <?php foreach ($attendance_summary['records'] as $record): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo date('l', strtotime($record['date'])); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = '';
                                        switch ($record['status']) {
                                            case 'Present':
                                                $badge_class = 'bg-success';
                                                break;
                                            case 'Late':
                                                $badge_class = 'bg-warning';
                                                break;
                                            case 'Absent':
                                                $badge_class = 'bg-danger';
                                                break;
                                            case 'On Leave':
                                                $badge_class = 'bg-info';
                                                break;
                                            default:
                                                $badge_class = 'bg-secondary';
                                        }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $record['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $record['time_out'] ? date('h:i A', strtotime($record['time_out'])) : '-'; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo $record['worked_hours'] > 0 ? number_format($record['worked_hours'], 2) : '-'; ?>
                                    </td>
                                    <td class="text-end text-danger">
                                        <?php echo $record['late_minutes'] > 0 ? $record['late_minutes'] : '-'; ?>
                                    </td>
                                    <td class="text-end text-success">
                                        <?php echo $record['overtime_hours'] > 0 ? number_format($record['overtime_hours'], 2) : '-'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                                    No attendance records found for this period
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">TOTALS:</td>
                            <td class="text-end">-</td>
                            <td class="text-end text-danger"><?php echo $attendance_summary['total_late_minutes']; ?></td>
                            <td class="text-end text-success"><?php echo number_format($attendance_summary['overtime_hours'], 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Empty State -->
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-file-invoice-dollar fa-5x text-muted opacity-25 mb-3"></i>
            <h4 class="text-muted">Select a Staff Member</h4>
            <p class="text-muted mb-0">Choose a staff member and pay period to generate their payslip</p>
        </div>
    </div>
<?php endif; ?>

<!-- Negative Net Pay Warning Modal -->
<div class="modal fade" id="negativePayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Invalid Net Pay
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-exclamation-circle fa-4x text-danger mb-3"></i>
                <h5>Net pay cannot be negative!</h5>
                <p class="text-muted mb-0">Please adjust the deductions or salary amounts before generating the payslip.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Generation Modal -->
<div class="modal fade" id="confirmGenerationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Confirm Payslip Generation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-file-invoice-dollar fa-4x text-success mb-3"></i>
                </div>
                <div class="alert alert-info">
                    <h6 class="fw-bold">Payslip Summary:</h6>
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>Staff:</td>
                            <td class="text-end fw-bold"><?php echo htmlspecialchars($user_info['full_name'] ?? $user_info['username'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <td>Period:</td>
                            <td class="text-end fw-bold" id="confirm_period"></td>
                        </tr>
                        <tr>
                            <td>Gross Pay:</td>
                            <td class="text-end fw-bold" id="confirm_gross"></td>
                        </tr>
                        <tr>
                            <td>Deductions:</td>
                            <td class="text-end fw-bold text-danger" id="confirm_deductions"></td>
                        </tr>
                        <tr class="table-success">
                            <td class="fw-bold">Net Pay:</td>
                            <td class="text-end fw-bold fs-5 text-success" id="confirm_net"></td>
                        </tr>
                    </table>
                </div>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. Are you sure you want to generate this payslip?
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="submitPayslipForm()">
                    <i class="fas fa-check me-1"></i>Generate Payslip
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Calculate payslip summary in real-time
function calculatePayslip() {
    const basicSalary = parseFloat(document.getElementById('basic_salary').value) || 0;
    const hourlyRate = parseFloat(document.getElementById('hourly_rate').value) || 0;
    const overtimeMultiplier = parseFloat(document.getElementById('overtime_rate_multiplier').value) || 1.25;
    const lateDeductionRate = parseFloat(document.getElementById('late_deduction_per_minute').value) || 0;
    const allowances = parseFloat(document.getElementById('allowances').value) || 0;
    const otherDeductions = parseFloat(document.getElementById('deductions').value) || 0;
    
    const overtimeHours = <?php echo $attendance_summary ? $attendance_summary['overtime_hours'] : 0; ?>;
    const lateMinutes = <?php echo $attendance_summary ? $attendance_summary['total_late_minutes'] : 0; ?>;
    
    const overtimePay = overtimeHours * (hourlyRate * overtimeMultiplier);
    const lateDeductions = lateMinutes * lateDeductionRate;
    const grossPay = basicSalary + allowances + overtimePay;
    const totalDeductions = lateDeductions + otherDeductions;
    const netPay = grossPay - totalDeductions;
    
    document.getElementById('display_basic').textContent = '₱' + basicSalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('display_allowances').textContent = '₱' + allowances.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('display_overtime').textContent = '₱' + overtimePay.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('display_gross').textContent = '₱' + grossPay.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('display_late').textContent = '₱' + lateDeductions.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('display_total_deductions').textContent = '₱' + totalDeductions.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('display_net').textContent = '₱' + netPay.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Validate payslip before showing confirmation
function validatePayslip(event) {
    event.preventDefault();
    
    const netPay = parseFloat(document.getElementById('display_net').textContent.replace(/[₱,]/g, ''));
    
    if (netPay < 0) {
        const modal = new bootstrap.Modal(document.getElementById('negativePayModal'));
        modal.show();
        return false;
    }
    
    // Update confirmation modal
    const period = '<?php echo isset($pay_period_start) && isset($pay_period_end) ? date("M d", strtotime($pay_period_start)) . " - " . date("M d, Y", strtotime($pay_period_end)) : ""; ?>';
    document.getElementById('confirm_period').textContent = period;
    document.getElementById('confirm_gross').textContent = document.getElementById('display_gross').textContent;
    document.getElementById('confirm_deductions').textContent = document.getElementById('display_total_deductions').textContent;
    document.getElementById('confirm_net').textContent = document.getElementById('display_net').textContent;
    
    const modal = new bootstrap.Modal(document.getElementById('confirmGenerationModal'));
    modal.show();
    
    return false;
}

// Submit the form after confirmation
function submitPayslipForm() {
    document.getElementById('payslipForm').removeEventListener('submit', validatePayslip);
    document.getElementById('payslipForm').submit();
}

// Calculate on page load
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($attendance_summary): ?>
        calculatePayslip();
    <?php endif; ?>
});
</script>

<?php include '../../../includes/footer.php'; ?>