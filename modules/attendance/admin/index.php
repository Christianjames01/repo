<?php
/**
 * Admin Attendance Management — Dashboard UI Edition
 * modules/attendance/admin/index.php
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
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title        = 'Admin Attendance Management';
$current_user_id   = getCurrentUserId();
$selected_date     = isset($_GET['date'])   ? $_GET['date']   : date('Y-m-d');
$selected_role     = isset($_GET['role'])   ? $_GET['role']   : 'all';
$selected_status   = isset($_GET['status']) ? $_GET['status'] : 'all';


// ── Handle manual attendance marking ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $user_id         = intval($_POST['user_id']);
    $attendance_date = sanitizeInput($_POST['attendance_date']);
    $status          = sanitizeInput($_POST['status']);
    $time_in         = !empty($_POST['time_in'])  ? sanitizeInput($_POST['time_in'])  : null;
    $time_out        = !empty($_POST['time_out']) ? sanitizeInput($_POST['time_out']) : null;
    $notes           = !empty($_POST['notes']) ? sanitizeInput($_POST['notes']) : '';

    if ($user_id <= 0) {
        $_SESSION['error_message'] = 'Invalid staff member selected.';
        header("Location: index.php?date=" . urlencode($attendance_date ?: date('Y-m-d')));
        exit();
    }

    // Check if record exists
    $stmt = $conn->prepare("SELECT attendance_id FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?");
    $stmt->bind_param("is", $user_id, $attendance_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res->fetch_assoc();
    $stmt->close();

    $columns_check  = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'updated_by'");
    $has_updated_by = $columns_check && $columns_check->num_rows > 0;

    if ($existing) {
        if ($has_updated_by) {
            $stmt = $conn->prepare("UPDATE tbl_attendance SET status=?,time_in=?,time_out=?,notes=?,updated_by=? WHERE attendance_id=?");
            $stmt->bind_param("ssssii", $status,$time_in,$time_out,$notes,$current_user_id,$existing['attendance_id']);
            $success = $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE tbl_attendance SET status=?,time_in=?,time_out=?,notes=? WHERE attendance_id=?");
            $stmt->bind_param("ssssi", $status,$time_in,$time_out,$notes,$existing['attendance_id']);
            $success = $stmt->execute();
            $stmt->close();
        }
        $message = 'Attendance updated successfully';
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_attendance (user_id,attendance_date,status,time_in,time_out,notes,created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("isssssi", $user_id,$attendance_date,$status,$time_in,$time_out,$notes,$current_user_id);
        $success = $stmt->execute();
        $stmt->close();
        $message = 'Attendance marked successfully';
    }

    if ($success) {
        logActivity($conn, $current_user_id, "Marked attendance for user #{$user_id}: {$status} on {$attendance_date}", 'tbl_attendance', $user_id);
        $_SESSION['success_message'] = $message;
    } else {
        $_SESSION['error_message'] = 'Failed to mark attendance: ' . $conn->error;
    }
    header("Location: index.php?date=$attendance_date");
    exit();
}

// ── Handle bulk attendance marking ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_mark'])) {
    $attendance_date    = sanitizeInput($_POST['bulk_date']);
    $bulk_status        = sanitizeInput($_POST['bulk_status']);
    $selected_users     = isset($_POST['selected_users']) ? $_POST['selected_users'] : [];
    $bulk_time_in       = !empty($_POST['bulk_time_in'])  ? sanitizeInput($_POST['bulk_time_in'])  : null;
    $bulk_time_out      = !empty($_POST['bulk_time_out']) ? sanitizeInput($_POST['bulk_time_out']) : null;
    $overwrite_existing = isset($_POST['overwrite_existing']);
    $success_count = $updated_count = $skipped_count = 0;

    if (empty($selected_users)) {
        $_SESSION['error_message'] = 'No staff members selected.';
        header("Location: index.php?date=$attendance_date");
        exit();
    }

    $columns_check  = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'updated_by'");
    $has_updated_by = $columns_check && $columns_check->num_rows > 0;

    foreach ($selected_users as $uid) {
        $uid = intval($uid);
        if ($uid <= 0) continue;

        $stmt = $conn->prepare("SELECT attendance_id FROM tbl_attendance WHERE user_id=? AND attendance_date=?");
        $stmt->bind_param("is", $uid, $attendance_date);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->fetch_assoc();
        $stmt->close();

        if ($existing) {
            if ($overwrite_existing) {
                if ($has_updated_by) {
                    $stmt = $conn->prepare("UPDATE tbl_attendance SET status=?,time_in=?,time_out=?,updated_by=? WHERE attendance_id=?");
                    $stmt->bind_param("sssii", $bulk_status,$bulk_time_in,$bulk_time_out,$current_user_id,$existing['attendance_id']);
                } else {
                    $stmt = $conn->prepare("UPDATE tbl_attendance SET status=?,time_in=?,time_out=? WHERE attendance_id=?");
                    $stmt->bind_param("sssi", $bulk_status,$bulk_time_in,$bulk_time_out,$existing['attendance_id']);
                }
                if ($stmt->execute()) $updated_count++;
                $stmt->close();
            } else {
                $skipped_count++;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO tbl_attendance (user_id,attendance_date,status,time_in,time_out,created_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("issssi", $uid,$attendance_date,$bulk_status,$bulk_time_in,$bulk_time_out,$current_user_id);
            if ($stmt->execute()) $success_count++;
            $stmt->close();
        }
    }

    $messages = [];
    if ($success_count > 0) $messages[] = "Created $success_count new record" . ($success_count > 1 ? 's' : '');
    if ($updated_count > 0) $messages[] = "Updated $updated_count existing record" . ($updated_count > 1 ? 's' : '');
    if ($skipped_count > 0) $messages[] = "Skipped $skipped_count record" . ($skipped_count > 1 ? 's' : '') . " (already marked)";

    if ($success_count > 0 || $updated_count > 0) {
        $total = $success_count + $updated_count;
        logActivity($conn, $current_user_id, "Bulk marked attendance for $total users", 'tbl_attendance');
        $_SESSION['success_message'] = "Bulk attendance completed: " . implode(', ', $messages);
    } else {
        $_SESSION['error_message'] = "No records created or updated. " . ($skipped_count > 0 ? "$skipped_count staff already marked. Enable 'Overwrite Existing' to update them." : "No staff selected.");
    }
    header("Location: index.php?date=$attendance_date");
    exit();
}
// ── Fetch users ──────────────────────────────────────────────────────────────
$users_query = "SELECT u.user_id, u.username, u.role,
                CONCAT(r.first_name,' ',r.last_name) as full_name, r.profile_photo
                FROM tbl_users u
                LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                WHERE u.is_active=1 AND u.role IN ('Admin','Staff','Tanod','Driver')";

if ($selected_role !== 'all') {
    $users = fetchAll($conn, $users_query . " AND u.role=? ORDER BY u.role,r.last_name", [$selected_role], 's');
} else {
    $users = fetchAll($conn, $users_query . " ORDER BY u.role,r.last_name");
}

// ── Fetch attendance records ─────────────────────────────────────────────────
$attendance_records = [];
foreach ($users as $user) {
    $attendance = fetchOne($conn,
        "SELECT a.*, CONCAT(cr.first_name,' ',cr.last_name) as marked_by_name
         FROM tbl_attendance a
         LEFT JOIN tbl_users cu ON a.created_by=cu.user_id
         LEFT JOIN tbl_residents cr ON cu.resident_id=cr.resident_id
         WHERE a.user_id=? AND a.attendance_date=?",
        [$user['user_id'], $selected_date], 'is'
    );
    $attendance_records[$user['user_id']] = $attendance;
}

// ── Stats ────────────────────────────────────────────────────────────────────
$stats = fetchOne($conn,
    "SELECT COUNT(*) as total_marked,
     SUM(CASE WHEN status='Present'  THEN 1 ELSE 0 END) as present,
     SUM(CASE WHEN status='Late'     THEN 1 ELSE 0 END) as late,
     SUM(CASE WHEN status='Absent'   THEN 1 ELSE 0 END) as absent,
     SUM(CASE WHEN status='On Leave' THEN 1 ELSE 0 END) as on_leave
     FROM tbl_attendance WHERE attendance_date=?",
    [$selected_date], 's'
);

$total_users = count($users);
$unmarked    = $total_users - ($stats['total_marked'] ?? 0);

// Link dashboard CSS
$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- ─── Inline attendance-specific overrides ─────────────────────────────── -->
<style>
/* Attendance Status Badges */
.att-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-family: 'DM Mono', monospace;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: .3px;
    white-space: nowrap;
}
.att-badge--present  { background: #d1fae5; color: #065f46; }
.att-badge--late     { background: #fef3c7; color: #92400e; }
.att-badge--absent   { background: #fee2e2; color: #7f1d1d; }
.att-badge--leave    { background: #dbeafe; color: #1e40af; }
.att-badge--halfday  { background: #ede9fe; color: #4c1d95; }
.att-badge--unmarked { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

/* Role Badge in table */
.role-pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 20px;
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    font-weight: 500;
    letter-spacing: .4px;
}
.role-pill--admin  { background: #fee2e2; color: #991b1b; }
.role-pill--staff  { background: #fef3c7; color: #92400e; }
.role-pill--tanod  { background: #dbeafe; color: #1e40af; }
.role-pill--driver { background: #d1fae5; color: #065f46; }

/* Time display */
.time-in  { color: #10b981; font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 600; }
.time-out { color: #ef4444; font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 600; }

/* Filter Card */
.att-filter-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    padding: 18px 22px;
    margin-bottom: 20px;
}
.att-filter-card .db-input {
    width: 100%;
    padding: 9px 13px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: #0f172a;
    background: #f8fafc;
    outline: none;
    transition: all .18s;
    appearance: none;
}
.att-filter-card .db-input:focus { border-color: #1c3461; box-shadow: 0 0 0 3px rgba(28,52,97,.1); background:#fff; }
.att-filter-row { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.att-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px; }
.att-filter-group label { font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }

/* Staff avatar circle */
.staff-avatar {
    width: 34px; height: 34px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13px; color: #fff; flex-shrink: 0;
    background: linear-gradient(135deg, #0d1b36, #1c3461);
    overflow: hidden;
}
.staff-avatar img { width: 100%; height: 100%; object-fit: cover; }
.staff-info { display: flex; align-items: center; gap: 10px; }
.staff-name  { font-weight: 700; font-size: 13px; color: #0f172a; }

/* Mark button */
.btn-mark {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 7px; font-family: 'Sora', sans-serif;
    font-size: 12px; font-weight: 600; cursor: pointer; border: none;
    background: linear-gradient(135deg, #0d1b36, #1c3461);
    color: #fff; transition: all .18s ease;
}
.btn-mark:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.25); }

/* Modal overrides for attendance */
.att-modal-header {
    background: linear-gradient(135deg, #0d1b36, #1c3461);
    padding: 18px 22px;
    border-radius: 20px 20px 0 0;
    display: flex; align-items: center; justify-content: space-between;
}
.att-modal-header h3 { color: #fff; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.att-modal-close {
    background: rgba(255,255,255,.12); border: none; color: rgba(255,255,255,.85);
    width: 30px; height: 30px; border-radius: 7px; cursor: pointer;
    font-size: 18px; display: flex; align-items: center; justify-content: center;
}
.att-modal-close:hover { background: rgba(255,255,255,.22); }

.att-modal-body { padding: 22px; }

.att-staff-info-box {
    background: linear-gradient(135deg, #eff6ff, #f0fdfa);
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 14px 18px;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 20px;
}
.att-staff-info-box .big-avatar {
    width: 48px; height: 48px; border-radius: 12px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.att-staff-info-box .info-name { font-size: 16px; font-weight: 800; color: #0d1b36; }
.att-staff-info-box .info-date { font-size: 12px; color: #64748b; font-family: 'DM Mono', monospace; }

.att-field { margin-bottom: 16px; }
.att-field label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 5px; color: #0f172a; text-transform: uppercase; letter-spacing: .5px; }
.att-field .req { color: #e11d48; }
.att-input {
    width: 100%; padding: 10px 13px;
    border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 13px; color: #0f172a;
    background: #f8fafc; outline: none; transition: all .18s; appearance: none;
}
.att-input:focus { border-color: #1c3461; box-shadow: 0 0 0 3px rgba(28,52,97,.1); background: #fff; }
textarea.att-input { resize: vertical; min-height: 80px; }

.att-time-card {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 16px 18px; margin-bottom: 16px;
}
.att-time-card-header {
    font-size: 12px; font-weight: 700; color: #0d1b36;
    text-transform: uppercase; letter-spacing: .5px;
    margin-bottom: 14px; display: flex; align-items: center; gap: 6px;
}
.att-time-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.att-time-field label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; display: block; }
.att-time-input-wrap { display: flex; gap: 6px; }
.att-time-input-wrap .att-input { border-radius: 7px 0 0 7px; }
.att-now-btn {
    padding: 0 12px; border: 1.5px solid #e2e8f0; border-left: none;
    border-radius: 0 7px 7px 0; background: #fff; color: #64748b;
    font-size: 11px; font-weight: 600; cursor: pointer; white-space: nowrap;
    transition: all .15s; font-family: 'Sora', sans-serif;
}
.att-now-btn:hover { background: #0d1b36; color: #fff; border-color: #0d1b36; }
.att-now-btn--in:hover  { background: #10b981; border-color: #10b981; color: #fff; }
.att-now-btn--out:hover { background: #ef4444; border-color: #ef4444; color: #fff; }

.att-presets { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 6px; }
.att-presets-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.att-preset-btn {
    padding: 5px 12px; border: 1.5px solid #e2e8f0; border-radius: 6px;
    background: #fff; font-family: 'DM Mono', monospace;
    font-size: 10.5px; font-weight: 500; cursor: pointer; color: #64748b;
    transition: all .15s;
}
.att-preset-btn:hover { border-color: #0d1b36; color: #0d1b36; background: #f0f4ff; }

.att-hours-box {
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
    padding: 10px 14px; margin-top: 12px;
    font-size: 12px; font-weight: 600; color: #065f46; display: none;
}
.att-hours-box span { font-family: 'DM Mono', monospace; font-size: 15px; font-weight: 700; }

.att-modal-footer {
    padding: 16px 22px; border-top: 1px solid #e2e8f0;
    display: flex; gap: 10px; justify-content: flex-end; background: #f8fafc;
    border-radius: 0 0 20px 20px;
}
.att-save-btn {
    padding: 10px 28px; border-radius: 8px; border: none;
    background: linear-gradient(135deg, #0d1b36, #1c3461);
    color: #fff; font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 700; cursor: pointer; transition: all .18s;
    display: flex; align-items: center; gap: 6px;
}
.att-save-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.3); }
.att-cancel-btn {
    padding: 10px 20px; border-radius: 8px;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #64748b; font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s;
}
.att-cancel-btn:hover { border-color: #94a3b8; color: #0f172a; }

/* Status select — colour-coded */
.status-select-wrap { position: relative; }
.status-select-wrap select { padding-left: 34px !important; }
.status-select-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    font-size: 14px; pointer-events: none; z-index: 1;
}

/* Bulk modal specifics */
.att-check-card {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 14px 16px; margin-bottom: 14px;
}
.att-check-card label { display: flex; align-items: center; gap: 9px; cursor: pointer; font-weight: 600; font-size: 13px; }
.att-check-card input[type=checkbox] { width: 17px; height: 17px; accent-color: #0d1b36; }
.att-check-body { padding-top: 12px; border-top: 1px solid #e2e8f0; margin-top: 12px; display: none; }
.att-check-body.open { display: block; }

.att-selected-pill {
    display: inline-flex; align-items: center; gap: 5px;
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 20px;
    padding: 4px 12px; font-size: 12px; font-weight: 600; color: #1e40af;
    font-family: 'DM Mono', monospace;
}
.att-selected-pill .count { font-size: 16px; font-weight: 800; }

/* Overwrite check */
.overwrite-warn {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
    padding: 10px 14px; font-size: 12px; color: #92400e; display: flex; gap: 7px;
}

/* Empty state */
.att-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 48px 24px; text-align: center; gap: 8px;
}
.att-empty i { font-size: 36px; color: #e2e8f0; }
.att-empty p { font-size: 13px; color: #94a3b8; }

/* Responsive tweaks */
@media (max-width: 768px) {
    .att-time-row { grid-template-columns: 1fr; }
    .att-filter-row { flex-direction: column; }
    .db-table thead th:nth-child(7),
    .db-table tbody td:nth-child(7) { display: none; }
}
/* ── Modal base styles ── */
.db-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(13,27,54,.55);
    backdrop-filter: blur(3px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.db-modal--open {
    display: flex !important;
}
.db-modal__box {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 24px 64px rgba(13,27,54,.28);
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    animation: attModalIn .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes attModalIn {
    from { opacity: 0; transform: scale(.92) translateY(16px); }
    to   { opacity: 1; transform: scale(1)  translateY(0); }
}
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar"><i class="fas fa-user-check" style="font-size:22px;color:#fff;"></i></div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($user_role); ?>
                </div>
                <h1 class="db-hero__title">Attendance Management</h1>
                <p class="db-hero__sub">Track and manage staff attendance for your barangay</p>
            </div>
        </div>
        <div class="db-hero__right">
            <div class="db-hero__datetime">
                <div class="db-hero__date">
                    <i class="fas fa-calendar-day"></i>
                    <span><?php echo date('F j, Y', strtotime($selected_date)); ?></span>
                </div>
                <div class="db-hero__time" id="att-live-time"><?php echo date('g:i:s A'); ?></div>
            </div>
        </div>
    </div>
</div>
<!-- Clock starts here immediately after the element is rendered -->
<script>
(function(){
    function attTick(){
        var n=new Date(),h=n.getHours(),m=n.getMinutes(),s=n.getSeconds();
        var ap=h>=12?'PM':'AM'; h=h%12||12;
        var el=document.getElementById('att-live-time');
        if(el) el.textContent=h+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+' '+ap;
    }
    attTick();
    setInterval(attTick,1000);
})();
</script>

<!-- ─── ALERTS ───────────────────────────────────────────────────────────── -->
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

<!-- ─── STAT CARDS ────────────────────────────────────────────────────────── -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue">
            <i class="fas fa-users"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $total_users; ?></div>
            <div class="db-stat-card__label">Total Staff</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;"><?php echo $stats['present'] ?? 0; ?></div>
            <div class="db-stat-card__label">Present</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber">
            <i class="fas fa-clock"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo $stats['late'] ?? 0; ?></div>
            <div class="db-stat-card__label">Late</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#e11d48;"><?php echo $stats['absent'] ?? 0; ?></div>
            <div class="db-stat-card__label">Absent</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#6366f1;"><?php echo $stats['on_leave'] ?? 0; ?></div>
            <div class="db-stat-card__label">On Leave</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#f1f5f9;color:#94a3b8;">
            <i class="fas fa-question-circle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#94a3b8;"><?php echo $unmarked; ?></div>
            <div class="db-stat-card__label">Unmarked</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#94a3b8,transparent)"></div>
    </div>
</div>

<!-- ─── QUICK ACTION BUTTONS ─────────────────────────────────────────────── -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
    <a href="generate-payslip.php" class="db-btn db-btn--ghost">
        <i class="fas fa-file-invoice-dollar"></i> Generate Payslip
    </a>
    <a href="duty-schedule.php" class="db-btn db-btn--ghost">
        <i class="fas fa-calendar-week"></i> Duty Schedule
    </a>
    <a href="special-schedule.php" class="db-btn db-btn--ghost">
        <i class="fas fa-calendar-alt"></i> Special Schedules
    </a>
    <a href="manage-leaves.php" class="db-btn db-btn--ghost">
        <i class="fas fa-calendar-check"></i> Manage Leaves
    </a>
    <a href="attendance-reports.php" class="db-btn db-btn--ghost">
        <i class="fas fa-chart-bar"></i> Reports
    </a>
    <button type="button" class="db-btn db-btn--primary" onclick="openAttModal('bulkMarkModal')">
        <i class="fas fa-users"></i> Bulk Mark Attendance
    </button>
</div>

<!-- ─── MAIN ATTENDANCE PANEL ────────────────────────────────────────────── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-clipboard-list"></i></span>
            <h2>Attendance — <?php echo date('F j, Y', strtotime($selected_date)); ?></h2>
        </div>
        <div class="db-panel__actions">
            <button class="db-btn db-btn--ghost db-btn--sm" onclick="attSelectAll()">
                <i class="fas fa-check-square"></i> Select All
            </button>
            <button class="db-btn db-btn--ghost db-btn--sm" onclick="attDeselectAll()">
                <i class="fas fa-square"></i> Deselect
            </button>
        </div>
    </div>

    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th width="42">
                        <input type="checkbox" id="attSelectAllCb" onchange="attToggleAll()" style="accent-color:#f59e0b;width:15px;height:15px;">
                    </th>
                    <th>Staff Member</th>
                    <th>Role</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Marked By</th>
                    <th width="100">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $filtered_count = 0;
            foreach ($users as $user):
                $attendance = $attendance_records[$user['user_id']] ?? null;
                if ($selected_status !== 'all') {
                    if ($selected_status === 'unmarked') { if ($attendance !== null) continue; }
                    else { if ($attendance === null || $attendance['status'] !== $selected_status) continue; }
                }
                $filtered_count++;

                // Avatar initials
                $displayName = $user['full_name'] ?? $user['username'];
                $initials    = strtoupper(substr($displayName, 0, 1));

                // Role pill class
                $rolePillMap = ['Admin'=>'role-pill--admin','Staff'=>'role-pill--staff','Tanod'=>'role-pill--tanod','Driver'=>'role-pill--driver'];
                $rolePill    = $rolePillMap[$user['role']] ?? 'role-pill--staff';

                // Status badge
                $statusBadge = '';
                if ($attendance) {
                    $badgeMap = ['Present'=>'att-badge--present','Late'=>'att-badge--late','Absent'=>'att-badge--absent','On Leave'=>'att-badge--leave','Half Day'=>'att-badge--halfday'];
                    $iconMap  = ['Present'=>'fa-check-circle','Late'=>'fa-clock','Absent'=>'fa-times-circle','On Leave'=>'fa-calendar-times','Half Day'=>'fa-adjust'];
                    $bc = $badgeMap[$attendance['status']] ?? 'att-badge--unmarked';
                    $ic = $iconMap[$attendance['status']]  ?? 'fa-circle';
                    $statusBadge = "<span class='att-badge {$bc}'><i class='fas {$ic}'></i> {$attendance['status']}</span>";
                } else {
                    $statusBadge = "<span class='att-badge att-badge--unmarked'><i class='fas fa-minus-circle'></i> Unmarked</span>";
                }
            ?>
            <tr>
                <td>
                    <input type="checkbox" class="att-user-cb" value="<?php echo $user['user_id']; ?>"
                           style="accent-color:#0d1b36;width:15px;height:15px;" onchange="attUpdateCount()">
                </td>
                <td>
                    <div class="staff-info">
                        <div class="staff-avatar">
                            <?php if (!empty($user['profile_photo']) && file_exists('../../../uploads/profiles/'.$user['profile_photo'])): ?>
                                <img src="../../../uploads/profiles/<?php echo $user['profile_photo']; ?>" alt="">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="staff-name"><?php echo htmlspecialchars($displayName); ?></div>
                            <div style="font-size:11px;color:#94a3b8;font-family:'DM Mono',monospace;">#<?php echo str_pad($user['user_id'],4,'0',STR_PAD_LEFT); ?></div>
                        </div>
                    </div>
                </td>
                <td><span class="role-pill <?php echo $rolePill; ?>"><?php echo $user['role']; ?></span></td>
                <td>
                    <?php if ($attendance && $attendance['time_in']): ?>
                        <span class="time-in"><i class="fas fa-sign-in-alt me-1"></i><?php echo date('h:i A', strtotime($attendance['time_in'])); ?></span>
                    <?php else: ?>
                        <span style="color:#cbd5e1;font-size:13px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($attendance && $attendance['time_out']): ?>
                        <span class="time-out"><i class="fas fa-sign-out-alt me-1"></i><?php echo date('h:i A', strtotime($attendance['time_out'])); ?></span>
                    <?php else: ?>
                        <span style="color:#cbd5e1;font-size:13px;">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $statusBadge; ?></td>
                <td>
                    <?php if ($attendance && !empty($attendance['notes'])): ?>
                        <span style="font-size:12px;color:#64748b;" title="<?php echo htmlspecialchars($attendance['notes']); ?>">
                            <?php echo htmlspecialchars(substr($attendance['notes'],0,22).(strlen($attendance['notes'])>22?'…':'')); ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($attendance && !empty($attendance['marked_by_name'])): ?>
                        <span style="font-size:11.5px;color:#64748b;"><?php echo htmlspecialchars($attendance['marked_by_name']); ?></span>
                    <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn-mark"
                            onclick="openMarkModal(<?php echo htmlspecialchars(json_encode($user)); ?>, <?php echo htmlspecialchars(json_encode($attendance)); ?>)">
                        <i class="fas fa-edit"></i> Mark
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>

            <?php if ($filtered_count === 0): ?>
            <tr>
                <td colspan="9">
                    <div class="att-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No records found for the selected filters</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div><!-- /db-panel -->


<!-- ═══════════════════════════════════════════════════════════════════
     MARK ATTENDANCE MODAL
═══════════════════════════════════════════════════════════════════ -->
<div id="markAttModal" class="db-modal">
    <div class="db-modal__box" style="max-width:560px;">
        <form method="POST">
            <div class="att-modal-header">
                <h3><i class="fas fa-user-clock"></i> Mark Attendance</h3>
                <button type="button" class="att-modal-close" onclick="closeAttModal('markAttModal')">×</button>
            </div>

            <div class="att-modal-body">
                <input type="hidden" name="mark_attendance" value="1">
                <input type="hidden" name="user_id" id="ma_user_id">
                <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">

                <!-- Staff Info Box -->
                <div class="att-staff-info-box">
                    <div class="big-avatar" id="ma_avatar_initial">?</div>
                    <div>
                        <div class="info-name" id="ma_name_display">—</div>
                        <div class="info-date"><i class="fas fa-calendar me-1"></i><?php echo date('F j, Y', strtotime($selected_date)); ?></div>
                    </div>
                </div>

                <!-- Status -->
                <div class="att-field">
                    <label>Attendance Status <span class="req">*</span></label>
                    <div class="status-select-wrap">
                        <span class="status-select-icon" id="ma_status_icon">✓</span>
                        <select name="status" id="ma_status" class="att-input" onchange="maUpdateStatus()" required>
                            <option value="Present">✓ Present</option>
                            <option value="Late">⏰ Late</option>
                            <option value="Absent">✗ Absent</option>
                            <option value="On Leave">📅 On Leave</option>
                            <option value="Half Day">◐ Half Day</option>
                        </select>
                    </div>
                    <div id="ma_status_hint" style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Staff member was present for their full shift</div>
                </div>

                <!-- Time Card -->
                <div class="att-time-card">
                    <div class="att-time-card-header"><i class="fas fa-clock me-1" style="color:#0ea5e9;"></i> Duty Time</div>
                    <div class="att-time-row">
                        <div class="att-time-field">
                            <label><i class="fas fa-sign-in-alt" style="color:#10b981;"></i> Time In</label>
                            <div class="att-time-input-wrap">
                                <input type="time" name="time_in" id="ma_time_in" class="att-input" onchange="maCalcHours()">
                                <button type="button" class="att-now-btn att-now-btn--in" onclick="maNowIn()">Now</button>
                            </div>
                        </div>
                        <div class="att-time-field">
                            <label><i class="fas fa-sign-out-alt" style="color:#ef4444;"></i> Time Out</label>
                            <div class="att-time-input-wrap">
                                <input type="time" name="time_out" id="ma_time_out" class="att-input" onchange="maCalcHours()">
                                <button type="button" class="att-now-btn att-now-btn--out" onclick="maNowOut()">Now</button>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="att-presets-label" style="margin-top:12px;">Quick Presets</div>
                        <div class="att-presets">
                            <button type="button" class="att-preset-btn" onclick="maPreset('08:00','17:00')">8AM–5PM</button>
                            <button type="button" class="att-preset-btn" onclick="maPreset('09:00','18:00')">9AM–6PM</button>
                            <button type="button" class="att-preset-btn" onclick="maPreset('07:00','16:00')">7AM–4PM</button>
                            <button type="button" class="att-preset-btn" onclick="maPreset('10:00','19:00')">10AM–7PM</button>
                            <button type="button" class="att-preset-btn" onclick="maPreset('06:00','14:00')">6AM–2PM</button>
                        </div>
                    </div>
                    <div class="att-hours-box" id="ma_hours_box">
                        Total Duty: <span id="ma_hours_val">—</span>
                    </div>
                </div>

                <!-- Notes -->
                <div class="att-field">
                    <label>Notes <span style="color:#94a3b8;font-weight:400;">(Optional)</span></label>
                    <textarea name="notes" id="ma_notes" class="att-input" rows="3" placeholder="Any remarks or additional notes…"></textarea>
                </div>
            </div>

            <div class="att-modal-footer">
                <button type="button" class="att-cancel-btn" onclick="closeAttModal('markAttModal')">Cancel</button>
                <button type="submit" class="att-save-btn"><i class="fas fa-save"></i> Save Attendance</button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════
     BULK MARK MODAL
═══════════════════════════════════════════════════════════════════ -->
<div id="bulkMarkModal" class="db-modal">
    <div class="db-modal__box" style="max-width:560px;">
        <form method="POST" id="bulkForm">
            <div class="att-modal-header">
                <h3><i class="fas fa-users-cog"></i> Bulk Mark Attendance</h3>
                <button type="button" class="att-modal-close" onclick="closeAttModal('bulkMarkModal')">×</button>
            </div>

            <div class="att-modal-body">
                <input type="hidden" name="bulk_mark" value="1">
                <input type="hidden" name="bulk_date" value="<?php echo $selected_date; ?>">

                <!-- Instructions -->
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:12.5px;color:#1e40af;display:flex;gap:8px;align-items:flex-start;">
                    <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;"></i>
                    <span>Select staff from the main table first, then choose their status here. This will mark attendance for all selected staff at once.</span>
                </div>

                <!-- Selected Count -->
                <div style="margin-bottom:16px;">
                    <div class="att-selected-pill">
                        <i class="fas fa-users"></i>
                        <span class="count" id="bulk_count">0</span> staff selected
                    </div>
                </div>

                <!-- Status -->
                <div class="att-field">
                    <label>Attendance Status <span class="req">*</span></label>
                    <select name="bulk_status" class="att-input" required>
                        <option value="Present">✓ Present</option>
                        <option value="Late">⏰ Late</option>
                        <option value="Absent">✗ Absent</option>
                        <option value="On Leave">📅 On Leave</option>
                    </select>
                </div>

                <!-- Time Toggle -->
                <div class="att-check-card">
                    <label>
                        <input type="checkbox" id="bulk_times_toggle" onchange="toggleBulkTimes()">
                        <span><i class="fas fa-clock me-1" style="color:#0ea5e9;"></i> Set Time In/Out for all selected staff</span>
                    </label>
                    <div class="att-check-body" id="bulk_times_body">
                        <div class="att-time-row" style="margin-top:4px;">
                            <div class="att-time-field">
                                <label><i class="fas fa-sign-in-alt" style="color:#10b981;"></i> Time In</label>
                                <div class="att-time-input-wrap">
                                    <input type="time" name="bulk_time_in" id="bulk_time_in" class="att-input">
                                    <button type="button" class="att-now-btn att-now-btn--in" onclick="bulkNowIn()">Now</button>
                                </div>
                            </div>
                            <div class="att-time-field">
                                <label><i class="fas fa-sign-out-alt" style="color:#ef4444;"></i> Time Out</label>
                                <div class="att-time-input-wrap">
                                    <input type="time" name="bulk_time_out" id="bulk_time_out" class="att-input">
                                    <button type="button" class="att-now-btn att-now-btn--out" onclick="bulkNowOut()">Now</button>
                                </div>
                            </div>
                        </div>
                        <div class="att-presets" style="margin-top:10px;">
                            <button type="button" class="att-preset-btn" onclick="bulkPreset('08:00','17:00')">8AM–5PM</button>
                            <button type="button" class="att-preset-btn" onclick="bulkPreset('09:00','18:00')">9AM–6PM</button>
                            <button type="button" class="att-preset-btn" onclick="bulkPreset('07:00','16:00')">7AM–4PM</button>
                        </div>
                    </div>
                </div>

                <!-- Overwrite Existing -->
                <div class="att-check-card">
                    <label>
                        <input type="checkbox" name="overwrite_existing">
                        <span><i class="fas fa-redo me-1" style="color:#f59e0b;"></i> Overwrite existing records</span>
                    </label>
                </div>

                <!-- Warning -->
                <div class="overwrite-warn">
                    <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                    <span>By default, staff who already have attendance marked for this date will be skipped. Enable overwrite to update them.</span>
                </div>
            </div>

            <div class="att-modal-footer">
                <button type="button" class="att-cancel-btn" onclick="closeAttModal('bulkMarkModal')">Cancel</button>
                <button type="button" class="att-save-btn" onclick="submitBulk()">
                    <i class="fas fa-check"></i> Mark Attendance
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ─── SCRIPTS ───────────────────────────────────────────────────────────── -->
<script>
/* ── Live clock — window.onload ensures this fires after ALL scripts including Bootstrap ── */
window.addEventListener('load', function(){
    function attTick(){
        var n=new Date(), h=n.getHours(), m=n.getMinutes(), s=n.getSeconds();
        var ap=h>=12?'PM':'AM'; h=h%12||12;
        var el=document.getElementById('att-live-time');
        if(el) el.textContent=h+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+' '+ap;
    }
    attTick();
    setInterval(attTick, 1000);
});

/* ── Modal helpers ──────────────────────────────────────────────────────── */
function openAttModal(id){
    document.getElementById(id).classList.add('db-modal--open');
    document.body.style.overflow='hidden';
}
function closeAttModal(id){
    document.getElementById(id).classList.remove('db-modal--open');
    document.body.style.overflow='';
}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeAttModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeAttModal(m.id));});

/* ── Checkbox helpers ───────────────────────────────────────────────────── */
function attSelectAll(){ document.querySelectorAll('.att-user-cb').forEach(c=>c.checked=true); document.getElementById('attSelectAllCb').checked=true; attUpdateCount(); }
function attDeselectAll(){ document.querySelectorAll('.att-user-cb').forEach(c=>c.checked=false); document.getElementById('attSelectAllCb').checked=false; attUpdateCount(); }
function attToggleAll(){ const v=document.getElementById('attSelectAllCb').checked; document.querySelectorAll('.att-user-cb').forEach(c=>c.checked=v); attUpdateCount(); }
function attUpdateCount(){ document.getElementById('bulk_count').textContent=document.querySelectorAll('.att-user-cb:checked').length; }

/* ── Mark Attendance Modal ──────────────────────────────────────────────── */
function openMarkModal(user, attendance){
    document.getElementById('ma_user_id').value   = user.user_id;
    document.getElementById('ma_name_display').textContent = user.full_name || user.username;
    document.getElementById('ma_avatar_initial').textContent = (user.full_name||user.username).charAt(0).toUpperCase();

    if(attendance){
        document.getElementById('ma_status').value  = attendance.status;
        document.getElementById('ma_time_in').value = attendance.time_in  ? attendance.time_in.substring(0,5)  : '';
        document.getElementById('ma_time_out').value= attendance.time_out ? attendance.time_out.substring(0,5) : '';
        document.getElementById('ma_notes').value   = attendance.notes || '';
    } else {
        document.getElementById('ma_status').value   = 'Present';
        document.getElementById('ma_time_in').value  = '';
        document.getElementById('ma_time_out').value = '';
        document.getElementById('ma_notes').value    = '';
    }
    maUpdateStatus();
    maCalcHours();
    openAttModal('markAttModal');
}

function maUpdateStatus(){
    const s=document.getElementById('ma_status').value;
    const hints={Present:'Staff was present for their full shift.',Late:'Staff arrived late for their shift.',Absent:'Staff did not report for duty.',On Leave:'Staff is on approved leave.','Half Day':'Staff worked half of their shift.'};
    const icons={Present:'✓',Late:'⏰',Absent:'✗','On Leave':'📅','Half Day':'◐'};
    document.getElementById('ma_status_hint').textContent = hints[s]||'';
    document.getElementById('ma_status_icon').textContent = icons[s]||'✓';
}

function maNowIn(){
    const n=new Date();
    document.getElementById('ma_time_in').value=`${String(n.getHours()).padStart(2,'0')}:${String(n.getMinutes()).padStart(2,'0')}`;
    maCalcHours();
}
function maNowOut(){
    const n=new Date();
    document.getElementById('ma_time_out').value=`${String(n.getHours()).padStart(2,'0')}:${String(n.getMinutes()).padStart(2,'0')}`;
    maCalcHours();
}
function maPreset(ti,to){
    document.getElementById('ma_time_in').value=ti;
    document.getElementById('ma_time_out').value=to;
    maCalcHours();
}
function maCalcHours(){
    const ti=document.getElementById('ma_time_in').value;
    const to=document.getElementById('ma_time_out').value;
    const box=document.getElementById('ma_hours_box');
    if(ti&&to){
        const [ih,im]=ti.split(':').map(Number);
        const [oh,om]=to.split(':').map(Number);
        let diff=(oh*60+om)-(ih*60+im);
        if(diff<0)diff+=1440;
        document.getElementById('ma_hours_val').textContent=`${Math.floor(diff/60)}h ${diff%60}m`;
        box.style.display='block';
    } else { box.style.display='none'; }
}

/* ── Bulk Mark Modal ─────────────────────────────────────────────────────── */
function toggleBulkTimes(){
    const open=document.getElementById('bulk_times_toggle').checked;
    document.getElementById('bulk_times_body').classList.toggle('open',open);
    if(!open){document.getElementById('bulk_time_in').value='';document.getElementById('bulk_time_out').value='';}
}
function bulkNowIn(){ const n=new Date(); document.getElementById('bulk_time_in').value=`${String(n.getHours()).padStart(2,'0')}:${String(n.getMinutes()).padStart(2,'0')}`; }
function bulkNowOut(){ const n=new Date(); document.getElementById('bulk_time_out').value=`${String(n.getHours()).padStart(2,'0')}:${String(n.getMinutes()).padStart(2,'0')}`; }
function bulkPreset(ti,to){
    document.getElementById('bulk_time_in').value=ti;
    document.getElementById('bulk_time_out').value=to;
    document.getElementById('bulk_times_toggle').checked=true;
    toggleBulkTimes();
}
function submitBulk(){
    const checked=document.querySelectorAll('.att-user-cb:checked');
    if(checked.length===0){ alert('Please select at least one staff member from the main table.'); return; }
    const form=document.getElementById('bulkForm');
    // Remove previously added hidden inputs
    form.querySelectorAll('input[name="selected_users[]"]').forEach(i=>i.remove());
    checked.forEach(cb=>{
        const inp=document.createElement('input');
        inp.type='hidden'; inp.name='selected_users[]'; inp.value=cb.value;
        form.appendChild(inp);
    });
    form.submit();
}

/* ── Auto-dismiss alerts ────────────────────────────────────────────────── */
setTimeout(()=>{
    document.querySelectorAll('.db-alert').forEach(a=>{
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
},5000);
</script>

<?php include '../../../includes/footer.php'; ?>