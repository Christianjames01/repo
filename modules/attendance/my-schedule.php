<?php
/**
 * Staff My Duty Schedule View with Attendance Marking
 * modules/attendance/my-schedule.php
 * FIXED PHILIPPINE TIME VERSION - WITH LEAVE MANAGEMENT LINK
 */

// CRITICAL: Set timezone FIRST before any other code
date_default_timezone_set('Asia/Manila');

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /barangaylink/modules/auth/login.php');
    exit();
}

$page_title = 'My Duty Schedule';
$current_user_id = getCurrentUserId();

// ============================================================================
// CHECK TABLE STRUCTURE
// ============================================================================
$columns_result = $conn->query("SHOW COLUMNS FROM tbl_attendance");
$has_marked_by = false;
$has_notes = false;
while ($col = $columns_result->fetch_assoc()) {
    if ($col['Field'] === 'marked_by') $has_marked_by = true;
    if ($col['Field'] === 'notes')     $has_notes     = true;
}

// ============================================================================
// GET CURRENT USER PROFILE (for avatar display)
// ============================================================================
$current_user_profile = fetchOne($conn,
    "SELECT u.user_id, u.username, u.role,
            CONCAT(r.first_name, ' ', r.last_name) as full_name,
            r.profile_photo
     FROM tbl_users u
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
     WHERE u.user_id = ?",
    [$current_user_id], 'i'
);

// ============================================================================
// HANDLE ATTENDANCE MARKING (WITH PHILIPPINE TIME)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $action = sanitizeInput($_POST['action']);
    $attendance_date = date('Y-m-d');

    $column_check = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'time_in'");
    $column_info  = $column_check->fetch_assoc();
    $current_time = (strpos(strtoupper($column_info['Type']), 'DATETIME') !== false)
                    ? date('Y-m-d H:i:s')
                    : date('H:i:s');

    $existing = fetchOne($conn,
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $attendance_date], 'is'
    );

    if ($action === 'time_in') {
        if (!$existing) {
            $status = 'Present';
            $today  = date('l');
            $schedule = fetchOne($conn,
                "SELECT time_in FROM tbl_duty_schedules WHERE user_id = ? AND day_of_week = ? AND is_active = 1",
                [$current_user_id, $today], 'is'
            );
            if (!$schedule) {
                $schedule = fetchOne($conn,
                    "SELECT time_in FROM tbl_special_duty_schedules WHERE user_id = ? AND schedule_date = ?",
                    [$current_user_id, $attendance_date], 'is'
                );
            }
            $diff_minutes = 0;
            if ($schedule && $schedule['time_in']) {
                $diff_minutes = (strtotime(date('H:i:s')) - strtotime($schedule['time_in'])) / 60;
                if ($diff_minutes > 15) $status = 'Late';
            }

            $note = "Self-marked at " . date('h:i A');
            if ($status === 'Late') $note .= " (Late by " . round($diff_minutes) . " minutes)";

            if ($has_marked_by && $has_notes) {
                $sql = "INSERT INTO tbl_attendance (user_id,attendance_date,time_in,status,marked_by,notes) VALUES (?,?,?,?,?,?)";
                $result = executeQuery($conn, $sql, [$current_user_id,$attendance_date,$current_time,$status,$current_user_id,$note], 'isssss');
            } elseif ($has_notes) {
                $sql = "INSERT INTO tbl_attendance (user_id,attendance_date,time_in,status,notes) VALUES (?,?,?,?,?)";
                $result = executeQuery($conn, $sql, [$current_user_id,$attendance_date,$current_time,$status,$note], 'issss');
            } else {
                $sql = "INSERT INTO tbl_attendance (user_id,attendance_date,time_in,status) VALUES (?,?,?,?)";
                $result = executeQuery($conn, $sql, [$current_user_id,$attendance_date,$current_time,$status], 'isss');
            }

            if ($result) {
                logActivity($conn, $current_user_id, "Marked time in: $current_time ($status)", 'tbl_attendance');
                if ($status === 'Late')
                    $_SESSION['warning_message'] = "Time in recorded at " . date('h:i A') . " — You are late by " . round($diff_minutes) . " minutes";
                else
                    $_SESSION['success_message'] = "Time in recorded successfully at " . date('h:i A');
            } else {
                $_SESSION['error_message'] = "Failed to record time in";
            }
        } else {
            $_SESSION['error_message'] = "You have already marked time in today";
        }
    } elseif ($action === 'time_out') {
        if ($existing && !$existing['time_out']) {
            if ($has_notes) {
                $sql = "UPDATE tbl_attendance SET time_out=?, notes=CONCAT(COALESCE(notes,''),?) WHERE attendance_id=?";
                $result = executeQuery($conn, $sql, [$current_time," | Time out: Self-marked at ".date('h:i A'),$existing['attendance_id']], 'ssi');
            } else {
                $sql = "UPDATE tbl_attendance SET time_out=? WHERE attendance_id=?";
                $result = executeQuery($conn, $sql, [$current_time,$existing['attendance_id']], 'si');
            }
            if ($result) {
                logActivity($conn, $current_user_id, "Marked time out: $current_time", 'tbl_attendance');
                $_SESSION['success_message'] = "Time out recorded successfully at " . date('h:i A');
            } else {
                $_SESSION['error_message'] = "Failed to record time out";
            }
        } elseif (!$existing) {
            $_SESSION['error_message'] = "Please mark time in first";
        } else {
            $_SESSION['error_message'] = "You have already marked time out today";
        }
    }

    header("Location: my-schedule.php");
    exit();
}

// ============================================================================
// GET SCHEDULE DATA
// ============================================================================
$weekly_schedule = fetchAll($conn,
    "SELECT * FROM tbl_duty_schedules WHERE user_id=? AND is_active=1
     ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')",
    [$current_user_id], 'i'
);

$individual_schedules = fetchAll($conn,
    "SELECT ss.*, CONCAT(r.first_name,' ',r.last_name) as assigned_by, 'individual' as source
     FROM tbl_special_duty_schedules ss
     LEFT JOIN tbl_users u ON ss.created_by=u.user_id
     LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
     WHERE ss.user_id=? AND ss.schedule_date>=CURDATE()
     ORDER BY ss.schedule_date LIMIT 10",
    [$current_user_id], 'i'
);

$event_schedules = fetchAll($conn,
    "SELECT ss.schedule_date, ss.custom_time_in as time_in, ss.custom_time_out as time_out,
            ss.schedule_type, ss.description as notes,
            CONCAT(r.first_name,' ',r.last_name) as assigned_by, 'event' as source
     FROM tbl_special_schedules ss
     INNER JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id=ssa.schedule_id
     LEFT JOIN tbl_users u ON ss.created_by=u.user_id
     LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
     WHERE ssa.user_id=? AND ss.schedule_date>=CURDATE() AND ss.is_working_day=1
     ORDER BY ss.schedule_date LIMIT 10",
    [$current_user_id], 'i'
);

$special_schedules = array_slice(
    array_merge($individual_schedules, $event_schedules),
    0, 10
);
usort($special_schedules, fn($a,$b) => strtotime($a['schedule_date']) - strtotime($b['schedule_date']));

$today         = date('l');
$today_date    = date('Y-m-d');
$today_schedule = null;

$today_special = fetchOne($conn,
    "SELECT * FROM tbl_special_duty_schedules WHERE user_id=? AND schedule_date=?",
    [$current_user_id, $today_date], 'is'
);

if ($today_special) {
    $today_schedule = ['type'=>'special','time_in'=>$today_special['time_in'],'time_out'=>$today_special['time_out'],
                       'schedule_type'=>$today_special['schedule_type'],'notes'=>$today_special['notes']];
} else {
    foreach ($weekly_schedule as $sched) {
        if ($sched['day_of_week'] === $today) {
            $today_schedule = ['type'=>'regular','time_in'=>$sched['time_in'],'time_out'=>$sched['time_out'],
                               'schedule_name'=>$sched['schedule_name']??'','notes'=>$sched['notes']??''];
            break;
        }
    }
}

$total_weekly_hours = 0;
foreach ($weekly_schedule as $sched) {
    $diff = (strtotime($sched['time_out']) - strtotime($sched['time_in'])) / 3600;
    if ($diff < 0) $diff += 24;
    $total_weekly_hours += $diff;
}

$today_attendance = fetchOne($conn,
    "SELECT * FROM tbl_attendance WHERE user_id=? AND attendance_date=?",
    [$current_user_id, $today_date], 'is'
);

$leave_stats = fetchOne($conn,
    "SELECT COUNT(*) as total_leaves,
            SUM(CASE WHEN status='Pending'  THEN 1 ELSE 0 END) as pending_leaves,
            SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_leaves
     FROM tbl_leave_requests WHERE user_id=? AND YEAR(start_date)=YEAR(CURDATE())",
    [$current_user_id], 'i'
);

$can_time_in  = !$today_attendance;
$can_time_out = $today_attendance && !$today_attendance['time_out'];
$is_late      = false;
$is_early_out = false;
$diff_minutes = 0;

if ($today_schedule && $today_attendance) {
    $diff_minutes = (strtotime($today_attendance['time_in']) - strtotime($today_schedule['time_in'])) / 60;
    $is_late = $diff_minutes > 15;
    if ($today_attendance['time_out'])
        $is_early_out = strtotime($today_attendance['time_out']) < strtotime($today_schedule['time_out']);
}

// Avatar helpers
function formatTimeDisplay($t) {
    if (empty($t)) return 'N/A';
    try { return (new DateTime($t))->format('g:i A'); } catch (Exception $e) {}
    $p = explode(':', $t);
    if (count($p) < 2) return 'N/A';
    $h = (int)$p[0]; $m = str_pad($p[1],2,'0',STR_PAD_LEFT);
    $ap = $h >= 12 ? 'PM' : 'AM';
    $dh = $h % 12 ?: 12;
    return "$dh:$m $ap";
}

// Role → colour map
$roleColors = [
    'Barangay Captain' => ['bg'=>'#fce7f3','color'=>'#9f1239'],
    'Secretary'        => ['bg'=>'#fef9c3','color'=>'#713f12'],
    'Treasurer'        => ['bg'=>'#e0f2fe','color'=>'#075985'],
    'Staff'            => ['bg'=>'#fef3c7','color'=>'#92400e'],
    'Tanod'            => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Barangay Tanod'   => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Driver'           => ['bg'=>'#d1fae5','color'=>'#065f46'],
    'Admin'            => ['bg'=>'#fee2e2','color'=>'#991b1b'],
    'Super Admin'      => ['bg'=>'#ede9fe','color'=>'#4c1d95'],
];
$avatarPalette = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];

$profile_name    = trim($current_user_profile['full_name'] ?? '') ?: ($current_user_profile['username'] ?? '?');
$profile_initial = strtoupper(substr($profile_name, 0, 1));
$profile_role    = $current_user_profile['role'] ?? '';
$profile_photo   = !empty($current_user_profile['profile_photo'])
                    ? '/barangaylink/uploads/profiles/' . $current_user_profile['profile_photo']
                    : '';
$profile_rc      = $roleColors[$profile_role] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
$profile_avatarBg = $avatarPalette[ord($profile_initial) % count($avatarPalette)];

include '../../includes/header.php';
?>

<div class="container-fluid py-4">

    <!-- ── Page Header ─────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">

            <!-- Profile Avatar -->
            <div style="position:relative;flex-shrink:0;">
                <?php if ($profile_photo): ?>
                    <img id="myProfilePhoto"
                         src="<?php echo htmlspecialchars($profile_photo); ?>"
                         alt="<?php echo htmlspecialchars($profile_name); ?>"
                         width="56" height="56"
                         style="width:56px;height:56px;border-radius:14px;object-fit:cover;display:block;
                                box-shadow:0 2px 8px rgba(13,27,54,.18);"
                         onerror="this.style.display='none';document.getElementById('myProfileInitial').style.display='flex';">
                <?php endif; ?>
                <div id="myProfileInitial"
                     style="width:56px;height:56px;border-radius:14px;
                            background:<?php echo $profile_avatarBg; ?>;color:#fff;
                            font-size:22px;font-weight:800;font-family:'Sora',sans-serif;
                            display:<?php echo $profile_photo ? 'none' : 'flex'; ?>;
                            align-items:center;justify-content:center;
                            box-shadow:0 2px 8px rgba(13,27,54,.18);">
                    <?php echo $profile_initial; ?>
                </div>
            </div>

            <!-- Name + role -->
            <div>
                <h1 class="h4 mb-1 fw-bold" style="color:#0f172a;">
                    <?php echo htmlspecialchars($profile_name); ?>
                </h1>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span style="display:inline-block;padding:3px 10px;border-radius:20px;
                                 background:<?php echo $profile_rc['bg']; ?>;color:<?php echo $profile_rc['color']; ?>;
                                 font-size:11px;font-weight:700;letter-spacing:.4px;font-family:'DM Mono',monospace;">
                        <?php echo htmlspecialchars($profile_role); ?>
                    </span>
                    <span style="font-size:12px;color:#94a3b8;font-family:'DM Mono',monospace;">
                        <i class="fas fa-calendar-week me-1"></i>My Duty Schedule
                    </span>
                </div>
            </div>

        </div>

        <!-- Action buttons -->
        <div class="d-flex gap-2 flex-wrap">
            <a href="my-payslips.php" class="btn btn-info btn-sm">
                <i class="fas fa-money-check-alt me-1"></i> PayChecks
            </a>
            <a href="manage-leaves.php" class="btn btn-success btn-sm">
                <i class="fas fa-calendar-times me-1"></i> Manage Leaves
                <?php if (!empty($leave_stats['pending_leaves']) && $leave_stats['pending_leaves'] > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?php echo $leave_stats['pending_leaves']; ?></span>
                <?php endif; ?>
            </a>
            <a href="leave-request.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> Request Leave
            </a>
            <a href="my-attendance.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-clipboard-list me-1"></i> Attendance History
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- ── Today's Schedule & Attendance ───────────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-day me-2"></i>
                        Today's Schedule &amp; Attendance
                    </h5>
                </div>
                <div class="card-body">
                    <div class="text-center py-3">
                        <h3 class="mb-2"><?php echo date('l, F j, Y'); ?></h3>
                        <div class="mb-4">
                            <h2 class="mb-0" id="current-time"><?php echo date('h:i A'); ?></h2>
                            <small class="text-muted">Current Time (Philippine Time)</small>
                        </div>

                        <?php if ($today_schedule): ?>
                            <div class="alert alert-info mb-3">
                                <?php if ($today_schedule['type'] === 'special'): ?>
                                    <div class="mb-2">
                                        <span class="badge bg-warning text-dark fs-6">
                                            <i class="fas fa-star me-1"></i>
                                            <?php echo $today_schedule['schedule_type']; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-around my-3">
                                    <div>
                                        <div class="text-success">
                                            <i class="fas fa-sign-in-alt fa-2x mb-2"></i>
                                            <h4 class="mb-0"><?php echo formatTimeDisplay($today_schedule['time_in']); ?></h4>
                                            <small>Scheduled In</small>
                                        </div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-arrow-right fa-2x text-muted"></i>
                                    </div>
                                    <div>
                                        <div class="text-danger">
                                            <i class="fas fa-sign-out-alt fa-2x mb-2"></i>
                                            <h4 class="mb-0"><?php echo formatTimeDisplay($today_schedule['time_out']); ?></h4>
                                            <small>Scheduled Out</small>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($today_schedule['notes'])): ?>
                                    <div class="mt-3 text-start">
                                        <strong>Note:</strong> <?php echo htmlspecialchars($today_schedule['notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Attendance Marking Buttons -->
                            <div class="mb-3">
                                <?php if ($can_time_in):
                                    $would_be_late   = false;
                                    $late_by_minutes = 0;
                                    if ($today_schedule) {
                                        $dm = (strtotime(date('H:i:s')) - strtotime($today_schedule['time_in'])) / 60;
                                        if ($dm > 15) { $would_be_late = true; $late_by_minutes = round($dm); }
                                    }
                                ?>
                                    <?php if ($would_be_late): ?>
                                        <div class="alert alert-warning mb-3">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Warning:</strong> You are <?php echo $late_by_minutes; ?> minutes late.
                                            Marking now will record as <strong>Late</strong>.
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-success mb-3">
                                            <i class="fas fa-check-circle me-2"></i>
                                            You're within the grace period. Marking now will record as <strong>Present</strong>.
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" id="timeInForm" class="d-inline">
                                        <input type="hidden" name="mark_attendance" value="1">
                                        <input type="hidden" name="action" value="time_in">
                                        <button type="button"
                                                class="btn btn-<?php echo $would_be_late ? 'warning' : 'success'; ?> btn-lg w-100"
                                                onclick="markTimeIn()">
                                            <i class="fas fa-sign-in-alt me-2"></i>
                                            Mark Time In<?php echo $would_be_late ? ' (Late)' : ''; ?>
                                        </button>
                                    </form>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle"></i>
                                        Grace period: 15 minutes after scheduled time
                                    </small>

                                <?php elseif ($can_time_out): ?>
                                    <form method="POST" id="timeOutForm" class="d-inline">
                                        <input type="hidden" name="mark_attendance" value="1">
                                        <input type="hidden" name="action" value="time_out">
                                        <button type="button" class="btn btn-danger btn-lg w-100" onclick="markTimeOut()">
                                            <i class="fas fa-sign-out-alt me-2"></i>
                                            Mark Time Out
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg w-100" disabled>
                                        <i class="fas fa-check-circle me-2"></i>
                                        Attendance Completed
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- Attendance Status -->
                            <?php if ($today_attendance): ?>
                                <div class="alert alert-<?php echo $today_attendance['time_out'] ? 'success' : 'warning'; ?> mb-3">
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <strong>Actual Time In:</strong><br>
                                            <?php if ($today_attendance['time_in']): ?>
                                                <span class="fs-5 <?php echo $is_late ? 'text-danger' : ''; ?>">
                                                    <?php echo formatTimeDisplay($today_attendance['time_in']); ?>
                                                </span>
                                                <?php if ($is_late): ?>
                                                    <br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Late</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-6">
                                            <strong>Actual Time Out:</strong><br>
                                            <?php if ($today_attendance['time_out']): ?>
                                                <span class="fs-5 <?php echo $is_early_out ? 'text-warning' : ''; ?>">
                                                    <?php echo formatTimeDisplay($today_attendance['time_out']); ?>
                                                </span>
                                                <?php if ($is_early_out): ?>
                                                    <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Early Out</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <?php echo getStatusBadge($today_attendance['status']); ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Please mark your attendance!</strong><br>
                                    Click the button above to mark your time in.
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="alert alert-secondary">
                                <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                <p class="mb-0"><strong>No Schedule Today</strong></p>
                                <small>You don't have a duty schedule assigned for today. This is your rest day!</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Schedule Summary ──────────────────────────────────────────── -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line text-success me-2"></i>
                        Schedule Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 bg-primary bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Working Days</h6>
                                        <h2 class="mb-0"><?php echo count($weekly_schedule); ?></h2>
                                        <small class="text-muted">per week</small>
                                    </div>
                                    <div class="fs-1 text-primary opacity-50"><i class="fas fa-calendar-check"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-success bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Weekly Hours</h6>
                                        <h2 class="mb-0"><?php echo number_format($total_weekly_hours, 1); ?></h2>
                                        <small class="text-muted">hours/week</small>
                                    </div>
                                    <div class="fs-1 text-success opacity-50"><i class="fas fa-clock"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-warning bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Special Schedules</h6>
                                        <h2 class="mb-0"><?php echo count($special_schedules); ?></h2>
                                        <small class="text-muted">upcoming</small>
                                    </div>
                                    <div class="fs-1 text-warning opacity-50"><i class="fas fa-star"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-info bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Leave Requests</h6>
                                        <h2 class="mb-0"><?php echo $leave_stats['total_leaves'] ?? 0; ?></h2>
                                        <small class="text-muted">
                                            <?php echo $leave_stats['pending_leaves'] ?? 0; ?> pending,
                                            <?php echo $leave_stats['approved_leaves'] ?? 0; ?> approved
                                        </small>
                                    </div>
                                    <div class="fs-1 text-info opacity-50"><i class="fas fa-calendar-times"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="alert alert-light mb-0">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Reminder:</strong> Please mark your attendance on time.
                                Late arrivals (more than 15 minutes) will be recorded.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Weekly Schedule ─────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-calendar-week text-primary me-2"></i>
                My Weekly Schedule
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($weekly_schedule)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="150">Day</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Total Hours</th>
                                <th>Schedule Name</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                            $schedule_map = [];
                            foreach ($weekly_schedule as $sched) $schedule_map[$sched['day_of_week']] = $sched;

                            foreach ($days as $day):
                                $sched    = $schedule_map[$day] ?? null;
                                $is_today = ($day === $today);
                            ?>
                                <tr <?php echo $is_today ? 'class="table-primary"' : ''; ?>>
                                    <td>
                                        <strong><?php echo $day; ?></strong>
                                        <?php if ($is_today): ?>
                                            <span class="badge bg-primary ms-2">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($sched): ?>
                                        <td><span class="text-success"><i class="fas fa-sign-in-alt me-1"></i><?php echo formatTimeDisplay($sched['time_in']); ?></span></td>
                                        <td><span class="text-danger"><i class="fas fa-sign-out-alt me-1"></i><?php echo formatTimeDisplay($sched['time_out']); ?></span></td>
                                        <td>
                                            <?php 
                                            $diff = (strtotime($sched['time_out']) - strtotime($sched['time_in'])) / 3600;
                                            if ($diff < 0) $diff += 24;
                                            ?>
                                            <span class="badge bg-info"><?php echo number_format($diff,1); ?> hrs</span>
                                        </td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($sched['schedule_name'] ?? '-'); ?></small></td>
                                        <td>
                                            <?php if (!empty($sched['notes'])): ?>
                                                <small class="text-muted" title="<?php echo htmlspecialchars($sched['notes']); ?>">
                                                    <?php echo htmlspecialchars(substr($sched['notes'],0,30).(strlen($sched['notes'])>30?'…':'')); ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td colspan="5" class="text-center text-muted">
                                            <i class="fas fa-calendar-times me-1"></i> Rest Day
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Regular Schedule Assigned</h5>
                    <p class="text-muted">You don't have a weekly duty schedule assigned yet. Please contact your administrator.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Special Schedules ───────────────────────────────────────────── -->
    <?php if (!empty($special_schedules)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-star text-warning me-2"></i>
                Upcoming Special Schedules
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Duration</th>
                            <th>Type</th>
                            <th>Notes</th>
                            <th>Assigned By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($special_schedules as $special):
                            $is_today_special = ($special['schedule_date'] === $today_date);
                            $diff = (strtotime($special['time_out']) - strtotime($special['time_in'])) / 3600;
                            if ($diff < 0) $diff += 24;
                        ?>
                            <tr <?php echo $is_today_special ? 'class="table-warning"' : ''; ?>>
                                <td>
                                    <strong><?php echo formatDate($special['schedule_date']); ?></strong>
                                    <?php if ($is_today_special): ?>
                                        <span class="badge bg-warning text-dark ms-2">Today</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('l', strtotime($special['schedule_date'])); ?></td>
                                <td><span class="text-success"><i class="fas fa-sign-in-alt me-1"></i><?php echo formatTimeDisplay($special['time_in']); ?></span></td>
                                <td><span class="text-danger"><i class="fas fa-sign-out-alt me-1"></i><?php echo formatTimeDisplay($special['time_out']); ?></span></td>
                                <td><span class="badge bg-info"><?php echo number_format($diff,1); ?> hrs</span></td>
                                <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($special['schedule_type']); ?></span></td>
                                <td>
                                    <?php if (!empty($special['notes'])): ?>
                                        <small class="text-muted" title="<?php echo htmlspecialchars($special['notes']); ?>">
                                            <?php echo htmlspecialchars(substr($special['notes'],0,30).(strlen($special['notes'])>30?'…':'')); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($special['assigned_by'] ?? 'System'); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function updateTime() {
    const n = new Date(), h = n.getHours(), m = n.getMinutes();
    const ap = h >= 12 ? 'PM' : 'AM', dh = h % 12 || 12;
    const el = document.getElementById('current-time');
    if (el) el.textContent = dh + ':' + String(m).padStart(2,'0') + ' ' + ap;
}
function markTimeIn() {
    const t = new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true});
    if (confirm(`Mark your TIME IN now at ${t}?\n\n(Time will be recorded in Philippine Time)`))
        document.getElementById('timeInForm').submit();
}
function markTimeOut() {
    const t = new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true});
    if (confirm(`Mark your TIME OUT now at ${t}?\n\n(Time will be recorded in Philippine Time)`))
        document.getElementById('timeOutForm').submit();
}
setInterval(updateTime, 1000);
updateTime();
</script>

<?php include '../../includes/footer.php'; ?>