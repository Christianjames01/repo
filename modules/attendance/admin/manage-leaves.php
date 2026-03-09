<?php
/**
 * Admin/Super Admin Manage Leave Requests — Restyled to match Dashboard UI
 * modules/attendance/admin/manage-leaves.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) redirect('/barangaylink1/modules/auth/login.php', 'Please login to continue', 'error');

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) redirect('/barangaylink1/modules/dashboard/index.php', 'Access denied', 'error');

$page_title      = 'Manage Leave Requests';
$current_user_id = getCurrentUserId();

// ── Submit own leave from modal ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave_modal'])) {
    $leave_type = sanitizeInput($_POST['leave_type']);
    $start_date = sanitizeInput($_POST['start_date']);
    $end_date   = sanitizeInput($_POST['end_date']);
    $reason     = sanitizeInput($_POST['reason']);
    $errors     = [];

    if (strtotime($start_date) > strtotime($end_date))    $errors[] = 'End date must be after start date';
    if (strtotime($start_date) < strtotime(date('Y-m-d'))) $errors[] = 'Start date cannot be in the past';
    if (strlen($reason) < 10)                              $errors[] = 'Reason must be at least 10 characters long';

    if (empty($errors)) {
        $overlap = fetchOne($conn,
            "SELECT leave_id FROM tbl_leave_requests WHERE user_id=? AND status IN ('Pending','Approved')
             AND ((start_date<=? AND end_date>=?) OR (start_date<=? AND end_date>=?) OR (start_date>=? AND end_date<=?))",
            [$current_user_id,$start_date,$start_date,$end_date,$end_date,$start_date,$end_date],'issssss');

        if ($overlap) {
            $_SESSION['error_message'] = 'You already have a leave request for these dates';
        } else {
            if (executeQuery($conn,"INSERT INTO tbl_leave_requests (user_id,leave_type,start_date,end_date,reason,status,created_at) VALUES (?,?,?,?,?,'Pending',NOW())",[$current_user_id,$leave_type,$start_date,$end_date,$reason],'issss')) {
                $leave_id = $conn->insert_id;
                logActivity($conn,$current_user_id,'Submitted leave request','tbl_leave_requests',$leave_id);
                $admins = fetchAll($conn,"SELECT user_id FROM tbl_users WHERE role IN ('Admin','Super Admin') AND user_id!=?",[$current_user_id],'i');
                if ($admins) {
                    $uname = getUserFullName($conn,$current_user_id);
                    foreach ($admins as $admin) createNotification($conn,$admin['user_id'],'New Leave Request',"{$uname} submitted a {$leave_type} request from {$start_date} to {$end_date}",'leave_request',$leave_id,'leave');
                }
                $_SESSION['success_message'] = 'Leave request submitted successfully!';
                header("Location: manage-leaves.php"); exit();
            } else {
                $_SESSION['error_message'] = 'Failed to submit leave request. Please try again.';
            }
        }
    } else {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
}

// ── Approve / Reject ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['leave_id'])) {
    $leave_id    = intval($_POST['leave_id']);
    $action      = $_POST['action'];
    $admin_notes = sanitizeInput($_POST['admin_notes'] ?? '');

    if (in_array($action, ['approve','reject'])) {
        $status = ($action === 'approve') ? 'Approved' : 'Rejected';
        if (executeQuery($conn,"UPDATE tbl_leave_requests SET status=?,admin_notes=?,processed_by=?,processed_at=NOW() WHERE leave_id=?",[$status,$admin_notes,$current_user_id,$leave_id],'ssii')) {
            $leave = fetchOne($conn,"SELECT lr.*,u.user_id,u.username FROM tbl_leave_requests lr LEFT JOIN tbl_users u ON lr.user_id=u.user_id WHERE lr.leave_id=?",[$leave_id],'i');
            if ($leave) {
                createNotification($conn,$leave['user_id'],"Leave Request $status","Your {$leave['leave_type']} request from {$leave['start_date']} to {$leave['end_date']} has been $status.".($admin_notes?" Notes: $admin_notes":""),'leave_'.strtolower($status),$leave_id,'leave');
                if ($status === 'Approved') {
                    $start = new DateTime($leave['start_date']);
                    $end   = new DateTime($leave['end_date']); $end->modify('+1 day');
                    foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $date) {
                        $ad = $date->format('Y-m-d');
                        if (!fetchOne($conn,"SELECT attendance_id FROM tbl_attendance WHERE user_id=? AND attendance_date=?",[$leave['user_id'],$ad],'is'))
                            executeQuery($conn,"INSERT INTO tbl_attendance (user_id,attendance_date,status,notes,created_by) VALUES (?,?,'On Leave',?,?)",[$leave['user_id'],$ad,$leave['leave_type'],$current_user_id],'issi');
                    }
                }
            }
            logActivity($conn,$current_user_id,"{$status} leave request",'tbl_leave_requests',$leave_id);
            $_SESSION['success_message'] = "Leave request has been $status successfully";
        } else {
            $_SESSION['error_message'] = "Failed to update leave request";
        }
        header("Location: manage-leaves.php"); exit();
    }
}

// ── Query ─────────────────────────────────────────────────────────────────────
$status_filter = $_GET['status']  ?? 'all';
$user_filter   = $_GET['user_id'] ?? 'all';

$sql = "SELECT lr.leave_id,lr.user_id,lr.leave_type,lr.start_date,lr.end_date,lr.reason,lr.status,
               lr.admin_notes,lr.created_at,lr.processed_at,lr.processed_by,
               u.username,u.role,r.profile_photo,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(r.first_name,'')), ' ',TRIM(COALESCE(r.last_name,''))), ' '),u.username) as requester_name,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(pr.first_name,'')), ' ',TRIM(COALESCE(pr.last_name,''))), ' '),pu.username) as processor_name
        FROM tbl_leave_requests lr
        INNER JOIN tbl_users u ON lr.user_id=u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
        LEFT JOIN tbl_users pu ON lr.processed_by=pu.user_id
        LEFT JOIN tbl_residents pr ON pu.resident_id=pr.resident_id
        WHERE 1=1";
$params = []; $types = '';
if ($status_filter !== 'all') { $sql .= " AND lr.status=?"; $params[] = $status_filter; $types .= 's'; }
if ($user_filter   !== 'all') { $sql .= " AND lr.user_id=?"; $params[] = intval($user_filter); $types .= 'i'; }
$sql .= " ORDER BY CASE WHEN lr.status='Pending' THEN 1 WHEN lr.status='Approved' THEN 2 WHEN lr.status='Rejected' THEN 3 ELSE 4 END, lr.created_at DESC";

$leaves = [];
try {
    $leaves = !empty($params) ? fetchAll($conn,$sql,$params,$types) : fetchAll($conn,$sql);
} catch (Exception $e) {
    error_log("Leave query error: ".$e->getMessage());
}

$stats = ['pending'=>0,'approved'=>0,'rejected'=>0,'cancelled'=>0,'total'=>count($leaves)];
foreach ($leaves as $lv) {
    $k = strtolower($lv['status']);
    if (isset($stats[$k])) $stats[$k]++;
}

$all_users = fetchAll($conn,
    "SELECT u.user_id,COALESCE(NULLIF(CONCAT(TRIM(COALESCE(r.first_name,'')), ' ',TRIM(COALESCE(r.last_name,''))), ' '),u.username) as full_name,u.role
     FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
     WHERE u.role IN ('Admin','Super Admin','Staff','Tanod','Driver') ORDER BY full_name");

$avatarColors = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v='.time().'">';
include '../../../includes/header.php';
?>

<style>
/* ── Admin Manage Leaves (dashboard-matched) ── */
.aml-page { padding:0 0 40px; }

.aml-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; padding-top:6px; }
.aml-header__title { font-size:22px; font-weight:800; letter-spacing:-0.4px; display:flex; align-items:center; gap:10px; }
.aml-header__title i { color:var(--db-sky); }
.aml-header__sub { font-size:13px; color:var(--db-muted); margin-top:3px; }

/* Stats */
.aml-stats { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
.aml-stat { flex:1 1 150px; background:var(--db-surf); border-radius:var(--db-radius); padding:18px; display:flex; flex-direction:column; gap:8px; box-shadow:var(--db-shadow); border:1px solid var(--db-border); overflow:hidden; position:relative; }
.aml-stat__icon  { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:15px; }
.aml-stat__num   { font-size:26px; font-weight:800; line-height:1; letter-spacing:-1px; }
.aml-stat__label { font-size:10.5px; color:var(--db-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.aml-stat__bar   { height:3px; border-radius:2px; opacity:.4; }

/* Filter bar */
.aml-filter { background:var(--db-surf); border-radius:var(--db-radius-lg); border:1px solid var(--db-border); box-shadow:var(--db-shadow); padding:18px 24px; margin-bottom:22px; }
.aml-filter__row { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; }
.aml-filter__field { flex:1 1 180px; }
.aml-filter__field label { display:block; font-size:11px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.7px; margin-bottom:6px; font-family:'DM Mono',monospace; }
.aml-filter__field select { width:100%; padding:8px 32px 8px 12px; border:1.5px solid var(--db-border); border-radius:var(--db-radius-sm); font-family:'Sora',sans-serif; font-size:13px; color:var(--db-text); background:var(--db-surf); outline:none; transition:all .18s; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
.aml-filter__field select:focus { border-color:var(--db-navy-light); box-shadow:0 0 0 3px rgba(28,52,97,.1); }

/* Requester avatar */
.aml-avatar { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.aml-avatar img { width:100%; height:100%; object-fit:cover; }

/* Leave type pill */
.aml-type { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; background:var(--db-indigo-light); color:var(--db-indigo); font-family:'DM Mono',monospace; }

/* Role pill */
.aml-role { display:inline-block; padding:2px 9px; border-radius:20px; font-size:10.5px; font-weight:700; background:var(--db-surf2); color:var(--db-muted); font-family:'DM Mono',monospace; border:1px solid var(--db-border); }

/* Status badges */
.aml-status-pending   { background:var(--db-warning-light); color:#92400e; }
.aml-status-approved  { background:var(--db-success-light); color:#065f46; }
.aml-status-rejected  { background:var(--db-danger-light);  color:#9f1239; }
.aml-status-cancelled { background:var(--db-surf2);         color:var(--db-muted); border:1px solid var(--db-border); }

/* db-modal extras */
.aml-modal-approve { background:linear-gradient(135deg,#059669,#047857) !important; }
.aml-modal-reject  { background:linear-gradient(135deg,var(--db-danger),#b91c1c) !important; }
.aml-modal-request { background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light)) !important; }

/* Leave summary card inside modal */
.aml-leave-card { background:var(--db-surf2); border:1px solid var(--db-border); border-radius:var(--db-radius); padding:14px 16px; margin-bottom:16px; }
.aml-leave-card__row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.aml-leave-card__item { display:flex; flex-direction:column; gap:2px; }
.aml-leave-card__item label { font-size:10px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.6px; font-family:'DM Mono',monospace; }
.aml-leave-card__item span { font-size:13px; font-weight:600; color:var(--db-text); }

/* View modal detail grid */
.aml-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.aml-detail-block { background:var(--db-surf2); border-radius:var(--db-radius-sm); padding:12px 14px; }
.aml-detail-block--full { grid-column:span 2; }
.aml-detail-block label { display:block; font-size:10px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.6px; font-family:'DM Mono',monospace; margin-bottom:5px; }
.aml-detail-block .val  { font-size:13px; color:var(--db-text); font-weight:500; }
.aml-admin-note { background:var(--db-warning-light); border:1px solid #fcd34d; border-left:4px solid var(--db-amber); border-radius:var(--db-radius-sm); padding:12px 14px; font-size:13px; color:#78350f; }

/* db-field for modal forms */
.aml-field { margin-bottom:14px; }
.aml-field label { display:block; font-size:11.5px; font-weight:700; color:var(--db-text); margin-bottom:6px; }
.aml-field label .req { color:var(--db-danger); }
.aml-input { width:100%; padding:9px 13px; border:1.5px solid var(--db-border); border-radius:var(--db-radius-sm); font-family:'Sora',sans-serif; font-size:13px; color:var(--db-text); background:var(--db-surf); outline:none; transition:all .18s; }
.aml-input:focus { border-color:var(--db-navy-light); box-shadow:0 0 0 3px rgba(28,52,97,.1); }
.aml-input--select { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:32px; }
.aml-hint { font-size:11.5px; color:var(--db-muted); margin-top:4px; }
.aml-field-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

/* Duration info box */
.aml-duration-box { display:flex; align-items:center; gap:10px; background:var(--db-sky-light); border:1px solid #bae6fd; border-radius:var(--db-radius-sm); padding:10px 14px; font-size:13px; color:#075985; margin-bottom:14px; }
.aml-duration-box i { font-size:15px; }

/* Process info box */
.aml-info-box { border-radius:var(--db-radius-sm); padding:12px 14px; font-size:13px; display:flex; gap:10px; align-items:flex-start; margin-bottom:14px; }
.aml-info-box--approve { background:var(--db-success-light); border:1px solid #86efac; color:#065f46; }
.aml-info-box--reject  { background:var(--db-danger-light);  border:1px solid #fca5a5; color:#9f1239; }

/* Pending row highlight */
.aml-row-pending { background:rgba(245,158,11,.04) !important; }

@media(max-width:760px){
    .aml-stats { gap:10px; }
    .aml-stat { flex:1 1 130px; }
    .aml-detail-grid { grid-template-columns:1fr; }
    .aml-detail-block--full { grid-column:span 1; }
    .aml-field-row { grid-template-columns:1fr; }
    .aml-leave-card__row { grid-template-columns:1fr; }
}
</style>

<div class="aml-page">

    <!-- Header -->
    <div class="aml-header">
        <div>
            <div class="aml-header__title"><i class="fas fa-calendar-check"></i> Manage Leave Requests</div>
            <div class="aml-header__sub">Review and process all staff leave requests</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="../admin/index.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-arrow-left"></i> Back</a>
            <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('leaveRequestModal')">
                <i class="fas fa-calendar-plus"></i> My Leave Request
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success"><div class="db-alert__icon"><i class="fas fa-check-circle"></i></div><span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="db-alert db-alert--error"><div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div><span><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="aml-stats">
        <div class="aml-stat">
            <div class="aml-stat__icon" style="background:var(--db-warning-light);color:var(--db-amber-dark)"><i class="fas fa-clock"></i></div>
            <div class="aml-stat__num" style="color:var(--db-amber)"><?php echo $stats['pending']; ?></div>
            <div class="aml-stat__label">Pending</div>
            <div class="aml-stat__bar" style="background:linear-gradient(90deg,var(--db-amber),transparent)"></div>
        </div>
        <div class="aml-stat">
            <div class="aml-stat__icon" style="background:var(--db-success-light);color:var(--db-success)"><i class="fas fa-check-circle"></i></div>
            <div class="aml-stat__num" style="color:var(--db-success)"><?php echo $stats['approved']; ?></div>
            <div class="aml-stat__label">Approved</div>
            <div class="aml-stat__bar" style="background:linear-gradient(90deg,var(--db-success),transparent)"></div>
        </div>
        <div class="aml-stat">
            <div class="aml-stat__icon" style="background:var(--db-danger-light);color:var(--db-danger)"><i class="fas fa-times-circle"></i></div>
            <div class="aml-stat__num" style="color:var(--db-danger)"><?php echo $stats['rejected']; ?></div>
            <div class="aml-stat__label">Rejected</div>
            <div class="aml-stat__bar" style="background:linear-gradient(90deg,var(--db-danger),transparent)"></div>
        </div>
        <div class="aml-stat">
            <div class="aml-stat__icon" style="background:var(--db-surf2);color:var(--db-muted)"><i class="fas fa-ban"></i></div>
            <div class="aml-stat__num" style="color:var(--db-muted)"><?php echo $stats['cancelled']; ?></div>
            <div class="aml-stat__label">Cancelled</div>
            <div class="aml-stat__bar" style="background:linear-gradient(90deg,var(--db-muted),transparent)"></div>
        </div>
        <div class="aml-stat">
            <div class="aml-stat__icon" style="background:var(--db-indigo-light);color:var(--db-indigo)"><i class="fas fa-list-alt"></i></div>
            <div class="aml-stat__num" style="color:var(--db-indigo)"><?php echo $stats['total']; ?></div>
            <div class="aml-stat__label">Total Shown</div>
            <div class="aml-stat__bar" style="background:linear-gradient(90deg,var(--db-indigo),transparent)"></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="aml-filter">
        <form method="GET">
            <div class="aml-filter__row">
                <div class="aml-filter__field">
                    <label><i class="fas fa-traffic-light me-1"></i> Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all"       <?php echo $status_filter==='all'?'selected':''; ?>>All Status</option>
                        <option value="Pending"   <?php echo $status_filter==='Pending'?'selected':''; ?>>Pending</option>
                        <option value="Approved"  <?php echo $status_filter==='Approved'?'selected':''; ?>>Approved</option>
                        <option value="Rejected"  <?php echo $status_filter==='Rejected'?'selected':''; ?>>Rejected</option>
                        <option value="Cancelled" <?php echo $status_filter==='Cancelled'?'selected':''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="aml-filter__field" style="flex:2 1 220px">
                    <label><i class="fas fa-user me-1"></i> Staff Member</label>
                    <select name="user_id" onchange="this.form.submit()">
                        <option value="all">— All Staff —</option>
                        <?php foreach ($all_users as $u): ?>
                        <option value="<?php echo $u['user_id']; ?>" <?php echo $user_filter==$u['user_id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?> (<?php echo $u['role']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex-shrink:0;padding-bottom:0">
                    <a href="manage-leaves.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-list-alt"></i></span>
                <h2>Leave Requests</h2>
            </div>
            <span class="db-badge db-badge--muted"><?php echo count($leaves); ?> record(s)</span>
        </div>

        <?php if (!empty($leaves)): ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th>Requester</th>
                        <th>Leave Type</th>
                        <th>Period</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leaves as $lv):
                    $duration = (strtotime($lv['end_date']) - strtotime($lv['start_date'])) / 86400 + 1;
                    $initial  = strtoupper(substr($lv['requester_name'] ?? '?', 0, 1));
                    $avBg     = $avatarColors[ord($initial) % count($avatarColors)];
                    $isPending = $lv['status'] === 'Pending';
                    $statusCls = 'aml-status-'.strtolower($lv['status']);
                ?>
                <tr class="<?php echo $isPending ? 'aml-row-pending' : ''; ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="aml-avatar" style="background:<?php echo $avBg; ?>">
                                <?php if (!empty($lv['profile_photo'])): ?>
                                    <img src="/barangaylink1/uploads/profiles/<?php echo htmlspecialchars($lv['profile_photo']); ?>" alt="" onerror="this.parentNode.innerHTML='<?php echo $initial; ?>'">
                                <?php else: echo $initial; endif; ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:13px"><?php echo htmlspecialchars($lv['requester_name']); ?></div>
                                <span class="aml-role"><?php echo htmlspecialchars($lv['role']); ?></span>
                            </div>
                        </div>
                    </td>
                    <td><span class="aml-type"><?php echo htmlspecialchars($lv['leave_type']); ?></span></td>
                    <td>
                        <div style="font-family:'DM Mono',monospace;font-size:12px;font-weight:600">
                            <?php echo date('M d', strtotime($lv['start_date'])); ?> – <?php echo date('M d, Y', strtotime($lv['end_date'])); ?>
                        </div>
                    </td>
                    <td><span class="db-badge db-badge--muted"><?php echo $duration; ?> day(s)</span></td>
                    <td>
                        <span class="db-badge <?php echo $statusCls; ?>" style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">
                            <?php
                            $ico = ['Pending'=>'fa-clock','Approved'=>'fa-check-circle','Rejected'=>'fa-times-circle','Cancelled'=>'fa-ban'];
                            echo '<i class="fas '.($ico[$lv['status']]??'fa-circle').' me-1"></i>'.htmlspecialchars($lv['status']);
                            ?>
                        </span>
                    </td>
                    <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($lv['created_at'])); ?></span></td>
                    <td>
                        <div class="db-btn-group">
                            <button class="db-icon-btn db-icon-btn--info" onclick='openViewModal(<?php echo json_encode($lv); ?>)' title="View Details"><i class="fas fa-eye"></i></button>
                            <?php if ($isPending): ?>
                            <button class="db-icon-btn" style="color:var(--db-success)" onclick='openProcessModal(<?php echo json_encode($lv); ?>,"approve")' title="Approve"><i class="fas fa-check"></i></button>
                            <button class="db-icon-btn db-icon-btn--danger" onclick='openProcessModal(<?php echo json_encode($lv); ?>,"reject")' title="Reject"><i class="fas fa-times"></i></button>
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
            <i class="fas fa-calendar-check"></i>
            <p>No leave requests found for the selected filters.</p>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /aml-page -->


<!-- ── MODAL: View Leave Details ── -->
<div id="viewLeaveModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-file-alt"></i> Leave Request Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewLeaveModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div id="viewLeaveContent"></div>
            <div style="margin-top:18px">
                <button class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('viewLeaveModal')">Close</button>
            </div>
        </div>
    </div>
</div>


<!-- ── MODAL: Process Leave (Approve / Reject) ── -->
<div id="processLeaveModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header" id="processModalHeader">
            <h3 id="processModalTitle"><i class="fas fa-check-circle"></i> Process Leave</h3>
            <button class="db-modal__close" onclick="closeModal('processLeaveModal')">×</button>
        </div>
        <form method="POST" id="processLeaveForm" class="db-modal__body">
            <input type="hidden" name="leave_id"   id="processLeaveId">
            <input type="hidden" name="action"     id="processAction">

            <!-- Leave summary -->
            <div class="aml-leave-card" id="processLeaveSummary"></div>

            <!-- Info box -->
            <div class="aml-info-box" id="processInfoBox">
                <i class="fas fa-info-circle" style="margin-top:1px"></i>
                <span id="processInfoText"></span>
            </div>

            <!-- Admin notes -->
            <div class="aml-field">
                <label>
                    Admin Notes
                    <span class="req" id="notesReqIndicator" style="display:none"> *</span>
                </label>
                <textarea class="aml-input" name="admin_notes" id="adminNotesField" rows="4"
                          placeholder="Add notes or comments about this decision…"></textarea>
                <div class="aml-hint">This note will be visible to the staff member.</div>
            </div>

            <div style="display:flex;gap:10px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('processLeaveModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--full" id="processSubmitBtn"><i class="fas fa-check"></i> <span id="processSubmitText">Confirm</span></button>
            </div>
        </form>
    </div>
</div>


<!-- ── MODAL: My Leave Request (admin can also request) ── -->
<div id="leaveRequestModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header aml-modal-request">
            <h3><i class="fas fa-calendar-plus"></i> Submit Leave Request</h3>
            <button class="db-modal__close" onclick="closeModal('leaveRequestModal')">×</button>
        </div>
        <form method="POST" id="leaveRequestForm" class="db-modal__body">
            <input type="hidden" name="submit_leave_modal" value="1">

            <div id="leaveFormAlert"></div>

            <div class="aml-field">
                <label>Leave Type <span class="req">*</span></label>
                <select class="aml-input aml-input--select" name="leave_type" id="lr_leave_type" required>
                    <option value="">— Select leave type —</option>
                    <option value="Sick Leave">Sick Leave</option>
                    <option value="Vacation Leave">Vacation Leave</option>
                    <option value="Emergency Leave">Emergency Leave</option>
                    <option value="Personal Leave">Personal Leave</option>
                    <option value="Bereavement Leave">Bereavement Leave</option>
                    <option value="Maternity Leave">Maternity Leave</option>
                    <option value="Paternity Leave">Paternity Leave</option>
                </select>
            </div>

            <div class="aml-field-row">
                <div class="aml-field">
                    <label>Start Date <span class="req">*</span></label>
                    <input type="date" class="aml-input" name="start_date" id="lr_start_date" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="aml-field">
                    <label>End Date <span class="req">*</span></label>
                    <input type="date" class="aml-input" name="end_date" id="lr_end_date" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <div id="lr_duration_box" class="aml-duration-box" style="display:none">
                <i class="fas fa-clock"></i>
                <span>Duration: <strong id="lr_duration_text">—</strong></span>
            </div>

            <div class="aml-field">
                <label>Reason <span class="req">*</span></label>
                <textarea class="aml-input" name="reason" id="lr_reason" rows="4" required minlength="10"
                          placeholder="Please provide detailed reason for your leave request…"></textarea>
                <div class="aml-hint"><i class="fas fa-info-circle me-1"></i>Minimum 10 characters</div>
            </div>

            <!-- Reminders box -->
            <div style="background:var(--db-warning-light);border:1px solid #fcd34d;border-radius:var(--db-radius-sm);padding:12px 14px;font-size:12.5px;color:#78350f;margin-bottom:14px">
                <strong><i class="fas fa-exclamation-triangle me-1"></i>Reminders:</strong>
                <ul style="margin:6px 0 0 16px;padding:0;line-height:1.8">
                    <li>Submit at least 3 days in advance</li>
                    <li>For emergencies, contact your supervisor immediately</li>
                    <li>You will be notified once processed</li>
                </ul>
            </div>

            <div style="display:flex;gap:10px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('leaveRequestModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full" id="lr_submit_btn">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// ── Modal helpers ──
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

// ── Date helpers ──
function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d+'T00:00');
    return dt.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
function fmtDateTime(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return dt.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true});
}

// ── View modal ──
function openViewModal(lv) {
    const duration = Math.round((new Date(lv.end_date) - new Date(lv.start_date))/(1000*60*60*24)) + 1;
    const statusCls = {Pending:'aml-status-pending',Approved:'aml-status-approved',Rejected:'aml-status-rejected',Cancelled:'aml-status-cancelled'};
    const statusIco = {Pending:'fa-clock',Approved:'fa-check-circle',Rejected:'fa-times-circle',Cancelled:'fa-ban'};
    const avColor = '<?php echo $avatarColors[0]; ?>';

    let html = `
    <div class="aml-detail-grid">
        <div class="aml-detail-block">
            <label>Requester</label>
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:32px;height:32px;border-radius:8px;background:var(--db-navy);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;overflow:hidden;flex-shrink:0">
                    ${lv.profile_photo
                        ? `<img src="/barangaylink1/uploads/profiles/${lv.profile_photo}" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.innerHTML='${lv.requester_name?.[0]?.toUpperCase()||'?'}'">` 
                        : (lv.requester_name?.[0]?.toUpperCase()||'?')}
                </div>
                <div>
                    <div style="font-weight:700;font-size:13px">${lv.requester_name}</div>
                    <span style="font-size:10.5px;color:var(--db-muted)">${lv.role}</span>
                </div>
            </div>
        </div>
        <div class="aml-detail-block">
            <label>Status</label>
            <span class="db-badge ${statusCls[lv.status]||''}" style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">
                <i class="fas ${statusIco[lv.status]||'fa-circle'} me-1"></i>${lv.status}
            </span>
        </div>
        <div class="aml-detail-block">
            <label>Leave Type</label>
            <span class="aml-type">${lv.leave_type}</span>
        </div>
        <div class="aml-detail-block">
            <label>Duration</label>
            <span class="db-badge db-badge--muted">${duration} day(s)</span>
        </div>
        <div class="aml-detail-block">
            <label>Start Date</label>
            <span class="val">${fmtDate(lv.start_date)}</span>
        </div>
        <div class="aml-detail-block">
            <label>End Date</label>
            <span class="val">${fmtDate(lv.end_date)}</span>
        </div>
        <div class="aml-detail-block aml-detail-block--full">
            <label>Reason</label>
            <div style="font-size:13px;line-height:1.7;color:var(--db-text)">${lv.reason||'—'}</div>
        </div>`;

    if (lv.admin_notes && lv.admin_notes.trim()) {
        html += `<div class="aml-detail-block aml-detail-block--full" style="background:transparent;padding:0">
            <div class="aml-admin-note"><strong><i class="fas fa-sticky-note me-1"></i>Admin Notes</strong><br>${lv.admin_notes}</div>
        </div>`;
    }

    html += `<div class="aml-detail-block aml-detail-block--full" style="background:transparent;padding:8px 0 0;border-top:1px solid var(--db-border)">
        <div style="font-size:11.5px;color:var(--db-muted)">
            <i class="fas fa-clock me-1"></i><strong>Submitted:</strong> ${fmtDateTime(lv.created_at)}`;
    if (lv.processor_name && lv.processed_at)
        html += `<br><i class="fas fa-user-shield me-1"></i><strong>Processed by:</strong> ${lv.processor_name} · ${fmtDateTime(lv.processed_at)}`;
    html += `</div></div></div>`;

    document.getElementById('viewLeaveContent').innerHTML = html;
    openModal('viewLeaveModal');
}

// ── Process modal ──
function openProcessModal(lv, action) {
    const duration = Math.round((new Date(lv.end_date) - new Date(lv.start_date))/(1000*60*60*24)) + 1;
    document.getElementById('processLeaveId').value   = lv.leave_id;
    document.getElementById('processAction').value    = action;
    document.getElementById('adminNotesField').value  = '';

    // Summary card
    document.getElementById('processLeaveSummary').innerHTML = `
        <div class="aml-leave-card__row">
            <div class="aml-leave-card__item" style="grid-column:span 2">
                <label>Staff Member</label>
                <span style="font-weight:700">${lv.requester_name} <span style="font-weight:400;color:var(--db-muted);font-size:11px">${lv.role}</span></span>
            </div>
            <div class="aml-leave-card__item">
                <label>Leave Type</label>
                <span>${lv.leave_type}</span>
            </div>
            <div class="aml-leave-card__item">
                <label>Duration</label>
                <span>${duration} day(s)</span>
            </div>
            <div class="aml-leave-card__item" style="grid-column:span 2">
                <label>Period</label>
                <span>${fmtDate(lv.start_date)} – ${fmtDate(lv.end_date)}</span>
            </div>
        </div>`;

    const hdr = document.getElementById('processModalHeader');
    const ttl = document.getElementById('processModalTitle');
    const inf = document.getElementById('processInfoBox');
    const btn = document.getElementById('processSubmitBtn');
    const req = document.getElementById('notesReqIndicator');

    if (action === 'approve') {
        hdr.style.background = 'linear-gradient(135deg,#059669,#047857)';
        ttl.innerHTML = '<i class="fas fa-check-circle"></i> Approve Leave Request';
        inf.className = 'aml-info-box aml-info-box--approve';
        document.getElementById('processInfoText').textContent = 'Approving will automatically create "On Leave" attendance records for the specified dates.';
        btn.className = 'db-btn db-btn--full';
        btn.style.background = 'linear-gradient(135deg,#059669,#047857)';
        btn.style.color = '#fff';
        document.getElementById('processSubmitText').textContent = 'Approve Leave';
        req.style.display = 'none';
        document.getElementById('adminNotesField').required = false;
    } else {
        hdr.style.background = 'linear-gradient(135deg,var(--db-danger),#b91c1c)';
        ttl.innerHTML = '<i class="fas fa-times-circle"></i> Reject Leave Request';
        inf.className = 'aml-info-box aml-info-box--reject';
        document.getElementById('processInfoText').textContent = 'Please provide a clear reason for rejecting this leave request.';
        btn.className = 'db-btn db-btn--danger db-btn--full';
        btn.style.background = '';
        btn.style.color = '';
        document.getElementById('processSubmitText').textContent = 'Reject Leave';
        req.style.display = 'inline';
        document.getElementById('adminNotesField').required = true;
    }
    openModal('processLeaveModal');
}

document.getElementById('processLeaveForm').addEventListener('submit', function(e) {
    const action = document.getElementById('processAction').value;
    const notes  = document.getElementById('adminNotesField').value.trim();
    if (action === 'reject' && !notes) {
        e.preventDefault();
        document.getElementById('adminNotesField').focus();
        document.getElementById('adminNotesField').style.borderColor = 'var(--db-danger)';
        return false;
    }
    const msg = action === 'approve' ? 'Approve this leave request?' : 'Reject this leave request?';
    if (!confirm(msg)) { e.preventDefault(); return false; }
    const btn = document.getElementById('processSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
});

// ── Leave request form (my own) ──
document.getElementById('lr_start_date').addEventListener('change', function() {
    document.getElementById('lr_end_date').min = this.value;
    calcLrDuration();
});
document.getElementById('lr_end_date').addEventListener('change', calcLrDuration);

function calcLrDuration() {
    const sd = document.getElementById('lr_start_date').value;
    const ed = document.getElementById('lr_end_date').value;
    const box = document.getElementById('lr_duration_box');
    if (sd && ed) {
        const days = Math.round((new Date(ed)-new Date(sd))/(1000*60*60*24))+1;
        if (days > 0) {
            document.getElementById('lr_duration_text').textContent = days + ' day(s)';
            box.style.display = 'flex';
            box.style.background = days > 15 ? 'var(--db-warning-light)' : 'var(--db-sky-light)';
            box.style.borderColor = days > 15 ? '#fcd34d' : '#bae6fd';
            box.style.color = days > 15 ? '#78350f' : '#075985';
            return;
        }
    }
    box.style.display = 'none';
}

document.getElementById('leaveRequestForm').addEventListener('submit', function(e) {
    const sd     = document.getElementById('lr_start_date').value;
    const ed     = document.getElementById('lr_end_date').value;
    const reason = document.getElementById('lr_reason').value.trim();
    const type   = document.getElementById('lr_leave_type').value;
    document.getElementById('leaveFormAlert').innerHTML = '';

    if (new Date(sd) > new Date(ed)) {
        e.preventDefault();
        showLrAlert('End date must be after or equal to start date','error');
        return false;
    }
    if (reason.length < 10) {
        e.preventDefault();
        showLrAlert('Please provide a more detailed reason (at least 10 characters)','error');
        document.getElementById('lr_reason').focus();
        return false;
    }
    const days = Math.round((new Date(ed)-new Date(sd))/(1000*60*60*24))+1;
    if (!confirm(`Submit ${type} request?\nDuration: ${days} day(s)\nFrom: ${sd} to ${ed}`)) {
        e.preventDefault(); return false;
    }
    const btn = document.getElementById('lr_submit_btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
});

function showLrAlert(msg, type) {
    document.getElementById('leaveFormAlert').innerHTML =
        `<div class="db-alert db-alert--${type==='error'?'error':'success'}" style="margin-bottom:12px">
            <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
            <span>${msg}</span>
            <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
         </div>`;
}

// Reset leave form on close
document.getElementById('leaveRequestModal').addEventListener('click', function(e) {
    if (e.target === this) {
        document.getElementById('leaveRequestForm').reset();
        document.getElementById('lr_duration_box').style.display = 'none';
        document.getElementById('leaveFormAlert').innerHTML = '';
        document.getElementById('lr_submit_btn').disabled = false;
        document.getElementById('lr_submit_btn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    }
});

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php include '../../../includes/footer.php'; ?>