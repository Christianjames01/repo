<?php
/**
 * Special Schedule Management — Restyled to match Dashboard UI
 * modules/attendance/admin/special-schedule.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');

$page_title      = 'Special Schedule Management';
$current_user_id = getCurrentUserId();

// ── POST handlers (logic unchanged) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule'])) {
    $schedule_name  = sanitizeInput($_POST['schedule_name']);
    $schedule_type  = sanitizeInput($_POST['schedule_type']);
    $schedule_date  = sanitizeInput($_POST['schedule_date']);
    $description    = sanitizeInput($_POST['description']);
    $is_working_day = isset($_POST['is_working_day']) ? 1 : 0;
    $custom_time_in = !empty($_POST['custom_time_in'])  ? sanitizeInput($_POST['custom_time_in'])  : null;
    $custom_time_out= !empty($_POST['custom_time_out']) ? sanitizeInput($_POST['custom_time_out']) : null;
    if (executeQuery($conn,"INSERT INTO tbl_special_schedules (schedule_name,schedule_type,schedule_date,description,is_working_day,custom_time_in,custom_time_out,created_by) VALUES (?,?,?,?,?,?,?,?)",[$schedule_name,$schedule_type,$schedule_date,$description,$is_working_day,$custom_time_in,$custom_time_out,$current_user_id],'ssssissi')) {
        logActivity($conn,$current_user_id,"Created special schedule: $schedule_name",'tbl_special_schedules');
        $_SESSION['success_message']='Special schedule created successfully';
    } else $_SESSION['error_message']='Failed to create special schedule';
    header("Location: special-schedule.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_staff'])) {
    $schedule_id   = intval($_POST['schedule_id']);
    $selected_users= $_POST['selected_users'] ?? [];
    $success_count = 0;
    foreach ($selected_users as $uid) {
        $uid = intval($uid);
        $ex = fetchOne($conn,"SELECT id FROM tbl_special_schedule_assignments WHERE schedule_id=? AND user_id=?",[$schedule_id,$uid],'ii');
        if (!$ex && executeQuery($conn,"INSERT INTO tbl_special_schedule_assignments (schedule_id,user_id,created_by) VALUES (?,?,?)",[$schedule_id,$uid,$current_user_id],'iii')) $success_count++;
    }
    if ($success_count > 0) { logActivity($conn,$current_user_id,"Assigned $success_count staff to special schedule",'tbl_special_schedule_assignments'); $_SESSION['success_message']="Successfully assigned $success_count staff member(s)"; }
    else $_SESSION['error_message']="No new assignments were created";
    header("Location: special-schedule.php"); exit();
}

if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $schedule_id = intval($_GET['delete']);
    executeQuery($conn,"DELETE FROM tbl_special_schedule_assignments WHERE schedule_id=?",[$schedule_id],'i');
    if (executeQuery($conn,"DELETE FROM tbl_special_schedules WHERE schedule_id=?",[$schedule_id],'i')) { logActivity($conn,$current_user_id,"Deleted special schedule",'tbl_special_schedules',$schedule_id); $_SESSION['success_message']='Special schedule deleted successfully'; }
    else $_SESSION['error_message']='Failed to delete special schedule';
    header("Location: special-schedule.php"); exit();
}
// ─────────────────────────────────────────────────────────────────────────────

$schedules = fetchAll($conn,
    "SELECT ss.*,COUNT(DISTINCT ssa.user_id) as assigned_count,CONCAT(cr.first_name,' ',cr.last_name) as created_by_name
     FROM tbl_special_schedules ss
     LEFT JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id=ssa.schedule_id
     LEFT JOIN tbl_users cu ON ss.created_by=cu.user_id
     LEFT JOIN tbl_residents cr ON cu.resident_id=cr.resident_id
     GROUP BY ss.schedule_id ORDER BY ss.schedule_date DESC");

// Fetch assigned staff details (with photos) for each schedule
$assigned_staff_map = [];
if (!empty($schedules)) {
    $schedule_ids = implode(',', array_map(fn($s) => intval($s['schedule_id']), $schedules));
    $assigned_rows = fetchAll($conn,
        "SELECT ssa.schedule_id,
                u.user_id,
                CONCAT(r.first_name,' ',r.last_name) as full_name,
                u.role,
                r.profile_photo
         FROM tbl_special_schedule_assignments ssa
         LEFT JOIN tbl_users u ON ssa.user_id=u.user_id
         LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
         WHERE ssa.schedule_id IN ($schedule_ids)
         ORDER BY r.last_name");
    foreach ($assigned_rows as $ar) {
        $assigned_staff_map[$ar['schedule_id']][] = $ar;
    }
}

$staff = fetchAll($conn,
    "SELECT u.user_id,u.username,u.role,CONCAT(r.first_name,' ',r.last_name) as full_name,r.profile_photo
     FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
     WHERE u.is_active=1 AND u.role IN ('Admin','Staff','Tanod','Driver')
     ORDER BY u.role,r.last_name");

$avatarColors = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<style>
/* ── Special Schedule page (dashboard-matched) ── */
.ss-page { padding:0 0 40px; }

.ss-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; padding-top:6px; }
.ss-header__title { font-size:22px; font-weight:800; letter-spacing:-0.4px; display:flex; align-items:center; gap:10px; }
.ss-header__title i { color:var(--db-indigo); }
.ss-header__sub { font-size:13px; color:var(--db-muted); margin-top:3px; }

/* Type cards */
.ss-type-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:22px; }
.ss-type-card { flex:1 1 130px; background:var(--db-surf); border-radius:var(--db-radius); padding:18px 16px; text-align:center; box-shadow:var(--db-shadow); border:1px solid var(--db-border); transition:transform .2s, box-shadow .2s; }
.ss-type-card:hover { transform:translateY(-3px); box-shadow:var(--db-shadow-lg); }
.ss-type-card__icon { font-size:28px; margin-bottom:10px; }
.ss-type-card__label { font-size:13px; font-weight:700; margin-bottom:4px; }
.ss-type-card__sub { font-size:11px; color:var(--db-muted); }

/* Staff mini avatar */
.ss-avatar { width:30px; height:30px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.ss-avatar img { width:100%; height:100%; object-fit:cover; }

/* Table working-day indicator */
.ss-work-yes { display:inline-flex; align-items:center; gap:5px; }
.ss-work-no  { display:inline-flex; align-items:center; gap:5px; }

/* Today row highlight */
.ss-today-row { background:#eff6ff !important; }

/* ── View modal staff list ── */
.vs-staff-list { display:flex; flex-direction:column; gap:6px; margin-top:8px; max-height:220px; overflow-y:auto; }
.vs-staff-item {
    display:flex; align-items:center; gap:10px;
    padding:8px 10px; border-radius:8px;
    background:#f8fafc; border:1px solid #e2e8f0;
}
.vs-staff-avatar {
    width:34px; height:34px; border-radius:9px;
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:800; color:#fff; flex-shrink:0; overflow:hidden;
}
.vs-staff-avatar img { width:100%; height:100%; object-fit:cover; }
.vs-staff-name { font-size:13px; font-weight:700; color:#0f172a; }
.vs-staff-role { font-size:10.5px; color:#94a3b8; font-family:'DM Mono',monospace; }
</style>

<div class="ss-page">

    <!-- Header -->
    <div class="ss-header">
        <div>
            <div class="ss-header__title"><i class="fas fa-calendar-alt"></i> Special Schedule Management</div>
            <div class="ss-header__sub">Manage holidays, events, and custom duty schedules</div>
        </div>
        <div style="display:flex;gap:8px">
            <a href="index.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-arrow-left"></i> Back</a>
            <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('createScheduleModal')"><i class="fas fa-plus"></i> Create Schedule</button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success"><div class="db-alert__icon"><i class="fas fa-check-circle"></i></div><span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="db-alert db-alert--error"><div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div><span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Type cards -->
    <div class="ss-type-row">
        <div class="ss-type-card">
            <div class="ss-type-card__icon" style="color:var(--db-danger)">🎉</div>
            <div class="ss-type-card__label">Holiday</div>
            <div class="ss-type-card__sub">Regular or special holidays</div>
        </div>
        <div class="ss-type-card">
            <div class="ss-type-card__icon" style="color:var(--db-success)">⭐</div>
            <div class="ss-type-card__label">Special Event</div>
            <div class="ss-type-card__sub">Community events, festivals</div>
        </div>
        <div class="ss-type-card">
            <div class="ss-type-card__icon" style="color:var(--db-amber)">⚠️</div>
            <div class="ss-type-card__label">Emergency</div>
            <div class="ss-type-card__sub">Disaster response</div>
        </div>
        <div class="ss-type-card">
            <div class="ss-type-card__icon" style="color:var(--db-sky)">⚙️</div>
            <div class="ss-type-card__label">Custom</div>
            <div class="ss-type-card__sub">Custom duty arrangements</div>
        </div>
    </div>

    <!-- Schedules Table -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></span>
                <h2>Special Schedules</h2>
            </div>
            <span class="db-badge db-badge--muted"><?php echo count($schedules); ?> schedule(s)</span>
        </div>

        <?php if (!empty($schedules)): ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr><th>Date</th><th>Schedule Name</th><th>Type</th><th>Status</th><th>Time</th><th>Staff</th><th>Description</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($schedules as $s):
                    $is_today = ($s['schedule_date'] === date('Y-m-d'));
                    $is_past  = strtotime($s['schedule_date']) < strtotime('today');
                    $type_icons = ['Holiday'=>'🎉','Special Event'=>'⭐','Emergency'=>'⚠️','Custom'=>'⚙️'];
                    $type_badge = ['Holiday'=>'db-badge--danger','Special Event'=>'db-badge--success','Emergency'=>'db-badge--warning','Custom'=>'db-badge--info'];
                    $desc_short = strlen($s['description']??'')>48 ? substr($s['description'],0,48).'…' : ($s['description']??'—');
                ?>
                <tr class="<?php echo $is_today?'ss-today-row':''; ?>">
                    <td>
                        <span class="db-id"><?php echo date('M d, Y', strtotime($s['schedule_date'])); ?></span>
                        <?php if ($is_today): ?><br><span class="db-badge db-badge--info" style="font-size:9px">Today</span><?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($s['schedule_name']); ?></strong><br>
                        <span class="db-text-sm">by <?php echo htmlspecialchars($s['created_by_name']??'—'); ?></span>
                    </td>
                    <td>
                        <span class="db-badge <?php echo $type_badge[$s['schedule_type']] ?? 'db-badge--muted'; ?>">
                            <?php echo ($type_icons[$s['schedule_type']]??'').' '.htmlspecialchars($s['schedule_type']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($s['is_working_day']): ?>
                            <span class="db-badge db-badge--success"><i class="fas fa-briefcase"></i> Working</span>
                        <?php else: ?>
                            <span class="db-badge db-badge--muted"><i class="fas fa-moon"></i> Non-Working</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['custom_time_in'] || $s['custom_time_out']): ?>
                            <span class="db-text-sm">
                                <?php if ($s['custom_time_in']): ?><i class="fas fa-sign-in-alt" style="color:var(--db-success)"></i> <?php echo date('h:i A',strtotime($s['custom_time_in'])); ?><br><?php endif; ?>
                                <?php if ($s['custom_time_out']): ?><i class="fas fa-sign-out-alt" style="color:var(--db-danger)"></i> <?php echo date('h:i A',strtotime($s['custom_time_out'])); ?><?php endif; ?>
                            </span>
                        <?php else: echo '<span class="db-text-muted">—</span>'; endif; ?>
                    </td>
                    <td>
                        <?php
                        $sid = $s['schedule_id'];
                        $assigned = $assigned_staff_map[$sid] ?? [];
                        $show_max = 4;
                        if (!empty($assigned)):
                        ?>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="display:flex;align-items:center">
                                <?php foreach (array_slice($assigned, 0, $show_max) as $idx => $am):
                                    $ai = strtoupper(substr($am['full_name']??'?', 0, 1));
                                    $ab = $avatarColors[ord($ai) % count($avatarColors)];
                                ?>
                                <div title="<?php echo htmlspecialchars($am['full_name']??''); ?>"
                                     style="width:28px;height:28px;border-radius:7px;background:<?php echo $ab; ?>;border:2px solid #fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;overflow:hidden;margin-left:<?php echo $idx>0?'-8px':'0'; ?>;position:relative;z-index:<?php echo $show_max-$idx; ?>">
                                    <?php if (!empty($am['profile_photo'])): ?>
                                        <img src="/barangaylink1/uploads/profiles/<?php echo htmlspecialchars($am['profile_photo']); ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.innerHTML='<?php echo $ai; ?>'">
                                    <?php else: echo $ai; endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (count($assigned) > $show_max): ?>
                                <div style="width:28px;height:28px;border-radius:7px;background:var(--db-surf2);border:2px solid #fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--db-muted);margin-left:-8px;z-index:0">
                                    +<?php echo count($assigned)-$show_max; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:11px;color:var(--db-muted);font-family:'DM Mono',monospace"><?php echo count($assigned); ?></span>
                        </div>
                        <?php else: ?>
                        <span class="db-badge db-badge--muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($desc_short); ?></span></td>
                    <td>
                        <div class="db-btn-group">
                            <button class="db-icon-btn db-icon-btn--info"
                                    onclick='viewSchedule(<?php echo json_encode($s); ?>, <?php echo json_encode($assigned_staff_map[$s['schedule_id']] ?? []); ?>)'
                                    title="View"><i class="fas fa-eye"></i></button>
                            <button class="db-icon-btn" onclick="assignStaff(<?php echo $s['schedule_id']; ?>,'<?php echo htmlspecialchars($s['schedule_name']); ?>')" title="Assign Staff" style="color:var(--db-success)"><i class="fas fa-user-plus"></i></button>
                            <?php if (!$is_past): ?>
                            <button class="db-icon-btn db-icon-btn--danger" onclick="confirmDelete(<?php echo $s['schedule_id']; ?>,'<?php echo htmlspecialchars($s['schedule_name']); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="db-empty">
            <i class="fas fa-calendar-plus"></i>
            <p>No special schedules created yet. Click "Create Schedule" to add one.</p>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /ss-page -->


<!-- ── MODAL: Create Schedule ── -->
<div id="createScheduleModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-calendar-plus"></i> Create Special Schedule</h3>
            <button class="db-modal__close" onclick="closeModal('createScheduleModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="create_schedule" value="1">
            <div class="db-field-row">
                <div class="db-field" style="flex:2">
                    <label>Schedule Name <span class="req">*</span></label>
                    <input type="text" name="schedule_name" class="db-input" placeholder="e.g., Christmas Day" required>
                </div>
                <div class="db-field">
                    <label>Date <span class="req">*</span></label>
                    <input type="date" name="schedule_date" class="db-input" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            <div class="db-field-row">
                <div class="db-field">
                    <label>Type <span class="req">*</span></label>
                    <select name="schedule_type" class="db-input" required>
                        <option value="">— Select —</option>
                        <option value="Holiday">🎉 Holiday</option>
                        <option value="Special Event">⭐ Special Event</option>
                        <option value="Emergency">⚠️ Emergency</option>
                        <option value="Custom">⚙️ Custom</option>
                    </select>
                </div>
                <div class="db-field">
                    <label>Work Status</label>
                    <label style="display:flex;align-items:center;gap:8px;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);cursor:pointer;font-size:13px">
                        <input type="checkbox" id="is_working_day" name="is_working_day" onchange="toggleCustomTimes()" style="accent-color:var(--db-navy-light);width:15px;height:15px">
                        This is a working day
                    </label>
                    <small class="db-text-sm">Check if staff will work</small>
                </div>
            </div>
            <div class="db-field">
                <label>Description</label>
                <textarea name="description" class="db-input" rows="2" placeholder="Add details…"></textarea>
            </div>
            <div id="custom_times_section" style="display:none">
                <div class="db-panel" style="margin-bottom:14px;border-color:var(--db-navy-light)">
                    <div class="db-panel__header" style="padding:12px 16px;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light))">
                        <div class="db-panel__title">
                            <span class="db-panel__icon" style="background:rgba(255,255,255,.12);color:#fff"><i class="fas fa-clock"></i></span>
                            <h2 style="color:#fff;font-size:13px">Custom Duty Hours (Optional)</h2>
                        </div>
                    </div>
                    <div style="padding:14px 16px">
                        <div class="db-field-row">
                            <div class="db-field">
                                <label>Time In</label>
                                <input type="time" name="custom_time_in" class="db-input">
                            </div>
                            <div class="db-field">
                                <label>Time Out</label>
                                <input type="time" name="custom_time_out" class="db-input">
                            </div>
                        </div>
                        <small class="db-text-sm">Leave blank to use regular duty hours</small>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('createScheduleModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Create Schedule</button>
            </div>
        </form>
    </div>
</div>


<!-- ── MODAL: Assign Staff ── -->
<div id="assignStaffModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header" style="background:linear-gradient(135deg,var(--db-teal),#0f766e)">
            <h3><i class="fas fa-user-plus"></i> Assign Staff to Schedule</h3>
            <button class="db-modal__close" onclick="closeModal('assignStaffModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="assign_staff" value="1">
            <input type="hidden" name="schedule_id" id="assign_schedule_id">
            <div style="background:var(--db-teal-light);border:1px solid #99f6e4;border-radius:var(--db-radius-sm);padding:10px 14px;margin-bottom:16px;font-size:13px;color:#065f46">
                <strong>Schedule:</strong> <span id="assign_schedule_name_display"></span>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:10px">
                <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="selectAllStaff(true)"><i class="fas fa-check-square"></i> Select All</button>
                <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="selectAllStaff(false)"><i class="fas fa-square"></i> Deselect All</button>
            </div>
            <div style="max-height:320px;overflow-y:auto;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);padding:8px">
                <?php
                $avatarColors = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];
                foreach ($staff as $m):
                    $si = strtoupper(substr($m['full_name']??$m['username'],0,1));
                    $sb = $avatarColors[ord($si)%count($avatarColors)];
                ?>
                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--db-radius-sm);cursor:pointer;transition:background .12s" onmouseover="this.style.background='var(--db-surf2)'" onmouseout="this.style.background='transparent'">
                    <input type="checkbox" name="selected_users[]" value="<?php echo $m['user_id']; ?>" class="staff-assign-cb" style="accent-color:var(--db-navy-light);width:15px;height:15px;flex-shrink:0">
                    <div class="ss-avatar" style="background:<?php echo $sb; ?>">
                        <?php if (!empty($m['profile_photo'])): ?><img src="/barangaylink1/uploads/profiles/<?php echo htmlspecialchars($m['profile_photo']); ?>" alt=""><?php else: echo $si; endif; ?>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:600"><?php echo htmlspecialchars($m['full_name']??$m['username']); ?></div>
                        <div style="font-size:10.5px;color:var(--db-muted)"><?php echo $m['role']; ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('assignStaffModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-check"></i> Assign Staff</button>
            </div>
        </form>
    </div>
</div>


<!-- ── MODAL: View Schedule ── -->
<div id="viewScheduleModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-info-circle"></i> Schedule Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewScheduleModal')">×</button>
        </div>
        <div class="db-modal__body" id="view_schedule_content"></div>
    </div>
</div>


<!-- ── MODAL: Delete Confirm ── -->
<div id="deleteScheduleModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteScheduleModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div style="text-align:center;margin-bottom:18px">
                <div style="width:56px;height:56px;border-radius:50%;background:var(--db-danger-light);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:22px;color:var(--db-danger)"><i class="fas fa-exclamation-triangle"></i></div>
                <div style="font-size:15px;font-weight:700">Delete this schedule?</div>
            </div>
            <div class="db-delete-target" id="delete_schedule_name_display"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This will also remove all staff assignments.</p>
            <div style="display:flex;gap:10px;margin-top:18px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteScheduleModal')">Cancel</button>
                <a href="#" id="confirm_delete_href" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</a>
            </div>
        </div>
    </div>
</div>


<script>
// ── Modal helpers ──
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function toggleCustomTimes() {
    document.getElementById('custom_times_section').style.display = document.getElementById('is_working_day').checked ? 'block' : 'none';
}

function assignStaff(id, name) {
    document.getElementById('assign_schedule_id').value = id;
    document.getElementById('assign_schedule_name_display').textContent = name;
    document.querySelectorAll('.staff-assign-cb').forEach(cb=>cb.checked=false);
    openModal('assignStaffModal');
}

function selectAllStaff(v) { document.querySelectorAll('.staff-assign-cb').forEach(cb=>cb.checked=v); }

function fmtTime(t){ if(!t)return'—'; const[h,m]=t.split(':'); const ap=h>=12?'PM':'AM'; return`${h%12||12}:${m} ${ap}`; }

const avatarColors = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];

function viewSchedule(s, staffList) {
    const workHtml = s.is_working_day
        ? '<span class="db-badge db-badge--success"><i class="fas fa-briefcase"></i> Working Day</span>'
        : '<span class="db-badge db-badge--muted"><i class="fas fa-moon"></i> Non-Working</span>';
    const timeHtml = (s.custom_time_in||s.custom_time_out)
        ? `${s.custom_time_in?'<i class="fas fa-sign-in-alt" style="color:var(--db-success)"></i> '+fmtTime(s.custom_time_in):''}${s.custom_time_out?' &nbsp; <i class="fas fa-sign-out-alt" style="color:var(--db-danger)"></i> '+fmtTime(s.custom_time_out):''}`
        : '<span class="db-text-muted">Regular hours</span>';

    // Build staff profile list
    let staffHtml = '';
    if (staffList && staffList.length > 0) {
        staffHtml = '<div class="vs-staff-list">';
        staffList.forEach(m => {
            const initial = (m.full_name||'?').charAt(0).toUpperCase();
            const color   = avatarColors[initial.charCodeAt(0) % avatarColors.length];
            const avatar  = m.profile_photo
                ? `<img src="/barangaylink1/uploads/profiles/${m.profile_photo}" alt="" onerror="this.parentNode.innerHTML='${initial}'">`
                : initial;
            staffHtml += `
                <div class="vs-staff-item">
                    <div class="vs-staff-avatar" style="background:${color}">${avatar}</div>
                    <div>
                        <div class="vs-staff-name">${m.full_name||'—'}</div>
                        <div class="vs-staff-role">${m.role||''}</div>
                    </div>
                </div>`;
        });
        staffHtml += '</div>';
    } else {
        staffHtml = '<span class="db-badge db-badge--muted">No staff assigned</span>';
    }

    document.getElementById('view_schedule_content').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div><div class="db-text-sm">Schedule Name</div><strong>${s.schedule_name}</strong></div>
            <div><div class="db-text-sm">Date</div><strong>${new Date(s.schedule_date+'T00:00').toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</strong></div>
            <div><div class="db-text-sm">Type</div><strong>${s.schedule_type}</strong></div>
            <div><div class="db-text-sm">Status</div>${workHtml}</div>
            <div style="grid-column:span 2"><div class="db-text-sm">Custom Duty Hours</div><strong>${timeHtml}</strong></div>
            <div style="grid-column:span 2"><div class="db-text-sm">Description</div><span>${s.description||'No description'}</span></div>
            <div style="grid-column:span 2">
                <div class="db-text-sm" style="margin-bottom:6px">
                    Assigned Staff
                    <span class="db-badge db-badge--info" style="margin-left:6px">${staffList ? staffList.length : 0}</span>
                </div>
                ${staffHtml}
            </div>
            <div><div class="db-text-sm">Created By</div><span>${s.created_by_name||'—'}</span></div>
        </div>
        <div style="margin-top:18px">
            <button class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('viewScheduleModal')">Close</button>
        </div>`;
    openModal('viewScheduleModal');
}

function confirmDelete(id, name) {
    document.getElementById('delete_schedule_name_display').textContent = name;
    document.getElementById('confirm_delete_href').href = `special-schedule.php?delete=${id}&confirm=1`;
    openModal('deleteScheduleModal');
}

setTimeout(()=>{ document.querySelectorAll('.db-alert').forEach(a=>{ a.style.opacity='0'; a.style.transform='translateY(-8px)'; setTimeout(()=>a.remove(),400); }); }, 5000);
</script>

<?php include '../../../includes/footer.php'; ?>