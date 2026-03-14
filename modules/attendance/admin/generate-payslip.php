<?php
/**
 * Payslip Generation Module - Restyled to match Dashboard UI
 * modules/attendance/admin/generate-payslip.php
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
if (!in_array($user_role, ['Admin', 'Super Admin'])) {
    redirect('/barangaylink1/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Generate Payslip';
$current_user_id = getCurrentUserId();

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_user  = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

/**
 * Calculate attendance summary with overtime and late deductions
 */
function calculateAttendanceSummary($conn, $user_id, $start_date, $end_date, $hourly_rate, $overtime_multiplier, $late_deduction_rate) {
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
    
    foreach ($non_working_records as $record) {
        if ($record['status'] === 'Absent') $summary['absent_days']++;
        elseif ($record['status'] === 'On Leave') $summary['leave_days']++;
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
        
        if ($schedule && $schedule['time_in']) {
            $time_in = strtotime($record['time_in']);
            $scheduled_time_in = strtotime($record['attendance_date'] . ' ' . $schedule['time_in']);
            if ($time_in > $scheduled_time_in) {
                $late_minutes = floor(($time_in - $scheduled_time_in) / 60);
                if ($late_minutes > $grace_period_minutes) {
                    $late_minutes -= $grace_period_minutes;
                    $record_detail['late_minutes'] = $late_minutes;
                    $summary['total_late_minutes'] += $late_minutes;
                    $summary['late_days']++;
                }
            }
        }
        
        $worked_seconds = strtotime($record['time_out']) - strtotime($record['time_in']);
        $worked_hours = $worked_seconds / 3600;
        if ($worked_hours > 6) $worked_hours -= 1;
        $record_detail['worked_hours'] = round($worked_hours, 2);
        
        if ($schedule && $schedule['time_out']) {
            $scheduled_in  = strtotime($record['attendance_date'] . ' ' . $schedule['time_in']);
            $scheduled_out = strtotime($record['attendance_date'] . ' ' . $schedule['time_out']);
            $scheduled_hours = ($scheduled_out - $scheduled_in) / 3600;
            if ($scheduled_hours > 6) $scheduled_hours -= 1;
            if ($worked_hours > $scheduled_hours) {
                $ot = $worked_hours - $scheduled_hours;
                $record_detail['overtime_hours'] = round($ot, 2);
                $summary['overtime_hours'] += $ot;
            }
        } else {
            if ($worked_hours > 8) {
                $ot = $worked_hours - 8;
                $record_detail['overtime_hours'] = round($ot, 2);
                $summary['overtime_hours'] += $ot;
            }
        }
        
        $summary['records'][] = $record_detail;
    }
    
    $summary['late_deductions']  = round($summary['total_late_minutes'] * $late_deduction_rate, 2);
    $summary['overtime_pay']     = round($summary['overtime_hours'] * ($hourly_rate * $overtime_multiplier), 2);
    $summary['overtime_hours']   = round($summary['overtime_hours'], 2);
    
    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payslip'])) {
    $user_id                  = intval($_POST['user_id']);
    $pay_period_start         = sanitizeInput($_POST['pay_period_start']);
    $pay_period_end           = sanitizeInput($_POST['pay_period_end']);
    $basic_salary             = floatval($_POST['basic_salary']);
    $hourly_rate              = floatval($_POST['hourly_rate']);
    $overtime_rate_multiplier = floatval($_POST['overtime_rate_multiplier']);
    $late_deduction_per_minute= floatval($_POST['late_deduction_per_minute']);
    $allowances               = floatval($_POST['allowances']);
    $deductions               = floatval($_POST['deductions']);
    
    $attendance_summary = calculateAttendanceSummary($conn, $user_id, $pay_period_start, $pay_period_end, $hourly_rate, $overtime_rate_multiplier, $late_deduction_per_minute);
    
    $gross_pay       = $basic_salary + $allowances + $attendance_summary['overtime_pay'];
    $total_deductions= $deductions + $attendance_summary['late_deductions'];
    $net_pay         = $gross_pay - $total_deductions;
    
    $sql = "INSERT INTO tbl_payslips (
        user_id, pay_period_start, pay_period_end, basic_salary, 
        hourly_rate, overtime_hours, overtime_pay, gross_pay,
        late_minutes, late_deductions, allowances, 
        absences, absence_deductions, other_deductions, 
        total_deductions, net_pay, days_present, days_late, 
        days_absent, generated_by, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $types = 'issdddddiddiddddiiiis';

    $success = executeQuery($conn, $sql, [
        $user_id, $pay_period_start, $pay_period_end, $basic_salary,
        $hourly_rate, $attendance_summary['overtime_hours'], $attendance_summary['overtime_pay'], $gross_pay,
        intval($attendance_summary['total_late_minutes']), $attendance_summary['late_deductions'], $allowances,
        $attendance_summary['absent_days'], 0.00, $deductions,
        $total_deductions, $net_pay,
        $attendance_summary['present_days'], $attendance_summary['late_days'], $attendance_summary['absent_days'],
        $current_user_id, 'Approved'
    ], $types);

    if ($success) {
        $payslip_id = $conn->insert_id;
        logActivity($conn, $current_user_id, 'Generated payslip', 'tbl_payslips', $payslip_id, "Period: $pay_period_start to $pay_period_end");
        $_SESSION['success_message'] = 'Payslip generated successfully';
        header("Location: /barangaylink1/modules/attendance/admin/view-payslip.php?id=$payslip_id");
        exit();
    } else {
        error_log("Payslip generation failed: " . $conn->error);
        $_SESSION['error_message'] = 'Failed to generate payslip. Please try again.';
    }
}

$staff = fetchAll($conn,
    "SELECT u.user_id, u.username, u.role, 
            CONCAT(r.first_name, ' ', r.last_name) as full_name,
            r.profile_photo
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
    ORDER BY r.last_name, r.first_name"
);

$attendance_summary = null;
$user_info          = null;
$pay_period_start   = null;
$pay_period_end     = null;

if ($selected_user > 0) {
    $pay_period_start = date('Y-m-01', strtotime($selected_month));
    $pay_period_end   = date('Y-m-t',  strtotime($selected_month));
    
    $user_info = fetchOne($conn,
        "SELECT u.user_id, u.username, u.role, 
                CONCAT(r.first_name, ' ', r.last_name) as full_name,
                r.profile_photo, r.contact_number
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?",
        [$selected_user], 'i'
    );
    
    $attendance_summary = calculateAttendanceSummary($conn, $selected_user, $pay_period_start, $pay_period_end, 75, 1.25, 2);
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<style>
    /* ══════════════════════════════════════
   PAYSLIP DARK MODE OVERRIDES
══════════════════════════════════════ */

/* Filter bar */
body.dark-mode .ps-filter {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .ps-filter__field label {
    color: #94a3b8;
}
body.dark-mode .ps-filter__field select,
body.dark-mode .ps-filter__field input[type="month"] {
    background: #334155;
    color: #e2e8f0;
    border-color: #475569;
}

/* Stat cards */
body.dark-mode .ps-stat-card {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .ps-stat-card__label {
    color: #94a3b8;
}
body.dark-mode .ps-stat-card__sub {
    color: #64748b;
}

/* Salary form panel */
body.dark-mode .ps-salary-fields {
    background: #1e293b;
}
body.dark-mode .ps-field label {
    color: #94a3b8;
}
body.dark-mode .ps-field-hint {
    color: #64748b;
}
body.dark-mode .ps-input-group {
    border-color: #475569;
}
body.dark-mode .ps-input-group:focus-within {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15);
}
body.dark-mode .ps-input-group__prefix,
body.dark-mode .ps-input-group__suffix {
    background: #243044;
    border-color: #475569;
    color: #64748b;
}
body.dark-mode .ps-input-group input {
    background: #1e293b;
    color: #e2e8f0;
}

/* Info note */
body.dark-mode .ps-note {
    background: #0c1f4a;
    border-color: #1e3a5f;
    border-left-color: #3b82f6;
    color: #93c5fd;
}

/* Payslip summary panel */
body.dark-mode .ps-summary__row {
    border-color: #334155;
}
body.dark-mode .ps-summary__label {
    color: #e2e8f0;
}
body.dark-mode .ps-summary__sub {
    color: #64748b;
}
body.dark-mode .ps-summary__value {
    color: #e2e8f0;
}
body.dark-mode .ps-summary__section-head {
    background: #243044;
    color: #64748b;
}
body.dark-mode .ps-summary__row--deduct {
    background: #2a1015 !important;
}
body.dark-mode .ps-summary__row--gross {
    background: #052e1c !important;
}
/* Gross total row */
body.dark-mode .ps-summary__row[style*="background:#f8fafc"] {
    background: #243044 !important;
}
body.dark-mode .ps-summary__row[style*="background:#fff1f2"] {
    background: #2a1015 !important;
}
/* Net pay row stays navy — fine as-is */

/* Other deductions inline input */
body.dark-mode .ps-summary__inline-input {
    background: #334155;
    color: #e2e8f0;
    border-color: #475569;
}
body.dark-mode .ps-summary__inline-input:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15);
}

/* Action bar */
body.dark-mode .ps-action-bar {
    background: #243044;
    border-color: #334155;
}
body.dark-mode .ps-action-bar div {
    color: #94a3b8;
}

/* Attendance detail table */
body.dark-mode .ps-att-table tbody tr:hover {
    background: #243044;
}
body.dark-mode .ps-att-table tbody td {
    color: #e2e8f0;
}
body.dark-mode .ps-att-table tfoot td {
    background: #243044;
    border-color: #334155;
    color: #e2e8f0;
}

/* Empty state */
body.dark-mode .ps-empty h3 {
    color: #e2e8f0;
}
body.dark-mode .ps-empty p {
    color: #64748b;
}
body.dark-mode .ps-empty i {
    color: #334155;
}

/* Confirm modal table */
body.dark-mode .ps-confirm-table td {
    border-color: #334155;
    color: #e2e8f0;
}
body.dark-mode .ps-confirm-table .total-row td {
    background: #052e1c;
    color: #6ee7b7;
}

/* Modal body inline hardcoded bg */
body.dark-mode #confirmGenerationModal .db-modal__body div[style*="background:var(--db-surf2)"] {
    background: #243044 !important;
    border-color: #334155 !important;
}

/* Page header */
body.dark-mode .ps-header__title {
    color: #e2e8f0;
}
body.dark-mode .ps-header__sub {
    color: #94a3b8;
}
/* ── Payslip Summary row fixes ── */

/* Overtime Pay row */
body.dark-mode .ps-summary__row[style*="background:#f0fdf4"] {
    background: #052e1c !important;
}
body.dark-mode #display_overtime {
    color: #6ee7b7 !important;
}

/* Gross Pay row */
body.dark-mode .ps-summary__row[style*="background:#f8fafc"] {
    background: #1e293b !important;
    border-top-color: #334155 !important;
}
body.dark-mode .ps-summary__row[style*="background:#f8fafc"] .ps-summary__label {
    color: #e2e8f0 !important;
}
body.dark-mode #display_gross {
    color: #e2e8f0 !important;
}

/* Late Deductions row */
body.dark-mode .ps-summary__row[style*="background:#fff8f8"] {
    background: #2a1015 !important;
}
body.dark-mode .ps-summary__row[style*="background:#fff8f8"] .ps-summary__label {
    color: #fca5a5 !important;
}
body.dark-mode .ps-summary__row[style*="background:#fff8f8"] .ps-summary__sub {
    color: #94a3b8 !important;
}

/* Other Deductions inline input — fix the dark-on-dark */
body.dark-mode .ps-summary__inline-input {
    background: #334155 !important;
    color: #e2e8f0 !important;
    border-color: #60a5fa !important;
}

/* Total Deductions row */
body.dark-mode .ps-summary__row[style*="background:#fff1f2"] {
    background: #3b0a12 !important;
    border-top-color: #7f1d1d !important;
}
body.dark-mode .ps-summary__row[style*="background:#fff1f2"] .ps-summary__label {
    color: #fca5a5 !important;
}
body.dark-mode #display_total_deductions {
    color: #fca5a5 !important;
}

/* Action bar status text */
body.dark-mode .ps-action-bar div {
    color: #94a3b8 !important;
}
body.dark-mode .ps-action-bar strong {
    color: #6ee7b7 !important;
}
/* ── Page-level overrides / additions ── */
.ps-page { padding: 0 0 40px; }

/* Filter bar */
.ps-filter {
    background: var(--db-surf);
    border-radius: var(--db-radius-lg);
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
    padding: 20px 26px;
    margin-bottom: 22px;
}
.ps-filter__row {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
}
.ps-filter__field { flex: 1 1 200px; }
.ps-filter__field label {
    display: block;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--db-muted);
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 6px;
    font-family: 'DM Mono', monospace;
}
.ps-filter__field select,
.ps-filter__field input[type="month"] {
    width: 100%;
    padding: 9px 13px;
    border: 1.5px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--db-text);
    background: var(--db-surf);
    outline: none;
    transition: all .18s;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
}
.ps-filter__field input[type="month"] {
    background-image: none;
    padding-right: 13px;
}
.ps-filter__field select:focus,
.ps-filter__field input[type="month"]:focus {
    border-color: var(--db-navy-light);
    box-shadow: 0 0 0 3px rgba(28,52,97,.1);
}

/* Staff hero card */
.ps-staff-hero {
    background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 60%, #224090 100%);
    border-radius: var(--db-radius-lg);
    padding: 26px 30px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
    box-shadow: var(--db-shadow-lg);
}
.ps-staff-hero::before {
    content: '';
    position: absolute;
    width: 260px; height: 260px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.06);
    top: -100px; right: -60px;
    pointer-events: none;
}
.ps-staff-hero::after {
    content: '';
    position: absolute;
    width: 140px; height: 140px;
    border-radius: 50%;
    border: 1px solid rgba(245,158,11,.12);
    top: -30px; right: 80px;
    pointer-events: none;
}
.ps-staff-hero__left { display: flex; align-items: center; gap: 18px; position:relative; z-index:1; }
.ps-staff-hero__avatar {
    width: 58px; height: 58px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--db-amber), #d97706);
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; font-weight: 800; color: #fff; flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(245,158,11,.35);
    overflow: hidden;
}
.ps-staff-hero__avatar img { width: 100%; height: 100%; object-fit: cover; }
.ps-staff-hero__name { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.3px; margin-bottom: 6px; }
.ps-staff-hero__meta { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ps-staff-hero__phone { font-size: 12px; color: rgba(255,255,255,.6); font-family: 'DM Mono', monospace; }

.ps-staff-hero__right { position:relative; z-index:1; text-align: right; }
.ps-staff-hero__period-label { font-size: 11px; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .7px; font-family: 'DM Mono', monospace; margin-bottom: 4px; }
.ps-staff-hero__period { font-size: 18px; font-weight: 800; color: #fff; }
.ps-staff-hero__period-sub { font-size: 12px; color: rgba(255,255,255,.55); font-family: 'DM Mono', monospace; margin-top: 3px; }

/* Two-column layout */
.ps-grid { display: grid; grid-template-columns: 1fr 380px; gap: 18px; align-items: start; }
@media(max-width:1100px){ .ps-grid { grid-template-columns: 1fr; } }

/* Salary form card */
.ps-salary-fields { display: flex; flex-direction: column; gap: 14px; padding: 22px; }
.ps-field { display: flex; flex-direction: column; gap: 5px; }
.ps-field label { font-size: 11.5px; font-weight: 600; color: var(--db-muted); text-transform: uppercase; letter-spacing: .6px; font-family: 'DM Mono', monospace; }
.ps-field label .req { color: var(--db-rose); }
.ps-input-group { display: flex; border: 1.5px solid var(--db-border); border-radius: var(--db-radius-sm); overflow: hidden; transition: all .18s; }
.ps-input-group:focus-within { border-color: var(--db-navy-light); box-shadow: 0 0 0 3px rgba(28,52,97,.1); }
.ps-input-group__prefix {
    padding: 9px 12px;
    background: var(--db-surf2);
    border-right: 1.5px solid var(--db-border);
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    color: var(--db-muted);
    display: flex; align-items: center;
    white-space: nowrap;
}
.ps-input-group__suffix {
    padding: 9px 12px;
    background: var(--db-surf2);
    border-left: 1.5px solid var(--db-border);
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    color: var(--db-muted);
    display: flex; align-items: center;
}
.ps-input-group input {
    flex: 1; border: none; outline: none;
    padding: 9px 12px;
    font-family: 'Sora', sans-serif; font-size: 13.5px; font-weight: 600;
    color: var(--db-text); background: var(--db-surf);
    min-width: 0;
}
.ps-field-hint { font-size: 11px; color: var(--db-muted); }
.ps-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* Summary card */
.ps-summary { padding: 0; }
.ps-summary__row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 22px;
    border-bottom: 1px solid var(--db-border);
    gap: 10px;
}
.ps-summary__row:last-child { border-bottom: none; }
.ps-summary__row--total {
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
}
.ps-summary__row--deduct { background: #fff8f8; }
.ps-summary__row--gross { background: #f0fdf4; }
.ps-summary__label { font-size: 13px; font-weight: 600; color: var(--db-text); }
.ps-summary__sub { font-size: 10.5px; color: var(--db-muted); font-family: 'DM Mono', monospace; margin-top: 2px; }
.ps-summary__value { font-family: 'DM Mono', monospace; font-size: 14px; font-weight: 700; color: var(--db-text); }
.ps-summary__value--green { color: var(--db-success); }
.ps-summary__value--red   { color: var(--db-danger); }
.ps-summary__value--white { color: #fff; font-size: 20px; }
.ps-summary__label--white { color: rgba(255,255,255,.8); font-size: 14px; }
.ps-summary__sub--white   { color: rgba(255,255,255,.5); }
.ps-summary__divider { height: 1px; background: var(--db-border); margin: 0; }
.ps-summary__section-head {
    padding: 8px 22px 6px;
    font-size: 10px; font-weight: 700;
    color: var(--db-muted); text-transform: uppercase;
    letter-spacing: .8px; font-family: 'DM Mono', monospace;
    background: var(--db-surf2);
}

/* Inline deduction input inside summary */
.ps-summary__input-wrap { display: flex; align-items: center; gap: 8px; }
.ps-summary__inline-input {
    width: 110px;
    padding: 6px 10px;
    border: 1.5px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 700;
    color: var(--db-text); background: var(--db-surf);
    outline: none; text-align: right;
    transition: all .18s;
}
.ps-summary__inline-input:focus { border-color: var(--db-navy-light); box-shadow: 0 0 0 3px rgba(28,52,97,.1); }

/* Attendance detail table */
.ps-att-table thead tr { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); }
.ps-att-table thead th { color: rgba(255,255,255,.8); font-family:'DM Mono',monospace; font-size:10px; font-weight:500; text-transform:uppercase; letter-spacing:.8px; padding:11px 16px; white-space:nowrap; border:none; }
.ps-att-table tbody tr { border-bottom: 1px solid var(--db-border); transition: background .12s; }
.ps-att-table tbody tr:last-child { border-bottom: none; }
.ps-att-table tbody tr:hover { background: #f5f8ff; }
.ps-att-table tbody td { padding: 11px 16px; vertical-align: middle; font-size: 12.5px; }
.ps-att-table tfoot td { padding: 11px 16px; font-size: 13px; font-weight: 700; background: var(--db-surf2); border-top: 2px solid var(--db-border); }

/* Info note */
.ps-note {
    display: flex; align-items: flex-start; gap: 12px;
    background: var(--db-info-light);
    border: 1px solid #bfdbfe;
    border-left: 4px solid var(--db-info);
    border-radius: var(--db-radius);
    padding: 14px 16px;
    margin: 0 22px 18px;
    font-size: 12.5px; color: #1e40af; line-height: 1.6;
}
.ps-note i { font-size: 15px; flex-shrink: 0; margin-top: 1px; }

/* Empty state */
.ps-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 64px 24px; text-align: center; gap: 12px;
}
.ps-empty i { font-size: 52px; color: var(--db-border); }
.ps-empty h3 { font-size: 18px; font-weight: 700; color: var(--db-text); }
.ps-empty p { font-size: 13.5px; color: var(--db-muted); max-width: 280px; }

/* Action bar */
.ps-action-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px;
    border-top: 1px solid var(--db-border);
    background: var(--db-surf2);
    flex-wrap: wrap; gap: 10px;
}

/* Page header */
.ps-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 22px; flex-wrap: wrap; gap: 12px;
    padding-top: 6px;
}
.ps-header__title { font-size: 22px; font-weight: 800; letter-spacing: -0.4px; display: flex; align-items: center; gap: 10px; }
.ps-header__title i { color: var(--db-success); }
.ps-header__sub { font-size: 13px; color: var(--db-muted); margin-top: 3px; }
.ps-header__nav { display: flex; gap: 8px; }

/* Stat card adjustments for this page */
.ps-stats-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 22px; }
.ps-stat-card {
    flex: 1 1 150px;
    background: var(--db-surf);
    border-radius: var(--db-radius);
    padding: 18px 18px 14px;
    display: flex; flex-direction: column; gap: 10px;
    box-shadow: var(--db-shadow);
    border: 1px solid var(--db-border);
    position: relative; overflow: hidden;
}
.ps-stat-card__icon { width: 40px; height: 40px; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-size:17px; }
.ps-stat-card__num  { font-size: 28px; font-weight: 800; line-height: 1; letter-spacing: -1px; }
.ps-stat-card__label { font-size:10.5px; color:var(--db-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.ps-stat-card__sub  { font-size:10.5px; color:var(--db-muted); font-family:'DM Mono',monospace; margin-top:1px; }
.ps-stat-card__bar  { height:3px; border-radius:2px; opacity:.4; margin-top:4px; }

/* Modal confirm table */
.ps-confirm-table { width:100%; border-collapse:collapse; font-size:13px; }
.ps-confirm-table td { padding:9px 12px; border-bottom:1px solid var(--db-border); }
.ps-confirm-table tr:last-child td { border-bottom:none; }
.ps-confirm-table .total-row td { font-size:15px; font-weight:800; background: var(--db-success-light); color:#065f46; }

@media(max-width:760px){
    .ps-field-row { grid-template-columns: 1fr; }
    .ps-stats-row { gap:10px; }
    .ps-stat-card { flex: 1 1 130px; }
}
</style>

<div class="ps-page">

    <!-- ── Page Header ── -->
    <div class="ps-header">
        <div>
            <div class="ps-header__title">
                <i class="fas fa-file-invoice-dollar"></i>
                Generate Payslip
            </div>
            <div class="ps-header__sub">Calculate and generate staff payslips with attendance data</div>
        </div>
        <div class="ps-header__nav">
            <a href="index.php" class="db-btn db-btn--ghost db-btn--sm">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="payslip-list.php" class="db-btn db-btn--primary db-btn--sm">
                <i class="fas fa-list"></i> All Payslips
            </a>
        </div>
    </div>

    <!-- ── Alerts ── -->
    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success">
        <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
        <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
        <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="db-alert db-alert--error">
        <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
        <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
        <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <!-- ── Filter Panel ── -->
    <div class="ps-filter">
        <form method="GET">
            <div class="ps-filter__row">
                <div class="ps-filter__field" style="flex:2 1 220px">
                    <label><i class="fas fa-user me-1"></i> Staff Member</label>
                    <select name="user_id" onchange="this.form.submit()">
                        <option value="">— Select staff member —</option>
                        <?php foreach ($staff as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>" <?php echo $selected_user == $s['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['full_name'] ?? $s['username']); ?> (<?php echo $s['role']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ps-filter__field" style="flex:1 1 180px">
                    <label><i class="fas fa-calendar me-1"></i> Pay Period</label>
                    <input type="month" name="month" value="<?php echo $selected_month; ?>" onchange="this.form.submit()">
                </div>
                <div style="flex-shrink:0; padding-bottom:0;">
                    <a href="generate-payslip.php" class="db-btn db-btn--ghost db-btn--sm">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>


    <?php if ($user_info && $attendance_summary): ?>

    <!-- ── Staff Hero ── -->
    <div class="ps-staff-hero">
        <div class="ps-staff-hero__left">
            <div class="ps-staff-hero__avatar">
                <?php if ($user_info['profile_photo'] && file_exists('../../../uploads/profiles/' . $user_info['profile_photo'])): ?>
                    <img src="../../../uploads/profiles/<?php echo $user_info['profile_photo']; ?>" alt="Profile">
                <?php else: ?>
                    <?php echo strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="ps-staff-hero__name"><?php echo htmlspecialchars($user_info['full_name'] ?? $user_info['username']); ?></div>
                <div class="ps-staff-hero__meta">
                    <span class="db-badge db-badge--muted" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.75);border-color:rgba(255,255,255,.2)">
                        <?php echo $user_info['role']; ?>
                    </span>
                    <?php if ($user_info['contact_number']): ?>
                    <span class="ps-staff-hero__phone"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($user_info['contact_number']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="ps-staff-hero__right">
            <div class="ps-staff-hero__period-label">Pay Period</div>
            <div class="ps-staff-hero__period"><?php echo date('F Y', strtotime($selected_month)); ?></div>
            <div class="ps-staff-hero__period-sub">
                <?php echo date('M d', strtotime($pay_period_start)) . ' – ' . date('M d, Y', strtotime($pay_period_end)); ?>
            </div>
        </div>
    </div>

    <!-- ── Attendance Stats ── -->
    <div class="ps-stats-row">
        <div class="ps-stat-card">
            <div class="ps-stat-card__icon" style="background:var(--db-sky-light);color:var(--db-sky)"><i class="fas fa-calendar-check"></i></div>
            <div>
                <div class="ps-stat-card__num" style="color:var(--db-sky)"><?php echo $attendance_summary['total_working_days']; ?></div>
                <div class="ps-stat-card__label">Working Days</div>
                <div class="ps-stat-card__sub"><?php echo $attendance_summary['present_days']; ?> on time · <?php echo $attendance_summary['late_days']; ?> late</div>
            </div>
            <div class="ps-stat-card__bar" style="background:linear-gradient(90deg,var(--db-sky),transparent)"></div>
        </div>
        <div class="ps-stat-card">
            <div class="ps-stat-card__icon" style="background:var(--db-warning-light);color:var(--db-amber-dark)"><i class="fas fa-clock"></i></div>
            <div>
                <div class="ps-stat-card__num" style="color:var(--db-amber)"><?php echo $attendance_summary['total_late_minutes']; ?></div>
                <div class="ps-stat-card__label">Late Minutes</div>
                <div class="ps-stat-card__sub"><?php echo $attendance_summary['late_days']; ?> day(s) late</div>
            </div>
            <div class="ps-stat-card__bar" style="background:linear-gradient(90deg,var(--db-amber),transparent)"></div>
        </div>
        <div class="ps-stat-card">
            <div class="ps-stat-card__icon" style="background:var(--db-danger-light);color:var(--db-danger)"><i class="fas fa-times-circle"></i></div>
            <div>
                <div class="ps-stat-card__num" style="color:var(--db-danger)"><?php echo $attendance_summary['absent_days']; ?></div>
                <div class="ps-stat-card__label">Absent Days</div>
                <div class="ps-stat-card__sub"><?php echo $attendance_summary['leave_days']; ?> on leave</div>
            </div>
            <div class="ps-stat-card__bar" style="background:linear-gradient(90deg,var(--db-danger),transparent)"></div>
        </div>
        <div class="ps-stat-card">
            <div class="ps-stat-card__icon" style="background:var(--db-teal-light);color:var(--db-teal)"><i class="fas fa-business-time"></i></div>
            <div>
                <div class="ps-stat-card__num" style="color:var(--db-teal)"><?php echo number_format($attendance_summary['overtime_hours'], 2); ?></div>
                <div class="ps-stat-card__label">Overtime Hours</div>
                <div class="ps-stat-card__sub">Beyond schedule</div>
            </div>
            <div class="ps-stat-card__bar" style="background:linear-gradient(90deg,var(--db-teal),transparent)"></div>
        </div>
    </div>

    <!-- ── Main Grid: Form + Summary ── -->
    <form method="POST" id="payslipForm" onsubmit="return validatePayslip(event)">
        <input type="hidden" name="generate_payslip" value="1">
        <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">
        <input type="hidden" name="pay_period_start" value="<?php echo $pay_period_start; ?>">
        <input type="hidden" name="pay_period_end" value="<?php echo $pay_period_end; ?>">

        <div class="ps-grid">

            <!-- LEFT: Salary inputs -->
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-money-bill-wave"></i></span>
                        <h2>Salary Configuration</h2>
                    </div>
                </div>

                <div class="ps-salary-fields">
                    <div class="ps-field-row">
                        <div class="ps-field">
                            <label>Basic Salary <span class="req">*</span></label>
                            <div class="ps-input-group">
                                <span class="ps-input-group__prefix">₱</span>
                                <input type="number" name="basic_salary" id="basic_salary" value="15000" step="0.01" required onchange="calculatePayslip()">
                            </div>
                        </div>
                        <div class="ps-field">
                            <label>Allowances <span class="req">*</span></label>
                            <div class="ps-input-group">
                                <span class="ps-input-group__prefix">₱</span>
                                <input type="number" name="allowances" id="allowances" value="2000" step="0.01" required onchange="calculatePayslip()">
                            </div>
                            <span class="ps-field-hint">Transport, meal, etc.</span>
                        </div>
                    </div>

                    <div class="ps-field-row">
                        <div class="ps-field">
                            <label>Hourly Rate <span class="req">*</span></label>
                            <div class="ps-input-group">
                                <span class="ps-input-group__prefix">₱/hr</span>
                                <input type="number" name="hourly_rate" id="hourly_rate" value="75" step="0.01" required onchange="calculatePayslip()">
                            </div>
                            <span class="ps-field-hint">Used for overtime</span>
                        </div>
                        <div class="ps-field">
                            <label>OT Multiplier <span class="req">*</span></label>
                            <div class="ps-input-group">
                                <input type="number" name="overtime_rate_multiplier" id="overtime_rate_multiplier" value="1.25" step="0.01" min="1" required onchange="calculatePayslip()" style="text-align:center">
                                <span class="ps-input-group__suffix">×</span>
                            </div>
                            <span class="ps-field-hint">1.25 = 125% of hourly rate</span>
                        </div>
                    </div>

                    <div class="ps-field">
                        <label>Late Deduction Rate <span class="req">*</span></label>
                        <div class="ps-input-group" style="max-width:240px">
                            <span class="ps-input-group__prefix">₱</span>
                            <input type="number" name="late_deduction_per_minute" id="late_deduction_per_minute" value="2" step="0.01" required onchange="calculatePayslip()">
                            <span class="ps-input-group__suffix">per min</span>
                        </div>
                    </div>

                    <div class="ps-note">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            Calculations cover only days with recorded clock-in/out 
                            (<strong><?php echo $attendance_summary['total_working_days']; ?> working day(s)</strong>). 
                            Absences (<?php echo $attendance_summary['absent_days']; ?>) and approved leaves 
                            (<?php echo $attendance_summary['leave_days']; ?>) are excluded from salary.
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Live summary -->
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <span class="db-panel__icon" style="background:var(--db-success-light);color:var(--db-success)"><i class="fas fa-calculator"></i></span>
                        <h2>Payslip Summary</h2>
                    </div>
                </div>

                <div class="ps-summary">
                    <div class="ps-summary__section-head">Earnings</div>

                    <div class="ps-summary__row">
                        <div>
                            <div class="ps-summary__label">Basic Salary</div>
                        </div>
                        <div class="ps-summary__value" id="display_basic">₱0.00</div>
                    </div>

                    <div class="ps-summary__row">
                        <div>
                            <div class="ps-summary__label">Allowances</div>
                        </div>
                        <div class="ps-summary__value" id="display_allowances">₱0.00</div>
                    </div>

                    <div class="ps-summary__row" style="background:#f0fdf4">
                        <div>
                            <div class="ps-summary__label">Overtime Pay</div>
                            <div class="ps-summary__sub"><?php echo $attendance_summary['overtime_hours']; ?> hrs × rate</div>
                        </div>
                        <div class="ps-summary__value ps-summary__value--green" id="display_overtime">₱0.00</div>
                    </div>

                    <div class="ps-summary__row" style="background:#f8fafc;border-top:2px solid var(--db-border)">
                        <div class="ps-summary__label" style="font-size:14px;font-weight:800">Gross Pay</div>
                        <div class="ps-summary__value" id="display_gross" style="font-size:18px;font-weight:800">₱0.00</div>
                    </div>

                    <div class="ps-summary__section-head">Deductions</div>

                    <div class="ps-summary__row" style="background:#fff8f8">
                        <div>
                            <div class="ps-summary__label">Late Deductions</div>
                            <div class="ps-summary__sub"><?php echo $attendance_summary['total_late_minutes']; ?> mins × rate</div>
                        </div>
                        <div class="ps-summary__value ps-summary__value--red" id="display_late">₱0.00</div>
                    </div>

                    <div class="ps-summary__row" style="background:#fff8f8">
                        <div>
                            <div class="ps-summary__label">Other Deductions</div>
                            <div class="ps-summary__sub">SSS, PhilHealth, etc.</div>
                        </div>
                        <div class="ps-summary__input-wrap">
                            <span style="font-family:'DM Mono',monospace;font-size:12px;color:var(--db-muted)">₱</span>
                            <input type="number" name="deductions" id="deductions" value="500" step="0.01" required onchange="calculatePayslip()" class="ps-summary__inline-input">
                        </div>
                    </div>

                    <div class="ps-summary__row" style="background:#fff1f2;border-top:2px solid #fecdd3">
                        <div class="ps-summary__label" style="color:var(--db-danger)">Total Deductions</div>
                        <div class="ps-summary__value ps-summary__value--red" id="display_total_deductions" style="font-size:16px;font-weight:800">₱0.00</div>
                    </div>

                    <!-- NET PAY — navy stripe -->
                    <div class="ps-summary__row ps-summary__row--total">
                        <div>
                            <div class="ps-summary__label ps-summary__label--white">NET PAY</div>
                            <div class="ps-summary__sub ps-summary__sub--white">
                                <?php echo date('M d', strtotime($pay_period_start)) . ' – ' . date('M d, Y', strtotime($pay_period_end)); ?>
                            </div>
                        </div>
                        <div class="ps-summary__value ps-summary__value--white" id="display_net">₱0.00</div>
                    </div>
                </div>

                <div class="ps-action-bar">
                    <div style="font-size:11.5px;color:var(--db-muted)">
                        <i class="fas fa-shield-alt me-1"></i> Payslip status: <strong>Approved</strong>
                    </div>
                    <button type="submit" class="db-btn db-btn--primary">
                        <i class="fas fa-file-invoice-dollar"></i> Generate Payslip
                    </button>
                </div>
            </div>

        </div><!-- /ps-grid -->
    </form>


    <!-- ── Attendance Detail Table ── -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list-alt"></i></span>
                <h2>Attendance Detail</h2>
            </div>
            <span class="db-badge db-badge--muted"><?php echo count($attendance_summary['records']); ?> record(s)</span>
        </div>

        <div class="db-table-wrap">
            <table class="db-table ps-att-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Status</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th style="text-align:right">Hours</th>
                        <th style="text-align:right">Late (min)</th>
                        <th style="text-align:right">OT (hrs)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($attendance_summary['records'])): ?>
                    <?php foreach ($attendance_summary['records'] as $rec): ?>
                    <tr>
                        <td><span class="db-id"><?php echo date('M d, Y', strtotime($rec['date'])); ?></span></td>
                        <td><?php echo date('l', strtotime($rec['date'])); ?></td>
                        <td>
                            <?php
                            $bc = ['Present'=>'db-badge--success','Late'=>'db-badge--warning','Absent'=>'db-badge--danger','On Leave'=>'db-badge--info'];
                            $cls = $bc[$rec['status']] ?? 'db-badge--muted';
                            ?>
                            <span class="db-badge <?php echo $cls; ?>"><?php echo $rec['status']; ?></span>
                        </td>
                        <td><?php echo $rec['time_in']  ? date('h:i A', strtotime($rec['time_in']))  : '—'; ?></td>
                        <td><?php echo $rec['time_out'] ? date('h:i A', strtotime($rec['time_out'])) : '—'; ?></td>
                        <td style="text-align:right;font-family:'DM Mono',monospace;font-size:12px">
                            <?php echo $rec['worked_hours'] > 0 ? number_format($rec['worked_hours'], 2) : '—'; ?>
                        </td>
                        <td style="text-align:right">
                            <?php if ($rec['late_minutes'] > 0): ?>
                                <span class="db-badge db-badge--warning"><?php echo $rec['late_minutes']; ?></span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td style="text-align:right">
                            <?php if ($rec['overtime_hours'] > 0): ?>
                                <span class="db-badge db-badge--success"><?php echo number_format($rec['overtime_hours'], 2); ?></span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="db-empty">
                                <i class="fas fa-inbox"></i>
                                <p>No attendance records found for this period</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right">TOTALS</td>
                        <td style="text-align:right;font-family:'DM Mono',monospace">—</td>
                        <td style="text-align:right">
                            <span class="db-badge db-badge--warning"><?php echo $attendance_summary['total_late_minutes']; ?></span>
                        </td>
                        <td style="text-align:right">
                            <span class="db-badge db-badge--success"><?php echo number_format($attendance_summary['overtime_hours'], 2); ?></span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Empty State ── -->
    <div class="db-panel">
        <div class="ps-empty">
            <i class="fas fa-file-invoice-dollar"></i>
            <h3>Select a Staff Member</h3>
            <p>Choose a staff member and pay period above to calculate and generate their payslip.</p>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /ps-page -->


<!-- ═══════════════════════════
     MODAL: Negative Net Pay
════════════════════════════ -->
<div id="negativePayModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-exclamation-triangle"></i> Invalid Net Pay</h3>
            <button class="db-modal__close" onclick="closeModal('negativePayModal')">×</button>
        </div>
        <div class="db-modal__body" style="text-align:center;padding:32px 24px">
            <div style="width:60px;height:60px;border-radius:50%;background:var(--db-danger-light);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;color:var(--db-danger)">
                <i class="fas fa-times-circle"></i>
            </div>
            <div style="font-size:16px;font-weight:700;margin-bottom:8px">Net pay cannot be negative!</div>
            <div style="font-size:13px;color:var(--db-muted)">Please adjust the deductions or salary amounts before generating the payslip.</div>
            <div style="margin-top:24px">
                <button class="db-btn db-btn--ghost" onclick="closeModal('negativePayModal')" style="width:100%;justify-content:center">
                    <i class="fas fa-arrow-left"></i> Go Back &amp; Adjust
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════
     MODAL: Confirm Generation
════════════════════════════ -->
<div id="confirmGenerationModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-file-invoice-dollar"></i> Confirm Payslip Generation</h3>
            <button class="db-modal__close" onclick="closeModal('confirmGenerationModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div style="text-align:center;margin-bottom:20px">
                <div style="width:60px;height:60px;border-radius:50%;background:var(--db-success-light);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:24px;color:var(--db-success)">
                    <i class="fas fa-check"></i>
                </div>
                <div style="font-size:15px;font-weight:700">Ready to generate</div>
            </div>

            <div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);overflow:hidden;margin-bottom:16px">
                <div style="padding:10px 16px;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));font-size:11px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.7px;font-family:'DM Mono',monospace">
                    Summary
                </div>
                <table class="ps-confirm-table">
                    <tr>
                        <td style="color:var(--db-muted)">Staff</td>
                        <td style="text-align:right;font-weight:700"><?php echo htmlspecialchars($user_info['full_name'] ?? $user_info['username'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--db-muted)">Period</td>
                        <td style="text-align:right;font-weight:700" id="confirm_period"></td>
                    </tr>
                    <tr>
                        <td style="color:var(--db-muted)">Gross Pay</td>
                        <td style="text-align:right;font-weight:700" id="confirm_gross"></td>
                    </tr>
                    <tr>
                        <td style="color:var(--db-danger)">Deductions</td>
                        <td style="text-align:right;font-weight:700;color:var(--db-danger)" id="confirm_deductions"></td>
                    </tr>
                    <tr class="total-row">
                        <td style="font-weight:800;font-size:15px">Net Pay</td>
                        <td style="text-align:right;font-size:18px;font-weight:800" id="confirm_net"></td>
                    </tr>
                </table>
            </div>

            <div class="db-alert db-alert--error" style="margin-bottom:0;font-size:12px">
                <div class="db-alert__icon"><i class="fas fa-exclamation-triangle"></i></div>
                <span>This action cannot be undone. Are you sure?</span>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('confirmGenerationModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="db-btn db-btn--primary db-btn--full" onclick="submitPayslipForm()">
                    <i class="fas fa-file-invoice-dollar"></i> Generate
                </button>
            </div>
        </div>
    </div>
</div>


<script>
// ── Real-time calculation ──
function calculatePayslip() {
    const basicSalary        = parseFloat(document.getElementById('basic_salary').value)                || 0;
    const hourlyRate         = parseFloat(document.getElementById('hourly_rate').value)                 || 0;
    const overtimeMult       = parseFloat(document.getElementById('overtime_rate_multiplier').value)    || 1.25;
    const lateRate           = parseFloat(document.getElementById('late_deduction_per_minute').value)   || 0;
    const allowances         = parseFloat(document.getElementById('allowances').value)                  || 0;
    const otherDeductions    = parseFloat(document.getElementById('deductions').value)                  || 0;

    const overtimeHours = <?php echo $attendance_summary ? $attendance_summary['overtime_hours'] : 0; ?>;
    const lateMinutes   = <?php echo $attendance_summary ? $attendance_summary['total_late_minutes'] : 0; ?>;

    const overtimePay     = overtimeHours * (hourlyRate * overtimeMult);
    const lateDeductions  = lateMinutes * lateRate;
    const grossPay        = basicSalary + allowances + overtimePay;
    const totalDeductions = lateDeductions + otherDeductions;
    const netPay          = grossPay - totalDeductions;

    const fmt = v => '₱' + v.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    document.getElementById('display_basic').textContent           = fmt(basicSalary);
    document.getElementById('display_allowances').textContent      = fmt(allowances);
    document.getElementById('display_overtime').textContent        = fmt(overtimePay);
    document.getElementById('display_gross').textContent           = fmt(grossPay);
    document.getElementById('display_late').textContent            = fmt(lateDeductions);
    document.getElementById('display_total_deductions').textContent= fmt(totalDeductions);
    document.getElementById('display_net').textContent             = fmt(netPay);

    // Colour net pay red if negative
    const netEl = document.getElementById('display_net');
    netEl.style.color = netPay < 0 ? 'var(--db-danger)' : '#fff';
}

// ── Validate before showing confirm modal ──
function validatePayslip(event) {
    event.preventDefault();
    const netPay = parseFloat(document.getElementById('display_net').textContent.replace(/[₱,]/g, ''));
    if (netPay < 0) {
        openModal('negativePayModal');
        return false;
    }
    const period = '<?php echo isset($pay_period_start) && isset($pay_period_end) ? date("M d", strtotime($pay_period_start)) . " – " . date("M d, Y", strtotime($pay_period_end)) : ""; ?>';
    document.getElementById('confirm_period').textContent      = period;
    document.getElementById('confirm_gross').textContent       = document.getElementById('display_gross').textContent;
    document.getElementById('confirm_deductions').textContent  = document.getElementById('display_total_deductions').textContent;
    document.getElementById('confirm_net').textContent         = document.getElementById('display_net').textContent;
    openModal('confirmGenerationModal');
    return false;
}

function submitPayslipForm() {
    document.getElementById('payslipForm').removeEventListener('submit', validatePayslip);
    document.getElementById('payslipForm').submit();
}

// ── Modal helpers (same as dashboard) ──
function openModal(id) {
    document.getElementById(id).classList.add('db-modal--open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('db-modal--open');
    document.body.style.overflow = '';
}
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.db-modal--open').forEach(m => closeModal(m.id)); });

// ── Auto-dismiss alerts ──
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($attendance_summary): ?>calculatePayslip();<?php endif; ?>
});
</script>

<?php include '../../../includes/footer.php'; ?>