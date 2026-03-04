<?php
/**
 * Notifications Dashboard - modules/notifications/index.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Notifications';
$user_id    = $_SESSION['user_id'];
$user_role  = getCurrentUserRole();

// ── Normalise role so "Super Administrator" === "Super Admin" everywhere ──────
$is_super_admin = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');

// ── Notification filter ───────────────────────────────────────────────────────
if ($user_role === 'Resident') {
    $notification_filter = "(
        type LIKE '%incident%' OR
        type LIKE '%request%'  OR
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type LIKE '%blotter%' OR
        type IN ('general','announcement','alert','status_update','email_reply') OR
        reference_type IN ('incident','request','document','complaint',
                           'appointment','medical_assistance','blotter','announcement','notification')
    )";
} else {
    $notification_filter = "(
        type LIKE '%incident%' OR
        type LIKE '%blotter%'  OR
        type LIKE '%request%'  OR
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type IN ('general','announcement','alert','status_update','email_reply') OR
        reference_type IN ('incident','blotter','request','document','complaint',
                           'appointment','medical_assistance','announcement','notification')
    )";
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        executeQuery($conn,
            "DELETE FROM tbl_notifications WHERE notification_id = ? AND user_id = ? AND $notification_filter",
            [$nid, $user_id], 'ii'
        );
        $_SESSION['success_message'] = 'Notification deleted successfully';
        header('Location: index.php'); exit();

    } elseif ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $nid, $user_id);
        $stmt->execute(); $stmt->close();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        }
        $_SESSION['success_message'] = 'Notification marked as read';
        header('Location: index.php'); exit();

    } elseif ($_POST['action'] === 'bulk_delete') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                executeQuery($conn,
                    "DELETE FROM tbl_notifications WHERE notification_id = ? AND user_id = ? AND $notification_filter",
                    [$nid, $user_id], 'ii'
                );
            }
            $_SESSION['success_message'] = 'Selected notifications deleted successfully';
        }
        header('Location: index.php'); exit();

    } elseif ($_POST['action'] === 'bulk_read') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $nid, $user_id);
                $stmt->execute(); $stmt->close();
            }
            $_SESSION['success_message'] = 'Selected notifications marked as read';
        }
        header('Location: index.php'); exit();
    }
}

// ── Statistics ────────────────────────────────────────────────────────────────
$stats = fetchOne($conn, "
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as `read`,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
    FROM tbl_notifications
    WHERE user_id = ? AND $notification_filter
", [$user_id], 'i');

// ── Pagination & filtering ────────────────────────────────────────────────────
$page        = max(1, intval($_GET['page'] ?? 1));
$per_page    = 15;
$offset      = ($page - 1) * $per_page;
$filter      = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type']   ?? '';

$where  = "user_id = ? AND $notification_filter";
$params = [$user_id];
$types  = 'i';

if ($filter === 'unread')      { $where .= " AND is_read = 0"; }
elseif ($filter === 'read')    { $where .= " AND is_read = 1"; }
elseif ($filter === 'today')   { $where .= " AND DATE(created_at) = CURDATE()"; }
elseif ($filter === 'week')    { $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; }

if ($type_filter) {
    if (stripos($type_filter, 'announcement') !== false || stripos($type_filter, 'general') !== false) {
        $where .= " AND (type IN ('general','announcement','alert','status_update') OR reference_type = 'announcement')";
    } elseif (stripos($type_filter, 'blotter') !== false) {
        $where .= " AND (type LIKE '%blotter%' OR reference_type = 'blotter')";
    } elseif (stripos($type_filter, 'incident') !== false) {
        $where .= " AND (type LIKE '%incident%' OR reference_type = 'incident')";
    } elseif (stripos($type_filter, 'complaint') !== false) {
        $where .= " AND (type LIKE '%complaint%' OR reference_type = 'complaint')";
    } elseif (stripos($type_filter, 'appointment') !== false) {
        $where .= " AND (type LIKE '%appointment%' OR reference_type = 'appointment')";
    } elseif (stripos($type_filter, 'medical_assistance') !== false) {
        $where .= " AND (type LIKE '%medical_assistance%' OR reference_type = 'medical_assistance')";
    } elseif (stripos($type_filter, 'request') !== false || stripos($type_filter, 'document') !== false) {
        $where .= " AND (type LIKE '%request%' OR type LIKE '%document%' OR reference_type IN ('request','document'))";
    } else {
        $where   .= " AND type = ?";
        $params[] = $type_filter;
        $types   .= 's';
    }
}

$count       = fetchOne($conn, "SELECT COUNT(*) as total FROM tbl_notifications WHERE $where", $params, $types);
$total       = $count['total'];
$total_pages = ceil($total / $per_page);

$notifications = fetchAll($conn,
    "SELECT * FROM tbl_notifications WHERE $where ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset]),
    $types . 'ii'
);

// ── Type counts for filter buttons ────────────────────────────────────────────
$all_type_stats = fetchAll($conn, "
    SELECT type, reference_type, COUNT(*) as count
    FROM tbl_notifications
    WHERE user_id = ? AND $notification_filter
    GROUP BY type, reference_type
", [$user_id], 'i');

$incident_total            = 0;
$blotter_total             = 0;
$complaint_total           = 0;
$request_total             = 0;
$appointment_total         = 0;
$medical_assistance_total  = 0;
$announcement_total        = 0;

foreach ($all_type_stats as $ts) {
    $t  = strtolower(trim($ts['type']          ?? ''));
    $rt = strtolower(trim($ts['reference_type'] ?? ''));
    $c  = intval($ts['count']);

    if ($rt === 'incident' || stripos($t, 'incident') !== false) {
        $incident_total += $c;
    } elseif ($rt === 'blotter' || stripos($t, 'blotter') !== false) {
        $blotter_total += $c;
    } elseif ($rt === 'complaint' || stripos($t, 'complaint') !== false) {
        $complaint_total += $c;
    } elseif ($rt === 'appointment' || stripos($t, 'appointment') !== false) {
        $appointment_total += $c;
    } elseif ($rt === 'medical_assistance' || stripos($t, 'medical') !== false) {
        $medical_assistance_total += $c;
    } elseif ($rt === 'request' || $rt === 'document' ||
              stripos($t, 'request') !== false || stripos($t, 'document') !== false) {
        $request_total += $c;
    } elseif ($rt === 'announcement' ||
              in_array($t, ['general', 'announcement', 'alert', 'status_update'])) {
        $announcement_total += $c;
    }
}

$is_announcement_active       = (stripos($type_filter, 'announcement') !== false || stripos($type_filter, 'general') !== false);
$is_incident_active           = (stripos($type_filter, 'incident')     !== false);
$is_blotter_active            = (stripos($type_filter, 'blotter')      !== false);
$is_complaint_active          = (stripos($type_filter, 'complaint')    !== false);
$is_request_active            = (stripos($type_filter, 'request')      !== false || stripos($type_filter, 'document') !== false);
$is_appointment_active        = (stripos($type_filter, 'appointment')  !== false);
$is_medical_assistance_active = (stripos($type_filter, 'medical_assistance') !== false);

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
body { font-family: 'Sora', sans-serif; background: var(--db-bg); color: var(--db-text); font-size: 13.5px; }

/* ── Hero ── */
.rm-hero {
    background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #224090 100%);
    padding: 28px 36px; margin-bottom: 24px;
    border-radius: 0 0 var(--db-radius-lg) var(--db-radius-lg);
    position: relative; overflow: hidden;
}
.rm-hero__ring { position: absolute; border-radius: 50%; border: 1px solid rgba(255,255,255,.06); pointer-events: none; }
.rm-hero__ring--1 { width: 320px; height: 320px; top: -140px; right: -80px; }
.rm-hero__ring--2 { width: 200px; height: 200px; top: -60px; right: 60px; border-color: rgba(245,158,11,.12); }
.rm-hero__ring--3 { width: 120px; height: 120px; bottom: -50px; left: 35%; border-color: rgba(13,148,136,.14); }
.rm-hero__inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.rm-hero__left  { display: flex; align-items: center; gap: 18px; }
.rm-hero__icon  {
    width: 54px; height: 54px; border-radius: 14px;
    background: linear-gradient(135deg, var(--db-indigo), #4338ca);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff; flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(99,102,241,.4);
}
.rm-hero__title { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.4px; margin-bottom: 3px; }
.rm-hero__sub   { font-size: 13px; color: rgba(255,255,255,.55); }
.rm-hero__sub small { display: block; margin-top: 2px; font-size: 11.5px; color: rgba(255,255,255,.4); }
.rm-hero__actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

/* ── Alerts ── */
.db-alert {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px; border-radius: var(--db-radius);
    margin-bottom: 16px; font-weight: 500; font-size: 13.5px;
    border-left: 4px solid; transition: opacity .3s ease, transform .3s ease;
}
.db-alert--success { background: var(--db-success-light); color: #065f46; border-color: var(--db-success); }
.db-alert--error   { background: var(--db-danger-light);  color: #7f1d1d;  border-color: var(--db-danger); }
.db-alert__close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .6; }
.db-alert__close:hover { opacity: 1; }

/* ── Stats Row ── */
.db-stats-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 24px; }
.db-stat-card {
    flex: 1 1 150px;
    background: var(--db-surf); border-radius: var(--db-radius);
    padding: 20px 18px 16px;
    display: flex; flex-direction: column; gap: 12px;
    box-shadow: var(--db-shadow); border: 1px solid var(--db-border);
    position: relative; overflow: hidden;
    transition: transform .2s ease, box-shadow .2s ease;
    text-decoration: none; color: inherit; cursor: pointer;
}
.db-stat-card:hover { transform: translateY(-3px); box-shadow: var(--db-shadow-lg); color: inherit; text-decoration: none; }
.db-stat-card--active { border-color: var(--db-navy-light); box-shadow: 0 0 0 3px rgba(28,52,97,.15), var(--db-shadow-lg); }
.db-stat-card__icon { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
.db-stat-card__icon--indigo { background: var(--db-indigo-light); color: var(--db-indigo); }
.db-stat-card__icon--amber  { background: var(--db-amber-light);  color: var(--db-amber-dark); }
.db-stat-card__icon--success{ background: var(--db-success-light);color: var(--db-success); }
.db-stat-card__icon--sky    { background: var(--db-sky-light);    color: var(--db-sky); }
.db-stat-card__icon--rose   { background: var(--db-rose-light);   color: var(--db-rose); }
.db-stat-card__num   { font-size: 30px; font-weight: 800; line-height: 1; letter-spacing: -1px; }
.db-stat-card__label { font-size: 11px; color: var(--db-muted); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }
.db-stat-card__sparkline { height: 3px; border-radius: 2px; margin-top: 4px; opacity: .4; }
.db-stat-card__sparkline--indigo { background: linear-gradient(90deg, var(--db-indigo), transparent); }
.db-stat-card__sparkline--amber  { background: linear-gradient(90deg, var(--db-amber), transparent); }
.db-stat-card__sparkline--success{ background: linear-gradient(90deg, var(--db-success), transparent); }
.db-stat-card__sparkline--sky    { background: linear-gradient(90deg, var(--db-sky), transparent); }
.db-stat-card__sparkline--rose   { background: linear-gradient(90deg, var(--db-rose), transparent); }

/* ── Panel ── */
.db-panel {
    background: var(--db-surf); border-radius: var(--db-radius-lg);
    border: 1px solid var(--db-border); box-shadow: var(--db-shadow);
    margin-bottom: 18px; overflow: hidden;
    animation: dbFadeUp .35s ease both;
}
@keyframes dbFadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
.db-panel__header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid var(--db-border);
    gap: 10px; flex-wrap: wrap;
}
.db-panel__title { display: flex; align-items: center; gap: 10px; }
.db-panel__title h2 { font-size: 15px; font-weight: 700; letter-spacing: -.2px; }
.db-panel__icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.db-panel__icon--indigo { background: var(--db-indigo-light); color: var(--db-indigo); }
.db-panel__footer { padding: 14px 22px; border-top: 1px solid var(--db-border); background: var(--db-surf2); }

/* ── Buttons ── */
.db-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: var(--db-radius-sm);
    font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    text-decoration: none; transition: all .18s ease; white-space: nowrap;
}
.db-btn--sm { padding: 6px 12px; font-size: 12px; }
.db-btn--primary { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; }
.db-btn--primary:hover { background: linear-gradient(135deg, var(--db-navy-light), #2748a0); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.25); color: #fff; }
.db-btn--ghost { background: var(--db-surf2); color: var(--db-text); border-color: var(--db-border); }
.db-btn--ghost:hover { background: var(--db-border); color: var(--db-text); text-decoration: none; }
.db-btn--success { background: var(--db-success); color: #fff; }
.db-btn--success:hover { background: #059669; color: #fff; transform: translateY(-1px); }
.db-btn--danger { background: var(--db-danger); color: #fff; }
.db-btn--danger:hover { background: #dc2626; color: #fff; }
.db-btn--info { background: var(--db-sky); color: #fff; }
.db-btn--info:hover { background: #0284c7; color: #fff; transform: translateY(-1px); }

/* ── Badges ── */
.db-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
    letter-spacing: .3px; white-space: nowrap;
}
.db-badge--indigo  { background: var(--db-indigo-light);  color: #4338ca; }
.db-badge--amber   { background: var(--db-amber-light);   color: #92400e; }
.db-badge--sky     { background: var(--db-sky-light);     color: #0369a1; }
.db-badge--rose    { background: var(--db-rose-light);    color: #9f1239; }
.db-badge--success { background: var(--db-success-light); color: #065f46; }
.db-badge--teal    { background: var(--db-teal-light);    color: #0f766e; }
.db-badge--danger  { background: var(--db-danger-light);  color: #7f1d1d; }
.db-badge--info    { background: var(--db-info-light);    color: #1e40af; }
.db-badge--muted   { background: var(--db-surf2); color: var(--db-muted); border: 1px solid var(--db-border); }
.db-badge--dark    { background: var(--db-navy); color: #fff; }
.db-badge--warning { background: var(--db-warning-light); color: #92400e; }
.db-badge--new { font-size: .62rem; padding: 2px 7px; font-weight: 700; animation: pulseBadge 2s infinite; }
@keyframes pulseBadge { 0%,100%{opacity:1} 50%{opacity:.6} }

/* ── Filter pills strip ── */
.db-filter-strip {
    background: var(--db-surf); border-radius: var(--db-radius);
    border: 1px solid var(--db-border); box-shadow: var(--db-shadow);
    padding: 14px 20px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.db-filter-strip__label {
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
    letter-spacing: .8px; text-transform: uppercase; color: var(--db-muted);
    margin-right: 4px; flex-shrink: 0;
}
.db-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 13px; border-radius: 20px; font-size: 12px; font-weight: 600;
    text-decoration: none; border: 1.5px solid var(--db-border);
    background: var(--db-surf2); color: var(--db-text);
    transition: all .18s ease; white-space: nowrap;
}
.db-pill:hover { border-color: var(--db-navy-light); color: var(--db-navy); background: #f0f4ff; text-decoration: none; }
.db-pill--active { background: var(--db-navy); color: #fff; border-color: var(--db-navy); }
.db-pill--active:hover { background: var(--db-navy-light); color: #fff; }
.db-pill__count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 18px; height: 18px; border-radius: 9px; padding: 0 4px;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
}
.db-pill--active .db-pill__count { background: rgba(255,255,255,.2); color: #fff; }
.db-pill:not(.db-pill--active) .db-pill__count { background: var(--db-border); color: var(--db-muted); }
.db-pill--dark:not(.db-pill--active)    { border-color: rgba(33,37,41,.2);    color: #343a40; }
.db-pill--dark.db-pill--active          { background: #2d3748; border-color: #2d3748; }
.db-pill--primary:not(.db-pill--active) { border-color: rgba(59,130,246,.3);  color: var(--db-info); }
.db-pill--primary.db-pill--active       { background: var(--db-info); border-color: var(--db-info); }
.db-pill--danger:not(.db-pill--active)  { border-color: rgba(239,68,68,.3);   color: var(--db-danger); }
.db-pill--danger.db-pill--active        { background: var(--db-danger); border-color: var(--db-danger); }
.db-pill--warning:not(.db-pill--active) { border-color: rgba(245,158,11,.3);  color: var(--db-amber-dark); }
.db-pill--warning.db-pill--active       { background: var(--db-amber-dark); border-color: var(--db-amber-dark); }
.db-pill--info:not(.db-pill--active)    { border-color: rgba(14,165,233,.3);  color: var(--db-sky); }
.db-pill--info.db-pill--active          { background: var(--db-sky); border-color: var(--db-sky); }
.db-pill--success:not(.db-pill--active) { border-color: rgba(16,185,129,.3);  color: var(--db-success); }
.db-pill--success.db-pill--active       { background: var(--db-success); border-color: var(--db-success); }
.db-pill--indigo:not(.db-pill--active)  { border-color: rgba(99,102,241,.3);  color: var(--db-indigo); }
.db-pill--indigo.db-pill--active        { background: var(--db-indigo); border-color: var(--db-indigo); }

/* ── Table ── */
.db-table-wrap { overflow-x: auto; }
.db-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.db-table thead tr { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); }
.db-table thead th { color: rgba(255,255,255,.8); font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .8px; padding: 11px 16px; white-space: nowrap; border: none; }
.db-table tbody tr { border-bottom: 1px solid var(--db-border); transition: background .12s; }
.db-table tbody tr:last-child { border-bottom: none; }
.db-table tbody td { padding: 11px 16px; vertical-align: middle; }

/* ── Notification rows ── */
.db-notif-row { cursor: pointer; }
.db-notif-row--unread { background: linear-gradient(to right, rgba(99,102,241,.04), rgba(99,102,241,.01)); border-left: 3px solid var(--db-indigo) !important; }
.db-notif-row--unread td:first-child { padding-left: 13px; }
.db-notif-row:hover { background: #f5f8ff !important; }
.db-notif-icon-wrap { position: relative; display: inline-block; }
.db-notif-icon { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 17px; transition: transform .2s; }
.db-notif-row:hover .db-notif-icon { transform: scale(1.08); }
.db-notif-icon--primary  { background: var(--db-indigo-light); color: var(--db-indigo); }
.db-notif-icon--warning  { background: var(--db-warning-light); color: var(--db-amber-dark); }
.db-notif-icon--success  { background: var(--db-success-light); color: var(--db-success); }
.db-notif-icon--info     { background: var(--db-info-light);    color: var(--db-info); }
.db-notif-icon--danger   { background: var(--db-danger-light);  color: var(--db-danger); }
.db-notif-icon--dark     { background: rgba(13,27,54,.08);      color: var(--db-navy); }
.db-notif-icon--sky      { background: var(--db-sky-light);     color: var(--db-sky); }
.db-notif-icon--teal     { background: var(--db-teal-light);    color: var(--db-teal); }
.db-unread-dot { position: absolute; top: -3px; right: -3px; width: 11px; height: 11px; background: var(--db-indigo); border-radius: 50%; border: 2px solid #fff; animation: pulseDot 2s infinite; }
@keyframes pulseDot { 0%,100%{opacity:1} 50%{opacity:.45} }
.db-notif-title   { font-size: 13px; font-weight: 700; color: var(--db-text); margin-bottom: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.db-notif-preview { font-size: 12px; color: var(--db-muted); line-height: 1.55; margin-bottom: 6px; }
.db-notif-meta    { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.db-notif-time    { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--db-muted); white-space: nowrap; }

/* ── Dropdown ── */
.db-dropdown-wrap { position: relative; display: inline-block; }
.db-dropdown-menu {
    display: none; position: absolute; right: 0; top: calc(100% + 6px);
    background: var(--db-surf); border: 1px solid var(--db-border);
    border-radius: var(--db-radius); box-shadow: var(--db-shadow-lg);
    min-width: 200px; z-index: 999; padding: 6px;
    animation: dbFadeUp .2s ease;
}
.db-dropdown-wrap.open .db-dropdown-menu { display: block; }
.db-dropdown-header { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .7px; color: var(--db-muted); padding: 7px 10px 5px; }
.db-dropdown-item {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; border-radius: var(--db-radius-sm);
    font-size: 13px; color: var(--db-text); text-decoration: none;
    transition: background .15s; cursor: pointer;
}
.db-dropdown-item:hover { background: var(--db-surf2); color: var(--db-text); text-decoration: none; }
.db-dropdown-item.active { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; }
.db-dropdown-item.active:hover { color: #fff; }
.db-dropdown-divider { height: 1px; background: var(--db-border); margin: 5px 0; }

/* ── Pagination ── */
.db-pagination { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.db-page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: var(--db-radius-sm);
    font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500;
    text-decoration: none; color: var(--db-text); border: 1px solid var(--db-border);
    background: var(--db-surf); transition: all .15s;
}
.db-page-btn:hover { background: var(--db-surf2); color: var(--db-text); text-decoration: none; }
.db-page-btn.active { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; border-color: var(--db-navy); }
.db-page-btn.disabled { opacity: .4; pointer-events: none; }

/* ── Empty state ── */
.db-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 64px 24px; text-align: center; gap: 12px; }
.db-empty i { font-size: 48px; color: var(--db-border); animation: floatIcon 3s ease-in-out infinite; }
@keyframes floatIcon { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-8px)} }
.db-empty p { font-size: 14px; color: var(--db-muted); max-width: 280px; }

/* ── Modals ── */
.db-modal { display: none; position: fixed; inset: 0; background: rgba(13,27,54,.55); backdrop-filter: blur(5px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
.db-modal--open { display: flex; }
.db-modal__box { background: var(--db-surf); border-radius: var(--db-radius-lg); width: 100%; max-width: 440px; overflow: hidden; box-shadow: var(--db-shadow-lg); animation: dbModalIn .28s cubic-bezier(.34,1.56,.64,1); }
@keyframes dbModalIn { from{opacity:0;transform:scale(.9) translateY(16px)} to{opacity:1;transform:scale(1) translateY(0)} }
.db-modal__header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; }
.db-modal__header--danger  { background: linear-gradient(135deg, #7f1d1d, var(--db-danger)); }
.db-modal__header--warning { background: linear-gradient(135deg, var(--db-amber-dark), var(--db-amber)); }
.db-modal__header--success { background: linear-gradient(135deg, #065f46, var(--db-success)); }
.db-modal__header h3 { color: #fff; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.db-modal__close { background: rgba(255,255,255,.15); border: none; color: rgba(255,255,255,.85); width: 30px; height: 30px; border-radius: 7px; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; transition: background .15s; }
.db-modal__close:hover { background: rgba(255,255,255,.28); color: #fff; }
.db-modal__body { padding: 26px 22px; text-align: center; }
.db-modal__confirm-icon { width: 68px; height: 68px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 26px; }
.db-modal__confirm-icon--danger  { background: var(--db-danger-light);  color: var(--db-danger); }
.db-modal__confirm-icon--warning { background: var(--db-warning-light); color: var(--db-amber-dark); }
.db-modal__confirm-icon--success { background: var(--db-success-light); color: var(--db-success); }
.db-modal__body h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.db-modal__body p  { font-size: 13px; color: var(--db-muted); margin: 0; }
.db-modal__footer  { display: flex; gap: 10px; padding: 0 22px 22px; }
.db-modal__footer .db-btn { flex: 1; justify-content: center; }

/* ── Hover preview card ── */
.db-preview-card {
    position: fixed; z-index: 9999; width: 310px;
    background: var(--db-surf); border-radius: var(--db-radius);
    box-shadow: var(--db-shadow-lg); border: 1px solid var(--db-border);
    overflow: hidden; pointer-events: none;
    animation: dbFadeUp .18s ease;
}
.db-preview-card__header { display: flex; align-items: center; gap: 12px; padding: 13px 15px 10px; border-bottom: 1px solid var(--db-border); }
.db-preview-card__icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.db-preview-card__type  { font-family: 'DM Mono', monospace; font-size: 9.5px; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; color: var(--db-muted); margin-bottom: 2px; }
.db-preview-card__title { font-size: .88rem; font-weight: 700; color: var(--db-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-preview-card__body  { padding: 11px 15px 13px; }
.db-preview-card__msg   { font-size: .8rem; color: var(--db-muted); line-height: 1.6; margin-bottom: 9px; }
.db-preview-card__time  { font-family: 'DM Mono', monospace; font-size: .72rem; color: #adb5bd; display: flex; align-items: center; gap: 4px; }

@media(max-width:768px){
    .rm-hero { padding: 20px; border-radius: 0; }
    .db-stats-row { gap: 10px; }
    .db-stat-card { flex: 1 1 140px; }
    .db-filter-strip { padding: 12px 14px; }
    .db-preview-card { display: none !important; }
    .db-table thead th, .db-table tbody td { padding: 9px 10px; font-size: 11.5px; }
}
</style>

<!-- ═══════════════════ HERO ═══════════════════ -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-bell"></i></div>
            <div>
                <div class="rm-hero__title">My Notifications</div>
                <div class="rm-hero__sub">
                    <i class="far fa-calendar me-1"></i><?= date('l, F j, Y') ?>
                    <small>
                        <?php if ($user_role === 'Resident'): ?>
                            Incidents, Complaints, Document Requests &amp; Announcements
                        <?php else: ?>
                            Incidents, Blotter, Complaints, Requests &amp; Announcements
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="rm-hero__actions">
            <?php if ($is_super_admin): ?>
                <a href="<?= BASE_URL ?>modules/notifications/email-residents.php" class="db-btn db-btn--info db-btn--sm">
                    <i class="fas fa-envelope"></i> Email Residents
                </a>
                <a href="<?= BASE_URL ?>modules/notifications/email-history.php" class="db-btn db-btn--info db-btn--sm">
                    <i class="fas fa-history"></i> Email History
                </a>
            <?php endif; ?>
            <?php if ($stats['unread'] > 0): ?>
                <a href="<?= BASE_URL ?>modules/notifications/mark_all_read.php" class="db-btn db-btn--success db-btn--sm">
                    <i class="fas fa-check-double"></i> Mark All Read
                </a>
            <?php endif; ?>
            <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
</div>

<div style="padding: 0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<!-- ═══════════════════ STATS ═══════════════════ -->
<div class="db-stats-row">
    <a href="?filter=all<?= $type_filter ? '&type='.$type_filter : '' ?>" class="db-stat-card <?= $filter==='all' ? 'db-stat-card--active' : '' ?>">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-bell"></i></div>
        <div><div class="db-stat-card__num"><?= $stats['total'] ?></div><div class="db-stat-card__label">Total</div></div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </a>
    <a href="?filter=unread<?= $type_filter ? '&type='.$type_filter : '' ?>" class="db-stat-card <?= $filter==='unread' ? 'db-stat-card--active' : '' ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-envelope"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?= $stats['unread'] ?></div><div class="db-stat-card__label">Unread</div></div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </a>
    <a href="?filter=read<?= $type_filter ? '&type='.$type_filter : '' ?>" class="db-stat-card <?= $filter==='read' ? 'db-stat-card--active' : '' ?>">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-envelope-open"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?= $stats['read'] ?></div><div class="db-stat-card__label">Read</div></div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--success"></div>
    </a>
    <a href="?filter=today<?= $type_filter ? '&type='.$type_filter : '' ?>" class="db-stat-card <?= $filter==='today' ? 'db-stat-card--active' : '' ?>">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-calendar-day"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?= $stats['today'] ?></div><div class="db-stat-card__label">Today</div></div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--sky"></div>
    </a>
    <a href="?filter=week<?= $type_filter ? '&type='.$type_filter : '' ?>" class="db-stat-card <?= $filter==='week' ? 'db-stat-card--active' : '' ?>">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-calendar-week"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?= $stats['this_week'] ?></div><div class="db-stat-card__label">This Week</div></div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </a>
</div>

<!-- ═══════════════════ FILTER PILLS ═══════════════════ -->
<div class="db-filter-strip">
    <span class="db-filter-strip__label">Type:</span>
    <a href="?filter=<?= $filter ?>" class="db-pill <?= !$type_filter ? 'db-pill--active' : '' ?>">
        <i class="fas fa-th" style="font-size:10px"></i> All
        <span class="db-pill__count"><?= $stats['total'] ?></span>
    </a>
    <?php if ($announcement_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=announcement" class="db-pill db-pill--dark <?= $is_announcement_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-bullhorn" style="font-size:10px"></i> Announcements
        <span class="db-pill__count"><?= $announcement_total ?></span>
    </a>
    <?php endif; ?>
    <?php if ($incident_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=incident_reported" class="db-pill db-pill--primary <?= $is_incident_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-exclamation-triangle" style="font-size:10px"></i> Incident
        <span class="db-pill__count"><?= $incident_total ?></span>
    </a>
    <?php endif; ?>
    <?php if ($blotter_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=blotter_filed" class="db-pill db-pill--danger <?= $is_blotter_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-gavel" style="font-size:10px"></i> Blotter
        <span class="db-pill__count"><?= $blotter_total ?></span>
    </a>
    <?php endif; ?>
    <?php if ($complaint_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=complaint_filed" class="db-pill db-pill--warning <?= $is_complaint_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-comments" style="font-size:10px"></i> Complaints
        <span class="db-pill__count"><?= $complaint_total ?></span>
    </a>
    <?php endif; ?>
    <?php if ($request_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=document_request" class="db-pill db-pill--info <?= $is_request_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-file-alt" style="font-size:10px"></i> Documents
        <span class="db-pill__count"><?= $request_total ?></span>
    </a>
    <?php endif; ?>
    <?php if ($appointment_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=appointment_booked" class="db-pill db-pill--success <?= $is_appointment_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-calendar-check" style="font-size:10px"></i> Appointments
        <span class="db-pill__count"><?= $appointment_total ?></span>
    </a>
    <?php endif; ?>
    <?php if ($medical_assistance_total > 0): ?>
    <a href="?filter=<?= $filter ?>&type=medical_assistance_request" class="db-pill db-pill--indigo <?= $is_medical_assistance_active ? 'db-pill--active' : '' ?>">
        <i class="fas fa-hand-holding-medical" style="font-size:10px"></i> Medical
        <span class="db-pill__count"><?= $medical_assistance_total ?></span>
    </a>
    <?php endif; ?>
</div>

<!-- ═══════════════════ MAIN PANEL ═══════════════════ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list-ul"></i></div>
            <h2>
                <?php
                $filter_titles = ['all'=>'All Notifications','unread'=>'Unread','read'=>'Read','today'=>"Today's",'week'=>"This Week's"];
                echo ($filter_titles[$filter] ?? 'All Notifications') . ' Notifications';
                ?>
            </h2>
            <span class="db-badge db-badge--indigo"><?= $total ?></span>
        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">

            <!-- Status filter dropdown -->
            <div class="db-dropdown-wrap" id="statusDropdown">
                <button class="db-btn db-btn--ghost db-btn--sm" onclick="toggleDropdown('statusDropdown')">
                    <i class="fas fa-filter"></i>
                    Status: <?php $fl=['all'=>'All','unread'=>'Unread','read'=>'Read','today'=>'Today','week'=>'This Week']; echo $fl[$filter]??'All'; ?>
                    <i class="fas fa-chevron-down" style="font-size:10px;opacity:.6"></i>
                </button>
                <div class="db-dropdown-menu">
                    <div class="db-dropdown-header">Filter by Status</div>
                    <a class="db-dropdown-item <?= $filter==='all'   ?'active':'' ?>" href="?filter=all<?=   $type_filter?'&type='.$type_filter:'' ?>"><i class="fas fa-list"></i> All</a>
                    <a class="db-dropdown-item <?= $filter==='unread'?'active':'' ?>" href="?filter=unread<?= $type_filter?'&type='.$type_filter:'' ?>"><i class="fas fa-envelope"></i> Unread Only</a>
                    <a class="db-dropdown-item <?= $filter==='read'  ?'active':'' ?>" href="?filter=read<?=  $type_filter?'&type='.$type_filter:'' ?>"><i class="fas fa-envelope-open"></i> Read Only</a>
                    <div class="db-dropdown-divider"></div>
                    <div class="db-dropdown-header">Filter by Date</div>
                    <a class="db-dropdown-item <?= $filter==='today'?'active':'' ?>" href="?filter=today<?= $type_filter?'&type='.$type_filter:'' ?>"><i class="fas fa-calendar-day"></i> Today</a>
                    <a class="db-dropdown-item <?= $filter==='week' ?'active':'' ?>" href="?filter=week<?=  $type_filter?'&type='.$type_filter:'' ?>"><i class="fas fa-calendar-week"></i> This Week</a>
                </div>
            </div>

            <!-- Type filter dropdown -->
            <div class="db-dropdown-wrap" id="typeDropdown">
                <button class="db-btn db-btn--ghost db-btn--sm" onclick="toggleDropdown('typeDropdown')">
                    <i class="fas fa-tag"></i>
                    Type: <?php
                    if ($type_filter) {
                        if ($is_announcement_active)           echo 'Announcements';
                        elseif ($is_incident_active)           echo 'Incident';
                        elseif ($is_blotter_active)            echo 'Blotter';
                        elseif ($is_complaint_active)          echo 'Complaints';
                        elseif ($is_appointment_active)        echo 'Appointments';
                        elseif ($is_medical_assistance_active) echo 'Medical';
                        elseif ($is_request_active)            echo 'Documents';
                        else echo ucwords(str_replace('_',' ',$type_filter));
                    } else { echo 'All'; }
                    ?>
                    <i class="fas fa-chevron-down" style="font-size:10px;opacity:.6"></i>
                </button>
                <div class="db-dropdown-menu">
                    <div class="db-dropdown-header">Filter by Type</div>
                    <a class="db-dropdown-item <?= !$type_filter?'active':'' ?>" href="?filter=<?= $filter ?>"><i class="fas fa-th"></i> All Types</a>
                    <div class="db-dropdown-divider"></div>
                    <?php if ($announcement_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_announcement_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=announcement">
                        <i class="fas fa-bullhorn"></i> Announcements <span class="db-badge db-badge--dark" style="margin-left:auto"><?= $announcement_total ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($incident_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_incident_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=incident_reported">
                        <i class="fas fa-exclamation-triangle"></i> Incident <span class="db-badge db-badge--info" style="margin-left:auto"><?= $incident_total ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($blotter_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_blotter_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=blotter_filed">
                        <i class="fas fa-gavel"></i> Blotter <span class="db-badge db-badge--danger" style="margin-left:auto"><?= $blotter_total ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($complaint_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_complaint_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=complaint_filed">
                        <i class="fas fa-comments"></i> Complaints <span class="db-badge db-badge--warning" style="margin-left:auto"><?= $complaint_total ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($request_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_request_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=document_request">
                        <i class="fas fa-file-alt"></i> Documents <span class="db-badge db-badge--sky" style="margin-left:auto"><?= $request_total ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($appointment_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_appointment_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=appointment_booked">
                        <i class="fas fa-calendar-check"></i> Appointments <span class="db-badge db-badge--success" style="margin-left:auto"><?= $appointment_total ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($medical_assistance_total > 0): ?>
                    <a class="db-dropdown-item <?= $is_medical_assistance_active?'active':'' ?>" href="?filter=<?= $filter ?>&type=medical_assistance_request">
                        <i class="fas fa-hand-holding-medical"></i> Medical <span class="db-badge db-badge--indigo" style="margin-left:auto"><?= $medical_assistance_total ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bulk actions dropdown -->
            <?php if (!empty($notifications)): ?>
            <div class="db-dropdown-wrap" id="bulkDropdown">
                <button class="db-btn db-btn--ghost db-btn--sm" onclick="toggleDropdown('bulkDropdown')">
                    <i class="fas fa-tasks"></i> Bulk Actions
                    <i class="fas fa-chevron-down" style="font-size:10px;opacity:.6"></i>
                </button>
                <div class="db-dropdown-menu">
                    <div class="db-dropdown-header">Select</div>
                    <div class="db-dropdown-item" onclick="selectAll()"><i class="fas fa-check-square"></i> Select All</div>
                    <div class="db-dropdown-item" onclick="deselectAll()"><i class="fas fa-square"></i> Deselect All</div>
                    <div class="db-dropdown-divider"></div>
                    <div class="db-dropdown-header">Actions</div>
                    <div class="db-dropdown-item" onclick="bulkMarkRead()"><i class="fas fa-check-circle" style="color:var(--db-success)"></i> Mark Selected Read</div>
                    <div class="db-dropdown-item" onclick="bulkDelete()" style="color:var(--db-danger)"><i class="fas fa-trash"></i> Delete Selected</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
    <div class="db-empty">
        <i class="fas fa-bell-slash"></i>
        <p>
        <?php
        if ($filter === 'unread')    echo "You're all caught up! No unread notifications.";
        elseif ($filter === 'today') echo "No notifications received today.";
        else                         echo "You don't have any notifications yet.";
        ?>
        </p>
        <?php if ($filter !== 'all' || $type_filter): ?>
        <a href="?filter=all" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <form id="bulkForm" method="POST">
        <input type="hidden" name="action" id="bulkAction">
        <div class="db-table-wrap">
            <table class="db-table" id="notifTable">
                <thead>
                    <tr>
                        <th style="width:42px;text-align:center">
                            <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" style="cursor:pointer">
                        </th>
                        <th style="width:62px"></th>
                        <th>Notification Details</th>
                        <th style="width:150px">Received</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($notifications as $notif):
                    $t  = $notif['type'];
                    $rt = $notif['reference_type'] ?? '';

                    // ── icon / color logic ──
                    $icon       = 'fa-bell';
                    $icon_class = 'db-notif-icon--primary';
                    $badge_var  = 'indigo';

                    if ($rt === 'announcement' || in_array($t, ['general','announcement','alert','status_update'])) {
                        $icon = 'fa-bullhorn'; $icon_class = 'db-notif-icon--dark'; $badge_var = 'dark';
                        if ($t === 'alert')            { $icon = 'fa-exclamation-circle'; $icon_class = 'db-notif-icon--danger';  $badge_var = 'danger';  }
                        elseif ($t === 'announcement') { $icon = 'fa-bullhorn';           $icon_class = 'db-notif-icon--dark';    $badge_var = 'dark';    }
                        elseif ($t === 'general')      { $icon = 'fa-bell';               $icon_class = 'db-notif-icon--primary'; $badge_var = 'indigo';  }
                    } elseif (stripos($t, 'incident') !== false || $rt === 'incident') {
                        $icon = 'fa-exclamation-triangle'; $icon_class = 'db-notif-icon--warning'; $badge_var = 'warning';
                        if ($t === 'incident_assignment') { $icon = 'fa-user-check'; $icon_class = 'db-notif-icon--sky';     $badge_var = 'sky';     }
                        elseif ($t === 'status_update')   { $icon = 'fa-sync-alt';   $icon_class = 'db-notif-icon--success'; $badge_var = 'success'; }
                    } elseif (stripos($t, 'blotter') !== false || $rt === 'blotter') {
                        $icon = 'fa-gavel'; $icon_class = 'db-notif-icon--danger'; $badge_var = 'danger';
                    } elseif (stripos($t, 'complaint') !== false || $rt === 'complaint') {
                        $icon = 'fa-comments'; $icon_class = 'db-notif-icon--warning'; $badge_var = 'warning';
                        if (stripos($t, 'status') !== false) { $icon = 'fa-sync-alt'; $icon_class = 'db-notif-icon--success'; $badge_var = 'success'; }
                    } elseif (stripos($t, 'request') !== false || stripos($t, 'document') !== false || $rt === 'request' || $rt === 'document') {
                        $icon = 'fa-file-alt'; $icon_class = 'db-notif-icon--sky'; $badge_var = 'sky';
                    } elseif (stripos($t, 'appointment') !== false || $rt === 'appointment') {
                        $icon = 'fa-calendar-check'; $icon_class = 'db-notif-icon--success'; $badge_var = 'success';
                        if (stripos($t, 'cancelled') !== false) { $icon = 'fa-calendar-times'; $icon_class = 'db-notif-icon--danger'; $badge_var = 'danger'; }
                    } elseif (stripos($t, 'medical_assistance') !== false || $rt === 'medical_assistance') {
                        $icon = 'fa-hand-holding-medical'; $icon_class = 'db-notif-icon--teal'; $badge_var = 'teal';
                    }

                    // ── view URL ──
                    $is_ann_type = ($rt === 'announcement' || in_array($t, ['general','announcement','alert','status_update']));
                    if ($is_ann_type) {
                        $view_url = 'notification-detail.php?id=' . intval($notif['notification_id']);
                    } elseif (!empty($notif['reference_id'])) {
                        if      ($rt === 'incident')             $view_url = '../incidents/incident-details.php?id='   . intval($notif['reference_id']);
                        elseif  ($rt === 'blotter')              $view_url = '../blotter/view-blotter.php?id='         . intval($notif['reference_id']);
                        elseif  ($rt === 'complaint')            $view_url = '../complaints/complaint-details.php?id=' . intval($notif['reference_id']);
                        elseif  ($rt === 'request' || $rt === 'document') $view_url = '../requests/view-request.php?id=' . intval($notif['reference_id']);
                        elseif  ($rt === 'appointment')          $view_url = '../health/appointments.php';
                        elseif  ($rt === 'medical_assistance')   $view_url = '../health/medical-assistance.php';
                        else                                     $view_url = 'notification-detail.php?id=' . intval($notif['notification_id']);
                    } else {
                        $view_url = 'notification-detail.php?id=' . intval($notif['notification_id']);
                    }

                    $preview_msg = htmlspecialchars(mb_strimwidth($notif['message'], 0, 120, '...'));
                    $full_msg    = htmlspecialchars($notif['message']);
                    $full_title  = htmlspecialchars($notif['title']);
                    $type_label  = htmlspecialchars(ucwords(str_replace('_', ' ', $notif['type'])));
                    $ref_label   = !empty($rt) ? htmlspecialchars(ucwords($rt)) : '';
                    $time_text   = timeAgo($notif['created_at']);
                ?>
                <tr class="db-notif-row <?= !$notif['is_read'] ? 'db-notif-row--unread' : '' ?>"
                    data-preview-title="<?= $full_title ?>"
                    data-preview-message="<?= $preview_msg ?>"
                    data-full-message="<?= $full_msg ?>"
                    data-preview-icon="<?= $icon ?>"
                    data-preview-icon-class="<?= $icon_class ?>"
                    data-preview-type="<?= $type_label ?>"
                    data-ref-label="<?= $ref_label ?>"
                    data-preview-time="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($notif['created_at']))) ?>"
                    data-url="<?= htmlspecialchars($view_url) ?>"
                    data-notif-id="<?= $notif['notification_id'] ?>"
                    data-is-read="<?= $notif['is_read'] ? '1' : '0' ?>">

                    <td style="text-align:center">
                        <input type="checkbox" class="notification-checkbox" name="notification_ids[]"
                               value="<?= $notif['notification_id'] ?>" style="cursor:pointer">
                    </td>
                    <td style="text-align:center">
                        <div class="db-notif-icon-wrap">
                            <div class="db-notif-icon <?= $icon_class ?>">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <?php if (!$notif['is_read']): ?>
                                <span class="db-unread-dot"></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="db-notif-title">
                            <?= $full_title ?>
                            <?php if (!$notif['is_read']): ?>
                                <span class="db-badge db-badge--indigo db-badge--new">NEW</span>
                            <?php endif; ?>
                        </div>
                        <div class="db-notif-preview"><?= $preview_msg ?></div>
                        <div class="db-notif-meta">
                            <span class="db-badge db-badge--<?= $badge_var ?>"><i class="fas fa-tag"></i> <?= $type_label ?></span>
                            <?php if (!empty($rt)): ?>
                            <span class="db-badge db-badge--muted"><i class="fas fa-link"></i> <?= ucwords($rt) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="db-notif-time"><i class="far fa-clock"></i> <?= $time_text ?></div>
                        <div class="db-notif-time" style="margin-top:3px;font-size:10.5px"><?= date('M j, g:i A', strtotime($notif['created_at'])) ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($total_pages > 1): ?>
    <div class="db-panel__footer" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <span style="font-size:12.5px;color:var(--db-muted);font-family:'DM Mono',monospace">
            Showing <?= $offset+1 ?> – <?= min($offset+$per_page, $total) ?> of <?= $total ?>
        </span>
        <div class="db-pagination">
            <a href="?page=1&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>" class="db-page-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-angle-double-left"></i></a>
            <a href="?page=<?= $page-1 ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>" class="db-page-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
            <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
            <a href="?page=<?= $i ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>" class="db-page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="?page=<?= $page+1 ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>" class="db-page-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
            <a href="?page=<?= $total_pages ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>" class="db-page-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="fas fa-angle-double-right"></i></a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div><!-- /db-panel -->

</div><!-- /padding wrapper -->

<!-- ═══════════════════ HOVER PREVIEW CARD ═══════════════════ -->
<div id="notifPreviewCard" class="db-preview-card" style="display:none">
    <div class="db-preview-card__header">
        <div class="db-preview-card__icon" id="previewIconBox"><i class="fas fa-bell" id="previewIcon"></i></div>
        <div style="flex:1;min-width:0">
            <div class="db-preview-card__type" id="previewTypeLabel"></div>
            <div class="db-preview-card__title" id="previewTitle"></div>
        </div>
    </div>
    <div class="db-preview-card__body">
        <p class="db-preview-card__msg" id="previewMessage"></p>
        <div class="db-preview-card__time"><i class="far fa-clock"></i><span id="previewTime"></span></div>
    </div>
</div>

<!-- Delete Confirm -->
<div class="db-modal" id="deleteConfirmModal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Delete Notification</h3>
            <button class="db-modal__close" onclick="closeDbModal('deleteConfirmModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal__confirm-icon db-modal__confirm-icon--danger"><i class="fas fa-trash"></i></div>
            <h3>Are you sure?</h3>
            <p>This action cannot be undone.</p>
        </div>
        <div class="db-modal__footer">
            <button type="button" class="db-btn db-btn--ghost" onclick="closeDbModal('deleteConfirmModal')"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" class="db-btn db-btn--danger" id="confirmDeleteBtn"><i class="fas fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<!-- Bulk Validation -->
<div class="db-modal" id="bulkValidationModal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--warning">
            <h3><i class="fas fa-exclamation-triangle"></i> Action Required</h3>
            <button class="db-modal__close" onclick="closeDbModal('bulkValidationModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal__confirm-icon db-modal__confirm-icon--warning"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 id="bulkValidationTitle">Action Required</h3>
            <p id="bulkValidationMessage">Please select notifications first.</p>
        </div>
        <div class="db-modal__footer">
            <button type="button" class="db-btn db-btn--primary" onclick="closeDbModal('bulkValidationModal')"><i class="fas fa-check"></i> Got it</button>
        </div>
    </div>
</div>

<!-- Bulk Confirm -->
<div class="db-modal" id="bulkConfirmModal">
    <div class="db-modal__box">
        <div class="db-modal__header" id="bulkConfirmHeader">
            <h3 id="bulkConfirmHeaderTitle"><i class="fas fa-check-circle"></i> Confirm Action</h3>
            <button class="db-modal__close" onclick="closeDbModal('bulkConfirmModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal__confirm-icon" id="bulkConfirmIconWrap"><i class="fas fa-check-circle" id="bulkConfirmIcon"></i></div>
            <h3 id="bulkConfirmTitle">Confirm Action</h3>
            <p id="bulkConfirmMessage">Proceed with this bulk action?</p>
        </div>
        <div class="db-modal__footer">
            <button type="button" class="db-btn db-btn--ghost" onclick="closeDbModal('bulkConfirmModal')"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" class="db-btn db-btn--primary" id="bulkConfirmBtn"><i class="fas fa-check"></i> Confirm</button>
        </div>
    </div>
</div>

<script>
/* ── Dropdown toggle ── */
function toggleDropdown(id) {
    document.querySelectorAll('.db-dropdown-wrap.open').forEach(function(el) {
        if (el.id !== id) el.classList.remove('open');
    });
    document.getElementById(id).classList.toggle('open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.db-dropdown-wrap'))
        document.querySelectorAll('.db-dropdown-wrap.open').forEach(function(el) { el.classList.remove('open'); });
});

/* ── Modal helpers ── */
function openDbModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeDbModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', function(e) { if (e.target.classList.contains('db-modal')) closeDbModal(e.target.id); });
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.db-modal--open').forEach(function(m){ closeDbModal(m.id); });
});

/* ── Hover preview ── */
var hoverCard=document.getElementById('notifPreviewCard'),
    previewIcon=document.getElementById('previewIcon'),
    previewIconBox=document.getElementById('previewIconBox'),
    previewTitle=document.getElementById('previewTitle'),
    previewMessage=document.getElementById('previewMessage'),
    previewType=document.getElementById('previewTypeLabel'),
    previewTime=document.getElementById('previewTime'),
    hideTimer=null;

function showHoverCard(row,e){
    if(!hoverCard)return;
    clearTimeout(hideTimer);
    previewTitle.textContent   = row.dataset.previewTitle   ||'';
    previewMessage.textContent = row.dataset.previewMessage ||'';
    previewType.textContent    = row.dataset.previewType    ||'';
    previewTime.textContent    = row.dataset.previewTime    ||'';
    previewIcon.className      = 'fas '+(row.dataset.previewIcon||'fa-bell');
    previewIconBox.className   = 'db-preview-card__icon '+(row.dataset.previewIconClass||'db-notif-icon--primary');
    positionHoverCard(e);
    hoverCard.style.display='block';
}
function positionHoverCard(e){
    if(!hoverCard)return;
    var margin=16,cw=hoverCard.offsetWidth||310,ch=hoverCard.offsetHeight||180;
    var vw=window.innerWidth,vh=window.innerHeight;
    var x=e.clientX+margin,y=e.clientY+margin;
    if(x+cw>vw-margin) x=e.clientX-cw-margin;
    if(y+ch>vh-margin) y=e.clientY-ch-margin;
    hoverCard.style.left=x+'px'; hoverCard.style.top=y+'px';
}
function hideHoverCard(){ if(hoverCard) hoverCard.style.display='none'; }

/* ── Row click ── */
document.querySelectorAll('.db-notif-row').forEach(function(row){
    row.addEventListener('mouseenter',function(e){ showHoverCard(this,e); });
    row.addEventListener('mousemove', function(e){ positionHoverCard(e); });
    row.addEventListener('mouseleave',function(){
        hideTimer=setTimeout(function(){ if(hoverCard&&!hoverCard.matches(':hover')) hideHoverCard(); },150);
    });
    row.addEventListener('click',function(e){
        if(e.target.closest('input[type="checkbox"]')) return;
        var notifId=this.getAttribute('data-notif-id');
        var url=this.getAttribute('data-url');
        var isRead=this.getAttribute('data-is-read');
        if(!notifId) return;
        var dest=(url&&url!=='')?url:('notification-detail.php?id='+notifId);
        if(isRead==='0'){
            var fd=new FormData();
            fd.append('action','mark_read');
            fd.append('notification_id',notifId);
            fetch('mark-as-read.php',{method:'POST',body:fd})
                .catch(function(){})
                .finally(function(){ window.location.href=dest; });
        } else {
            window.location.href=dest;
        }
    });
});

/* ── Bulk helpers ── */
function toggleSelectAll(cb){
    document.querySelectorAll('.notification-checkbox').forEach(function(c){ c.checked=cb.checked; });
}
function selectAll(){
    document.querySelectorAll('.notification-checkbox').forEach(function(c){ c.checked=true; });
    var sa=document.getElementById('selectAllCheckbox'); if(sa) sa.checked=true;
}
function deselectAll(){
    document.querySelectorAll('.notification-checkbox').forEach(function(c){ c.checked=false; });
    var sa=document.getElementById('selectAllCheckbox'); if(sa) sa.checked=false;
}
function showBulkValidation(msg,title){
    document.getElementById('bulkValidationTitle').textContent   = title||'Action Required';
    document.getElementById('bulkValidationMessage').textContent = msg;
    openDbModal('bulkValidationModal');
}
function showBulkConfirm(msg,title,iconClass,isDelete,onConfirm){
    var header=document.getElementById('bulkConfirmHeader');
    header.className='db-modal__header '+(isDelete?'db-modal__header--danger':'db-modal__header--success');
    document.getElementById('bulkConfirmHeaderTitle').innerHTML='<i class="fas '+iconClass+'"></i> '+title;
    document.getElementById('bulkConfirmTitle').textContent=title;
    document.getElementById('bulkConfirmMessage').textContent=msg;
    var wrap=document.getElementById('bulkConfirmIconWrap');
    wrap.className='db-modal__confirm-icon '+(isDelete?'db-modal__confirm-icon--danger':'db-modal__confirm-icon--success');
    document.getElementById('bulkConfirmIcon').className='fas '+iconClass;
    var btn=document.getElementById('bulkConfirmBtn');
    btn.className='db-btn '+(isDelete?'db-btn--danger':'db-btn--success');
    var fresh=btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh,btn);
    fresh.addEventListener('click',function(){ closeDbModal('bulkConfirmModal'); onConfirm(); });
    openDbModal('bulkConfirmModal');
}
function bulkMarkRead(){
    var checked=document.querySelectorAll('.notification-checkbox:checked');
    if(!checked.length){ showBulkValidation('Please select at least one notification.','Nothing Selected'); return; }
    showBulkConfirm('Mark '+checked.length+' notification(s) as read?','Mark as Read','fa-check-circle',false,function(){
        document.getElementById('bulkAction').value='bulk_read';
        document.getElementById('bulkForm').submit();
    });
}
function bulkDelete(){
    var checked=document.querySelectorAll('.notification-checkbox:checked');
    if(!checked.length){ showBulkValidation('Please select at least one notification.','Nothing Selected'); return; }
    showBulkConfirm('Delete '+checked.length+' notification(s)? Cannot be undone.','Delete Notifications','fa-trash',true,function(){
        document.getElementById('bulkAction').value='bulk_delete';
        document.getElementById('bulkForm').submit();
    });
}

/* ── Auto-dismiss alerts ── */
setTimeout(function(){
    document.querySelectorAll('.db-alert').forEach(function(a){
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(function(){ a.remove(); },400);
    });
},5000);
</script>

<?php include '../../includes/footer.php'; ?>