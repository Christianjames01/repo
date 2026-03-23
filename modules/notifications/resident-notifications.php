<?php
/**
 * Resident Notifications — modules/notifications/index.php
 * Resident-only view. ALL original PHP logic preserved exactly.
 * UI: clean personal notification feed (no admin controls).
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title  = 'My Notifications';
$user_id     = $_SESSION['user_id'];
$user_role   = getCurrentUserRole();

/* ── Resident-only notification filter ─────────────────────────────── */
$notification_filter = "(type LIKE '%incident%' OR type LIKE '%request%' OR type LIKE '%document%' OR
    type LIKE '%complaint%' OR type LIKE '%appointment%' OR type LIKE '%medical_assistance%' OR
    type LIKE '%blotter%' OR type IN ('general','announcement','alert','status_update','email_reply') OR
    reference_type IN ('incident','request','document','complaint','appointment',
                       'medical_assistance','blotter','announcement','notification','email_inbox'))";

/* ── POST actions (all original logic preserved) ────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        executeQuery($conn, "DELETE FROM tbl_notifications WHERE notification_id=? AND user_id=? AND $notification_filter", [$nid, $user_id], 'ii');
        $_SESSION['success_message'] = 'Notification deleted.';
        header('Location: index.php'); exit();
    } elseif ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        $s = $conn->prepare("UPDATE tbl_notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
        $s->bind_param("ii", $nid, $user_id); $s->execute(); $s->close();
        $redirect = !empty($_POST['redirect_url']) ? $_POST['redirect_url'] : 'index.php';
        header('Location: ' . $redirect); exit();
    } elseif ($_POST['action'] === 'bulk_delete') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                executeQuery($conn, "DELETE FROM tbl_notifications WHERE notification_id=? AND user_id=? AND $notification_filter", [$nid, $user_id], 'ii');
            }
            $_SESSION['success_message'] = 'Selected notifications deleted.';
        }
        header('Location: index.php'); exit();
    } elseif ($_POST['action'] === 'bulk_read') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                $s = $conn->prepare("UPDATE tbl_notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
                $s->bind_param("ii", $nid, $user_id); $s->execute(); $s->close();
            }
            $_SESSION['success_message'] = 'Marked as read.';
        }
        header('Location: index.php'); exit();
    }
}

/* ── Stats ───────────────────────────────────────────────────────────── */
$stats = fetchOne($conn, "
    SELECT COUNT(*) as total,
        SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read=1 THEN 1 ELSE 0 END) as `read`,
        SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
    FROM tbl_notifications WHERE user_id=? AND $notification_filter", [$user_id], 'i');

/* ── Pagination / filtering ──────────────────────────────────────────── */
$page        = max(1, intval($_GET['page'] ?? 1));
$per_page    = 20;
$offset      = ($page - 1) * $per_page;
$filter      = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? '';

$where  = "user_id=? AND $notification_filter";
$params = [$user_id];
$types  = 'i';

if ($filter === 'unread')    { $where .= " AND is_read=0"; }
elseif ($filter === 'read')  { $where .= " AND is_read=1"; }
elseif ($filter === 'today') { $where .= " AND DATE(created_at)=CURDATE()"; }
elseif ($filter === 'week')  { $where .= " AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"; }

if ($type_filter) {
    if      (stripos($type_filter, 'email') !== false)       $where .= " AND (type='email_reply' OR reference_type='email_inbox')";
    elseif  (stripos($type_filter, 'announcement') !== false || stripos($type_filter, 'general') !== false)
                                                              $where .= " AND (type IN ('general','announcement','alert','status_update') OR reference_type='announcement')";
    elseif  (stripos($type_filter, 'blotter') !== false)     $where .= " AND (type LIKE '%blotter%' OR reference_type='blotter')";
    elseif  (stripos($type_filter, 'incident') !== false)    $where .= " AND (type LIKE '%incident%' OR reference_type='incident')";
    elseif  (stripos($type_filter, 'complaint') !== false)   $where .= " AND (type LIKE '%complaint%' OR reference_type='complaint')";
    elseif  (stripos($type_filter, 'appointment') !== false) $where .= " AND (type LIKE '%appointment%' OR reference_type='appointment')";
    elseif  (stripos($type_filter, 'medical') !== false)     $where .= " AND (type LIKE '%medical%' OR reference_type='medical_assistance')";
    elseif  (stripos($type_filter, 'request') !== false || stripos($type_filter, 'document') !== false)
                                                              $where .= " AND (type LIKE '%request%' OR type LIKE '%document%' OR reference_type IN ('request','document'))";
    else    { $where .= " AND type=?"; $params[] = $type_filter; $types .= 's'; }
}

$count       = fetchOne($conn, "SELECT COUNT(*) as total FROM tbl_notifications WHERE $where", $params, $types);
$total       = $count['total'];
$total_pages = ceil($total / $per_page);
$notifications = fetchAll($conn,
    "SELECT * FROM tbl_notifications WHERE $where ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset]), $types . 'ii');

/* ── Type counts ─────────────────────────────────────────────────────── */
$all_type_stats = fetchAll($conn, "
    SELECT type, reference_type, COUNT(*) as count FROM tbl_notifications
    WHERE user_id=? AND $notification_filter GROUP BY type, reference_type", [$user_id], 'i');

$cnt = ['incident' => 0, 'blotter' => 0, 'complaint' => 0, 'request' => 0,
        'appointment' => 0, 'medical' => 0, 'announcement' => 0, 'email' => 0];
foreach ($all_type_stats as $ts) {
    $t = strtolower(trim($ts['type'] ?? '')); $rt = strtolower(trim($ts['reference_type'] ?? '')); $c = intval($ts['count']);
    if      ($rt === 'email_inbox' || $t === 'email_reply')                                                                    $cnt['email']        += $c;
    elseif  ($rt === 'incident' || stripos($t, 'incident') !== false)                                                          $cnt['incident']     += $c;
    elseif  ($rt === 'blotter' || stripos($t, 'blotter') !== false)                                                            $cnt['blotter']      += $c;
    elseif  ($rt === 'complaint' || stripos($t, 'complaint') !== false)                                                        $cnt['complaint']    += $c;
    elseif  ($rt === 'appointment' || stripos($t, 'appointment') !== false)                                                    $cnt['appointment']  += $c;
    elseif  ($rt === 'medical_assistance' || stripos($t, 'medical') !== false)                                                 $cnt['medical']      += $c;
    elseif  ($rt === 'request' || $rt === 'document' || stripos($t, 'request') !== false || stripos($t, 'document') !== false) $cnt['request']      += $c;
    elseif  ($rt === 'announcement' || in_array($t, ['general', 'announcement', 'alert', 'status_update']))                    $cnt['announcement'] += $c;
}

function nAct($tf, ...$keys) { foreach ($keys as $k) if (stripos($tf, $k) !== false) return true; return false; }

/* ── Icon / colour / label ───────────────────────────────────────────── */
function gIcon($t, $rt) {
    if ($t === 'email_reply' || $rt === 'email_inbox')   return ['fa-envelope',              '#1976d2', 'Email'];
    if ($rt === 'announcement' || in_array($t, ['general', 'announcement', 'alert', 'status_update'])) {
        if ($t === 'alert') return ['fa-exclamation-circle', '#ef4444', 'Alert'];
        return ['fa-bullhorn', '#6366f1', 'Announcement'];
    }
    if (stripos($t, 'incident') !== false || $rt === 'incident')           return ['fa-exclamation-triangle', '#f59e0b', 'Incident'];
    if (stripos($t, 'blotter') !== false  || $rt === 'blotter')            return ['fa-gavel',                '#ef4444', 'Blotter'];
    if (stripos($t, 'complaint') !== false || $rt === 'complaint')         return ['fa-comments',             '#f97316', 'Complaint'];
    if (stripos($t, 'request') !== false || stripos($t, 'document') !== false || $rt === 'request' || $rt === 'document')
                                                                            return ['fa-file-alt',             '#1976d2', 'Document'];
    if (stripos($t, 'appointment') !== false || $rt === 'appointment')     return ['fa-calendar-check',       '#14b8a6', 'Appointment'];
    if (stripos($t, 'medical') !== false || $rt === 'medical_assistance')  return ['fa-hand-holding-medical', '#8b5cf6', 'Medical'];
    return ['fa-bell', '#6b7280', 'Notification'];
}

/* ── View URL ────────────────────────────────────────────────────────── */
function gUrl($t, $rt, $nid, $rid) {
    $ie = ($t === 'email_reply' || $rt === 'email_inbox');
    $ia = ($rt === 'announcement' || in_array($t, ['general', 'announcement', 'alert', 'status_update']));
    if ($ie || $ia || !$rid) return 'notification-detail.php?id=' . $nid;
    if ($rt === 'incident')          return '../incidents/incident-details.php?id=' . $rid;
    if ($rt === 'blotter')           return '../blotter/view-blotter.php?id=' . $rid;
    if ($rt === 'complaint')         return '../complaints/complaint-details.php?id=' . $rid;
    if ($rt === 'request' || $rt === 'document') return '../requests/view-request.php?id=' . $rid;
    if ($rt === 'appointment')       return '../health/appointments.php';
    if ($rt === 'medical_assistance') return '../health/medical-assistance.php';
    return 'notification-detail.php?id=' . $nid;
}

/* timeAgo() is already declared in includes/functions.php — no redeclaration needed */

$tp = $type_filter ? '&type=' . urlencode($type_filter) : '';

/* ── CSS ─────────────────────────────────────────────────────────────── */
$extra_css = '
<style>
@import url("https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap");

.main-content {
    padding: 0 !important;
    background: #f0f4f8 !important;
    font-family: "Outfit", sans-serif !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

:root {
    --bg:         #f0f4f8;
    --surface:    #ffffff;
    --border:     #e2e8f0;
    --text:       #1a202c;
    --text-2:     #4a5568;
    --text-3:     #a0aec0;
    --blue:       #3b82f6;
    --blue-light: #eff6ff;
    --blue-dark:  #2563eb;
    --green:      #10b981;
    --red:        #ef4444;
    --orange:     #f59e0b;
    --shadow-sm:  0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow-md:  0 4px 16px rgba(0,0,0,.08);
    --radius:     14px;
    --radius-sm:  8px;
}

/* ── Scroll wrapper ──────────────────────────────────────────────────── */
.rn-page {
    flex: 1; overflow-y: auto; overflow-x: hidden;
    padding: 20px 24px 32px;
    display: flex; flex-direction: column; gap: 18px;
}
.rn-page::-webkit-scrollbar { width: 6px; }
.rn-page::-webkit-scrollbar-track { background: transparent; }
.rn-page::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 3px; }

/* ── Page header ─────────────────────────────────────────────────────── */
.rn-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.rn-header-left { display: flex; align-items: center; gap: 12px; }
.rn-header-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 19px; flex-shrink: 0;
}
.rn-title    { font-size: 20px; font-weight: 800; color: var(--text); line-height: 1.1; }
.rn-subtitle { font-size: 13px; color: var(--text-3); font-weight: 500; margin-top: 2px; }
.rn-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── Buttons ─────────────────────────────────────────────────────────── */
.rn-btn {
    padding: 7px 14px; border-radius: var(--radius-sm);
    font-size: 13px; font-weight: 600; cursor: pointer;
    border: 1.5px solid var(--border); background: var(--surface); color: var(--text-2);
    display: inline-flex; align-items: center; gap: 6px;
    transition: all .15s; font-family: "Outfit", sans-serif;
    text-decoration: none; line-height: 1; white-space: nowrap;
}
.rn-btn:hover   { border-color: var(--blue); color: var(--blue); background: var(--blue-light); text-decoration: none; }
.rn-btn.primary { background: var(--blue); color: #fff; border-color: var(--blue); }
.rn-btn.primary:hover { background: var(--blue-dark); color: #fff; }
.rn-btn.success { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.rn-btn.success:hover { background: #dcfce7; }
.rn-btn.danger  { background: #fef2f2; color: var(--red); border-color: #fecaca; }
.rn-btn.danger:hover  { background: #fee2e2; }
.rn-btn i { font-size: 11px; }

/* ── Stat strip ──────────────────────────────────────────────────────── */
.rn-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
@media(max-width: 700px) { .rn-stats { grid-template-columns: repeat(2, 1fr); } }
.rn-stat {
    background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius);
    padding: 16px 18px; text-decoration: none;
    display: flex; flex-direction: column; gap: 4px;
    transition: all .15s; box-shadow: var(--shadow-sm);
    position: relative; overflow: hidden;
}
.rn-stat::before {
    content: ""; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--stat-color, #e2e8f0);
}
.rn-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); text-decoration: none; }
.rn-stat-num { font-size: 28px; font-weight: 800; color: var(--stat-color, var(--text)); line-height: 1; }
.rn-stat-lbl { font-size: 12px; font-weight: 600; color: var(--text-3); }

/* ── Filter tabs ─────────────────────────────────────────────────────── */
.rn-tabs {
    display: flex; gap: 5px; flex-wrap: wrap;
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 6px; box-shadow: var(--shadow-sm);
}
.rn-tab {
    padding: 7px 16px; border-radius: var(--radius-sm);
    font-size: 13px; font-weight: 600; color: var(--text-2);
    cursor: pointer; text-decoration: none; transition: all .13s;
    display: inline-flex; align-items: center; gap: 6px;
    border: 1.5px solid transparent;
}
.rn-tab:hover  { background: var(--blue-light); color: var(--blue); text-decoration: none; }
.rn-tab.active { background: var(--blue); color: #fff; border-color: var(--blue); }
.rn-tab .tab-badge {
    border-radius: 20px; padding: 1px 7px; font-size: 11px; font-weight: 700;
    min-width: 20px; text-align: center; background: #e2e8f0; color: var(--text-3);
}
.rn-tab.active .tab-badge { background: rgba(255,255,255,.3); color: #fff; }

/* ── Two-column layout ───────────────────────────────────────────────── */
.rn-layout { display: grid; grid-template-columns: 210px 1fr; gap: 16px; align-items: start; }
@media(max-width: 860px) { .rn-layout { grid-template-columns: 1fr; } .rn-type-sidebar { display: none; } }

/* ── Type sidebar ────────────────────────────────────────────────────── */
.rn-type-sidebar {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm);
    position: sticky; top: 0;
}
.rn-type-header {
    padding: 12px 14px; font-size: 10px; font-weight: 800;
    letter-spacing: .7px; text-transform: uppercase; color: var(--text-3);
    border-bottom: 1px solid var(--border);
}
.rn-type-item {
    padding: 10px 14px; display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 600; color: var(--text-2);
    text-decoration: none; cursor: pointer; transition: background .12s;
    border-bottom: 1px solid #f7fafc;
}
.rn-type-item:hover  { background: var(--blue-light); color: var(--blue); text-decoration: none; }
.rn-type-item.active { background: var(--blue-light); color: var(--blue); }
.rn-type-dot {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; flex-shrink: 0;
}
.rn-type-count {
    margin-left: auto; font-size: 11px; font-weight: 700;
    background: #e2e8f0; color: var(--text-3);
    border-radius: 20px; padding: 1px 8px; min-width: 22px; text-align: center;
}
.rn-type-item.active .rn-type-count { background: #dbeafe; color: var(--blue); }

/* ── Feed panel ──────────────────────────────────────────────────────── */
.rn-feed-wrap {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
    box-shadow: var(--shadow-sm); display: flex; flex-direction: column;
}
.rn-feed-toolbar {
    padding: 10px 14px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.rn-search {
    display: flex; align-items: center;
    background: #f7fafc; border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); overflow: hidden; flex: 1; min-width: 160px;
}
.rn-search i { padding: 0 10px; color: var(--text-3); font-size: 12px; flex-shrink: 0; }
.rn-search input {
    background: transparent; border: none; outline: none;
    color: var(--text); font-size: 13px; font-family: "Outfit", sans-serif;
    padding: 7px 10px 7px 0; flex: 1; min-width: 0;
}
.rn-search input::placeholder { color: var(--text-3); }
.rn-search:focus-within { border-color: var(--blue); background: #fff; }

/* ── Dropdown ────────────────────────────────────────────────────────── */
.rn-dd { position: relative; display: inline-block; }
.rn-dd-menu {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: 10px; box-shadow: var(--shadow-md);
    min-width: 170px; z-index: 500; padding: 4px;
    animation: ddFadeIn .12s ease;
}
@keyframes ddFadeIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
.rn-dd.open .rn-dd-menu { display: block; }
.rn-dd-item {
    padding: 8px 12px; border-radius: 6px; font-size: 13px; color: var(--text);
    cursor: pointer; display: flex; align-items: center; gap: 8px;
    transition: background .1s; font-family: "Outfit", sans-serif;
}
.rn-dd-item:hover { background: var(--blue-light); color: var(--blue); }
.rn-dd-item i { font-size: 11px; width: 13px; }
.rn-dd-item.danger { color: var(--red); }
.rn-dd-item.danger:hover { background: #fef2f2; }
.rn-dd-sep { height: 1px; background: var(--border); margin: 3px 0; }
.rn-dd-header { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3); padding: 5px 12px 2px; }

/* ── Notification cards ──────────────────────────────────────────────── */
.rn-card {
    display: flex; align-items: flex-start; gap: 13px;
    padding: 14px 16px; border-bottom: 1px solid #f7fafc;
    cursor: pointer; transition: background .12s;
    text-decoration: none; position: relative;
}
.rn-card:last-child { border-bottom: none; }
.rn-card:hover { background: #f7fbff; text-decoration: none; }
.rn-card.unread       { background: #f0f7ff; border-left: 3px solid var(--blue); }
.rn-card.unread:hover { background: #e7f3ff; }
.rn-card.unread-email       { background: #fffbf0; border-left: 3px solid var(--orange); }
.rn-card.unread-email:hover { background: #fff8e1; }

.rn-card-icon {
    width: 40px; height: 40px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0; margin-top: 1px;
}
.rn-card-body { flex: 1; min-width: 0; }
.rn-card-title {
    font-size: 14px; font-weight: 700; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
}
.rn-card-title.read { font-weight: 500; color: var(--text-2); }
.rn-card-preview {
    font-size: 12.5px; color: var(--text-3); margin-top: 3px; line-height: 1.5;
    overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.rn-card-meta { margin-top: 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.rn-card-type {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700;
}
.rn-card-time { font-size: 11px; color: var(--text-3); display: flex; align-items: center; gap: 4px; }

.rn-card-right {
    display: flex; flex-direction: column; align-items: flex-end;
    gap: 8px; flex-shrink: 0; padding-left: 6px;
}
.rn-card-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s; }
.rn-card:hover .rn-card-actions { opacity: 1; }
.rn-card-cb { width: 15px; height: 15px; accent-color: var(--blue); cursor: pointer; }
.rn-unread-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }

.rn-icon-btn {
    width: 28px; height: 28px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    border: 1.5px solid var(--border); background: #fff;
    color: var(--text-3); cursor: pointer; font-size: 11px;
    transition: all .13s; font-family: inherit;
}
.rn-icon-btn:hover       { border-color: var(--blue); color: var(--blue); background: var(--blue-light); }
.rn-icon-btn.danger:hover{ border-color: var(--red);  color: var(--red);  background: #fef2f2; }

/* NEW badge */
.rn-new-badge {
    display: inline-flex; padding: 1px 6px; border-radius: 4px;
    font-size: 10px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase;
    animation: rn-pulse 2s infinite;
}
.rn-new-blue  { background: #dbeafe; color: #1d4ed8; }
.rn-new-amber { background: #fef3c7; color: #d97706; }
@keyframes rn-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Empty state ─────────────────────────────────────────────────────── */
.rn-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 64px 24px; gap: 12px; text-align: center;
}
.rn-empty-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: var(--blue-light);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: var(--blue);
}
.rn-empty h3 { font-size: 17px; font-weight: 700; color: var(--text); margin: 0; }
.rn-empty p  { font-size: 13px; color: var(--text-3); max-width: 240px; margin: 0; line-height: 1.6; }

/* ── Pagination ──────────────────────────────────────────────────────── */
.rn-pagination {
    padding: 12px 16px; display: flex; align-items: center;
    justify-content: space-between; border-top: 1px solid var(--border);
    flex-wrap: wrap; gap: 8px;
}
.rn-pagination-info { font-size: 12.5px; color: var(--text-3); }
.rn-pg-btns { display: flex; gap: 4px; }
.rn-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 8px; font-size: 12.5px; font-weight: 600;
    text-decoration: none; color: var(--text-2);
    border: 1.5px solid var(--border); background: var(--surface);
    transition: all .12s; cursor: pointer;
}
.rn-pg-btn:hover  { background: var(--blue-light); color: var(--blue); border-color: #bfdbfe; text-decoration: none; }
.rn-pg-btn.active { background: var(--blue); border-color: var(--blue); color: #fff; }
.rn-pg-btn.disabled { opacity: .3; pointer-events: none; }

/* ── Alert ───────────────────────────────────────────────────────────── */
.rn-alert {
    border-radius: var(--radius-sm); padding: 10px 16px;
    font-size: 13px; font-weight: 500;
    display: flex; align-items: center; gap: 9px;
}
.rn-alert.success { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
.rn-alert.error   { background: #fef2f2; color: #991b1b; border: 1.5px solid #fecaca; }
.rn-alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .5; color: inherit; line-height: 1; }

/* ── Modal ───────────────────────────────────────────────────────────── */
.rn-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 9999;
    align-items: center; justify-content: center;
}
.rn-modal-overlay.open { display: flex; }
.rn-modal {
    background: var(--surface); border-radius: 16px;
    width: 400px; max-width: 94vw;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: rn-mdIn .2s ease;
}
@keyframes rn-mdIn { from{opacity:0;transform:scale(.93)} to{opacity:1;transform:scale(1)} }
.rn-modal-header {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.rn-modal-header h3 { font-size: 15px; font-weight: 700; color: var(--text); margin: 0; display: flex; align-items: center; gap: 8px; }
.rn-modal-close {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    border: 1.5px solid var(--border); background: transparent;
    color: var(--text-3); cursor: pointer; font-size: 17px; transition: all .13s;
}
.rn-modal-close:hover { background: #fef2f2; color: var(--red); border-color: #fecaca; }
.rn-modal-body { padding: 24px 20px 16px; text-align: center; }
.rn-modal-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 24px; }
.rn-modal-icon.danger  { background: #fef2f2; color: var(--red); }
.rn-modal-icon.success { background: #f0fdf4; color: #16a34a; }
.rn-modal-body h3 { font-size: 16px; font-weight: 700; color: var(--text); margin: 0 0 6px; }
.rn-modal-body p  { font-size: 13px; color: var(--text-3); margin: 0; line-height: 1.6; }
.rn-modal-footer { padding: 0 20px 18px; display: flex; gap: 8px; }
.rn-modal-footer .rn-btn { flex: 1; justify-content: center; }

/* ── Toast ───────────────────────────────────────────────────────────── */
.rn-toast {
    position: fixed; bottom: 22px; right: 22px;
    background: #1e293b; color: #e2e8f0;
    padding: 12px 18px; border-radius: 10px;
    font-size: 13px; font-weight: 600;
    box-shadow: 0 8px 28px rgba(0,0,0,.2); z-index: 99999;
    display: flex; align-items: center; gap: 10px;
    transform: translateY(60px); opacity: 0; transition: all .25s ease;
    font-family: "Outfit", sans-serif;
}
.rn-toast.show { transform: translateY(0); opacity: 1; }
.rn-toast.success i { color: #34d399; }
.rn-toast.error   i { color: #f87171; }

/* ── DARK MODE OVERRIDES ─────────────────────────────────────────────── */
body.dark-mode .main-content {
    background: #0f172a !important;
}
body.dark-mode .rn-page {
    background: #0f172a !important;
}

/* Page header */
body.dark-mode .rn-title    { color: #f1f5f9 !important; }
body.dark-mode .rn-subtitle { color: #64748b !important; }

/* Stat cards */
body.dark-mode .rn-stat {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rn-stat-lbl { color: #64748b !important; }

/* Filter tabs bar */
body.dark-mode .rn-tabs {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rn-tab {
    color: #94a3b8 !important;
}
body.dark-mode .rn-tab:hover {
    background: #1e3a5f !important;
    color: #60a5fa !important;
}
body.dark-mode .rn-tab.active {
    background: #3b82f6 !important;
    color: #fff !important;
}
body.dark-mode .rn-tab .tab-badge {
    background: #334155 !important;
    color: #94a3b8 !important;
}

/* Type sidebar */
body.dark-mode .rn-type-sidebar {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rn-type-header {
    color: #64748b !important;
    border-bottom-color: #334155 !important;
}
body.dark-mode .rn-type-item {
    color: #94a3b8 !important;
    border-bottom-color: #1e293b !important;
}
body.dark-mode .rn-type-item:hover,
body.dark-mode .rn-type-item.active {
    background: #1e3a5f !important;
    color: #60a5fa !important;
}
body.dark-mode .rn-type-count {
    background: #334155 !important;
    color: #64748b !important;
}
body.dark-mode .rn-type-item.active .rn-type-count {
    background: #1e3a5f !important;
    color: #60a5fa !important;
}

/* Feed panel */
body.dark-mode .rn-feed-wrap {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rn-feed-toolbar {
    border-bottom-color: #334155 !important;
    background: #1e293b !important;
}

/* Search bar */
body.dark-mode .rn-search {
    background: #0f172a !important;
    border-color: #334155 !important;
}
body.dark-mode .rn-search input {
    color: #e2e8f0 !important;
    background: transparent !important;
}
body.dark-mode .rn-search input::placeholder { color: #475569 !important; }
body.dark-mode .rn-search:focus-within {
    border-color: #3b82f6 !important;
    background: #0f172a !important;
}

/* Buttons */
body.dark-mode .rn-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}
body.dark-mode .rn-btn:hover {
    background: #1e3a5f !important;
    border-color: #3b82f6 !important;
    color: #60a5fa !important;
}
body.dark-mode .rn-btn.primary {
    background: #3b82f6 !important;
    color: #fff !important;
    border-color: #3b82f6 !important;
}
body.dark-mode .rn-btn.success {
    background: #052e16 !important;
    color: #34d399 !important;
    border-color: #064e3b !important;
}
body.dark-mode .rn-btn.danger {
    background: #450a0a !important;
    color: #f87171 !important;
    border-color: #7f1d1d !important;
}

/* Dropdown menu */
body.dark-mode .rn-dd-menu {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rn-dd-item { color: #e2e8f0 !important; }
body.dark-mode .rn-dd-item:hover {
    background: #1e3a5f !important;
    color: #60a5fa !important;
}
body.dark-mode .rn-dd-item.danger { color: #f87171 !important; }
body.dark-mode .rn-dd-item.danger:hover { background: #450a0a !important; }
body.dark-mode .rn-dd-sep { background: #334155 !important; }
body.dark-mode .rn-dd-header { color: #475569 !important; }

/* Notification cards */
body.dark-mode .rn-card {
    border-bottom-color: #1e293b !important;
    background: #1e293b !important;
}
body.dark-mode .rn-card:hover { background: #243044 !important; }
body.dark-mode .rn-card.unread {
    background: #1a2d4a !important;
    border-left-color: #3b82f6 !important;
}
body.dark-mode .rn-card.unread:hover { background: #1e3a5f !important; }
body.dark-mode .rn-card.unread-email {
    background: #2a1f0a !important;
    border-left-color: #f59e0b !important;
}
body.dark-mode .rn-card.unread-email:hover { background: #352508 !important; }
body.dark-mode .rn-card-title { color: #f1f5f9 !important; }
body.dark-mode .rn-card-title.read { color: #64748b !important; }
body.dark-mode .rn-card-preview { color: #64748b !important; }
body.dark-mode .rn-card-time { color: #475569 !important; }

/* Icon buttons on cards */
body.dark-mode .rn-icon-btn {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #64748b !important;
}
body.dark-mode .rn-icon-btn:hover {
    background: #1e3a5f !important;
    border-color: #3b82f6 !important;
    color: #60a5fa !important;
}
body.dark-mode .rn-icon-btn.danger:hover {
    background: #450a0a !important;
    border-color: #7f1d1d !important;
    color: #f87171 !important;
}

/* NEW badges */
body.dark-mode .rn-new-blue  { background: #1e3a5f !important; color: #93c5fd !important; }
body.dark-mode .rn-new-amber { background: #2a1f0a !important; color: #fbbf24 !important; }

/* Pagination */
body.dark-mode .rn-pagination { border-top-color: #334155 !important; }
body.dark-mode .rn-pagination-info { color: #475569 !important; }
body.dark-mode .rn-pg-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}
body.dark-mode .rn-pg-btn:hover {
    background: #1e3a5f !important;
    color: #60a5fa !important;
    border-color: #3b82f6 !important;
}
body.dark-mode .rn-pg-btn.active {
    background: #3b82f6 !important;
    border-color: #3b82f6 !important;
    color: #fff !important;
}

/* Empty state */
body.dark-mode .rn-empty-icon {
    background: #1e3a5f !important;
    color: #60a5fa !important;
}
body.dark-mode .rn-empty h3 { color: #f1f5f9 !important; }
body.dark-mode .rn-empty p  { color: #64748b !important; }

/* Modals */
body.dark-mode .rn-modal {
    background: #1e293b !important;
}
body.dark-mode .rn-modal-header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .rn-modal-header h3 { color: #f1f5f9 !important; }
body.dark-mode .rn-modal-close {
    border-color: #334155 !important;
    color: #64748b !important;
}
body.dark-mode .rn-modal-body h3 { color: #f1f5f9 !important; }
body.dark-mode .rn-modal-body p  { color: #64748b !important; }
body.dark-mode .rn-modal-footer  { border-top-color: #334155 !important; }

/* Alert banners */
body.dark-mode .rn-alert.success {
    background: #052e16 !important;
    color: #34d399 !important;
    border-color: #064e3b !important;
}
body.dark-mode .rn-alert.error {
    background: #450a0a !important;
    color: #f87171 !important;
    border-color: #7f1d1d !important;
}

/* Scrollbar */
body.dark-mode .rn-page::-webkit-scrollbar-thumb { background: #334155 !important; }
</style>';

include '../../includes/header.php';
?>

<!-- ══ PAGE WRAPPER ══ -->
<div class="rn-page">

    <!-- ══ ALERTS ══ -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="rn-alert success" id="rnAlert">
        <i class="fas fa-check-circle"></i>
        <?= htmlspecialchars($_SESSION['success_message']) ?>
        <button class="rn-alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php unset($_SESSION['success_message']); endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="rn-alert error" id="rnAlertErr">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($_SESSION['error_message']) ?>
        <button class="rn-alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <!-- ══ PAGE HEADER ══ -->
    <div class="rn-header">
        <div class="rn-header-left">
            <div class="rn-header-icon"><i class="fas fa-bell"></i></div>
            <div>
                <div class="rn-title">My Notifications</div>
                <div class="rn-subtitle">
                    <?php
                    $lblMap = ['all'=>'All notifications','unread'=>'Unread only','read'=>'Previously read','today'=>'Received today','week'=>'Last 7 days'];
                    echo $lblMap[$filter] ?? 'All notifications';
                    if ($type_filter) echo ' &nbsp;·&nbsp; Filtered by <strong>' . htmlspecialchars(ucfirst(str_replace('_',' ',$type_filter))) . '</strong>';
                    ?>
                </div>
            </div>
        </div>
        <div class="rn-header-actions">
            <?php if ($stats['unread'] > 0): ?>
            <a href="<?= BASE_URL ?>/modules/notifications/mark_all_read.php" class="rn-btn success">
                <i class="fas fa-check-double"></i> Mark All Read
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/notifications/resident-new-incident.php" class="rn-btn primary">
                <i class="fas fa-plus"></i> New Incident
            </a>
            <button class="rn-btn" onclick="location.reload()" title="Refresh"><i class="fas fa-sync-alt"></i></button>
        </div>
    </div>

    <!-- ══ STAT CARDS ══ -->
    <div class="rn-stats">
        <a href="?filter=unread<?= $tp ?>" class="rn-stat" style="--stat-color:<?= $stats['unread'] > 0 ? '#ef4444' : '#10b981' ?>">
            <div class="rn-stat-num"><?= number_format($stats['unread']) ?></div>
            <div class="rn-stat-lbl"><?= $stats['unread'] > 0 ? 'Unread' : 'All Read ✓' ?></div>
        </a>
        <a href="?filter=today<?= $tp ?>" class="rn-stat" style="--stat-color:#3b82f6">
            <div class="rn-stat-num"><?= number_format($stats['today']) ?></div>
            <div class="rn-stat-lbl">Today</div>
        </a>
        <a href="?filter=week<?= $tp ?>" class="rn-stat" style="--stat-color:#8b5cf6">
            <div class="rn-stat-num"><?= number_format($stats['this_week']) ?></div>
            <div class="rn-stat-lbl">This Week</div>
        </a>
        <a href="?filter=all<?= $tp ?>" class="rn-stat" style="--stat-color:#f59e0b">
            <div class="rn-stat-num"><?= number_format($stats['total']) ?></div>
            <div class="rn-stat-lbl">Total</div>
        </a>
    </div>

    <!-- ══ FILTER TABS ══ -->
    <div class="rn-tabs">
        <a href="?filter=all<?= $tp ?>"    class="rn-tab <?= $filter==='all'   ?'active':'' ?>">All <span class="tab-badge"><?= $stats['total'] ?></span></a>
        <a href="?filter=unread<?= $tp ?>" class="rn-tab <?= $filter==='unread'?'active':'' ?>">
            Unread<?php if($stats['unread']>0): ?> <span class="tab-badge"><?= $stats['unread'] ?></span><?php endif; ?>
        </a>
        <a href="?filter=read<?= $tp ?>"   class="rn-tab <?= $filter==='read'  ?'active':'' ?>">Read</a>
        <a href="?filter=today<?= $tp ?>"  class="rn-tab <?= $filter==='today' ?'active':'' ?>">Today</a>
        <a href="?filter=week<?= $tp ?>"   class="rn-tab <?= $filter==='week'  ?'active':'' ?>">This Week</a>
    </div>

    <!-- ══ TWO-COLUMN LAYOUT ══ -->
    <div class="rn-layout">

        <!-- Type sidebar -->
        <div class="rn-type-sidebar">
            <div class="rn-type-header">Filter by Type</div>

            <a href="?filter=<?= $filter ?>" class="rn-type-item <?= !$type_filter ? 'active' : '' ?>">
                <span class="rn-type-dot" style="background:#eff6ff;color:#3b82f6"><i class="fas fa-list" style="font-size:12px"></i></span>
                All Types
                <span class="rn-type-count"><?= $stats['total'] ?></span>
            </a>

            <?php
            $typeItems = [
                ['email',        'email_reply',         'fa-envelope',              '#1976d2', 'Email',        $cnt['email']],
                ['announcement', 'announcement',        'fa-bullhorn',              '#6366f1', 'Announcements',$cnt['announcement']],
                ['incident',     'incident_reported',   'fa-exclamation-triangle',  '#f59e0b', 'Incidents',    $cnt['incident']],
                ['blotter',      'blotter_filed',       'fa-gavel',                 '#ef4444', 'Blotter',      $cnt['blotter']],
                ['complaint',    'complaint_filed',     'fa-comments',              '#f97316', 'Complaints',   $cnt['complaint']],
                ['document',     'document_request',    'fa-file-alt',              '#1976d2', 'Documents',    $cnt['request']],
                ['appointment',  'appointment_booked',  'fa-calendar-check',        '#14b8a6', 'Appointments', $cnt['appointment']],
                ['medical',      'medical_assistance',  'fa-hand-holding-medical',  '#8b5cf6', 'Medical',      $cnt['medical']],
            ];
            foreach ($typeItems as [$key, $typeParam, $icon, $color, $label, $count]):
                if ($count <= 0) continue;
                $isActive = nAct($type_filter, $key);
            ?>
            <a href="?filter=<?= $filter ?>&type=<?= $typeParam ?>" class="rn-type-item <?= $isActive ? 'active' : '' ?>">
                <span class="rn-type-dot" style="background:<?= $color ?>18;color:<?= $color ?>">
                    <i class="fas <?= $icon ?>" style="font-size:11px"></i>
                </span>
                <?= $label ?>
                <span class="rn-type-count"><?= $count ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Feed panel -->
        <div class="rn-feed-wrap">

            <!-- Toolbar: search + bulk actions only -->
            <div class="rn-feed-toolbar">
                <div class="rn-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search notifications…" oninput="rnSearch(this.value)">
                </div>

                <div class="rn-dd" id="rnDdBulk">
                    <button class="rn-btn" onclick="rnToggleDd('rnDdBulk')">
                        <i class="fas fa-tasks"></i>
                        <i class="fas fa-chevron-down" style="font-size:9px;opacity:.5"></i>
                    </button>
                    <div class="rn-dd-menu">
                        <div class="rn-dd-header">Selection</div>
                        <div class="rn-dd-item" onclick="rnSelAll()"><i class="fas fa-check-square"></i> Select All</div>
                        <div class="rn-dd-item" onclick="rnDeselAll()"><i class="fas fa-square"></i> Deselect All</div>
                        <div class="rn-dd-sep"></div>
                        <div class="rn-dd-header">Actions</div>
                        <div class="rn-dd-item" onclick="rnBulkRead()"><i class="fas fa-check-circle" style="color:#16a34a"></i> Mark as Read</div>
                        <div class="rn-dd-item danger" onclick="rnBulkDelete()"><i class="fas fa-trash"></i> Delete Selected</div>
                    </div>
                </div>

                <input type="checkbox" id="rnSelAll" class="rn-card-cb" title="Select all" onchange="rnToggleAll(this)">
            </div>

            <!-- Notifications -->
            <?php if (empty($notifications)): ?>
            <div class="rn-empty">
                <div class="rn-empty-icon">
                    <i class="fas <?= $filter === 'unread' ? 'fa-check-double' : 'fa-bell-slash' ?>"></i>
                </div>
                <h3><?= $filter === 'unread' ? "You're all caught up!" : 'No notifications found' ?></h3>
                <p><?= $filter === 'unread' ? 'No unread notifications at the moment.' : 'Nothing matches your current filters.' ?></p>
                <?php if ($filter !== 'all' || $type_filter): ?>
                <a href="?filter=all" class="rn-btn" style="margin-top:8px"><i class="fas fa-list"></i> View All</a>
                <?php endif; ?>
            </div>
            <?php else: ?>

            <form id="rnBulkForm" method="POST">
                <input type="hidden" name="action" id="rnBulkAction">

                <?php foreach ($notifications as $notif):
                    $t   = $notif['type'] ?? '';
                    $rt  = $notif['reference_type'] ?? '';
                    $nid = intval($notif['notification_id']);
                    $rid = intval($notif['reference_id'] ?? 0);

                    $is_email  = ($t === 'email_reply' || $rt === 'email_inbox');
                    $is_unread = !$notif['is_read'];

                    [$icon, $color, $type_label] = gIcon($t, $rt);
                    $view_url = gUrl($t, $rt, $nid, $rid);
                    $preview  = htmlspecialchars(mb_strimwidth($notif['message'] ?? '', 0, 140, '…'));
                    $icon_bg  = 'background:' . $color . '18;color:' . $color;
                    $row_cls  = $is_unread ? ($is_email ? 'unread-email' : 'unread') : '';
                ?>
                <a class="rn-card <?= $row_cls ?>"
                   href="<?= htmlspecialchars($view_url) ?>"
                   data-nid="<?= $nid ?>"
                   data-read="<?= $notif['is_read'] ? 1 : 0 ?>"
                   data-search="<?= strtolower(htmlspecialchars($notif['title'] . ' ' . ($notif['message'] ?? ''))) ?>"
                   onclick="rnMarkRead(event, <?= $nid ?>, <?= $notif['is_read'] ? 1 : 0 ?>)">

                    <!-- Checkbox -->
                    <div onclick="event.preventDefault();event.stopPropagation()" style="padding-top:2px">
                        <input type="checkbox" class="rn-card-cb rn-cb" name="notification_ids[]" value="<?= $nid ?>">
                    </div>

                    <!-- Icon -->
                    <div class="rn-card-icon" style="<?= $icon_bg ?>">
                        <i class="fas <?= $icon ?>"></i>
                    </div>

                    <!-- Body -->
                    <div class="rn-card-body">
                        <div class="rn-card-title <?= $is_unread ? '' : 'read' ?>">
                            <?= htmlspecialchars($notif['title']) ?>
                            <?php if ($is_unread): ?>
                            <span class="rn-new-badge <?= $is_email ? 'rn-new-amber' : 'rn-new-blue' ?>">
                                <?= $is_email ? 'NEW EMAIL' : 'NEW' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($preview): ?>
                        <div class="rn-card-preview"><?= $preview ?></div>
                        <?php endif; ?>
                        <div class="rn-card-meta">
                            <span class="rn-card-type" style="background:<?= $color ?>18;color:<?= $color ?>">
                                <i class="fas <?= $icon ?>" style="font-size:10px"></i>
                                <?= htmlspecialchars($type_label) ?>
                            </span>
                            <span class="rn-card-time">
                                <i class="fas fa-clock" style="font-size:10px"></i>
                                <?= timeAgo($notif['created_at']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Right: dot + hover actions -->
                    <div class="rn-card-right">
                        <?php if ($is_unread): ?>
                        <div class="rn-unread-dot" style="background:<?= $is_email ? '#f59e0b' : '#3b82f6' ?>"></div>
                        <?php endif; ?>
                        <div class="rn-card-actions" onclick="event.preventDefault();event.stopPropagation()">
                            <?php if ($is_unread): ?>
                            <button class="rn-icon-btn" title="Mark as read"
                                    onclick="rnMarkReadBtn(<?= $nid ?>, this)">
                                <i class="fas fa-check"></i>
                            </button>
                            <?php endif; ?>
                            <button class="rn-icon-btn danger" title="Delete"
                                    onclick="rnConfirmDelete(<?= $nid ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="rn-pagination">
                <span class="rn-pagination-info">
                    Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= number_format($total) ?>
                </span>
                <div class="rn-pg-btns">
                    <?php $pb = "?filter=$filter$tp"; ?>
                    <a href="<?= $pb ?>&page=1"              class="rn-pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-angle-double-left"></i></a>
                    <a href="<?= $pb ?>&page=<?= $page-1 ?>" class="rn-pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                    <a href="<?= $pb ?>&page=<?= $i ?>" class="rn-pg-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <a href="<?= $pb ?>&page=<?= $page+1 ?>"          class="rn-pg-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
                    <a href="<?= $pb ?>&page=<?= $total_pages ?>"     class="rn-pg-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="fas fa-angle-double-right"></i></a>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div><!-- /rn-feed-wrap -->

    </div><!-- /rn-layout -->

</div><!-- /rn-page -->

<!-- ══ DELETE MODAL ══ -->
<div class="rn-modal-overlay" id="rnDelModal">
    <div class="rn-modal">
        <div class="rn-modal-header">
            <h3><i class="fas fa-trash" style="color:#ef4444"></i> Delete Notification</h3>
            <button class="rn-modal-close" onclick="rnCloseModal('rnDelModal')">×</button>
        </div>
        <div class="rn-modal-body">
            <div class="rn-modal-icon danger"><i class="fas fa-trash"></i></div>
            <h3>Delete this notification?</h3>
            <p>This notification will be permanently removed and cannot be recovered.</p>
        </div>
        <div class="rn-modal-footer">
            <button class="rn-btn" onclick="rnCloseModal('rnDelModal')"><i class="fas fa-times"></i> Cancel</button>
            <button class="rn-btn danger" id="rnDelConfirmBtn"><i class="fas fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<!-- ══ BULK MODAL ══ -->
<div class="rn-modal-overlay" id="rnBulkModal">
    <div class="rn-modal">
        <div class="rn-modal-header">
            <h3 id="rnBulkModalTitle"></h3>
            <button class="rn-modal-close" onclick="rnCloseModal('rnBulkModal')">×</button>
        </div>
        <div class="rn-modal-body">
            <div class="rn-modal-icon" id="rnBulkModalIcon"></div>
            <h3 id="rnBulkModalH3"></h3>
            <p id="rnBulkModalMsg"></p>
        </div>
        <div class="rn-modal-footer">
            <button class="rn-btn" onclick="rnCloseModal('rnBulkModal')">Cancel</button>
            <button class="rn-btn" id="rnBulkConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ══ TOAST ══ -->
<div class="rn-toast" id="rnToast"><i class="fas fa-check-circle"></i><span id="rnToastMsg"></span></div>

<script>
/* ── Mark read on card click ────────────────────────────────────────── */
function rnMarkRead(e, nid, isRead) {
    if (isRead) return;
    const fd = new FormData();
    fd.append('notification_id', nid);
    fetch('<?= BASE_URL ?>/modules/notifications/mark-as-read.php', {
        method: 'POST', body: fd, keepalive: true
    }).catch(() => {});
}

/* ── Mark read via icon button (no navigate) ────────────────────────── */
function rnMarkReadBtn(nid, btn) {
    const fd = new FormData();
    fd.append('notification_id', nid);
    fetch('<?= BASE_URL ?>/modules/notifications/mark-as-read.php', { method: 'POST', body: fd })
        .then(() => {
            const card = btn.closest('.rn-card');
            if (card) {
                card.classList.remove('unread', 'unread-email');
                card.dataset.read = '1';
                card.querySelector('.rn-unread-dot')?.remove();
                card.querySelector('.rn-new-badge')?.remove();
                card.querySelector('.rn-card-title')?.classList.add('read');
                btn.closest('.rn-card-actions')?.remove();
            }
            rnToast('Marked as read', 'success');
        }).catch(() => {});
}

/* ── Search ──────────────────────────────────────────────────────────── */
function rnSearch(q) {
    const term = q.toLowerCase().trim();
    document.querySelectorAll('.rn-card[data-nid]').forEach(card => {
        if (!term) { card.style.display = ''; return; }
        card.style.display = (card.dataset.search || '').includes(term) ? '' : 'none';
    });
}

/* ── Dropdown ────────────────────────────────────────────────────────── */
function rnToggleDd(id) {
    document.querySelectorAll('.rn-dd.open').forEach(d => { if (d.id !== id) d.classList.remove('open'); });
    document.getElementById(id).classList.toggle('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.rn-dd')) document.querySelectorAll('.rn-dd.open').forEach(d => d.classList.remove('open'));
});

/* ── Checkboxes ──────────────────────────────────────────────────────── */
function rnToggleAll(cb) { document.querySelectorAll('.rn-cb').forEach(c => c.checked = cb.checked); }
function rnSelAll()  { document.querySelectorAll('.rn-cb').forEach(c => c.checked = true);  document.getElementById('rnSelAll').checked = true; }
function rnDeselAll(){ document.querySelectorAll('.rn-cb').forEach(c => c.checked = false); document.getElementById('rnSelAll').checked = false; }
function rnGetChecked() { return [...document.querySelectorAll('.rn-cb:checked')].map(c => c.value); }

/* ── Bulk actions ────────────────────────────────────────────────────── */
function rnBulkRead() {
    const ids = rnGetChecked();
    if (!ids.length) { rnToast('Select at least one notification.', 'error'); return; }
    rnShowBulkModal('success', 'Mark as Read', 'fa-check-circle', `Mark ${ids.length} notification(s) as read?`, 'bulk_read', 'success');
}
function rnBulkDelete() {
    const ids = rnGetChecked();
    if (!ids.length) { rnToast('Select at least one notification.', 'error'); return; }
    rnShowBulkModal('danger', 'Delete Notifications', 'fa-trash', `Permanently delete ${ids.length} notification(s)?`, 'bulk_delete', 'danger');
}
function rnShowBulkModal(type, title, icon, msg, action, btnCls) {
    const ic = type === 'danger' ? '#ef4444' : '#16a34a';
    document.getElementById('rnBulkModalTitle').innerHTML = `<i class="fas ${icon}" style="color:${ic}"></i> ${title}`;
    document.getElementById('rnBulkModalIcon').className  = `rn-modal-icon ${type}`;
    document.getElementById('rnBulkModalIcon').innerHTML  = `<i class="fas ${icon}"></i>`;
    document.getElementById('rnBulkModalH3').textContent  = title;
    document.getElementById('rnBulkModalMsg').textContent = msg;
    const btn = document.getElementById('rnBulkConfirmBtn'), nb = btn.cloneNode(true);
    btn.parentNode.replaceChild(nb, btn);
    nb.className = `rn-btn ${btnCls}`;
    nb.innerHTML = `<i class="fas ${icon}"></i> Confirm`;
    nb.addEventListener('click', () => {
        document.getElementById('rnBulkAction').value = action;
        document.getElementById('rnBulkForm').submit();
        rnCloseModal('rnBulkModal');
    });
    rnOpenModal('rnBulkModal');
}

/* ── Single delete ───────────────────────────────────────────────────── */
function rnConfirmDelete(nid) {
    const btn = document.getElementById('rnDelConfirmBtn'), nb = btn.cloneNode(true);
    btn.parentNode.replaceChild(nb, btn);
    nb.addEventListener('click', () => {
        const frm = document.createElement('form');
        frm.method = 'POST'; frm.style.display = 'none';
        frm.innerHTML = `<input name="action" value="delete"><input name="notification_id" value="${nid}">`;
        document.body.appendChild(frm); frm.submit();
    });
    rnOpenModal('rnDelModal');
}

/* ── Modal ───────────────────────────────────────────────────────────── */
function rnOpenModal(id)  { document.getElementById(id).classList.add('open'); }
function rnCloseModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.rn-modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) rnCloseModal(m.id); }));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.rn-modal-overlay.open').forEach(m => rnCloseModal(m.id));
});

/* ── Toast ───────────────────────────────────────────────────────────── */
let _toastT;
function rnToast(msg, type = 'success') {
    const t = document.getElementById('rnToast'), i = t.querySelector('i');
    document.getElementById('rnToastMsg').textContent = msg;
    t.className = `rn-toast ${type}`;
    i.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
    t.classList.add('show');
    clearTimeout(_toastT);
    _toastT = setTimeout(() => t.classList.remove('show'), 3200);
}

/* ── Auto-dismiss alerts ─────────────────────────────────────────────── */
setTimeout(() => {
    ['rnAlert','rnAlertErr'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.transition='opacity .4s'; el.style.opacity='0'; setTimeout(()=>el.remove(),400); }
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>