<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Force login and admin/staff access
requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'Manage Residents';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['resident_id'])) {
        $resident_id = (int)$_POST['resident_id'];
        
        switch ($_POST['action']) {
            case 'verify':
                $stmt = $conn->prepare("UPDATE tbl_residents SET is_verified = 1 WHERE resident_id = ?");
                $stmt->bind_param("i", $resident_id);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Resident verified successfully";
                } else {
                    $_SESSION['error_message'] = "Failed to verify resident";
                }
                $stmt->close();
                break;
                
            case 'unverify':
                $stmt = $conn->prepare("UPDATE tbl_residents SET is_verified = 0 WHERE resident_id = ?");
                $stmt->bind_param("i", $resident_id);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Resident unverified successfully";
                } else {
                    $_SESSION['error_message'] = "Failed to unverify resident";
                }
                $stmt->close();
                break;
                
            case 'delete':
                $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_requests WHERE resident_id = ?");
                $check_stmt->bind_param("i", $resident_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check = $check_result->fetch_assoc();
                $check_stmt->close();
                
                if ($check['count'] > 0) {
                    $_SESSION['error_message'] = "Cannot delete resident with existing requests. Please delete their requests first.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM tbl_users WHERE resident_id = ?");
                    $stmt->bind_param("i", $resident_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $stmt = $conn->prepare("DELETE FROM tbl_residents WHERE resident_id = ?");
                    $stmt->bind_param("i", $resident_id);
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Resident deleted successfully";
                    } else {
                        $_SESSION['error_message'] = "Failed to delete resident";
                    }
                    $stmt->close();
                }
                break;
        }
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit();
    }
}

// Fetch residents
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

$sql = "SELECT resident_id, first_name, middle_name, last_name, email, contact_number, address, is_verified, created_at FROM tbl_residents WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param, $search_param];
    $types = "ssss";
}

if ($status_filter === 'verified') {
    $sql .= " AND is_verified = 1";
} elseif ($status_filter === 'unverified') {
    $sql .= " AND is_verified = 0";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$residents = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
    SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified
    FROM tbl_residents";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');

:root {
    --db-navy: #0d1b36;
    --db-navy-mid: #152849;
    --db-navy-light: #1c3461;
    --db-amber: #f59e0b;
    --db-amber-light: #fef3c7;
    --db-amber-dark: #b45309;
    --db-teal: #0d9488;
    --db-teal-light: #ccfbf1;
    --db-rose: #e11d48;
    --db-rose-light: #ffe4e6;
    --db-sky: #0ea5e9;
    --db-sky-light: #e0f2fe;
    --db-indigo: #6366f1;
    --db-indigo-light: #e0e7ff;
    --db-success: #10b981;
    --db-success-light: #d1fae5;
    --db-warning: #f59e0b;
    --db-warning-light: #fef3c7;
    --db-danger: #ef4444;
    --db-danger-light: #fee2e2;
    --db-info: #3b82f6;
    --db-info-light: #dbeafe;

    --db-bg: #eef2f7;
    --db-surf: #ffffff;
    --db-surf2: #f8fafc;
    --db-border: #e2e8f0;
    --db-text: #0f172a;
    --db-muted: #64748b;

    --db-radius: 14px;
    --db-radius-sm: 8px;
    --db-radius-lg: 20px;
    --db-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg: 0 8px 40px rgba(13,27,54,.14), 0 2px 8px rgba(13,27,54,.06);
}

*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'Sora', sans-serif;
    background: var(--db-bg);
    color: var(--db-text);
    font-size: 13.5px;
}

/* ── PAGE HERO ── */
.rm-hero {
    background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #224090 100%);
    padding: 28px 36px;
    margin-bottom: 24px;
    border-radius: 0 0 var(--db-radius-lg) var(--db-radius-lg);
    position: relative;
    overflow: hidden;
}

.rm-hero__ring {
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.06);
    pointer-events: none;
}
.rm-hero__ring--1 { width: 300px; height: 300px; top: -130px; right: -60px; }
.rm-hero__ring--2 { width: 180px; height: 180px; top: -50px; right: 70px; border-color: rgba(245,158,11,.12); }
.rm-hero__ring--3 { width: 100px; height: 100px; bottom: -40px; left: 40%; border-color: rgba(13,148,136,.14); }

.rm-hero__inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.rm-hero__left { display: flex; align-items: center; gap: 16px; }

.rm-hero__icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--db-teal), #0f766e);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff;
    box-shadow: 0 4px 16px rgba(13,148,136,.4);
    flex-shrink: 0;
}

.rm-hero__title {
    font-size: 22px; font-weight: 800;
    color: #fff; letter-spacing: -0.4px;
    margin-bottom: 3px;
}

.rm-hero__sub { font-size: 13px; color: rgba(255,255,255,.55); }

/* ── ALERTS ── */
.db-alert {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px;
    border-radius: var(--db-radius);
    margin-bottom: 16px;
    font-weight: 500; font-size: 13.5px;
    border-left: 4px solid;
    transition: opacity .3s, transform .3s;
}
.db-alert--success { background: var(--db-success-light); color: #065f46; border-color: var(--db-success); }
.db-alert--error   { background: var(--db-danger-light);  color: #7f1d1d;  border-color: var(--db-danger); }
.db-alert__close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .6; }
.db-alert__close:hover { opacity: 1; }

/* ── STATS ROW ── */
.db-stats-row {
    display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 24px;
}

.db-stat-card {
    flex: 1 1 180px;
    background: var(--db-surf);
    border-radius: var(--db-radius);
    padding: 20px 18px 16px;
    display: flex; flex-direction: column; gap: 12px;
    box-shadow: var(--db-shadow);
    border: 1px solid var(--db-border);
    position: relative; overflow: hidden;
    transition: transform .2s, box-shadow .2s, border-color .2s;
    text-decoration: none; color: inherit;
}

.db-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--db-shadow-lg);
    color: inherit;
}

.db-stat-card.active {
    border-color: var(--db-navy-light);
    box-shadow: 0 0 0 3px rgba(28,52,97,.15), var(--db-shadow-lg);
    transform: translateY(-3px);
}

.db-stat-card__icon {
    width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.db-stat-card__icon--teal   { background: var(--db-teal-light);   color: var(--db-teal); }
.db-stat-card__icon--success { background: var(--db-success-light); color: var(--db-success); }
.db-stat-card__icon--amber  { background: var(--db-amber-light);  color: var(--db-amber-dark); }

.db-stat-card__num   { font-size: 30px; font-weight: 800; line-height: 1; letter-spacing: -1px; }
.db-stat-card__label { font-size: 11px; color: var(--db-muted); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }

.db-stat-card__sparkline { height: 3px; border-radius: 2px; opacity: .4; }
.db-stat-card__sparkline--teal    { background: linear-gradient(90deg, var(--db-teal), transparent); }
.db-stat-card__sparkline--success { background: linear-gradient(90deg, var(--db-success), transparent); }
.db-stat-card__sparkline--amber   { background: linear-gradient(90deg, var(--db-amber), transparent); }

/* ── PANEL ── */
.db-panel {
    background: var(--db-surf);
    border-radius: var(--db-radius-lg);
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
    margin-bottom: 18px;
    overflow: hidden;
    animation: dbFadeUp .35s ease both;
}
@keyframes dbFadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.db-panel__header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid var(--db-border);
    gap: 10px; flex-wrap: wrap;
}
.db-panel__title { display: flex; align-items: center; gap: 10px; }
.db-panel__title h2 { font-size: 15px; font-weight: 700; }

.db-panel__icon {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.db-panel__icon--teal { background: var(--db-teal-light); color: var(--db-teal); }

/* ── FILTER PANEL ── */
.rm-filter-body { padding: 18px 22px; }
.rm-filter-row  { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.rm-filter-row .db-input { font-family: 'Sora', sans-serif; }

.db-input {
    padding: 9px 13px;
    border: 1.5px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 13px; color: var(--db-text);
    background: var(--db-surf);
    outline: none;
    transition: border-color .18s, box-shadow .18s;
    appearance: none;
}
.db-input:focus {
    border-color: var(--db-navy-light);
    box-shadow: 0 0 0 3px rgba(28,52,97,.1);
}

/* ── BUTTONS ── */
.db-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    border-radius: var(--db-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    text-decoration: none;
    transition: all .18s;
    white-space: nowrap;
}
.db-btn--sm { padding: 6px 12px; font-size: 12px; }
.db-btn--primary {
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
    color: #fff;
}
.db-btn--primary:hover {
    background: linear-gradient(135deg, var(--db-navy-light), #2748a0);
    transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.25);
    color: #fff;
}
.db-btn--ghost {
    background: var(--db-surf2); color: var(--db-text); border-color: var(--db-border);
}
.db-btn--ghost:hover { background: var(--db-border); }

.db-btn-group { display: flex; gap: 4px; }

.db-icon-btn {
    width: 30px; height: 30px; border-radius: 7px;
    background: var(--db-surf2); border: 1px solid var(--db-border);
    color: var(--db-muted); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; transition: all .15s;
}
.db-icon-btn:hover              { background: var(--db-navy);    color: #fff; border-color: var(--db-navy); }
.db-icon-btn--info:hover        { background: var(--db-sky);     color: #fff; border-color: var(--db-sky); }
.db-icon-btn--primary:hover     { background: var(--db-info);    color: #fff; border-color: var(--db-info); }
.db-icon-btn--success:hover     { background: var(--db-success); color: #fff; border-color: var(--db-success); }
.db-icon-btn--warning:hover     { background: var(--db-amber);   color: #fff; border-color: var(--db-amber); }
.db-icon-btn--danger:hover      { background: var(--db-danger);  color: #fff; border-color: var(--db-danger); }

/* ── TABLE ── */
.db-table-wrap { overflow-x: auto; }

.db-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }

.db-table thead tr {
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
}
.db-table thead th {
    color: rgba(255,255,255,.8);
    font-family: 'DM Mono', monospace;
    font-size: 10px; font-weight: 500;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 11px 16px; white-space: nowrap; border: none;
}
.db-table tbody tr { border-bottom: 1px solid var(--db-border); transition: background .12s; }
.db-table tbody tr:last-child { border-bottom: none; }
.db-table tbody tr:hover { background: #f5f8ff; }
.db-table tbody td { padding: 12px 16px; vertical-align: middle; }

.db-id { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--db-indigo); font-weight: 500; }
.db-text-sm { font-size: 11.5px; color: var(--db-muted); }

/* ── BADGES ── */
.db-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
    letter-spacing: .3px; white-space: nowrap;
}
.db-badge--success { background: var(--db-success-light); color: #065f46; }
.db-badge--warning { background: var(--db-warning-light); color: #92400e; }

/* ── EMPTY STATE ── */
.db-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 56px 24px;
    text-align: center; gap: 12px;
}
.db-empty i { font-size: 44px; color: var(--db-border); }
.db-empty p { font-size: 14px; color: var(--db-muted); }

/* ── MODALS ── */
.db-modal {
    display: none; position: fixed; inset: 0;
    background: rgba(13,27,54,.55);
    backdrop-filter: blur(5px);
    z-index: 9999; align-items: center; justify-content: center; padding: 20px;
}
.db-modal--open { display: flex; }

.db-modal__box {
    background: var(--db-surf); border-radius: var(--db-radius-lg);
    width: 100%; max-width: 500px; max-height: 92vh; overflow-y: auto;
    box-shadow: var(--db-shadow-lg);
    animation: dbModalIn .28s cubic-bezier(.34,1.56,.64,1);
}
.db-modal__box--sm { max-width: 430px; }

@keyframes dbModalIn {
    from { opacity: 0; transform: scale(.9) translateY(16px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.db-modal__header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px;
    border-radius: var(--db-radius-lg) var(--db-radius-lg) 0 0;
}
.db-modal__header--success { background: linear-gradient(135deg, #065f46, var(--db-success)); }
.db-modal__header--warning { background: linear-gradient(135deg, #92400e, var(--db-amber)); }
.db-modal__header--danger  { background: linear-gradient(135deg, #7f1d1d, var(--db-danger)); }

.db-modal__header h3 {
    color: #fff; font-size: 15px; font-weight: 700;
    display: flex; align-items: center; gap: 8px;
}
.db-modal__close {
    background: rgba(255,255,255,.15); border: none;
    color: rgba(255,255,255,.85); width: 30px; height: 30px;
    border-radius: 7px; cursor: pointer; font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.db-modal__close:hover { background: rgba(255,255,255,.28); color: #fff; }

.db-modal__body { padding: 22px; }

.db-modal__info {
    background: var(--db-surf2);
    border: 1px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    padding: 14px 16px; margin-bottom: 16px;
}
.db-modal__info p { margin-bottom: 6px; font-size: 13px; }
.db-modal__info p:last-child { margin-bottom: 0; }

.db-modal__notice {
    display: flex; gap: 10px; align-items: flex-start;
    padding: 12px 14px; border-radius: var(--db-radius-sm);
    font-size: 12.5px; margin-bottom: 4px;
}
.db-modal__notice--info    { background: var(--db-info-light);    color: #1e40af; }
.db-modal__notice--warning { background: var(--db-warning-light); color: #92400e; }
.db-modal__notice--danger  { background: var(--db-danger-light);  color: #7f1d1d; }
.db-modal__notice ul { margin: 6px 0 0 14px; }
.db-modal__notice li { margin-bottom: 3px; }

.db-modal__footer { display: flex; gap: 10px; margin-top: 18px; }
.db-modal__footer .db-btn { flex: 1; justify-content: center; }

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--db-surf2); }
::-webkit-scrollbar-thumb { background: var(--db-border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--db-muted); }

@media (max-width: 768px) {
    .rm-hero { padding: 20px; border-radius: 0; }
    .rm-hero__title { font-size: 18px; }
    .rm-filter-row { flex-direction: column; }
    .rm-filter-row .db-input,
    .rm-filter-row .db-btn { width: 100%; }
    .db-table thead th, .db-table tbody td { padding: 9px 10px; font-size: 11.5px; }
}
</style>

<!-- ── HERO ── -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-users"></i></div>
            <div>
                <div class="rm-hero__title">Manage Residents</div>
                <div class="rm-hero__sub">View, verify, and manage all registered residents</div>
            </div>
        </div>
        <a href="add.php" class="db-btn db-btn--primary">
            <i class="fas fa-user-plus"></i> Add New Resident
        </a>
    </div>
</div>

<div style="padding: 0 24px 24px;">

    <!-- Alerts -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="db-alert db-alert--success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="db-alert db-alert--error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="db-stats-row">
        <a href="manage.php" class="db-stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
            <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-users"></i></div>
            <div>
                <div class="db-stat-card__num"><?php echo $stats['total']; ?></div>
                <div class="db-stat-card__label">Total Residents</div>
            </div>
            <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
        </a>
        <a href="?status=verified" class="db-stat-card <?php echo $status_filter === 'verified' ? 'active' : ''; ?>">
            <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['verified']; ?></div>
                <div class="db-stat-card__label">Verified</div>
            </div>
            <div class="db-stat-card__sparkline db-stat-card__sparkline--success"></div>
        </a>
        <a href="?status=unverified" class="db-stat-card <?php echo $status_filter === 'unverified' ? 'active' : ''; ?>">
            <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['unverified']; ?></div>
                <div class="db-stat-card__label">Pending Verification</div>
            </div>
            <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        </a>
    </div>

    <!-- Filter Panel -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div>
                <h2>Search & Filter</h2>
            </div>
        </div>
        <div class="rm-filter-body">
            <form method="GET">
                <div class="rm-filter-row">
                    <input type="text" name="search" class="db-input" style="flex:1;min-width:220px;"
                           placeholder="Search by name, contact, or email…"
                           value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status" class="db-input" style="min-width:160px;">
                        <option value="all"        <?php echo $status_filter === 'all'        ? 'selected' : ''; ?>>All Status</option>
                        <option value="verified"   <?php echo $status_filter === 'verified'   ? 'selected' : ''; ?>>Verified</option>
                        <option value="unverified" <?php echo $status_filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
                    </select>
                    <button type="submit" class="db-btn db-btn--primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if (!empty($search) || $status_filter !== 'all'): ?>
                    <a href="manage.php" class="db-btn db-btn--ghost">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Residents Table Panel -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list"></i></div>
                <h2>
                    <?php
                    if ($status_filter === 'verified')        echo 'Verified Residents';
                    elseif ($status_filter === 'unverified')  echo 'Pending Verification';
                    else                                       echo 'All Residents';
                    ?>
                </h2>
                <span class="db-badge db-badge--success" style="margin-left:8px;"><?php echo count($residents); ?></span>
            </div>
        </div>

        <?php if (empty($residents)): ?>
            <div class="db-empty">
                <i class="fas fa-users"></i>
                <p><?php echo (!empty($search) || $status_filter !== 'all') ? 'No residents match your filters.' : 'No residents registered yet.'; ?></p>
                <?php if (!empty($search) || $status_filter !== 'all'): ?>
                    <a href="manage.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filters</a>
                <?php else: ?>
                    <a href="add.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-user-plus"></i> Add Resident</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th style="text-align:center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($residents as $resident): ?>
                    <tr>
                        <td><span class="db-id">#<?php echo str_pad($resident['resident_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong></td>
                        <td><span class="db-text-sm"><?php echo htmlspecialchars($resident['email'] ?? 'N/A'); ?></span></td>
                        <td><?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="db-text-sm"><?php
                                $addr = $resident['address'] ?? 'N/A';
                                echo htmlspecialchars(strlen($addr) > 32 ? substr($addr, 0, 32) . '…' : $addr);
                            ?></span>
                        </td>
                        <td>
                            <?php if ($resident['is_verified']): ?>
                                <span class="db-badge db-badge--success"><i class="fas fa-check-circle"></i> Verified</span>
                            <?php else: ?>
                                <span class="db-badge db-badge--warning"><i class="fas fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($resident['created_at'])); ?></span></td>
                        <td>
                            <div class="db-btn-group" style="justify-content:center;">
                                <a href="view.php?id=<?php echo (int)$resident['resident_id']; ?>"
                                   class="db-icon-btn db-icon-btn--info" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit.php?id=<?php echo (int)$resident['resident_id']; ?>"
                                   class="db-icon-btn db-icon-btn--primary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($resident['is_verified']): ?>
                                    <button type="button"
                                            class="db-icon-btn db-icon-btn--warning"
                                            onclick="showUnverifyModal(<?php echo (int)$resident['resident_id']; ?>, '<?php echo htmlspecialchars(addslashes($resident['first_name'] . ' ' . $resident['last_name'])); ?>')"
                                            title="Unverify">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button"
                                            class="db-icon-btn db-icon-btn--success"
                                            onclick="showVerifyModal(<?php echo (int)$resident['resident_id']; ?>, '<?php echo htmlspecialchars(addslashes($resident['first_name'] . ' ' . $resident['last_name'])); ?>')"
                                            title="Verify">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                <?php endif; ?>
                                <button type="button"
                                        class="db-icon-btn db-icon-btn--danger"
                                        onclick="showDeleteModal(<?php echo (int)$resident['resident_id']; ?>, '<?php echo htmlspecialchars(addslashes($resident['first_name'] . ' ' . $resident['last_name'])); ?>')"
                                        title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /padding wrapper -->


<!-- ── VERIFY MODAL ── -->
<div id="verifyModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--success">
            <h3><i class="fas fa-user-check"></i> Verify Resident</h3>
            <button class="db-modal__close" onclick="closeModal('verifyModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal__info">
                <p><strong>Resident:</strong> <span id="verifyResidentName"></span></p>
                <p><strong>ID:</strong> <span id="verifyResidentId"></span></p>
            </div>
            <div class="db-modal__notice db-modal__notice--info">
                <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
                <span>Verifying this resident will grant them full access and allow them to submit requests.</span>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('verifyModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="db-btn db-btn--primary" onclick="confirmVerify()" style="background:linear-gradient(135deg,#065f46,var(--db-success));">
                    <i class="fas fa-check"></i> Verify Resident
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── UNVERIFY MODAL ── -->
<div id="unverifyModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--warning">
            <h3><i class="fas fa-user-times"></i> Unverify Resident</h3>
            <button class="db-modal__close" onclick="closeModal('unverifyModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal__info">
                <p><strong>Resident:</strong> <span id="unverifyResidentName"></span></p>
                <p><strong>ID:</strong> <span id="unverifyResidentId"></span></p>
            </div>
            <div class="db-modal__notice db-modal__notice--warning">
                <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                <span>Unverifying will restrict this resident's access. They will need re-verification to submit requests.</span>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('unverifyModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="db-btn db-btn--primary" onclick="confirmUnverify()" style="background:linear-gradient(135deg,#92400e,var(--db-amber));">
                    <i class="fas fa-times-circle"></i> Unverify
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── DELETE MODAL ── -->
<div id="deleteModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash-alt"></i> Delete Resident</h3>
            <button class="db-modal__close" onclick="closeModal('deleteModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal__info">
                <p><strong>Resident:</strong> <span id="deleteResidentName"></span></p>
                <p><strong>ID:</strong> <span id="deleteResidentId"></span></p>
            </div>
            <div class="db-modal__notice db-modal__notice--danger">
                <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                <div>
                    <strong>This action cannot be undone!</strong>
                    <ul>
                        <li>Resident account permanently deleted</li>
                        <li>Associated user account removed</li>
                        <li>All related data will be erased</li>
                    </ul>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="db-btn" onclick="confirmDelete()"
                        style="background:var(--db-danger);color:#fff;flex:1;justify-content:center;">
                    <i class="fas fa-trash"></i> Delete Permanently
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden action form -->
<form id="actionForm" method="POST" style="display:none;">
    <input type="hidden" name="resident_id" id="actionResidentId">
    <input type="hidden" name="action" id="actionType">
</form>

<script>
let currentResidentId = null;

function showVerifyModal(id, name) {
    currentResidentId = id;
    document.getElementById('verifyResidentName').textContent = name;
    document.getElementById('verifyResidentId').textContent = '#' + String(id).padStart(4,'0');
    openModal('verifyModal');
}
function showUnverifyModal(id, name) {
    currentResidentId = id;
    document.getElementById('unverifyResidentName').textContent = name;
    document.getElementById('unverifyResidentId').textContent = '#' + String(id).padStart(4,'0');
    openModal('unverifyModal');
}
function showDeleteModal(id, name) {
    currentResidentId = id;
    document.getElementById('deleteResidentName').textContent = name;
    document.getElementById('deleteResidentId').textContent = '#' + String(id).padStart(4,'0');
    openModal('deleteModal');
}
function confirmVerify()   { submitAction(currentResidentId, 'verify'); }
function confirmUnverify() { submitAction(currentResidentId, 'unverify'); }
function confirmDelete()   { submitAction(currentResidentId, 'delete'); }

function submitAction(id, action) {
    document.getElementById('actionResidentId').value = id;
    document.getElementById('actionType').value = action;
    document.getElementById('actionForm').submit();
}

function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow = ''; }

window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.db-modal--open').forEach(m => closeModal(m.id)); });

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php
$conn->close();
include '../../includes/footer.php';
?>