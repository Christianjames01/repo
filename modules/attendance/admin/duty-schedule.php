<?php
/**
 * Admin Duty Schedule Management — Restyled to match Dashboard UI
 * modules/attendance/admin/duty-schedule.php
 */

require_once __DIR__ . '/../../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff'])) redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');

$page_title      = 'Duty Schedule Management';
$current_user_id = getCurrentUserId();

// ── POST handlers (unchanged logic) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_schedule'])) {
    $user_id = intval($_POST['user_id']);
    $days    = $_POST['days'] ?? [];
    executeQuery($conn, "DELETE FROM tbl_duty_schedules WHERE user_id = ?", [$user_id], 'i');
    $success_count   = 0;
    $attempted_count = 0;
    foreach ($days as $day => $times) {
        if (!empty($times['time_in']) && !empty($times['time_out'])) {
            $attempted_count++;
            $ok = executeQuery($conn,
                "INSERT INTO tbl_duty_schedules (user_id,day_of_week,time_in,time_out,is_active,created_by) VALUES (?,?,?,?,1,?)",
                [$user_id, $day, $times['time_in'], $times['time_out'], $current_user_id],
                'isssi'
            );
            if ($ok) $success_count++;
        }
    }
    if ($attempted_count === 0) {
        logActivity($conn, $current_user_id, "Cleared duty schedule for user ID: $user_id", 'tbl_duty_schedules', $user_id);
        $_SESSION['success_message'] = "Schedule cleared successfully";
    } elseif ($success_count > 0) {
        logActivity($conn, $current_user_id, "Assigned duty schedule to user ID: $user_id", 'tbl_duty_schedules', $user_id);
        $_SESSION['success_message'] = "Successfully assigned schedule for $success_count day(s)";
    } else {
        $_SESSION['error_message'] = "Failed to save schedule. Please try again.";
    }
    header("Location: duty-schedule.php?user_id=".$user_id); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_template'])) {
    $user_ids    = $_POST['selected_users'] ?? [];
    $template_id = intval($_POST['template_id']);
    $template    = fetchOne($conn,"SELECT * FROM tbl_schedule_templates WHERE template_id = ?",[$template_id],'i');
    if ($template && !empty($user_ids)) {
        $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $success_count = 0;
        foreach ($user_ids as $user_id) {
            $user_id = intval($user_id);
            executeQuery($conn,"DELETE FROM tbl_duty_schedules WHERE user_id = ?",[$user_id],'i');
            foreach ($days as $day) {
                $dl = strtolower($day);
                $ti = $template[$dl.'_in']; $to = $template[$dl.'_out'];
                if ($ti && $to) executeQuery($conn,
                    "INSERT INTO tbl_duty_schedules (user_id,day_of_week,time_in,time_out,is_active,created_by) VALUES (?,?,?,?,1,?)",
                    [$user_id,$day,$ti,$to,$current_user_id],'isssi');
            }
            $success_count++;
        }
        logActivity($conn,$current_user_id,"Applied schedule template to $success_count user(s)",'tbl_duty_schedules',null);
        $_SESSION['success_message']="Successfully applied template to $success_count user(s)";
    } else {
        $_SESSION['error_message']="Failed to apply template";
    }
    header("Location: duty-schedule.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_special_schedule'])) {
    $user_id       = intval($_POST['user_id']);
    $schedule_date = sanitizeInput($_POST['schedule_date']);
    $time_in       = sanitizeInput($_POST['time_in']);
    $time_out      = sanitizeInput($_POST['time_out']);
    $schedule_type = sanitizeInput($_POST['schedule_type']);
    $notes         = sanitizeInput($_POST['notes']);
    $existing      = fetchOne($conn,"SELECT special_schedule_id FROM tbl_special_duty_schedules WHERE user_id=? AND schedule_date=?",[$user_id,$schedule_date],'is');
    if ($existing) {
        $success = executeQuery($conn,"UPDATE tbl_special_duty_schedules SET time_in=?,time_out=?,schedule_type=?,notes=? WHERE user_id=? AND schedule_date=?",[$time_in,$time_out,$schedule_type,$notes,$user_id,$schedule_date],'ssssis');
        $ssid = $existing['special_schedule_id'];
    } else {
        $success = executeQuery($conn,"INSERT INTO tbl_special_duty_schedules (user_id,schedule_date,time_in,time_out,schedule_type,notes,created_by) VALUES (?,?,?,?,?,?,?)",[$user_id,$schedule_date,$time_in,$time_out,$schedule_type,$notes,$current_user_id],'isssssi');
        $ssid = $success ? $conn->insert_id : false;
    }
    if ($success && $ssid) { logActivity($conn,$current_user_id,"Added special schedule for user ID: $user_id on $schedule_date",'tbl_special_duty_schedules',$ssid); $_SESSION['success_message']="Special schedule added successfully"; }
    else $_SESSION['error_message']="Failed to add special schedule";
    header("Location: duty-schedule.php?user_id=".$user_id); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_special_schedule'])) {
    $ssid = intval($_POST['special_schedule_id']);
    if (executeQuery($conn,"DELETE FROM tbl_special_duty_schedules WHERE special_schedule_id=?",[$ssid],'i')) { logActivity($conn,$current_user_id,"Deleted special schedule ID: $ssid",'tbl_special_duty_schedules',$ssid); $_SESSION['success_message']="Special schedule deleted successfully"; }
    else $_SESSION['error_message']="Failed to delete special schedule";
    header("Location: duty-schedule.php?user_id=".($_POST['user_id']??'')); exit();
}
// ─────────────────────────────────────────────────────────────────────────────

$users = fetchAll($conn,
    "SELECT u.user_id,u.username,u.role,CONCAT(r.first_name,' ',r.last_name) as full_name,r.profile_photo
     FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
     WHERE u.is_active=1 AND u.role IN ('Staff','Tanod','Barangay Tanod','Driver','Barangay Captain','Secretary','Treasurer')
     ORDER BY u.role,r.last_name");

$templates = fetchAll($conn,"SELECT * FROM tbl_schedule_templates WHERE is_active=1 ORDER BY template_name");

$selected_user  = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$user_schedules = []; $special_schedules = [];
if ($selected_user) {
    $user_schedules = fetchAll($conn,
        "SELECT * FROM tbl_duty_schedules WHERE user_id=? ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')",
        [$selected_user],'i');
    $special_schedules = fetchAll($conn,
        "SELECT ss.*,CONCAT(r.first_name,' ',r.last_name) as created_by_name
         FROM tbl_special_duty_schedules ss
         LEFT JOIN tbl_users u ON ss.created_by=u.user_id
         LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
         WHERE ss.user_id=? AND ss.schedule_date>=CURDATE() ORDER BY ss.schedule_date",
        [$selected_user],'i');
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';

// Selected user info
$selected_user_data = null;
if ($selected_user) foreach ($users as $u) if ($u['user_id']==$selected_user) { $selected_user_data=$u; break; }

$avatarColors = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];
$roleColors   = [
    'Barangay Captain'=>['bg'=>'#fce7f3','color'=>'#9f1239'],
    'Secretary'       =>['bg'=>'#fef9c3','color'=>'#713f12'],
    'Treasurer'       =>['bg'=>'#e0f2fe','color'=>'#075985'],
    'Staff'           =>['bg'=>'#fef3c7','color'=>'#92400e'],
    'Tanod'           =>['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Barangay Tanod'  =>['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Driver'          =>['bg'=>'#d1fae5','color'=>'#065f46'],
];
?>

<style>
/* ── Duty Schedule page (dashboard-matched) ── */
.ds-page { padding:0 0 40px; }

.ds-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; padding-top:6px; }
.ds-header__title { font-size:22px; font-weight:800; letter-spacing:-0.4px; display:flex; align-items:center; gap:10px; }
.ds-header__title i { color:var(--db-sky); }
.ds-header__sub   { font-size:13px; color:var(--db-muted); margin-top:3px; }

/* Filter bar */
.ds-filter { background:var(--db-surf); border-radius:var(--db-radius-lg); border:1px solid var(--db-border); box-shadow:var(--db-shadow); padding:18px 24px; margin-bottom:22px; }
.ds-filter__row { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; }
.ds-filter__field { flex:1 1 260px; }
.ds-filter__field label { display:block; font-size:11px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.7px; margin-bottom:6px; font-family:'DM Mono',monospace; }
.ds-filter__field select { width:100%; padding:9px 32px 9px 13px; border:1.5px solid var(--db-border); border-radius:var(--db-radius-sm); font-family:'Sora',sans-serif; font-size:13px; color:var(--db-text); background:var(--db-surf); outline:none; transition:all .18s; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
.ds-filter__field select:focus { border-color:var(--db-navy-light); box-shadow:0 0 0 3px rgba(28,52,97,.1); }

/* Staff hero */
.ds-staff-hero { background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#224090 100%); border-radius:var(--db-radius-lg); padding:22px 28px; margin-bottom:20px; display:flex; align-items:center; gap:18px; position:relative; overflow:hidden; box-shadow:var(--db-shadow-lg); }
.ds-staff-hero::before { content:''; position:absolute; width:240px; height:240px; border-radius:50%; border:1px solid rgba(255,255,255,.06); top:-100px; right:-60px; pointer-events:none; }
.ds-staff-hero__avatar { width:52px; height:52px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; color:#fff; flex-shrink:0; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,.25); position:relative; z-index:1; }
.ds-staff-hero__avatar img { width:100%; height:100%; object-fit:cover; }
.ds-staff-hero__info { position:relative; z-index:1; }
.ds-staff-hero__name { font-size:18px; font-weight:800; color:#fff; letter-spacing:-0.3px; margin-bottom:6px; }

/* Schedule grid */
.ds-sched-grid { padding:20px 22px; display:flex; flex-direction:column; gap:0; }
.ds-sched-row { display:grid; grid-template-columns:140px 1fr 1fr 80px 50px; gap:10px; align-items:center; padding:12px 0; border-bottom:1px solid var(--db-border); }
.ds-sched-row:last-child { border-bottom:none; }
.ds-sched-row--head { padding:8px 0; }
.ds-sched-head-label { font-size:10px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.7px; font-family:'DM Mono',monospace; }
.ds-day-label { font-weight:700; font-size:13px; color:var(--db-text); }
.ds-day-label--weekend { color:var(--db-rose); }
.ds-time-input { width:100%; padding:8px 11px; border:1.5px solid var(--db-border); border-radius:var(--db-radius-sm); font-family:'Sora',sans-serif; font-size:13px; color:var(--db-text); background:var(--db-surf); outline:none; transition:all .18s; }
.ds-time-input:focus { border-color:var(--db-navy-light); box-shadow:0 0 0 3px rgba(28,52,97,.1); }
.ds-hours-badge { font-family:'DM Mono',monospace; font-size:11px; font-weight:600; text-align:center; }

/* Presets bar */
.ds-presets { display:flex; gap:8px; flex-wrap:wrap; padding:14px 22px; border-top:1px solid var(--db-border); background:var(--db-surf2); }

/* Special schedule table */
.ds-special-empty { padding:24px; text-align:center; color:var(--db-muted); font-size:13px; }

/* Checkbox styled */
.ds-check { width:18px; height:18px; accent-color:var(--db-navy-light); cursor:not-allowed; }

@media(max-width:760px){
    .ds-sched-row { grid-template-columns:100px 1fr 1fr 70px 40px; gap:6px; }
}
</style>

<div class="ds-page">

    <!-- Header -->
    <div class="ds-header">
        <div>
            <div class="ds-header__title"><i class="fas fa-calendar-week"></i> Duty Schedule Management</div>
            <div class="ds-header__sub">Assign and manage staff duty schedules</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="index.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-arrow-left"></i> Back</a>
            <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('templateModal')"><i class="fas fa-clone"></i> Apply Template</button>
            <?php if ($selected_user): ?>
            <button class="db-btn db-btn--primary db-btn--sm" style="background:linear-gradient(135deg,var(--db-teal),#0f766e)" onclick="openModal('specialScheduleModal')"><i class="fas fa-calendar-plus"></i> Special Schedule</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success"><div class="db-alert__icon"><i class="fas fa-check-circle"></i></div><span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="db-alert db-alert--error"><div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div><span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Staff picker -->
    <div class="ds-filter">
        <form method="GET">
            <div class="ds-filter__row">
                <div class="ds-filter__field">
                    <label><i class="fas fa-user me-1"></i> Staff Member</label>
                    <select name="user_id" onchange="this.form.submit()">
                        <option value="">— Select a staff member —</option>
                        <?php
                        $curGroup = '';
                        foreach ($users as $u):
                            if ($u['role'] !== $curGroup) {
                                if ($curGroup !== '') echo '</optgroup>';
                                echo '<optgroup label="'.htmlspecialchars($u['role']).'">';
                                $curGroup = $u['role'];
                            }
                        ?>
                            <option value="<?php echo $u['user_id']; ?>" <?php echo $selected_user==$u['user_id']?'selected':''; ?>>
                                <?php echo htmlspecialchars($u['full_name'] ?? $u['username']); ?>
                            </option>
                        <?php endforeach; if ($curGroup !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div style="flex-shrink:0;padding-bottom:0">
                    <a href="duty-schedule.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($selected_user && $selected_user_data):
        $suName    = trim($selected_user_data['full_name'] ?? '') ?: ($selected_user_data['username'] ?? '?');
        $suInitial = strtoupper(substr($suName, 0, 1));
        $suRole    = $selected_user_data['role'] ?? '';
        $suPhoto   = !empty($selected_user_data['profile_photo']) ? '/barangaylink1/uploads/profiles/'.$selected_user_data['profile_photo'] : '';
        $avBg      = $avatarColors[ord($suInitial) % count($avatarColors)];
        $rc        = $roleColors[$suRole] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
    ?>

    <!-- Staff hero -->
    <div class="ds-staff-hero">
        <div class="ds-staff-hero__avatar" style="background:<?php echo $avBg; ?>">
            <?php if ($suPhoto): ?>
                <img src="<?php echo htmlspecialchars($suPhoto); ?>" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">
                <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center"><?php echo $suInitial; ?></span>
            <?php else: echo $suInitial; endif; ?>
        </div>
        <div class="ds-staff-hero__info">
            <div class="ds-staff-hero__name"><?php echo htmlspecialchars($suName); ?></div>
            <div>
                <span style="display:inline-block;padding:3px 10px;border-radius:20px;background:rgba(255,255,255,.14);color:rgba(255,255,255,.85);font-size:11px;font-weight:700;letter-spacing:.4px;font-family:'DM Mono',monospace">
                    <?php echo htmlspecialchars($suRole); ?>
                </span>
                <span style="font-size:11.5px;color:rgba(255,255,255,.5);margin-left:10px;font-family:'DM Mono',monospace">
                    <?php echo count($user_schedules); ?> day(s) scheduled
                </span>
            </div>
        </div>
    </div>

    <!-- Weekly Schedule Panel -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon" style="background:var(--db-sky-light);color:var(--db-sky)"><i class="fas fa-calendar-week"></i></span>
                <h2>Weekly Duty Schedule</h2>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="assign_schedule" value="1">
            <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">

            <?php
            $days        = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            $weekends    = ['Saturday','Sunday'];
            $schedule_map = [];
            foreach ($user_schedules as $s) $schedule_map[$s['day_of_week']] = $s;
            ?>

            <div class="ds-sched-grid">
                <!-- Head -->
                <div class="ds-sched-row ds-sched-row--head">
                    <div class="ds-sched-head-label">Day</div>
                    <div class="ds-sched-head-label">Time In</div>
                    <div class="ds-sched-head-label">Time Out</div>
                    <div class="ds-sched-head-label" style="text-align:center">Hours</div>
                    <div class="ds-sched-head-label" style="text-align:center">On</div>
                </div>

                <?php foreach ($days as $day):
                    $existing = $schedule_map[$day] ?? null;
                    $ti = $existing ? substr($existing['time_in'],0,5)  : '';
                    $to = $existing ? substr($existing['time_out'],0,5) : '';
                    $isWeekend = in_array($day,$weekends);
                    $hrs = '';
                    if ($ti && $to) {
                        $diff = (strtotime($to) - strtotime($ti)) / 3600;
                        if ($diff < 0) $diff += 24;
                        $hrs = number_format($diff, 1).' hrs';
                    }
                ?>
                <div class="ds-sched-row">
                    <div class="ds-day-label <?php echo $isWeekend?'ds-day-label--weekend':''; ?>">
                        <?php if ($isWeekend): ?><i class="fas fa-sun" style="font-size:11px;margin-right:4px;opacity:.7"></i><?php endif; ?>
                        <?php echo $day; ?>
                    </div>
                    <input type="time" class="ds-time-input" name="days[<?php echo $day; ?>][time_in]" value="<?php echo $ti; ?>" onchange="calcHours('<?php echo $day; ?>')">
                    <input type="time" class="ds-time-input" name="days[<?php echo $day; ?>][time_out]" value="<?php echo $to; ?>" onchange="calcHours('<?php echo $day; ?>')">
                    <div class="ds-hours-badge">
                        <span class="db-badge <?php echo $hrs ? 'db-badge--info' : 'db-badge--muted'; ?>" id="hrs_<?php echo $day; ?>">
                            <?php echo $hrs ?: '—'; ?>
                        </span>
                    </div>
                    <div style="text-align:center">
                        <input type="checkbox" class="ds-check" <?php echo ($ti&&$to)?'checked':''; ?> disabled>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Presets -->
            <div class="ds-presets">
                <span style="font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.6px;font-family:'DM Mono',monospace;align-self:center">Quick Presets:</span>
                <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="applyPreset('08:00','17:00','weekday')">8AM–5PM (Mon–Fri)</button>
                <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="applyPreset('07:00','16:00','weekday')">7AM–4PM (Mon–Fri)</button>
                <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="applyPreset('09:00','18:00','all')">9AM–6PM (All Days)</button>
                <button type="button" class="db-btn db-btn--danger db-btn--sm" onclick="clearAll()"><i class="fas fa-times"></i> Clear All</button>
            </div>

            <div style="padding:16px 22px;border-top:1px solid var(--db-border);background:var(--db-surf2)">
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Save Schedule</button>
            </div>
        </form>
    </div>

    <!-- Special Schedules Panel -->
    <?php if (!empty($special_schedules)): ?>
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon" style="background:var(--db-teal-light);color:var(--db-teal)"><i class="fas fa-calendar-alt"></i></span>
                <h2>Special Schedules</h2>
            </div>
            <span class="db-badge db-badge--muted"><?php echo count($special_schedules); ?> upcoming</span>
        </div>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Type</th><th>Notes</th><th>Created By</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($special_schedules as $ss): ?>
                <tr>
                    <td><span class="db-id"><?php echo date('M d, Y', strtotime($ss['schedule_date'])); ?></span></td>
                    <td><span class="db-text-sm"><?php echo date('h:i A', strtotime($ss['time_in'])); ?></span></td>
                    <td><span class="db-text-sm"><?php echo date('h:i A', strtotime($ss['time_out'])); ?></span></td>
                    <td><span class="db-badge db-badge--info"><?php echo htmlspecialchars($ss['schedule_type']); ?></span></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($ss['notes'] ?? '—'); ?></span></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($ss['created_by_name'] ?? 'System'); ?></span></td>
                    <td>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this special schedule?')">
                            <input type="hidden" name="delete_special_schedule" value="1">
                            <input type="hidden" name="special_schedule_id" value="<?php echo $ss['special_schedule_id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">
                            <button type="submit" class="db-icon-btn db-icon-btn--danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Empty state -->
    <div class="db-panel">
        <div class="db-empty">
            <i class="fas fa-users"></i>
            <p>Select a staff member from the dropdown above to view and manage their duty schedule.</p>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /ds-page -->


<!-- ── MODAL: Apply Template ── -->
<div id="templateModal" class="db-modal">
    <div class="db-modal__box" style="max-width:620px">
        <div class="db-modal__header">
            <h3><i class="fas fa-clone"></i> Apply Schedule Template</h3>
            <button class="db-modal__close" onclick="closeModal('templateModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="apply_template" value="1">

            <div class="db-alert db-alert--success" style="margin-bottom:16px">
                <div class="db-alert__icon"><i class="fas fa-info-circle"></i></div>
                <span>Select a template and staff members to apply the schedule in bulk.</span>
            </div>

            <div class="db-field">
                <label>Template <span class="req">*</span></label>
                <select name="template_id" id="tmpl_select" class="db-input" required onchange="showTemplatePreview()">
                    <option value="">— Choose a template —</option>
                    <?php foreach ($templates as $t): ?>
                    <option value="<?php echo $t['template_id']; ?>" data-template='<?php echo htmlspecialchars(json_encode($t)); ?>'>
                        <?php echo htmlspecialchars($t['template_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small id="tmpl_desc" class="ps-field-hint"></small>
            </div>

            <!-- Preview -->
            <div id="tmpl_preview" class="db-panel" style="display:none;margin-bottom:16px">
                <div class="db-panel__header" style="padding:12px 16px">
                    <div class="db-panel__title">
                        <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-eye"></i></span>
                        <h2 style="font-size:13px">Template Preview</h2>
                    </div>
                </div>
                <div id="tmpl_preview_content" class="db-table-wrap"></div>
            </div>

            <div class="db-field">
                <label>Select Staff Members <span class="req">*</span></label>
                <div style="margin-bottom:8px">
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;cursor:pointer">
                        <input type="checkbox" id="selectAllTmpl" onchange="toggleSelectAll()" style="accent-color:var(--db-navy-light);width:15px;height:15px">
                        Select All
                    </label>
                </div>
                <div style="max-height:280px;overflow-y:auto;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);padding:8px">
                    <?php
                    $mg = '';
                    foreach ($users as $u):
                        $si = strtoupper(substr($u['full_name'] ?? $u['username'], 0, 1));
                        $sb = $avatarColors[ord($si) % count($avatarColors)];
                        if ($u['role'] !== $mg) {
                            if ($mg) echo '</div>';
                            $mg = $u['role'];
                            echo '<div style="font-size:10px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.6px;font-family:DM Mono,monospace;padding:6px 4px 2px">'.$mg.'</div>';
                            echo '<div style="margin-bottom:8px;padding-left:4px">';
                        }
                    ?>
                        <label style="display:flex;align-items:center;gap:10px;padding:7px 8px;border-radius:var(--db-radius-sm);cursor:pointer;transition:background .12s" onmouseover="this.style.background='var(--db-surf2)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="selected_users[]" value="<?php echo $u['user_id']; ?>" class="tmpl-cb" style="accent-color:var(--db-navy-light);width:15px;height:15px;flex-shrink:0">
                            <div style="width:30px;height:30px;border-radius:7px;background:<?php echo $sb; ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;overflow:hidden;flex-shrink:0">
                                <?php if (!empty($u['profile_photo'])): ?>
                                    <img src="/barangaylink1/uploads/profiles/<?php echo htmlspecialchars($u['profile_photo']); ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.innerHTML='<?php echo $si; ?>'">
                                <?php else: echo $si; endif; ?>
                            </div>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:var(--db-text)"><?php echo htmlspecialchars($u['full_name'] ?? $u['username']); ?></div>
                                <div style="font-size:10.5px;color:var(--db-muted)"><?php echo htmlspecialchars($u['role']); ?></div>
                            </div>
                        </label>
                    <?php endforeach; if ($mg) echo '</div>'; ?>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:6px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('templateModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Apply Template</button>
            </div>
        </form>
    </div>
</div>


<!-- ── MODAL: Special Schedule ── -->
<?php if ($selected_user): ?>
<div id="specialScheduleModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header" style="background:linear-gradient(135deg,var(--db-teal),#0f766e)">
            <h3><i class="fas fa-calendar-plus"></i> Add Special Schedule</h3>
            <button class="db-modal__close" onclick="closeModal('specialScheduleModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="add_special_schedule" value="1">
            <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">

            <div class="db-alert db-alert--success" style="margin-bottom:16px">
                <div class="db-alert__icon"><i class="fas fa-info-circle"></i></div>
                <span>Special schedules override the regular weekly schedule for a specific date.</span>
            </div>

            <div class="db-field">
                <label>Date <span class="req">*</span></label>
                <input type="date" name="schedule_date" class="db-input" min="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="db-field-row">
                <div class="db-field">
                    <label>Time In <span class="req">*</span></label>
                    <input type="time" name="time_in" class="db-input" required>
                </div>
                <div class="db-field">
                    <label>Time Out <span class="req">*</span></label>
                    <input type="time" name="time_out" class="db-input" required>
                </div>
            </div>
            <div class="db-field">
                <label>Type <span class="req">*</span></label>
                <select name="schedule_type" class="db-input" required>
                    <option value="">— Select type —</option>
                    <option value="Overtime">Overtime</option>
                    <option value="Special Event">Special Event</option>
                    <option value="Coverage">Coverage</option>
                    <option value="Training">Training</option>
                    <option value="Meeting">Meeting</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="db-field">
                <label>Notes</label>
                <textarea name="notes" class="db-input" rows="3" placeholder="Optional notes…"></textarea>
            </div>
            <div style="display:flex;gap:10px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('specialScheduleModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Add Schedule</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<script>
// ── Modal helpers ──
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

// ── Hours calc ──
function calcHours(day) {
    const ti = document.querySelector(`input[name="days[${day}][time_in]"]`).value;
    const to = document.querySelector(`input[name="days[${day}][time_out]"]`).value;
    const el = document.getElementById('hrs_'+day);
    if (ti && to) {
        const [ih,im]=ti.split(':').map(Number), [oh,om]=to.split(':').map(Number);
        let diff = (oh*60+om)-(ih*60+im); if(diff<0) diff+=1440;
        el.textContent = (diff/60).toFixed(1)+' hrs';
        el.className = 'db-badge db-badge--info';
    } else { el.textContent='—'; el.className='db-badge db-badge--muted'; }
}

function applyPreset(ti, to, type) {
    const all=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    const wd =['Monday','Tuesday','Wednesday','Thursday','Friday'];
    (type==='weekday'?wd:all).forEach(d=>{
        document.querySelector(`input[name="days[${d}][time_in]"]`).value=ti;
        document.querySelector(`input[name="days[${d}][time_out]"]`).value=to;
        calcHours(d);
    });
}

function clearAll() {
    if (!confirm('Clear all schedules?')) return;
    ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].forEach(d=>{
        document.querySelector(`input[name="days[${d}][time_in]"]`).value='';
        document.querySelector(`input[name="days[${d}][time_out]"]`).value='';
        calcHours(d);
    });
}

// ── Template preview ──
function fmtTime(t){ if(!t)return'—'; const [h,m]=t.split(':'); const ap=h>=12?'PM':'AM'; return `${h%12||12}:${m} ${ap}`; }

function showTemplatePreview() {
    const sel = document.getElementById('tmpl_select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) { document.getElementById('tmpl_preview').style.display='none'; document.getElementById('tmpl_desc').textContent=''; return; }
    const t = JSON.parse(opt.getAttribute('data-template'));
    document.getElementById('tmpl_desc').textContent = t.description || '';
    const days=[['Monday','monday'],['Tuesday','tuesday'],['Wednesday','wednesday'],['Thursday','thursday'],['Friday','friday'],['Saturday','saturday'],['Sunday','sunday']];
    let html='<table class="db-table"><thead><tr><th>Day</th><th>Time In</th><th>Time Out</th><th>Hours</th></tr></thead><tbody>';
    days.forEach(([name,key])=>{
        const ti=t[key+'_in'], to=t[key+'_out'];
        if(ti&&to){ const [ih,im]=ti.split(':').map(Number),[oh,om]=to.split(':').map(Number); let d=(oh*60+om)-(ih*60+im); if(d<0)d+=1440; html+=`<tr><td><strong>${name}</strong></td><td>${fmtTime(ti)}</td><td>${fmtTime(to)}</td><td><span class="db-badge db-badge--info">${(d/60).toFixed(1)} hrs</span></td></tr>`; }
        else html+=`<tr><td><strong>${name}</strong></td><td colspan="3" style="text-align:center"><span class="db-text-muted">Rest Day</span></td></tr>`;
    });
    html+='</tbody></table>';
    document.getElementById('tmpl_preview_content').innerHTML=html;
    document.getElementById('tmpl_preview').style.display='block';
}

// ── Select all template users ──
function toggleSelectAll() {
    const checked = document.getElementById('selectAllTmpl').checked;
    document.querySelectorAll('.tmpl-cb').forEach(cb=>cb.checked=checked);
}
document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('.tmpl-cb').forEach(cb=>{
        cb.addEventListener('change',()=>{
            const all=document.querySelectorAll('.tmpl-cb');
            const selectAll=document.getElementById('selectAllTmpl');
            const allChecked=[...all].every(c=>c.checked);
            const someChecked=[...all].some(c=>c.checked);
            selectAll.checked=allChecked;
            selectAll.indeterminate=someChecked&&!allChecked;
        });
    });
});

// ── Auto-dismiss alerts ──
setTimeout(()=>{ document.querySelectorAll('.db-alert').forEach(a=>{ a.style.opacity='0'; a.style.transform='translateY(-8px)'; setTimeout(()=>a.remove(),400); }); }, 5000);
</script>

<?php include '../../../includes/footer.php'; ?>