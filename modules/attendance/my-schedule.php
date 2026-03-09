<?php
/**
 * Staff My Duty Schedule View with Attendance Marking
 * modules/attendance/my-schedule.php
 * RESTYLED TO MATCH ADMIN ATTENDANCE UI
 */

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

// ── Table structure check ────────────────────────────────────────────────────
$columns_result = $conn->query("SHOW COLUMNS FROM tbl_attendance");
$has_marked_by = false;
$has_notes = false;
while ($col = $columns_result->fetch_assoc()) {
    if ($col['Field'] === 'marked_by') $has_marked_by = true;
    if ($col['Field'] === 'notes')     $has_notes     = true;
}

// ── Current user profile ─────────────────────────────────────────────────────
$current_user_profile = fetchOne($conn,
    "SELECT u.user_id, u.username, u.role,
            CONCAT(r.first_name, ' ', r.last_name) as full_name,
            r.profile_photo
     FROM tbl_users u
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
     WHERE u.user_id = ?",
    [$current_user_id], 'i'
);

// ── Attendance marking ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $action = sanitizeInput($_POST['action']);
    $attendance_date = date('Y-m-d');

    $column_check = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'time_in'");
    $column_info  = $column_check->fetch_assoc();
    $current_time = (strpos(strtoupper($column_info['Type']), 'DATETIME') !== false)
                    ? date('Y-m-d H:i:s') : date('H:i:s');

    $existing = fetchOne($conn,
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $attendance_date], 'is'
    );

    if ($action === 'time_in') {
        if (!$existing) {
            $status = 'Present';
            $today_day = date('l');
            $schedule = fetchOne($conn,
                "SELECT time_in FROM tbl_duty_schedules WHERE user_id = ? AND day_of_week = ? AND is_active = 1",
                [$current_user_id, $today_day], 'is'
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

// ── Schedule data ────────────────────────────────────────────────────────────
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

$special_schedules = array_slice(array_merge($individual_schedules, $event_schedules), 0, 10);
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

// ── Helpers ──────────────────────────────────────────────────────────────────
function formatTimeDisplay($t) {
    if (empty($t)) return 'N/A';
    try { return (new DateTime($t))->format('g:i A'); } catch (Exception $e) {}
    $p = explode(':', $t);
    if (count($p) < 2) return 'N/A';
    $h = (int)$p[0]; $m = str_pad($p[1],2,'0',STR_PAD_LEFT);
    return (($h%12)?:12) . ":$m " . ($h>=12?'PM':'AM');
}

$roleColors = [
    'Barangay Captain'=>['bg'=>'#fce7f3','color'=>'#9f1239'],
    'Secretary'       =>['bg'=>'#fef9c3','color'=>'#713f12'],
    'Treasurer'       =>['bg'=>'#e0f2fe','color'=>'#075985'],
    'Staff'           =>['bg'=>'#fef3c7','color'=>'#92400e'],
    'Tanod'           =>['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Barangay Tanod'  =>['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Driver'          =>['bg'=>'#d1fae5','color'=>'#065f46'],
    'Admin'           =>['bg'=>'#fee2e2','color'=>'#991b1b'],
    'Super Admin'     =>['bg'=>'#ede9fe','color'=>'#4c1d95'],
];
$avatarPalette = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];

$profile_name    = trim($current_user_profile['full_name'] ?? '') ?: ($current_user_profile['username'] ?? '?');
$profile_initial = strtoupper(substr($profile_name, 0, 1));
$profile_role    = $current_user_profile['role'] ?? '';
$profile_photo   = !empty($current_user_profile['profile_photo'])
                    ? '/barangaylink/uploads/profiles/' . $current_user_profile['profile_photo'] : '';
$profile_rc      = $roleColors[$profile_role] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
$profile_avatarBg = $avatarPalette[ord($profile_initial) % count($avatarPalette)];

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<style>
/* ── shared tokens ─────────────────────────────────────────────── */
:root {
    --navy-deep:#0d1b36; --navy-mid:#1c3461;
    --green:#10b981; --rose:#e11d48; --amber:#f59e0b;
    --sky:#0ea5e9; --indigo:#6366f1;
    --slate-50:#f8fafc; --slate-100:#f1f5f9;
    --slate-200:#e2e8f0; --slate-400:#94a3b8;
    --slate-600:#475569; --slate-900:#0f172a;
}

/* ── today card ────────────────────────────────────────────────── */
.ms-today-card {
    background:#fff; border:1px solid var(--slate-200);
    border-radius:16px;
    box-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    overflow:hidden;
}
.ms-today-card__header {
    background:linear-gradient(135deg,var(--navy-deep),var(--navy-mid));
    padding:18px 22px; display:flex; align-items:center; gap:10px;
}
.ms-today-card__header h2 {
    color:#fff; font-family:'Sora',sans-serif;
    font-size:14px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px;
}
.ms-today-card__body { padding:22px; }

.ms-time-display {
    text-align:center; margin-bottom:20px;
}
.ms-time-display__date {
    font-family:'Sora',sans-serif; font-size:13px;
    font-weight:600; color:var(--slate-600); margin-bottom:4px;
}
.ms-time-display__clock {
    font-family:'DM Mono',monospace; font-size:38px;
    font-weight:800; color:var(--navy-deep); letter-spacing:2px; line-height:1;
}
.ms-time-display__sub {
    font-size:11px; color:var(--slate-400); margin-top:4px;
    font-family:'DM Mono',monospace;
}

.ms-schedule-bar {
    display:flex; align-items:center; justify-content:center;
    gap:16px; background:var(--slate-50);
    border:1px solid var(--slate-200); border-radius:12px;
    padding:16px 20px; margin-bottom:18px;
}
.ms-schedule-bar__time { text-align:center; }
.ms-schedule-bar__time-val {
    font-family:'DM Mono',monospace; font-size:22px;
    font-weight:800; line-height:1;
}
.ms-schedule-bar__time-val.in  { color:var(--green); }
.ms-schedule-bar__time-val.out { color:var(--rose); }
.ms-schedule-bar__time-label {
    font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; color:var(--slate-400); margin-top:3px;
    font-family:'Sora',sans-serif;
}
.ms-schedule-bar__arrow { color:var(--slate-400); font-size:20px; }

.ms-special-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:linear-gradient(135deg,#fef3c7,#fde68a);
    border:1px solid #fbbf24; border-radius:20px;
    padding:4px 12px; font-family:'DM Mono',monospace;
    font-size:10.5px; font-weight:700; color:#92400e;
    margin-bottom:12px;
}

/* ── Time-in / Time-out buttons ─────────────────────────────────── */
.ms-action-btn {
    width:100%; padding:14px 20px; border:none; border-radius:12px;
    font-family:'Sora',sans-serif; font-size:14px; font-weight:700;
    cursor:pointer; transition:all .2s; display:flex;
    align-items:center; justify-content:center; gap:8px;
}
.ms-action-btn--in {
    background:linear-gradient(135deg,#059669,#10b981); color:#fff;
}
.ms-action-btn--in:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(16,185,129,.35); }
.ms-action-btn--late {
    background:linear-gradient(135deg,#d97706,#f59e0b); color:#fff;
}
.ms-action-btn--late:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(245,158,11,.35); }
.ms-action-btn--out {
    background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff;
}
.ms-action-btn--out:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(239,68,68,.35); }
.ms-action-btn--done {
    background:var(--slate-100); color:var(--slate-400); cursor:not-allowed;
}

/* ── Actual attendance row ──────────────────────────────────────── */
.ms-att-row {
    display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:16px;
}
.ms-att-box {
    background:var(--slate-50); border:1px solid var(--slate-200);
    border-radius:10px; padding:12px 16px; text-align:center;
}
.ms-att-box__label {
    font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; color:var(--slate-400); margin-bottom:5px;
    font-family:'Sora',sans-serif;
}
.ms-att-box__val {
    font-family:'DM Mono',monospace; font-size:18px;
    font-weight:800; color:var(--navy-deep);
}
.ms-att-box__val.late   { color:var(--rose); }
.ms-att-box__val.early  { color:var(--amber); }
.ms-att-box__sub { font-size:10.5px; color:var(--slate-400); margin-top:3px; }

/* att badges */
.att-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px;
    font-family:'DM Mono',monospace; font-size:10.5px;
    font-weight:600; letter-spacing:.3px; white-space:nowrap;
}
.att-badge--present { background:#d1fae5; color:#065f46; }
.att-badge--late    { background:#fef3c7; color:#92400e; }
.att-badge--absent  { background:#fee2e2; color:#7f1d1d; }
.att-badge--leave   { background:#dbeafe; color:#1e40af; }
.att-badge--halfday { background:#ede9fe; color:#4c1d95; }

/* ── warn / info strips ─────────────────────────────────────────── */
.ms-strip {
    border-radius:10px; padding:10px 14px; margin-bottom:14px;
    font-family:'Sora',sans-serif; font-size:12px;
    display:flex; gap:8px; align-items:flex-start;
}
.ms-strip--warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.ms-strip--ok   { background:#f0fdf4; border:1px solid #bbf7d0; color:#065f46; }
.ms-strip--info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
.ms-strip--rest { background:var(--slate-100); border:1px solid var(--slate-200); color:var(--slate-600); text-align:center; justify-content:center; padding:28px 24px; }

/* ── summary cards ──────────────────────────────────────────────── */
.ms-summary-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:12px;
}
.ms-sum-card {
    background:#fff; border:1px solid var(--slate-200);
    border-radius:12px; padding:14px 16px;
    display:flex; align-items:center; gap:12px;
}
.ms-sum-card__icon {
    width:42px; height:42px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:17px;
}
.ms-sum-card__num {
    font-family:'DM Mono',monospace; font-size:22px; font-weight:800;
    color:var(--navy-deep); line-height:1;
}
.ms-sum-card__label {
    font-family:'Sora',sans-serif; font-size:11px; font-weight:600;
    color:var(--slate-400); text-transform:uppercase; letter-spacing:.4px; margin-top:2px;
}
.ms-sum-card__sub {
    font-family:'DM Mono',monospace; font-size:10.5px; color:var(--slate-400); margin-top:2px;
}

/* ── day pill for weekly table ──────────────────────────────────── */
.ms-day-pill {
    display:inline-flex; align-items:center; gap:5px;
    padding:4px 10px; border-radius:20px;
    font-family:'DM Mono',monospace; font-size:11px; font-weight:700;
    background:var(--slate-100); color:var(--slate-600);
}
.ms-day-pill.today {
    background:linear-gradient(135deg,var(--navy-deep),var(--navy-mid));
    color:#fff;
}
.ms-rest-row td { color:var(--slate-400); font-style:italic; }
.time-in  { color:var(--green); font-family:'DM Mono',monospace; font-size:12.5px; font-weight:600; }
.time-out { color:var(--rose);  font-family:'DM Mono',monospace; font-size:12.5px; font-weight:600; }

/* ── special schedule type badge ───────────────────────────────── */
.type-badge {
    display:inline-block; padding:3px 10px; border-radius:20px;
    background:#fef3c7; color:#92400e;
    font-family:'DM Mono',monospace; font-size:10px; font-weight:700;
}
.hrs-badge {
    display:inline-block; padding:3px 10px; border-radius:20px;
    background:#dbeafe; color:#1e40af;
    font-family:'DM Mono',monospace; font-size:10.5px; font-weight:700;
}

/* ── profile card ───────────────────────────────────────────────── */
.ms-profile-bar {
    display:flex; align-items:center; gap:14px; flex-wrap:wrap;
}
.ms-profile-avatar {
    width:52px; height:52px; border-radius:14px; flex-shrink:0;
    background:var(--navy-deep); display:flex; align-items:center; justify-content:center;
    font-size:21px; font-weight:800; color:#fff; overflow:hidden;
    box-shadow:0 2px 10px rgba(13,27,54,.2);
}
.ms-profile-avatar img { width:100%; height:100%; object-fit:cover; }

@media (max-width:768px) {
    .ms-summary-grid { grid-template-columns:1fr; }
    .ms-att-row { grid-template-columns:1fr; }
}
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <!-- Profile avatar -->
            <div class="ms-profile-avatar" id="heroAvatar">
                <?php if ($profile_photo): ?>
                    <img src="<?php echo htmlspecialchars($profile_photo); ?>"
                         alt="<?php echo htmlspecialchars($profile_name); ?>"
                         onerror="this.style.display='none';document.getElementById('heroInitial').style.display='flex';">
                    <span id="heroInitial" style="display:none;"><?php echo $profile_initial; ?></span>
                <?php else: ?>
                    <span style="background:<?php echo $profile_avatarBg; ?>; width:100%; height:100%; display:flex; align-items:center; justify-content:center; border-radius:14px;">
                        <?php echo $profile_initial; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($profile_role); ?>
                </div>
                <h1 class="db-hero__title"><?php echo htmlspecialchars($profile_name); ?></h1>
                <p class="db-hero__sub">My Duty Schedule &amp; Attendance</p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <a href="my-payslips.php"    class="db-btn db-btn--ghost"><i class="fas fa-money-check-alt"></i> PayChecks</a>
            <a href="manage-leaves.php"  class="db-btn db-btn--ghost">
                <i class="fas fa-calendar-times"></i> Manage Leaves
                <?php if (!empty($leave_stats['pending_leaves']) && $leave_stats['pending_leaves'] > 0): ?>
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#f59e0b;color:#fff;font-size:10px;font-weight:800;margin-left:2px;"><?php echo $leave_stats['pending_leaves']; ?></span>
                <?php endif; ?>
            </a>
            <a href="leave-request.php"  class="db-btn" style="background:linear-gradient(135deg,#0d1b36,#1c3461);color:#fff;border:none;"><i class="fas fa-plus"></i> Request Leave</a>
            <a href="my-attendance.php"  class="db-btn db-btn--ghost"><i class="fas fa-clipboard-list"></i> History</a>
        </div>
    </div>
</div>

<!-- ─── ALERTS ───────────────────────────────────────────────────────────── -->
<?php if (!empty($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['warning_message'])): ?>
<div class="db-alert" style="background:#fffbeb;border-left:4px solid #f59e0b;color:#92400e;">
    <div class="db-alert__icon" style="color:#f59e0b;"><i class="fas fa-exclamation-triangle"></i></div>
    <span><?php echo htmlspecialchars($_SESSION['warning_message']); unset($_SESSION['warning_message']); ?></span>
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

<!-- ─── STAT CARDS ────────────────────────────────────────────────────────── -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-calendar-check"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo count($weekly_schedule); ?></div>
            <div class="db-stat-card__label">Working Days/Week</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo number_format($total_weekly_hours,1); ?></div>
            <div class="db-stat-card__label">Hours per Week</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-star"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo count($special_schedules); ?></div>
            <div class="db-stat-card__label">Special Schedules</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#f59e0b,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-calendar-times"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#6366f1;"><?php echo $leave_stats['total_leaves'] ?? 0; ?></div>
            <div class="db-stat-card__label">Leave Requests '<?php echo date('y'); ?></div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;"><?php echo $leave_stats['approved_leaves'] ?? 0; ?></div>
            <div class="db-stat-card__label">Approved Leaves</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-hourglass-half"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo $leave_stats['pending_leaves'] ?? 0; ?></div>
            <div class="db-stat-card__label">Pending Leaves</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
</div>

<!-- ─── TWO-COL LAYOUT: Today + Summary ──────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- TODAY CARD -->
    <div class="ms-today-card">
        <div class="ms-today-card__header">
            <i class="fas fa-calendar-day" style="color:rgba(255,255,255,.8);"></i>
            <h2>Today's Schedule &amp; Attendance</h2>
        </div>
        <div class="ms-today-card__body">
            <div class="ms-time-display">
                <div class="ms-time-display__date"><?php echo date('l, F j, Y'); ?></div>
                <div class="ms-time-display__clock" id="live-clock"><?php echo date('h:i A'); ?></div>
                <div class="ms-time-display__sub">Philippine Standard Time</div>
            </div>

            <?php if ($today_schedule): ?>
                <?php if ($today_schedule['type'] === 'special'): ?>
                    <div style="text-align:center;margin-bottom:12px;">
                        <span class="ms-special-badge"><i class="fas fa-star"></i> <?php echo htmlspecialchars($today_schedule['schedule_type']); ?></span>
                    </div>
                <?php endif; ?>

                <div class="ms-schedule-bar">
                    <div class="ms-schedule-bar__time">
                        <div class="ms-schedule-bar__time-val in"><?php echo formatTimeDisplay($today_schedule['time_in']); ?></div>
                        <div class="ms-schedule-bar__time-label"><i class="fas fa-sign-in-alt"></i> Scheduled In</div>
                    </div>
                    <div class="ms-schedule-bar__arrow"><i class="fas fa-arrow-right"></i></div>
                    <div class="ms-schedule-bar__time">
                        <div class="ms-schedule-bar__time-val out"><?php echo formatTimeDisplay($today_schedule['time_out']); ?></div>
                        <div class="ms-schedule-bar__time-label"><i class="fas fa-sign-out-alt"></i> Scheduled Out</div>
                    </div>
                </div>

                <?php if (!empty($today_schedule['notes'])): ?>
                    <div class="ms-strip ms-strip--info" style="margin-bottom:14px;">
                        <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
                        <span><?php echo htmlspecialchars($today_schedule['notes']); ?></span>
                    </div>
                <?php endif; ?>

                <?php
                $would_be_late = false; $late_by = 0;
                if ($today_schedule && $can_time_in) {
                    $dm = (strtotime(date('H:i:s')) - strtotime($today_schedule['time_in'])) / 60;
                    if ($dm > 15) { $would_be_late = true; $late_by = round($dm); }
                }
                ?>
                <?php if ($can_time_in): ?>
                    <?php if ($would_be_late): ?>
                        <div class="ms-strip ms-strip--warn">
                            <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                            <span>You are <strong><?php echo $late_by; ?> minutes late</strong>. Marking now will record as <strong>Late</strong>.</span>
                        </div>
                    <?php else: ?>
                        <div class="ms-strip ms-strip--ok">
                            <i class="fas fa-check-circle" style="flex-shrink:0;margin-top:1px;"></i>
                            <span>Within grace period. Marking now will record as <strong>Present</strong>.</span>
                        </div>
                    <?php endif; ?>
                    <form method="POST" id="timeInForm">
                        <input type="hidden" name="mark_attendance" value="1">
                        <input type="hidden" name="action" value="time_in">
                        <button type="button"
                                class="ms-action-btn <?php echo $would_be_late ? 'ms-action-btn--late' : 'ms-action-btn--in'; ?>"
                                onclick="markTimeIn()">
                            <i class="fas fa-sign-in-alt"></i>
                            Mark Time In<?php echo $would_be_late ? ' (Late)' : ''; ?>
                        </button>
                    </form>
                    <div style="text-align:center;font-size:11px;color:var(--slate-400);margin-top:7px;font-family:'DM Mono',monospace;">
                        Grace period: 15 min after scheduled time
                    </div>
                <?php elseif ($can_time_out): ?>
                    <form method="POST" id="timeOutForm">
                        <input type="hidden" name="mark_attendance" value="1">
                        <input type="hidden" name="action" value="time_out">
                        <button type="button" class="ms-action-btn ms-action-btn--out" onclick="markTimeOut()">
                            <i class="fas fa-sign-out-alt"></i> Mark Time Out
                        </button>
                    </form>
                <?php else: ?>
                    <button type="button" class="ms-action-btn ms-action-btn--done" disabled>
                        <i class="fas fa-check-circle"></i> Attendance Completed
                    </button>
                <?php endif; ?>

                <?php if ($today_attendance): ?>
                    <div class="ms-att-row">
                        <div class="ms-att-box">
                            <div class="ms-att-box__label"><i class="fas fa-sign-in-alt"></i> Actual In</div>
                            <?php if ($today_attendance['time_in']): ?>
                                <div class="ms-att-box__val <?php echo $is_late ? 'late' : ''; ?>">
                                    <?php echo formatTimeDisplay($today_attendance['time_in']); ?>
                                </div>
                                <?php if ($is_late): ?>
                                    <div class="ms-att-box__sub" style="color:var(--rose);">
                                        <i class="fas fa-exclamation-triangle"></i> Late
                                    </div>
                                <?php endif; ?>
                            <?php else: ?><div class="ms-att-box__val" style="color:var(--slate-400);">—</div><?php endif; ?>
                        </div>
                        <div class="ms-att-box">
                            <div class="ms-att-box__label"><i class="fas fa-sign-out-alt"></i> Actual Out</div>
                            <?php if ($today_attendance['time_out']): ?>
                                <div class="ms-att-box__val <?php echo $is_early_out ? 'early' : ''; ?>">
                                    <?php echo formatTimeDisplay($today_attendance['time_out']); ?>
                                </div>
                                <?php if ($is_early_out): ?>
                                    <div class="ms-att-box__sub" style="color:var(--amber);">
                                        <i class="fas fa-exclamation-triangle"></i> Early Out
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="ms-att-box__val" style="color:var(--slate-400);font-size:13px;">Pending</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:center;margin-top:12px;">
                        <?php
                        $bm = ['Present'=>'att-badge--present','Late'=>'att-badge--late','Absent'=>'att-badge--absent','On Leave'=>'att-badge--leave','Half Day'=>'att-badge--halfday'];
                        $ic = ['Present'=>'fa-check-circle','Late'=>'fa-clock','Absent'=>'fa-times-circle','On Leave'=>'fa-calendar-times','Half Day'=>'fa-adjust'];
                        $bc = $bm[$today_attendance['status']] ?? 'att-badge--present';
                        $ii = $ic[$today_attendance['status']] ?? 'fa-circle';
                        echo "<span class='att-badge {$bc}'><i class='fas {$ii}'></i> {$today_attendance['status']}</span>";
                        ?>
                    </div>
                <?php else: ?>
                    <div class="ms-strip ms-strip--warn" style="margin-top:14px;">
                        <i class="fas fa-bell" style="flex-shrink:0;margin-top:1px;"></i>
                        <span><strong>Please mark your attendance!</strong> Click the button above.</span>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="ms-strip ms-strip--rest">
                    <div>
                        <i class="fas fa-moon" style="font-size:28px;color:var(--slate-400);display:block;margin-bottom:8px;"></i>
                        <strong style="font-size:14px;color:var(--slate-600);">No Schedule Today</strong>
                        <div style="font-size:12px;margin-top:4px;">This is your rest day. Enjoy!</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SUMMARY PANEL -->
    <div class="db-panel" style="margin-bottom:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-chart-line"></i></span>
                <h2>Schedule Summary</h2>
            </div>
        </div>
        <div style="padding:20px 22px;">
            <div class="ms-summary-grid">
                <div class="ms-sum-card">
                    <div class="ms-sum-card__icon" style="background:#dbeafe;color:#1e40af;"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <div class="ms-sum-card__num"><?php echo count($weekly_schedule); ?></div>
                        <div class="ms-sum-card__label">Working Days</div>
                        <div class="ms-sum-card__sub">per week</div>
                    </div>
                </div>
                <div class="ms-sum-card">
                    <div class="ms-sum-card__icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-clock"></i></div>
                    <div>
                        <div class="ms-sum-card__num"><?php echo number_format($total_weekly_hours,1); ?></div>
                        <div class="ms-sum-card__label">Weekly Hours</div>
                        <div class="ms-sum-card__sub">hours / week</div>
                    </div>
                </div>
                <div class="ms-sum-card">
                    <div class="ms-sum-card__icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="ms-sum-card__num"><?php echo count($special_schedules); ?></div>
                        <div class="ms-sum-card__label">Special Upcoming</div>
                        <div class="ms-sum-card__sub">schedules</div>
                    </div>
                </div>
                <div class="ms-sum-card">
                    <div class="ms-sum-card__icon" style="background:#ede9fe;color:#6366f1;"><i class="fas fa-calendar-times"></i></div>
                    <div>
                        <div class="ms-sum-card__num"><?php echo $leave_stats['total_leaves'] ?? 0; ?></div>
                        <div class="ms-sum-card__label">Leave Requests</div>
                        <div class="ms-sum-card__sub"><?php echo $leave_stats['pending_leaves']??0; ?> pending · <?php echo $leave_stats['approved_leaves']??0; ?> approved</div>
                    </div>
                </div>
            </div>
            <div class="ms-strip ms-strip--info" style="margin-top:16px;margin-bottom:0;">
                <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
                <span><strong>Reminder:</strong> Mark attendance on time. Late arrivals (15+ min) are automatically recorded.</span>
            </div>
        </div>
    </div>
</div>

<!-- ─── WEEKLY SCHEDULE TABLE ──────────────────────────────────────────────── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-calendar-week"></i></span>
            <h2>My Weekly Schedule</h2>
        </div>
    </div>
    <div class="db-table-wrap">
        <?php if (!empty($weekly_schedule)): ?>
        <table class="db-table">
            <thead>
                <tr>
                    <th width="160">Day</th>
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
                foreach ($weekly_schedule as $s) $schedule_map[$s['day_of_week']] = $s;
                foreach ($days as $day):
                    $sched    = $schedule_map[$day] ?? null;
                    $is_today = ($day === $today);
                ?>
                <tr <?php echo (!$sched) ? 'class="ms-rest-row"' : ''; ?>>
                    <td>
                        <span class="ms-day-pill <?php echo $is_today ? 'today' : ''; ?>">
                            <?php if ($is_today): ?><i class="fas fa-circle" style="font-size:6px;"></i><?php endif; ?>
                            <?php echo $day; ?>
                        </span>
                    </td>
                    <?php if ($sched): ?>
                        <td><span class="time-in"><i class="fas fa-sign-in-alt me-1"></i><?php echo formatTimeDisplay($sched['time_in']); ?></span></td>
                        <td><span class="time-out"><i class="fas fa-sign-out-alt me-1"></i><?php echo formatTimeDisplay($sched['time_out']); ?></span></td>
                        <td>
                            <?php
                            $diff = (strtotime($sched['time_out']) - strtotime($sched['time_in'])) / 3600;
                            if ($diff < 0) $diff += 24;
                            ?>
                            <span class="hrs-badge"><?php echo number_format($diff,1); ?> hrs</span>
                        </td>
                        <td style="font-size:12px;color:var(--slate-600);"><?php echo htmlspecialchars($sched['schedule_name'] ?? '—'); ?></td>
                        <td>
                            <?php if (!empty($sched['notes'])): ?>
                                <span style="font-size:12px;color:var(--slate-400);" title="<?php echo htmlspecialchars($sched['notes']); ?>">
                                    <?php echo htmlspecialchars(substr($sched['notes'],0,32).(strlen($sched['notes'])>32?'…':'')); ?>
                                </span>
                            <?php else: ?><span style="color:var(--slate-400);">—</span><?php endif; ?>
                        </td>
                    <?php else: ?>
                        <td colspan="5" style="text-align:center;">
                            <span style="font-size:12px;"><i class="fas fa-moon me-1"></i> Rest Day</span>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="att-empty">
            <i class="fas fa-calendar-times"></i>
            <p>No regular schedule assigned yet. Please contact your administrator.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── SPECIAL SCHEDULES ─────────────────────────────────────────────────── -->
<?php if (!empty($special_schedules)): ?>
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-star"></i></span>
            <h2>Upcoming Special Schedules</h2>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
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
                    $is_today_s = ($special['schedule_date'] === $today_date);
                    $diff = (strtotime($special['time_out']) - strtotime($special['time_in'])) / 3600;
                    if ($diff < 0) $diff += 24;
                ?>
                <tr>
                    <td>
                        <strong style="font-family:'DM Mono',monospace;font-size:12.5px;"><?php echo date('M j, Y', strtotime($special['schedule_date'])); ?></strong>
                        <?php if ($is_today_s): ?>
                            <span class="ms-special-badge" style="margin-left:6px;font-size:9px;padding:2px 8px;">Today</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--slate-600);"><?php echo date('l', strtotime($special['schedule_date'])); ?></td>
                    <td><span class="time-in"><i class="fas fa-sign-in-alt me-1"></i><?php echo formatTimeDisplay($special['time_in']); ?></span></td>
                    <td><span class="time-out"><i class="fas fa-sign-out-alt me-1"></i><?php echo formatTimeDisplay($special['time_out']); ?></span></td>
                    <td><span class="hrs-badge"><?php echo number_format($diff,1); ?> hrs</span></td>
                    <td><span class="type-badge"><?php echo htmlspecialchars($special['schedule_type']); ?></span></td>
                    <td>
                        <?php if (!empty($special['notes'])): ?>
                            <span style="font-size:12px;color:var(--slate-400);" title="<?php echo htmlspecialchars($special['notes']); ?>">
                                <?php echo htmlspecialchars(substr($special['notes'],0,30).(strlen($special['notes'])>30?'…':'')); ?>
                            </span>
                        <?php else: ?><span style="color:var(--slate-400);">—</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--slate-400);"><?php echo htmlspecialchars($special['assigned_by'] ?? 'System'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
(function(){
    function pad(v){return String(v).padStart(2,'0');}
    function tick(){
        var n=new Date(), h=n.getHours(), m=n.getMinutes(), s=n.getSeconds();
        var ap=h>=12?'PM':'AM'; h=h%12||12;
        var el=document.getElementById('live-clock');
        if(el) el.textContent=h+':'+pad(m)+':'+pad(s)+' '+ap;
    }
    tick(); setInterval(tick,1000);
})();

function markTimeIn(){
    var t=new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true});
    if(confirm('Mark your TIME IN now at '+t+'?\n\n(Time recorded in Philippine Standard Time)'))
        document.getElementById('timeInForm').submit();
}
function markTimeOut(){
    var t=new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true});
    if(confirm('Mark your TIME OUT now at '+t+'?\n\n(Time recorded in Philippine Standard Time)'))
        document.getElementById('timeOutForm').submit();
}

setTimeout(function(){
    document.querySelectorAll('.db-alert').forEach(function(a){
        a.style.transition='opacity .4s'; a.style.opacity='0';
        setTimeout(function(){try{a.remove();}catch(e){}},400);
    });
},5000);
</script>

<?php include '../../includes/footer.php'; ?>