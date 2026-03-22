<?php
/**
 * Notifications Dashboard - modules/notifications/index.php
 * UI: Exact match of ticketing-dashboard.html (ServiceDesk Plus light theme)
 * ALL original PHP logic preserved exactly.
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title     = 'Notifications';
$user_id        = $_SESSION['user_id'];
$user_role      = getCurrentUserRole();
$is_super_admin = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');
$is_admin       = in_array($user_role, ['Super Admin','Super Administrator','Admin','Staff']);

/* ── notification filter ─────────────────────────────────────────────── */
if ($user_role === 'Resident') {
    $nf = "(type LIKE '%incident%' OR type LIKE '%request%' OR type LIKE '%document%' OR
            type LIKE '%complaint%' OR type LIKE '%appointment%' OR type LIKE '%medical_assistance%' OR
            type LIKE '%blotter%' OR type IN ('general','announcement','alert','status_update','email_reply') OR
            reference_type IN ('incident','request','document','complaint','appointment',
                               'medical_assistance','blotter','announcement','notification','email_inbox'))";
} else {
    $nf = "(type LIKE '%incident%' OR type LIKE '%blotter%' OR type LIKE '%request%' OR
            type LIKE '%document%' OR type LIKE '%complaint%' OR type LIKE '%appointment%' OR
            type LIKE '%medical_assistance%' OR
            type IN ('general','announcement','alert','status_update','email_reply') OR
            reference_type IN ('incident','blotter','request','document','complaint','appointment',
                               'medical_assistance','announcement','notification','email_inbox'))";
}
$notification_filter = $nf;

/* ── POST actions ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        executeQuery($conn,"DELETE FROM tbl_notifications WHERE notification_id=? AND user_id=? AND $notification_filter",[$nid,$user_id],'ii');
        $_SESSION['success_message'] = 'Notification deleted.';
        header('Location: index.php'); exit();
    } elseif ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        $nid = intval($_POST['notification_id']);
        $s = $conn->prepare("UPDATE tbl_notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
        $s->bind_param("ii",$nid,$user_id); $s->execute(); $s->close();
        $redirect = !empty($_POST['redirect_url']) ? $_POST['redirect_url'] : 'index.php';
        header('Location: '.$redirect); exit();
    } elseif ($_POST['action'] === 'bulk_delete') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                executeQuery($conn,"DELETE FROM tbl_notifications WHERE notification_id=? AND user_id=? AND $notification_filter",[$nid,$user_id],'ii');
            }
            $_SESSION['success_message'] = 'Selected notifications deleted.';
        }
        header('Location: index.php'); exit();
    } elseif ($_POST['action'] === 'bulk_read') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                $s = $conn->prepare("UPDATE tbl_notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
                $s->bind_param("ii",$nid,$user_id); $s->execute(); $s->close();
            }
            $_SESSION['success_message'] = 'Marked as read.';
        }
        header('Location: index.php'); exit();
    }
}

/* ── Stats ────────────────────────────────────────────────────────────── */
$stats = fetchOne($conn,"
    SELECT COUNT(*) as total,
        SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read=1 THEN 1 ELSE 0 END) as `read`,
        SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
    FROM tbl_notifications WHERE user_id=? AND $notification_filter",[$user_id],'i');

/* ── Pagination / filtering ───────────────────────────────────────────── */
$page        = max(1,intval($_GET['page']??1));
$per_page    = 20;
$offset      = ($page-1)*$per_page;
$filter      = $_GET['filter']??'all';
$type_filter = $_GET['type']??'';

$where = "user_id=? AND $notification_filter";
$params = [$user_id]; $types = 'i';

if ($filter==='unread')    { $where .= " AND is_read=0"; }
elseif ($filter==='read')  { $where .= " AND is_read=1"; }
elseif ($filter==='today') { $where .= " AND DATE(created_at)=CURDATE()"; }
elseif ($filter==='week')  { $where .= " AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"; }

if ($type_filter) {
    if      (stripos($type_filter,'email')!==false)       $where .= " AND (type='email_reply' OR reference_type='email_inbox')";
    elseif  (stripos($type_filter,'announcement')!==false||stripos($type_filter,'general')!==false)
                                                           $where .= " AND (type IN ('general','announcement','alert','status_update') OR reference_type='announcement')";
    elseif  (stripos($type_filter,'blotter')!==false)     $where .= " AND (type LIKE '%blotter%' OR reference_type='blotter')";
    elseif  (stripos($type_filter,'incident')!==false)    $where .= " AND (type LIKE '%incident%' OR reference_type='incident')";
    elseif  (stripos($type_filter,'complaint')!==false)   $where .= " AND (type LIKE '%complaint%' OR reference_type='complaint')";
    elseif  (stripos($type_filter,'appointment')!==false) $where .= " AND (type LIKE '%appointment%' OR reference_type='appointment')";
    elseif  (stripos($type_filter,'medical')!==false)     $where .= " AND (type LIKE '%medical%' OR reference_type='medical_assistance')";
    elseif  (stripos($type_filter,'request')!==false||stripos($type_filter,'document')!==false)
                                                           $where .= " AND (type LIKE '%request%' OR type LIKE '%document%' OR reference_type IN ('request','document'))";
    else    { $where .= " AND type=?"; $params[] = $type_filter; $types .= 's'; }
}

$count       = fetchOne($conn,"SELECT COUNT(*) as total FROM tbl_notifications WHERE $where",$params,$types);
$total       = $count['total'];
$total_pages = ceil($total/$per_page);
$notifications = fetchAll($conn,
    "SELECT * FROM tbl_notifications WHERE $where ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?",
    array_merge($params,[$per_page,$offset]), $types.'ii');

/* ── Type counts ──────────────────────────────────────────────────────── */
$all_type_stats = fetchAll($conn,"
    SELECT type,reference_type,COUNT(*) as count FROM tbl_notifications
    WHERE user_id=? AND $notification_filter GROUP BY type,reference_type",[$user_id],'i');

$cnt = ['incident'=>0,'blotter'=>0,'complaint'=>0,'request'=>0,'appointment'=>0,'medical'=>0,'announcement'=>0,'email'=>0];
foreach ($all_type_stats as $ts) {
    $t=strtolower(trim($ts['type']??'')); $rt=strtolower(trim($ts['reference_type']??'')); $c=intval($ts['count']);
    if      ($rt==='email_inbox'||$t==='email_reply')                                                          $cnt['email']       +=$c;
    elseif  ($rt==='incident'||stripos($t,'incident')!==false)                                                 $cnt['incident']    +=$c;
    elseif  ($rt==='blotter'||stripos($t,'blotter')!==false)                                                   $cnt['blotter']     +=$c;
    elseif  ($rt==='complaint'||stripos($t,'complaint')!==false)                                               $cnt['complaint']   +=$c;
    elseif  ($rt==='appointment'||stripos($t,'appointment')!==false)                                           $cnt['appointment'] +=$c;
    elseif  ($rt==='medical_assistance'||stripos($t,'medical')!==false)                                        $cnt['medical']     +=$c;
    elseif  ($rt==='request'||$rt==='document'||stripos($t,'request')!==false||stripos($t,'document')!==false) $cnt['request']     +=$c;
    elseif  ($rt==='announcement'||in_array($t,['general','announcement','alert','status_update']))             $cnt['announcement']+=$c;
}

function nAct($tf,...$keys){foreach($keys as $k)if(stripos($tf,$k)!==false)return true;return false;}

/* ── Icon / colour / label ────────────────────────────────────────────── */
function gIcon($t,$rt){
    if($t==='email_reply'||$rt==='email_inbox')   return['fa-envelope','#1976d2','Email Reply'];
    if($rt==='announcement'||in_array($t,['general','announcement','alert','status_update'])){
        if($t==='alert') return['fa-exclamation-circle','#ef4444','Alert'];
        return['fa-bullhorn','#6366f1','Announcement'];
    }
    if(stripos($t,'incident')!==false||$rt==='incident')         return['fa-exclamation-triangle','#f59e0b','Incident'];
    if(stripos($t,'blotter')!==false||$rt==='blotter')           return['fa-gavel','#ef4444','Blotter'];
    if(stripos($t,'complaint')!==false||$rt==='complaint')       return['fa-comments','#f97316','Complaint'];
    if(stripos($t,'request')!==false||stripos($t,'document')!==false||$rt==='request'||$rt==='document')
                                                                  return['fa-file-alt','#1976d2','Document'];
    if(stripos($t,'appointment')!==false||$rt==='appointment')   return['fa-calendar-check','#14b8a6','Appointment'];
    if(stripos($t,'medical')!==false||$rt==='medical_assistance') return['fa-hand-holding-medical','#8b5cf6','Medical'];
    return['fa-bell','#6b7280','Notification'];
}

/* ── View URL ─────────────────────────────────────────────────────────── */
function gUrl($t,$rt,$nid,$rid){
    $ie=($t==='email_reply'||$rt==='email_inbox');
    $ia=($rt==='announcement'||in_array($t,['general','announcement','alert','status_update']));
    if($ie||$ia||!$rid) return 'notification-detail.php?id='.$nid;
    if($rt==='incident')    return '../incidents/incident-details.php?id='.$rid;
    if($rt==='blotter')     return '../blotter/view-blotter.php?id='.$rid;
    if($rt==='complaint')   return '../complaints/complaint-details.php?id='.$rid;
    if($rt==='request'||$rt==='document') return '../requests/view-request.php?id='.$rid;
    if($rt==='appointment') return '../health/appointments.php';
    if($rt==='medical_assistance') return '../health/medical-assistance.php';
    return 'notification-detail.php?id='.$nid;
}

$tp = $type_filter ? '&type='.urlencode($type_filter) : '';

/* ══════════════════════════════════════════════════════════════
   CSS — copied EXACTLY from ticketing-dashboard.html
   Only the .main-content override + notif-specific layout added
══════════════════════════════════════════════════════════════ */
$extra_css = '
<style>
@import url("https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap");

/* Make main content area behave like the HTML body */
.main-content {
  padding: 0 !important;
  background: #f3f4f6 !important;
  font-family: "Nunito Sans", sans-serif !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
}

:root {
  --body-bg:    #f3f4f6;
  --white:      #fff;
  --blue:       #1976d2;
  --blue-light: #e3f2fd;
  --text:       #374151;
  --text-muted: #9ca3af;
  --text-light: #6b7280;
  --border:     #e5e7eb;
  --red:        #ef4444;
  --orange:     #f59e0b;
  --green:      #22c55e;
  --teal:       #14b8a6;
  --purple:     #8b5cf6;
  --indigo:     #6366f1;
  --card-shadow:0 1px 3px rgba(0,0,0,0.08);
}

/* ── TAB BAR (exact from HTML) ── */
.tab-bar {
  background: var(--white);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  padding: 0 16px;
  gap: 0;
  flex-shrink: 0;
  height: 42px;
}
.tab-item {
  padding: 0 16px;
  height: 42px;
  display: flex;
  align-items: center;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-light);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  text-decoration: none;
  transition: all .13s;
}
.tab-item:hover { color: var(--blue); text-decoration: none; }
.tab-item.active { color: var(--blue); border-bottom-color: var(--blue); }

/* ── CONTENT AREA ── */
.content-area {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--body-bg);
}
.content-area::-webkit-scrollbar { width: 5px; }
.content-area::-webkit-scrollbar-track { background: transparent; }
.content-area::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }

/* ── SECTION HEADER ── */
.section-header { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
.section-title  { font-size: 13px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 6px; }
.section-title i { font-size: 11px; color: var(--blue); }
.section-spacer { flex: 1; }
.section-actions { display: flex; gap: 6px; align-items: center; }

/* ── BUTTONS (exact from HTML .sec-btn) ── */
.sec-btn {
  padding: 5px 10px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--border);
  background: var(--white);
  color: var(--text-light);
  display: inline-flex; align-items: center; gap: 5px;
  transition: all .13s;
  font-family: inherit;
  white-space: nowrap;
  text-decoration: none;
  line-height: 1;
}
.sec-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-light); text-decoration: none; }
.sec-btn.primary { background: var(--blue); color: #fff; border-color: var(--blue); }
.sec-btn.primary:hover { background: #1565c0; color: #fff; }
.sec-btn.success { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.sec-btn.success:hover { background: #dcfce7; }
.sec-btn.danger  { background: #fef2f2; color: var(--red); border-color: #fecaca; }
.sec-btn.danger:hover  { background: #fee2e2; }
.sec-btn i { font-size: 10px; }

/* ── TOP ROW — 6-col grid exactly like HTML ── */
.top-row {
  display: grid;
  grid-template-columns: 160px 160px 1fr 1fr 1fr 160px;
  gap: 10px;
  min-height: 90px;
}
@media(max-width:1100px){.top-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px) {.top-row{grid-template-columns:repeat(2,1fr)}}

/* Colored stat cards — exact from HTML */
.stat-card {
  border-radius: 8px;
  padding: 16px 14px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  cursor: pointer;
  transition: transform .13s, box-shadow .13s;
  text-decoration: none;
}
.stat-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.1); text-decoration: none; }
.stat-card .stat-num { font-size: 36px; font-weight: 800; color: #fff; line-height: 1; }
.stat-card .stat-lbl { font-size: 11px; font-weight: 700; color: rgba(255,255,255,.85); text-align: center; margin-top: 4px; line-height: 1.3; }
.stat-card.red    { background: linear-gradient(135deg, #ef4444, #f87171); }
.stat-card.teal   { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
.stat-card.cyan   { background: linear-gradient(135deg, #06b6d4, #22d3ee); }
.stat-card.yellow { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
.stat-card.cyan .stat-num, .stat-card.yellow .stat-num { font-size: 32px; }
.stat-card.blue-light-card { background: var(--white); border: 1px solid var(--border); box-shadow: var(--card-shadow); }
.stat-card.blue-light-card .stat-num { color: var(--blue); font-size: 32px; }
.stat-card.blue-light-card .stat-lbl { color: var(--text-light); }

/* ICT card — exact from HTML */
.ict-card {
  background: var(--white); border: 1px solid var(--border); border-radius: 8px;
  padding: 12px 14px; box-shadow: var(--card-shadow);
  display: flex; flex-direction: column; gap: 8px;
}
.ict-card-header { display: flex; align-items: center; justify-content: space-between; }
.ict-card-title { font-size: 11px; font-weight: 700; color: var(--text-light); }
.ict-card-sub   { font-size: 10px; color: var(--blue); font-weight: 600; }
.ict-card-refresh {
  width: 22px; height: 22px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border); background: transparent;
  color: var(--text-muted); cursor: pointer; font-size: 10px;
}
.ict-card-refresh:hover { background: var(--blue-light); color: var(--blue); }
.ict-stats-row { display: flex; align-items: center; }
.ict-stat { flex: 1; text-align: center; padding: 4px 0; }
.ict-stat:not(:last-child) { border-right: 1px solid var(--border); }
.ict-stat-num { font-size: 26px; font-weight: 800; color: var(--text-muted); }
.ict-stat-num.orange { color: #f59e0b; }
.ict-stat-lbl { font-size: 10px; color: var(--text-muted); font-weight: 500; }

/* ── MAIN GRID — exact 3-col from HTML ── */
.main-grid {
  display: grid;
  grid-template-columns: 290px 1fr 160px;
  gap: 10px;
  flex: 1;
  min-height: 0;
}
@media(max-width:1100px){.main-grid{grid-template-columns:260px 1fr} .right-sidebar{display:none}}
@media(max-width:700px) {.main-grid{grid-template-columns:1fr}      .left-panel{display:none}}

/* ── PANEL (exact from HTML) ── */
.panel {
  background: var(--white); border: 1px solid var(--border); border-radius: 8px;
  box-shadow: var(--card-shadow); display: flex; flex-direction: column;
  overflow: hidden; min-height: 0;
}
.panel-header { padding: 10px 14px 8px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.panel-title   { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
.panel-subtitle { font-size: 13px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 6px; }
.panel-subtitle i { font-size: 10px; color: var(--text-muted); }
.panel-toolbar {
  padding: 7px 14px; border-bottom: 1px solid var(--border);
  display: flex; gap: 5px; flex-wrap: wrap; flex-shrink: 0; align-items: center;
}
.panel-body { flex: 1; overflow-y: auto; overflow-x: hidden; }
.panel-body::-webkit-scrollbar { width: 4px; }
.panel-body::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 2px; }

/* Task/filter items in left panel */
.task-item { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background .1s; }
.task-item:hover { background: var(--blue-light); }
.task-item-header { display: flex; align-items: center; gap: 7px; margin-bottom: 4px; }
.task-avatar {
  width: 22px; height: 22px; border-radius: 50%;
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff; font-size: 10px; font-weight: 800;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.task-name   { font-size: 12.5px; font-weight: 700; color: var(--text); }
.task-meta   { font-size: 10.5px; color: var(--text-muted); margin-bottom: 4px; }
.task-status { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 600; color: var(--red); }
.task-status-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--red); }

/* ── REQUESTS PANEL toolbar + table ── */
.requests-panel { overflow: hidden; }
.req-toolbar {
  padding: 7px 14px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex-shrink: 0;
}

/* Search box */
.notif-search {
  display: flex; align-items: center;
  background: #f9fafb; border: 1px solid var(--border);
  border-radius: 4px; overflow: hidden; min-width: 200px;
}
.notif-search i { padding: 0 9px; color: var(--text-muted); font-size: 12px; flex-shrink: 0; }
.notif-search input {
  background: transparent; border: none; outline: none;
  color: var(--text); font-size: 12.5px; font-family: "Nunito Sans", sans-serif;
  padding: 6px 10px 6px 0; flex: 1; min-width: 0;
}
.notif-search input::placeholder { color: var(--text-muted); }
.notif-search:focus-within { border-color: var(--blue); }

/* ── TABLE — exact from HTML .req-table ── */
.req-table-wrap { flex: 1; overflow-y: auto; overflow-x: hidden; }
.req-table-wrap::-webkit-scrollbar { width: 4px; }
.req-table-wrap::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 2px; }
.req-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.req-table thead th {
  padding: 7px 12px; text-align: left; font-size: 10.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted);
  background: var(--white); border-bottom: 1px solid var(--border);
  white-space: nowrap; position: sticky; top: 0; z-index: 5;
}
.req-table thead th:first-child { width: 36px; padding: 7px 10px; }
.req-table tbody tr { border-bottom: 1px solid #f9fafb; cursor: pointer; transition: background .1s; }
.req-table tbody tr:hover { background: var(--blue-light); }
.req-table tbody tr.unread       { border-left: 3px solid var(--blue); }
.req-table tbody tr.unread-email { border-left: 3px solid var(--orange); }
.req-table tbody tr.selected     { background: #dbeafe; }
.req-table tbody tr.selected:hover { background: #bfdbfe; }
.req-table td { padding: 8px 12px; vertical-align: middle; color: var(--text-light); }
.req-table td:first-child { padding: 8px 10px; }
.req-cb { width: 14px; height: 14px; cursor: pointer; accent-color: var(--blue); }

/* Row icons — exact from HTML */
.req-icons { display: flex; align-items: center; gap: 3px; }
.req-icon { width: 18px; height: 18px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; cursor: pointer; position: relative; }
.req-icon:hover { color: var(--blue); }
.req-icon .icon-badge {
  position: absolute; top: -4px; right: -4px;
  background: var(--red); color: #fff; font-size: 8px; font-weight: 800;
  border-radius: 8px; padding: 0 3px; min-width: 13px; text-align: center;
  line-height: 13px; border: 1.5px solid #fff;
}
.req-priority {
  width: 20px; height: 20px; border-radius: 50%; background: #f59e0b; color: #fff;
  font-size: 10px; display: flex; align-items: center; justify-content: center;
  font-weight: 800; flex-shrink: 0;
}
.req-subject { font-size: 12.5px; color: var(--text); font-weight: 500; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
.req-subject.bold { font-weight: 700; }
.req-id  { font-size: 11.5px; color: var(--blue); font-weight: 600; white-space: nowrap; }
.req-dash { font-size: 11.5px; color: var(--text-muted); }
.tech-name { font-size: 11.5px; color: var(--text); font-weight: 500; display: flex; align-items: center; gap: 4px; max-width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tech-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--green); flex-shrink: 0; }
.tech-dot.away { background: var(--text-muted); }

/* Status badge */
.status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 3px; font-size: 11px; font-weight: 700; }
.status-open { background: #e3f2fd; color: #1976d2; }
.status-read { background: #f0fdf4; color: #16a34a; }

/* Date */
.date-primary   { font-size: 12px; color: var(--text); font-weight: 500; }
.date-secondary { font-size: 10.5px; color: var(--text-muted); margin-top: 1px; }

/* NEW badge */
.new-badge {
  display: inline-flex; padding: 1px 5px; border-radius: 3px;
  font-size: 9px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase;
  animation: nbPulse 2s infinite; vertical-align: middle; margin-left: 4px;
}
.new-blue  { background: #e3f2fd; color: #1976d2; }
.new-amber { background: #fff8e1; color: #d97706; }
@keyframes nbPulse { 0%,100%{opacity:1} 50%{opacity:.35} }

/* ── RIGHT SIDEBAR — exact from HTML ── */
.right-sidebar { display: flex; flex-direction: column; gap: 8px; overflow-y: auto; }
.right-sidebar::-webkit-scrollbar { width: 3px; }
.right-sidebar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 2px; }

.side-card {
  border-radius: 8px; padding: 12px 14px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; cursor: pointer; transition: transform .13s; min-height: 80px;
}
.side-card:hover { transform: scale(1.02); }
.side-card .side-num { font-size: 28px; font-weight: 800; color: #fff; line-height: 1; }
.side-card .side-lbl { font-size: 10px; font-weight: 700; color: rgba(255,255,255,.85); margin-top: 3px; }
.side-card.yellow    { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
.side-card.purple    { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
.side-card.dark-blue { background: linear-gradient(160deg, #1e3a5f 0%, #0f172a 100%); }
.side-inflow { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.side-inflow-lbl { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .6px; }
.side-inflow-row { text-align: center; }
.side-inflow-num { font-size: 22px; font-weight: 800; color: #fff; line-height: 1; }
.side-inflow-sub { font-size: 9.5px; color: rgba(255,255,255,.5); }

/* ── DROPDOWN — exact from HTML .dd-wrapper ── */
.dd-wrapper { position: relative; display: inline-block; }
.dd-menu {
  display: none; position: absolute; top: calc(100%+4px); left: 0;
  background: var(--white); border: 1px solid var(--border); border-radius: 6px;
  box-shadow: 0 8px 24px rgba(0,0,0,.1); min-width: 160px; z-index: 9000; padding: 4px;
  animation: ddIn .12s ease;
}
@keyframes ddIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
.dd-wrapper.open .dd-menu { display: block; }
.dd-menu-r { left: auto; right: 0; }
.dd-item {
  padding: 7px 10px; border-radius: 4px; font-size: 12.5px; color: var(--text);
  cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background .1s;
}
.dd-item:hover { background: var(--blue-light); color: var(--blue); }
.dd-item i { font-size: 11px; width: 12px; }
.dd-sep { height: 1px; background: var(--border); margin: 3px 0; }
.dd-header { font-size: 9.5px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; color: var(--text-muted); padding: 5px 10px 2px; }
.dd-item.danger { color: var(--red); }
.dd-item.danger:hover { background: #fef2f2; }

/* Split button — exact from HTML */
.split-btn { display: flex; border: 1px solid var(--blue); border-radius: 4px; overflow: hidden; }
.split-btn .main-part {
  padding: 5px 11px; font-size: 12px; font-weight: 600; background: var(--blue); color: #fff;
  cursor: pointer; border: none; font-family: inherit; display: flex; align-items: center; gap: 5px; transition: background .13s;
}
.split-btn .main-part:hover { background: #1565c0; }
.split-btn .arrow-part {
  padding: 5px 7px; background: #1565c0; color: #fff; cursor: pointer; border: none;
  border-left: 1px solid rgba(255,255,255,.2); font-size: 9px; display: flex; align-items: center; transition: background .13s;
}
.split-btn .arrow-part:hover { background: #0d47a1; }

/* Row detail expand */
.req-detail-row { background: #f0f7ff; border-bottom: 1px solid var(--blue-light) !important; }
.req-detail-row td { padding: 10px 14px !important; }
.req-detail-content { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
.req-detail-item { display: flex; flex-direction: column; gap: 2px; }
.req-detail-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
.req-detail-value { font-size: 12px; color: var(--text); font-weight: 500; }

/* ── MODALS — exact from HTML ── */
.modal-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5);
  z-index: 9999; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--white); border-radius: 10px; width: 420px; max-width: 95vw;
  max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2); animation: mdIn .2s ease;
}
@keyframes mdIn { from{opacity:0;transform:scale(.94)} to{opacity:1;transform:scale(1)} }
.modal-header { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-header h3 { font-size: 14px; font-weight: 700; color: var(--text); margin: 0; display: flex; align-items: center; gap: 7px; }
.modal-close {
  width: 28px; height: 28px; border-radius: 4px; display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; font-size: 16px; transition: all .13s;
}
.modal-close:hover { background: #fee2e2; color: var(--red); border-color: #fca5a5; }
.modal-body { padding: 22px 20px 16px; text-align: center; }
.modal-icon { width: 58px; height: 58px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 22px; }
.modal-icon.danger  { background: #fef2f2; color: var(--red); }
.modal-icon.success { background: #f0fdf4; color: #16a34a; }
.modal-body h3 { font-size: 15px; font-weight: 700; color: var(--text); margin: 0 0 5px; }
.modal-body p  { font-size: 13px; color: var(--text-muted); margin: 0; line-height: 1.6; }
.modal-footer { padding: 0 18px 16px; display: flex; gap: 8px; }
.modal-footer .sec-btn { flex: 1; justify-content: center; }

/* ── TOAST — exact from HTML ── */
.toast {
  position: fixed; bottom: 20px; right: 20px; background: #1e293b; color: #e2e8f0;
  padding: 12px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
  box-shadow: 0 8px 24px rgba(0,0,0,.2); z-index: 99999;
  display: flex; align-items: center; gap: 10px;
  transform: translateY(60px); opacity: 0; transition: all .25s ease;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success i { color: #34d399; }
.toast.error   i { color: #f87171; }

/* ── BOTTOM BAR — exact from HTML ── */
.bottom-bar {
  height: 34px; background: var(--white); border-top: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 14px; gap: 16px; flex-shrink: 0;
}
.bottom-btn {
  width: 26px; height: 26px; border-radius: 4px; display: flex; align-items: center; justify-content: center;
  color: var(--text-muted); cursor: pointer; border: 1px solid var(--border); background: transparent; font-size: 12px; transition: all .13s;
}
.bottom-btn:hover { background: var(--blue-light); color: var(--blue); }

/* My Summary tab */
.my-summary-tab {
  position: fixed; right: 0; top: 50%; transform: translateY(-50%) rotate(180deg);
  background: var(--blue); color: #fff; padding: 10px 5px; border-radius: 4px 0 0 4px;
  font-size: 11px; font-weight: 700; cursor: pointer; letter-spacing: .5px;
  writing-mode: vertical-rl; text-orientation: mixed; z-index: 50;
}

/* Pagination buttons */
.pg-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 4px; font-size: 12px; font-weight: 600;
  text-decoration: none; color: var(--text-muted); border: 1px solid var(--border);
  background: var(--white); transition: all .12s;
}
.pg-btn:hover { background: var(--blue-light); color: var(--blue); border-color: #bfdbfe; text-decoration: none; }
.pg-btn.active { background: var(--blue); border-color: var(--blue); color: #fff; }
.pg-btn.disabled { opacity: .3; pointer-events: none; }

/* ══ MY SUMMARY PANEL ══ */
.my-summary-tab {
  position: fixed; right: 0; top: 50%;
  transform: translateY(-50%) rotate(180deg);
  background: var(--blue); color: #fff;
  padding: 10px 5px; border-radius: 4px 0 0 4px;
  font-size: 11px; font-weight: 700; cursor: pointer;
  letter-spacing: .5px; writing-mode: vertical-rl;
  text-orientation: mixed; z-index: 200;
  transition: background .13s;
}
.my-summary-tab:hover { background: #1565c0; }

/* Overlay */
.ms-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.3); z-index: 300;
}
.ms-overlay.open { display: block; }

/* Panel */
.ms-panel {
  position: fixed; right: -860px; top: 0; bottom: 0;
  width: 420px; background: var(--white);
  box-shadow: -4px 0 24px rgba(0,0,0,.15);
  z-index: 301; display: flex; flex-direction: column;
  transition: right .25s cubic-bezier(.32,.84,.44,1), width .25s cubic-bezier(.32,.84,.44,1);
  overflow: hidden;
}
.ms-panel.open { right: 0; }

/* Header */
.ms-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; border-bottom: 1px solid var(--border);
  background: var(--white); flex-shrink: 0;
}
.ms-title { font-size: 15px; font-weight: 800; color: var(--text); }
.ms-close {
  width: 28px; height: 28px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border); background: transparent;
  color: var(--text-muted); cursor: pointer; font-size: 15px; transition: all .13s;
}
.ms-close:hover { background: #fee2e2; color: var(--red); border-color: #fecaca; }

/* Section label */
.ms-section-label {
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .7px; color: var(--text-muted);
  padding: 12px 18px 6px; flex-shrink: 0;
}

/* Stats row — 3 cards like screenshot */
.ms-stats-row {
  display: flex; gap: 8px; padding: 0 18px 12px; flex-shrink: 0;
}
.ms-stat-card {
  flex: 1; border-radius: 8px; padding: 12px 8px;
  display: flex; flex-direction: column; align-items: center;
  cursor: pointer; transition: all .13s; border: 2px solid transparent;
}
.ms-stat-card:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.1); }
.ms-stat-card.active { border-color: var(--blue); }
.ms-stat-num { font-size: 26px; font-weight: 800; line-height: 1; }
.ms-stat-lbl { font-size: 10px; font-weight: 600; margin-top: 3px; text-align: center; }
.ms-stat-red  { background: #fef2f2; }
.ms-stat-red .ms-stat-num { color: var(--red); }
.ms-stat-red .ms-stat-lbl { color: #b91c1c; }
.ms-stat-blue { background: #e3f2fd; }
.ms-stat-blue .ms-stat-num { color: var(--blue); }
.ms-stat-blue .ms-stat-lbl { color: var(--blue); }
.ms-stat-gray { background: #f9fafb; border: 1px solid var(--border); }
.ms-stat-gray .ms-stat-num { color: var(--text); }
.ms-stat-gray .ms-stat-lbl { color: var(--text-muted); }

/* Filter bar */
.ms-filter-bar {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 18px; background: #f0f7ff;
  border-top: 1px solid #bfdbfe; border-bottom: 1px solid #bfdbfe;
  font-size: 11.5px; color: var(--blue); font-weight: 600;
  flex-shrink: 0;
}
.ms-filter-count {
  margin-left: auto; background: var(--blue); color: #fff;
  border-radius: 10px; padding: 1px 7px; font-size: 10px; font-weight: 800;
}

/* Toolbar */
.ms-toolbar {
  display: flex; align-items: center; gap: 5px;
  padding: 7px 14px; border-bottom: 1px solid var(--border);
  flex-shrink: 0; flex-wrap: wrap;
}
.ms-tb-btn {
  padding: 5px 10px; border-radius: 4px; font-size: 11.5px; font-weight: 600;
  border: 1px solid var(--border); background: var(--white); color: var(--text-light);
  cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
  font-family: inherit; transition: all .13s; white-space: nowrap;
}
.ms-tb-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-light); }
.ms-tb-btn i { font-size: 10px; }

/* Dropdown inside toolbar */
.ms-tb-dd-wrap, .ms-dd-wrap { position: relative; display: inline-block; }
.ms-tb-dd, .ms-tb-dd-right {
  display: none; position: absolute; top: calc(100% + 4px); left: 0;
  background: var(--white); border: 1px solid var(--border);
  border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,.1);
  min-width: 150px; z-index: 500; padding: 4px;
}
.ms-tb-dd-right { left: auto; right: 0; }
.ms-tb-dd-wrap.open .ms-tb-dd,
.ms-tb-dd-wrap.open .ms-tb-dd-right,
.ms-dd-wrap.open .ms-tb-dd,
.ms-dd-wrap.open .ms-tb-dd-right { display: block; }
.ms-tb-dd-item {
  padding: 7px 10px; border-radius: 4px; font-size: 12.5px;
  color: var(--text); cursor: pointer; display: flex; align-items: center; gap: 7px;
  transition: background .1s;
}
.ms-tb-dd-item:hover { background: var(--blue-light); color: var(--blue); }
.ms-tb-dd-item i { font-size: 11px; width: 12px; }

/* Ticket list */
.ms-list {
  flex: 1; overflow-y: auto; overflow-x: hidden;
  position: relative;
}
.ms-list::-webkit-scrollbar { width: 4px; }
.ms-list::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 2px; }
/* ── ROW HOVER PREVIEW CARD ── */
.row-preview-card {
  position: fixed; z-index: 9999;
  background: #fff; border: 1px solid #e5e7eb;
  border-radius: 10px; padding: 14px 16px;
  box-shadow: 0 8px 28px rgba(0,0,0,.13);
  min-width: 300px; max-width: 380px;
  pointer-events: none;
  animation: ddIn .1s ease;
  font-family: "Nunito Sans", sans-serif;
}
.row-preview-title { font-size: 13.5px; font-weight: 700; color: #374151; margin-bottom: 6px; display: flex; align-items: center; gap: 7px; }
.row-preview-id { font-size: 11px; color: #9ca3af; font-weight: 500; margin-bottom: 6px; }
.row-preview-body { font-size: 12px; color: #6b7280; line-height: 1.6; }
/* Individual ticket item */
.ms-item {
  padding: 12px 16px; border-bottom: 1px solid #f3f4f6;
  cursor: pointer; transition: background .1s; position: relative;
}
.ms-item:hover { background: var(--blue-light); }
.ms-item.active { background: #dbeafe; border-left: 3px solid var(--blue); }
.ms-item-header { display: flex; align-items: center; gap: 8px; margin-bottom: 5px; }
.ms-item-icon {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0;
}
.ms-item-id { font-size: 11.5px; color: var(--blue); font-weight: 700; }
.ms-item-title { font-size: 12.5px; font-weight: 700; color: var(--text); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-item-cb { position: absolute; top: 14px; right: 14px; width: 14px; height: 14px; accent-color: var(--blue); cursor: pointer; }
.ms-item-meta { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ms-item-status { display: inline-flex; align-items: center; gap: 4px; }
.ms-item-status-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--red); }
.ms-loading {
  display: flex; align-items: center; justify-content: center;
  gap: 8px; padding: 40px; color: var(--text-muted); font-size: 13px;
}

/* Detail panel */
.ms-detail {
  position: absolute; top: 0; bottom: 0; right: 0; background: var(--white);
  display: flex; flex-direction: column; overflow: hidden;
  border-left: 1px solid var(--border);
}
.ms-detail-header {
  display: flex; align-items: center; gap: 6px; padding: 8px 12px;
  border-bottom: 1px solid var(--border); flex-shrink: 0; flex-wrap: wrap;
}
.ms-back-btn {
  width: 28px; height: 28px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border); background: transparent;
  color: var(--text-muted); cursor: pointer; font-size: 12px;
  transition: all .13s; flex-shrink: 0;
}
.ms-back-btn:hover { background: var(--blue-light); color: var(--blue); border-color: #bfdbfe; }
.ms-detail-toolbar { display: flex; gap: 5px; flex: 1; flex-wrap: wrap; }

/* Ticket card in detail */
.ms-ticket-card {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 16px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.ms-ticket-icon {
  width: 36px; height: 36px; border-radius: 50%;
  background: #fff8e1; color: #f59e0b;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
}
.ms-ticket-info { flex: 1; min-width: 0; }
.ms-ticket-id-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
.ms-ticket-id { font-size: 12.5px; font-weight: 800; color: var(--blue); }
.ms-ticket-title { font-size: 13px; font-weight: 700; color: var(--text); }
.ms-ticket-meta { font-size: 11px; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 6px; }
.ms-ticket-pin, .ms-ticket-search {
  width: 26px; height: 26px; border-radius: 4px; border: 1px solid var(--border);
  background: transparent; color: var(--text-muted); cursor: pointer; font-size: 11px;
  display: flex; align-items: center; justify-content: center; transition: all .13s; flex-shrink: 0;
}
.ms-ticket-pin:hover, .ms-ticket-search:hover { background: var(--blue-light); color: var(--blue); }

/* Status row */
.ms-status-row {
  display: flex; gap: 0; padding: 10px 16px;
  border-bottom: 1px solid var(--border); flex-shrink: 0; align-items: flex-start;
}
.ms-status-box { flex-shrink: 0; margin-right: 20px; }
.ms-status-label, .ms-trans-label {
  font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .6px; color: var(--text-muted); margin-bottom: 6px;
}
.ms-status-val {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 10px; border-radius: 20px;
  background: #fef2f2; color: var(--red); font-size: 12px; font-weight: 700;
  border: 1.5px solid #fecaca;
}
.ms-status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--red); }
.ms-transitions { flex: 1; }
.ms-trans-btn {
  padding: 5px 12px; border-radius: 4px; font-size: 12px; font-weight: 600;
  border: 1px solid var(--border); background: var(--white); color: var(--text-light);
  cursor: pointer; font-family: inherit; transition: all .13s;
}
.ms-trans-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-light); }

/* Conversation tabs */
.ms-conv-tabs {
  display: flex; overflow-x: auto; border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.ms-conv-tabs::-webkit-scrollbar { height: 0; }
.ms-conv-tab {
  padding: 9px 14px; font-size: 12.5px; font-weight: 600;
  color: var(--text-muted); cursor: pointer; white-space: nowrap;
  border-bottom: 2px solid transparent; transition: all .13s;
}
.ms-conv-tab:hover { color: var(--blue); }
.ms-conv-tab.active { color: var(--blue); border-bottom-color: var(--blue); }

/* Conversation body */
.ms-conv-body {
  flex: 1; overflow-y: auto; padding: 12px 16px;
}
.ms-conv-body::-webkit-scrollbar { width: 4px; }
.ms-conv-body::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 2px; }
.ms-conv-filter {
  display: flex; align-items: center; gap: 10px;
  font-size: 11.5px; color: var(--text-muted); margin-bottom: 10px; flex-wrap: wrap;
}
.ms-conv-filter label { display: flex; align-items: center; gap: 4px; cursor: pointer; }
.ms-conv-filter input { accent-color: var(--blue); }
.ms-conv-section-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .6px; color: var(--text-muted); margin-bottom: 8px;
}

/* Message bubble */
.ms-msg {
  display: flex; gap: 10px; margin-bottom: 14px;
}
.ms-msg-icon {
  width: 32px; height: 32px; border-radius: 50%;
  background: #e3f2fd; color: var(--blue);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
}
.ms-msg-body { flex: 1; }
.ms-msg-sender { font-size: 12px; font-weight: 700; color: var(--blue); margin-bottom: 2px; }
.ms-msg-time   { font-size: 10.5px; color: var(--text-muted); }
.ms-msg-text {
  margin-top: 6px; padding: 10px 12px;
  background: #f9fafb; border: 1px solid var(--border);
  border-radius: 0 8px 8px 8px; font-size: 12.5px; color: var(--text); line-height: 1.6;
}
.ms-msg-actions { display: flex; gap: 6px; margin-top: 6px; }
.ms-msg-act-btn {
  width: 24px; height: 24px; border-radius: 4px; border: 1px solid var(--border);
  background: transparent; color: var(--text-muted); cursor: pointer; font-size: 11px;
  display: flex; align-items: center; justify-content: center; transition: all .13s;
}
.ms-msg-act-btn:hover { background: var(--blue-light); color: var(--blue); }

/* Detail fields */
.ms-detail-field { margin-bottom: 12px; }
.ms-detail-field-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); margin-bottom: 3px; }
.ms-detail-field-value { font-size: 12.5px; color: var(--text); font-weight: 500; }
.ms-detail-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 700; }
.ms-detail-badge.open { background: #fef2f2; color: var(--red); }
.ms-detail-badge.read { background: #f0fdf4; color: #16a34a; }
</style>';

include '../../includes/header.php';
?>

<!-- ══ TAB BAR ══ -->
<div class="tab-bar">
    <a href="?filter=all<?= $tp ?>"    class="tab-item <?= $filter==='all'   ?'active':'' ?>">Dashboard</a>
    <a href="?filter=unread<?= $tp ?>" class="tab-item <?= $filter==='unread'?'active':'' ?>">Unread<?= $stats['unread']>0?' ('.$stats['unread'].')':'' ?></a>
    <a href="?filter=read<?= $tp ?>"   class="tab-item <?= $filter==='read'  ?'active':'' ?>">Read</a>
    <a href="?filter=today<?= $tp ?>"  class="tab-item <?= $filter==='today' ?'active':'' ?>">Today</a>
    <a href="?filter=week<?= $tp ?>"   class="tab-item <?= $filter==='week'  ?'active':'' ?>">This Week</a>
</div>

<!-- ══ SCROLLABLE CONTENT ══ -->
<div class="content-area">

    <!-- Section header -->
    <div class="section-header">
        <div class="section-title">
            <i class="fas fa-globe"></i>
            Notifications Dashboard
            <i class="fas fa-chevron-down" style="font-size:9px;color:#9ca3af;margin-left:2px"></i>
        </div>
        <div class="section-spacer"></div>
        <div class="section-actions">
            <!-- New Incident button — split style matching HTML exactly -->
            <div class="split-btn">
                <button class="main-part" onclick="location.href='<?= BASE_URL ?>/modules/notifications/new-incident.php'">
                    <i class="fas fa-plus"></i> New Incident
                </button>
                <div class="dd-wrapper" id="ddNewIncident">
                    <button class="arrow-part" onclick="toggleDD('ddNewIncident')">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dd-menu dd-menu-r">
                        <div class="dd-header">Create New</div>
                        <a class="dd-item" href="<?= BASE_URL ?>/modules/notifications/new-incident.php?type=incident">
                            <i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> New Incident
                        </a>
                        <a class="dd-item" href="<?= BASE_URL ?>/modules/complaints/add-complaint.php">
                            <i class="fas fa-comments" style="color:#f97316"></i> New Complaint
                        </a>
                        <a class="dd-item" href="<?= BASE_URL ?>/modules/blotter/add-blotter.php">
                            <i class="fas fa-gavel" style="color:#ef4444"></i> New Blotter
                        </a>
                        <a class="dd-item" href="<?= BASE_URL ?>/modules/requests/add-request.php">
                            <i class="fas fa-file-alt" style="color:#1976d2"></i> New Document Request
                        </a>
                        <div class="dd-sep"></div>
                        <a class="dd-item" href="<?= BASE_URL ?>/modules/health/book-appointment.php">
                            <i class="fas fa-calendar-check" style="color:#14b8a6"></i> Book Appointment
                        </a>
                        <a class="dd-item" href="<?= BASE_URL ?>/modules/health/request-assistance.php">
                            <i class="fas fa-hand-holding-medical" style="color:#8b5cf6"></i> Medical Assistance
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($is_super_admin): ?>
            <a href="<?= BASE_URL ?>/modules/notifications/email-residents.php" class="sec-btn primary">
                <i class="fas fa-envelope"></i> Email Residents
            </a>
            <a href="<?= BASE_URL ?>/modules/notifications/email-history.php" class="sec-btn">
                <i class="fas fa-history"></i> History
            </a>
            <?php endif; ?>
            <?php if ($stats['unread'] > 0): ?>
            <a href="<?= BASE_URL ?>/modules/notifications/mark_all_read.php" class="sec-btn success">
                <i class="fas fa-check-double"></i> Mark All Read
            </a>
            <?php endif; ?>
            <button class="sec-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
        </div>
    </div>

    <!-- ══ TOP ROW — 6 stat cards matching the HTML exactly ══ -->
    <div class="top-row">
        <!-- Unread — red if >0, teal if none -->
        <a href="?filter=unread<?= $tp ?>" class="stat-card <?= $stats['unread']>0?'red':'teal' ?>">
            <div class="stat-num"><?= number_format($stats['unread']) ?></div>
            <div class="stat-lbl"><?= $stats['unread']>0?'Unread':'All Read' ?></div>
        </a>

        <!-- Today — teal -->
        <a href="?filter=today<?= $tp ?>" class="stat-card teal">
            <div class="stat-num"><?= number_format($stats['today']) ?></div>
            <div class="stat-lbl">Today</div>
        </a>

        <!-- ICT summary card -->
        <div class="ict-card">
            <div class="ict-card-header">
                <div>
                    <div class="ict-card-title">Notification Summary (current view)</div>
                    <div class="ict-card-sub">All notification types</div>
                </div>
                <button class="ict-card-refresh" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
            </div>
            <div class="ict-stats-row">
                <div class="ict-stat">
                    <div class="ict-stat-num"><?= $stats['total'] ?></div>
                    <div class="ict-stat-lbl">Total</div>
                </div>
                <div class="ict-stat">
                    <div class="ict-stat-num orange"><?= $stats['unread'] ?></div>
                    <div class="ict-stat-lbl">Unread</div>
                </div>
                <div class="ict-stat">
                    <div class="ict-stat-num"><?= $stats['read'] ?></div>
                    <div class="ict-stat-lbl">Read</div>
                </div>
            </div>
        </div>

        <!-- This week — cyan -->
        <a href="?filter=week<?= $tp ?>" class="stat-card cyan">
            <div class="stat-num"><?= number_format($stats['this_week']) ?></div>
            <div class="stat-lbl">This Week</div>
        </a>

        <!-- Total — yellow -->
        <a href="?filter=all<?= $tp ?>" class="stat-card yellow">
            <div class="stat-num"><?= number_format($stats['total']) ?></div>
            <div class="stat-lbl">Total</div>
        </a>

        <!-- Read — white card -->
        <a href="?filter=read<?= $tp ?>" class="stat-card blue-light-card">
            <div class="stat-num"><?= number_format($stats['read']) ?></div>
            <div class="stat-lbl">Read</div>
        </a>
    </div>

    <!-- ══ ALERTS ══ -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:6px;padding:9px 14px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px" id="sdAlert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;color:inherit;line-height:1">×</button>
    </div>
    <?php unset($_SESSION['success_message']); endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:9px 14px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px" id="sdAlertErr">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;color:inherit;line-height:1">×</button>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <!-- ══ MAIN GRID ══ -->
    <div class="main-grid">

        <!-- ── LEFT PANEL: Type filters (same structure as Tasks panel) ── -->
        <div class="panel left-panel">
            <div class="panel-header">
                <div class="panel-title">Filter By Type</div>
                <div class="panel-subtitle">
                    Notification Types
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="panel-toolbar">
                <?php if ($stats['unread'] > 0): ?>
                <a href="<?= BASE_URL ?>/modules/notifications/mark_all_read.php" class="sec-btn success" style="font-size:11px">
                    <i class="fas fa-check-double"></i> Mark All Read
                </a>
                <?php endif; ?>
                <button class="sec-btn" onclick="location.reload()" style="font-size:11px"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
            <div class="panel-body">
                <!-- All types -->
                <a href="?filter=<?= $filter ?>" class="task-item" style="display:block;text-decoration:none;<?= !$type_filter?'background:#e3f2fd':'' ?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#1976d2,#42a5f5)"><i class="fas fa-list" style="font-size:9px"></i></div>
                        <div class="task-name">All Types</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?= $stats['total'] ?></div>
                    </div>
                </a>
                <?php if($cnt['email']>0): ?>
                <a href="?filter=<?=$filter?>&type=email_reply" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'email')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#1976d2,#42a5f5)"><i class="fas fa-envelope" style="font-size:9px"></i></div>
                        <div class="task-name">Email</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['email']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['announcement']>0): ?>
                <a href="?filter=<?=$filter?>&type=announcement" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'announcement','general')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#6366f1,#818cf8)"><i class="fas fa-bullhorn" style="font-size:9px"></i></div>
                        <div class="task-name">Announcements</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['announcement']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['incident']>0): ?>
                <a href="?filter=<?=$filter?>&type=incident_reported" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'incident')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)"><i class="fas fa-exclamation-triangle" style="font-size:9px"></i></div>
                        <div class="task-name">Incidents</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['incident']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['blotter']>0): ?>
                <a href="?filter=<?=$filter?>&type=blotter_filed" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'blotter')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#ef4444,#f87171)"><i class="fas fa-gavel" style="font-size:9px"></i></div>
                        <div class="task-name">Blotter</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['blotter']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['complaint']>0): ?>
                <a href="?filter=<?=$filter?>&type=complaint_filed" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'complaint')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-comments" style="font-size:9px"></i></div>
                        <div class="task-name">Complaints</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['complaint']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['request']>0): ?>
                <a href="?filter=<?=$filter?>&type=document_request" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'request','document')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#1976d2,#42a5f5)"><i class="fas fa-file-alt" style="font-size:9px"></i></div>
                        <div class="task-name">Documents</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['request']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['appointment']>0): ?>
                <a href="?filter=<?=$filter?>&type=appointment_booked" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'appointment')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#14b8a6,#2dd4bf)"><i class="fas fa-calendar-check" style="font-size:9px"></i></div>
                        <div class="task-name">Appointments</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['appointment']?></div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if($cnt['medical']>0): ?>
                <a href="?filter=<?=$filter?>&type=medical_assistance" class="task-item" style="display:block;text-decoration:none;<?=nAct($type_filter,'medical')?'background:#e3f2fd':''?>">
                    <div class="task-item-header">
                        <div class="task-avatar" style="background:linear-gradient(135deg,#8b5cf6,#a78bfa)"><i class="fas fa-hand-holding-medical" style="font-size:9px"></i></div>
                        <div class="task-name">Medical</div>
                        <div style="margin-left:auto;font-size:10px;font-weight:700;background:#e5e7eb;color:#6b7280;border-radius:8px;padding:1px 7px"><?=$cnt['medical']?></div>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── CENTER PANEL: Notifications table ── -->
        <div class="panel requests-panel">

            <!-- Panel header — exact match of screenshot "Requests / Open Requests ▼" -->
            <div class="panel-header">
                <div class="panel-title">Notifications</div>
                <div class="panel-subtitle">
                    <?php
                    $subtitleMap = [
                        'all'    => 'Open Notifications',
                        'unread' => 'Unread Notifications',
                        'read'   => 'Read Notifications',
                        'today'  => 'Today Notifications',
                        'week'   => 'This Week',
                    ];
                    echo $subtitleMap[$filter] ?? 'Open Notifications';
                    ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>

            <!-- ── TOOLBAR — exact match of screenshot ──
                 [ New Incident ▼ ] [ Pick Up ] [ Close ] [ Assign ▼ ]
            -->
            <div class="req-toolbar" style="padding:7px 14px;gap:6px;border-bottom:1px solid var(--border)">

                <!-- New Incident split button — exact from screenshot -->
                <div class="split-btn" style="flex-shrink:0">
                    <button class="main-part" onclick="location.href='<?= BASE_URL ?>/modules/notifications/new-incident.php'">
                        New Incident
                    </button>
                    <div class="dd-wrapper" id="ddNI2">
                        <button class="arrow-part" onclick="toggleDD('ddNI2')">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dd-menu" style="min-width:190px">
                            <div class="dd-header">Create New</div>
                            <a class="dd-item" href="<?= BASE_URL ?>/modules/notifications/new-incident.php">
                                <i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> New Incident
                            </a>
                            <a class="dd-item" href="<?= BASE_URL ?>/modules/complaints/add-complaint.php">
                                <i class="fas fa-comments" style="color:#f97316"></i> New Complaint
                            </a>
                            <a class="dd-item" href="<?= BASE_URL ?>/modules/blotter/add-blotter.php">
                                <i class="fas fa-gavel" style="color:#ef4444"></i> New Blotter
                            </a>
                            <a class="dd-item" href="<?= BASE_URL ?>/modules/requests/add-request.php">
                                <i class="fas fa-file-alt" style="color:#1976d2"></i> Document Request
                            </a>
                            <div class="dd-sep"></div>
                            <a class="dd-item" href="<?= BASE_URL ?>/modules/health/book-appointment.php">
                                <i class="fas fa-calendar-check" style="color:#14b8a6"></i> Book Appointment
                            </a>
                            <a class="dd-item" href="<?= BASE_URL ?>/modules/health/request-assistance.php">
                                <i class="fas fa-hand-holding-medical" style="color:#8b5cf6"></i> Medical Assistance
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Pick Up -->
                <button class="sec-btn" onclick="pickUpSelected()" style="flex-shrink:0">Pick Up</button>

                <!-- Close -->
                <button class="sec-btn" onclick="closeSelected()" style="flex-shrink:0">Close</button>

                <!-- Assign dropdown -->
                <div class="dd-wrapper" id="ddAssign2" style="flex-shrink:0">
                    <button class="sec-btn" onclick="toggleDD('ddAssign2')">
                        Assign <i class="fas fa-chevron-down" style="font-size:9px;opacity:.6;margin-left:2px"></i>
                    </button>
                    <div class="dd-menu">
                        <div class="dd-header">Assign To</div>
                        <div class="dd-item" onclick="assignTo('Barangay Captain')">
                            <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:4px"></span> Barangay Captain
                        </div>
                        <div class="dd-item" onclick="assignTo('Barangay Secretary')">
                            <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:4px"></span> Barangay Secretary
                        </div>
                        <div class="dd-item" onclick="assignTo('Barangay Treasurer')">
                            <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:4px"></span> Barangay Treasurer
                        </div>
                        <div class="dd-item" onclick="assignTo('Barangay Kagawad 1')">
                            <span style="width:7px;height:7px;border-radius:50%;background:#9ca3af;display:inline-block;margin-right:4px"></span> Barangay Kagawad 1
                        </div>
                        <div class="dd-item" onclick="assignTo('Barangay Kagawad 2')">
                            <span style="width:7px;height:7px;border-radius:50%;background:#9ca3af;display:inline-block;margin-right:4px"></span> Barangay Kagawad 2
                        </div>
                        <div class="dd-item" onclick="assignTo('SK Chairman')">
                            <span style="width:7px;height:7px;border-radius:50%;background:#9ca3af;display:inline-block;margin-right:4px"></span> SK Chairman
                        </div>
                    </div>
                </div>

                <div style="flex:1"></div>

                <!-- Search -->
                <div class="notif-search" style="min-width:160px">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search..." oninput="liveSearch(this.value)">
                </div>

                <!-- Bulk action -->
                <div class="dd-wrapper" id="ddBulk" style="flex-shrink:0">
                    <button class="sec-btn" onclick="toggleDD('ddBulk')" title="Bulk actions">
                        <i class="fas fa-tasks"></i>
                        <i class="fas fa-chevron-down" style="font-size:9px;opacity:.5"></i>
                    </button>
                    <div class="dd-menu dd-menu-r">
                        <div class="dd-header">Selection</div>
                        <div class="dd-item" onclick="selAllRows()"><i class="fas fa-check-square"></i> Select All</div>
                        <div class="dd-item" onclick="deselAllRows()"><i class="fas fa-square"></i> Deselect All</div>
                        <div class="dd-sep"></div>
                        <div class="dd-header">Actions</div>
                        <div class="dd-item" onclick="bulkRead()"><i class="fas fa-check-circle" style="color:#16a34a"></i> Mark as Read</div>
                        <div class="dd-item danger" onclick="bulkDelete()"><i class="fas fa-trash"></i> Delete Selected</div>
                    </div>
                </div>

                <!-- Select all checkbox -->
                <input type="checkbox" id="selAll" class="req-cb" title="Select all" onchange="toggleAll(this)" style="flex-shrink:0">
            </div>

            <!-- Table or empty state -->
            <?php if (empty($notifications)): ?>
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 24px;gap:12px;text-align:center;flex:1;background:#fff">
                <div style="width:64px;height:64px;border-radius:50%;background:#e3f2fd;display:flex;align-items:center;justify-content:center;font-size:24px;color:#1976d2">
                    <i class="fas fa-bell-slash"></i>
                </div>
                <h3 style="font-size:16px;font-weight:700;color:#374151;margin:0"><?= $filter==='unread'?"All caught up!":"No notifications found" ?></h3>
                <p style="font-size:13px;color:#9ca3af;max-width:260px;line-height:1.6;margin:0"><?= $filter==='unread'?"You have no unread notifications.":"No notifications match the current filters." ?></p>
                <?php if ($filter!=='all'||$type_filter): ?>
                <a href="?filter=all" class="sec-btn" style="margin-top:6px"><i class="fas fa-list"></i> View All</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <form id="bulkForm" method="POST">
                <input type="hidden" name="action" id="bulkAction">
                <div class="req-table-wrap">
                    <table class="req-table" id="notifTable">
                        <thead>
                            <tr>
                                <th></th>
                                <th></th>
                                <th>Subject</th>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Due by date</th>
                                <th>Technician</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($notifications as $notif):
                            $t   = $notif['type']??'';
                            $rt  = $notif['reference_type']??'';
                            $nid = intval($notif['notification_id']);
$rid = intval($notif['reference_id']??0);

                            // Define $is_email FIRST
                            $is_email = ($t==='email_reply'||$rt==='email_inbox');

                            [$icon,$color,$type_label] = gIcon($t,$rt);
                            $view_url = gUrl($t,$rt,$nid,$rid);

                            // Parse sender from email message
                            $sender_name=''; $sender_email='';
                            if ($is_email) {
                                $msg=$notif['message']??'';
                                if (preg_match('/From:\s*([^<\n]+?)(?:\s*<([^>]+)>)?(?:\s|$)/i',$msg,$m)) {
                                    $sender_name=trim($m[1]); $sender_email=trim($m[2]??'');
                                }
                                if (!$sender_email&&preg_match('/[\w._%+\-]+@[\w.\-]+\.[a-z]{2,}/i',$msg,$em)) $sender_email=$em[0];
                            }

                            // Get the requester's email from users table
                            $requester_email = '';
                            $requester_name  = '';
                            $req_uid = intval($notif['related_user_id'] ?? $notif['created_by'] ?? 0);
                            if ($req_uid) {
                                $req_user = fetchOne($conn,
"SELECT u.email,
        COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username) AS full_name
 FROM tbl_users u
 LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
 WHERE u.user_id = ?",
                                    [$req_uid], 'i');
                                if ($req_user) {
                                    $requester_email = $req_user['email'] ?? '';
                                    $requester_name  = $req_user['full_name'] ?? '';
                                }
                            }
                            // For email-type notifications, fall back to parsed sender
                            if ($is_email && !$requester_email && $sender_email) {
                                $requester_email = $sender_email;
                                $requester_name  = $sender_name;
                            }
                               

                            $is_unread = !$notif['is_read'];
                            $row_cls   = $is_unread ? ($is_email?'unread-email':'unread') : '';
                            $preview   = htmlspecialchars(mb_strimwidth($notif['message']??'',0,100,'…'));
                            $icon_bg   = 'background:'.htmlspecialchars($color).'18;color:'.htmlspecialchars($color);
                        ?>
<tr class="<?= $row_cls ?>"
                            data-url="<?= htmlspecialchars($view_url) ?>"
                            data-nid="<?= $nid ?>"
                            data-read="<?= $notif['is_read']?1:0 ?>"
                            data-search="<?= strtolower(htmlspecialchars($notif['title'].' '.($notif['message']??''))) ?>"
                          data-email="<?= htmlspecialchars($requester_email) ?>"
                            data-sender="<?= htmlspecialchars($requester_name) ?>">

                            <!-- Checkbox -->
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="req-cb notif-cb" name="notification_ids[]" value="<?= $nid ?>" onchange="updateSelBar()">
                            </td>

                            <!-- Icons (same as HTML req-icons) -->
                            <td onclick="event.stopPropagation()">
                                <div class="req-icons">
                                    <div class="req-icon" style="<?= $icon_bg ?>" title="<?= htmlspecialchars($type_label) ?>">
                                        <i class="fas <?= $icon ?>"></i>
                                        <?php if ($is_unread): ?>
                                        <span class="icon-badge" style="background:<?= $is_email?'#f59e0b':'#1976d2' ?>"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="req-icon" style="color:#9ca3af" title="Notes">
                                        <i class="fas fa-sticky-note"></i>
                                    </div>
                                    <div class="req-priority" title="<?= htmlspecialchars($type_label) ?>" style="background:<?= htmlspecialchars($color) ?>">
                                        <i class="fas fa-bolt" style="font-size:9px"></i>
                                    </div>
                                </div>
                            </td>

                            <!-- Subject -->
                            <td>
                                <span class="req-subject <?= $is_unread?'bold':'' ?>" title="<?= htmlspecialchars($notif['title']) ?>">
                                    <?= htmlspecialchars($notif['title']) ?>
                                    <?php if ($is_unread): ?>
                                    <span class="new-badge <?= $is_email?'new-amber':'new-blue' ?>"><?= $is_email?'NEW EMAIL':'NEW' ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($is_email && $sender_name): ?>
                                <div style="font-size:11px;color:#1976d2;margin-top:2px;display:flex;align-items:center;gap:3px">
                                    <i class="fas fa-user-circle"></i> <?= htmlspecialchars($sender_name) ?>
                                </div>
                                <?php endif; ?>
                                <div style="font-size:11px;color:#9ca3af;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px"><?= $preview ?></div>
                            </td>

                            <!-- ID -->
                            <td><span class="req-id">ID-<?= str_pad($nid,4,'0',STR_PAD_LEFT) ?></span></td>

                            <!-- Status -->
                            <td>
                                <span class="status-badge <?= $is_unread?'status-open':'status-read' ?>">
                                    <i class="fas <?= $is_unread?'fa-circle':'fa-check' ?>" style="font-size:<?= $is_unread?'6':'9' ?>px"></i>
                                    <?= $is_unread?'Open':'Read' ?>
                                </span>
                            </td>

                            <!-- Priority -->
                            <td><span class="req-dash">—</span></td>

                            <!-- Due -->
                            <td><span class="req-dash">—</span></td>

                            <!-- Technician -->
                            <td>
                                <div class="tech-name">
                                    <span class="tech-dot"></span>
                                    Admin
                                </div>
                            </td>

                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fff;border-top:1px solid #e5e7eb;flex-wrap:wrap;gap:8px;flex-shrink:0">
                    <span style="font-size:12px;color:#9ca3af">
                        Showing <?= $offset+1 ?>–<?= min($offset+$per_page,$total) ?> of <?= number_format($total) ?> notifications
                    </span>
                    <div style="display:flex;gap:3px">
                        <?php $pb = "?filter=$filter$tp"; ?>
                        <a href="<?=$pb?>&page=1"           class="pg-btn <?=$page<=1?'disabled':''?>"><i class="fas fa-angle-double-left"></i></a>
                        <a href="<?=$pb?>&page=<?=$page-1?>" class="pg-btn <?=$page<=1?'disabled':''?>"><i class="fas fa-chevron-left"></i></a>
                        <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
                        <a href="<?=$pb?>&page=<?=$i?>" class="pg-btn <?=$i===$page?'active':''?>"><?=$i?></a>
                        <?php endfor; ?>
                        <a href="<?=$pb?>&page=<?=$page+1?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>"><i class="fas fa-chevron-right"></i></a>
                        <a href="<?=$pb?>&page=<?=$total_pages?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>"><i class="fas fa-angle-double-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>

        <!-- ── RIGHT SIDEBAR (exact from HTML) ── -->
        <div class="right-sidebar">
            <div class="side-card yellow" onclick="location.href='?filter=unread<?=$tp?>'">
                <div class="side-num"><?= number_format($stats['unread']) ?></div>
                <div class="side-lbl">Unread</div>
            </div>
            <div class="side-card purple" onclick="location.href='?filter=read<?=$tp?>'">
                <div class="side-num"><?= number_format($stats['read']) ?></div>
                <div class="side-lbl">Read</div>
            </div>
            <div class="side-card dark-blue">
                <div class="side-inflow">
                    <div class="side-inflow-lbl">Notif. Inflow</div>
                    <div class="side-inflow-row">
                        <div class="side-inflow-num"><?= number_format($stats['today']) ?></div>
                        <div class="side-inflow-sub">Today</div>
                    </div>
                    <div class="side-inflow-row">
                        <div class="side-inflow-num"><?= number_format($stats['this_week']) ?></div>
                        <div class="side-inflow-sub">Last 7 days</div>
                    </div>
                    <div class="side-inflow-row">
                        <div class="side-inflow-num"><?= number_format($stats['total']) ?></div>
                        <div class="side-inflow-sub">All time</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /main-grid -->

</div><!-- /content-area -->

<!-- ══ BOTTOM BAR ══ -->
<div class="bottom-bar">
    <button class="bottom-btn"><i class="fas fa-comment-dots"></i></button>
    <button class="bottom-btn"><i class="fas fa-pen"></i></button>
    <button class="bottom-btn"><i class="fas fa-cog"></i></button>
    <button class="bottom-btn"><i class="fas fa-ellipsis-h"></i></button>
</div>

<!-- MY SUMMARY TAB -->
<!-- ══ MY SUMMARY TAB + PANEL ══ -->
<div class="my-summary-tab" id="mySummaryTab" onclick="toggleMySummary()">My Summary</div>

<!-- MY SUMMARY SIDE PANEL -->
<div class="ms-overlay" id="msOverlay" onclick="closeMySummary()"></div>
<div class="ms-panel" id="msPanel">
    <div class="ms-header">
        <span class="ms-title">My Summary</span>
        <button class="ms-close" onclick="closeMySummary()"><i class="fas fa-times"></i></button>
    </div>

   <!-- Stats row -->
    <div class="ms-section-label">Notifications</div>
    <div class="ms-stats-row">
        <div class="ms-stat-card ms-stat-red active" id="msStatOverdue" onclick="msFilter('overdue')">
            <div class="ms-stat-num" id="msUnreadNum">0</div>
            <div class="ms-stat-lbl">Overdue</div>
        </div>
        <div class="ms-stat-card ms-stat-blue" id="msStatDueToday" onclick="msFilter('due_today')">
            <div class="ms-stat-num" id="msTodayNum">0</div>
            <div class="ms-stat-lbl">Due Today</div>
        </div>
        <div class="ms-stat-card ms-stat-gray" id="msStatPending" onclick="msFilter('pending')">
            <div class="ms-stat-num" id="msTotalNum">0</div>
            <div class="ms-stat-lbl">Pending</div>
        </div>
    </div>

    <!-- Active filter label -->
    <div class="ms-filter-bar" id="msFilterBar">
        <i class="fas fa-filter" style="font-size:10px"></i>
        <span id="msFilterLabel">Module: Notification | Value: Today</span>
        <span class="ms-filter-count" id="msFilterCount">0</span>
    </div>

    <!-- Toolbar -->
    <div class="ms-toolbar">
        <button class="ms-tb-btn" onclick="msPickUp()"><i class="fas fa-hand-pointer"></i> Pick Up</button>
        <div class="ms-tb-dd-wrap" id="msTbAssign">
            <button class="ms-tb-btn" onclick="toggleMsDD('msTbAssign')">
                <i class="fas fa-user-plus"></i> Assign <i class="fas fa-chevron-down" style="font-size:9px"></i>
            </button>
            <div class="ms-tb-dd">
                <div class="ms-tb-dd-item" onclick="msAssignTo('Barangay Captain')">Barangay Captain</div>
                <div class="ms-tb-dd-item" onclick="msAssignTo('Barangay Secretary')">Barangay Secretary</div>
                <div class="ms-tb-dd-item" onclick="msAssignTo('Barangay Kagawad')">Barangay Kagawad</div>
            </div>
        </div>
        <button class="ms-tb-btn" onclick="msMarkAllRead()"><i class="fas fa-check-double"></i> Mark Read</button>
        <button class="ms-tb-btn" onclick="msSortToggle()">
            <i class="fas fa-sort-amount-down" id="msSortIcon"></i>
        </button>
    </div>

    <!-- Ticket list -->
    <div class="ms-list" id="msList">
        <div class="ms-loading" id="msLoading">
            <i class="fas fa-spinner fa-spin"></i> Loading...
        </div>
    </div>

    <!-- Detail view -->
    <div class="ms-detail" id="msDetail" style="display:none">
        <div class="ms-detail-header">
            <button class="ms-back-btn" onclick="msSummaryBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="ms-detail-toolbar">
                <button class="ms-tb-btn" onclick="msDetailEdit()"><i class="fas fa-edit"></i> Edit</button>
                <button class="ms-tb-btn" onclick="msDetailPickUp()"><i class="fas fa-hand-pointer"></i> Pick up</button>
                <button class="ms-tb-btn" onclick="msDetailAssign()"><i class="fas fa-user-plus"></i> Assign</button>
                <button class="ms-tb-btn" onclick="msDetailPrint()"><i class="fas fa-print"></i> Print</button>
            </div>
            <div class="ms-dd-wrap" id="msDetailActions">
                <button class="ms-tb-btn" onclick="toggleMsDD('msDetailActions')">
                    Actions <i class="fas fa-chevron-down" style="font-size:9px"></i>
                </button>
                <div class="ms-tb-dd ms-tb-dd-right">
                    <div class="ms-tb-dd-item" onclick="msMarkReadDetail()"><i class="fas fa-check"></i> Mark as Read</div>
                    <div class="ms-tb-dd-item" onclick="msDeleteDetail()"><i class="fas fa-trash"></i> Delete</div>
                </div>
            </div>
        </div>

        <!-- Ticket card -->
        <div class="ms-ticket-card">
            <div class="ms-ticket-icon" id="msDetailIcon">
                <i class="fas fa-bell"></i>
            </div>
            <div class="ms-ticket-info">
                <div class="ms-ticket-id-title">
                    <span class="ms-ticket-id" id="msDetailId">ID-0000</span>
                    <span class="ms-ticket-title" id="msDetailTitle">Title</span>
                </div>
                <div class="ms-ticket-meta" id="msDetailMeta"></div>
            </div>
<button class="ms-ticket-pin" id="msPinBtn" title="Pin to top" onclick="msPinTicket()"><i class="fas fa-thumbtack"></i></button>
<button class="ms-ticket-search" title="Search in message" onclick="msSearchInMessage()"><i class="fas fa-search"></i></button>
        </div>

        <!-- Status + transitions -->
        <div class="ms-status-row">
            <div class="ms-status-box">
                <div class="ms-status-label">Status</div>
                <div class="ms-status-val" id="msDetailStatus">
                    <span class="ms-status-dot"></span> Open
                </div>
            </div>
            <div class="ms-transitions">
                <div class="ms-trans-label">Transitions</div>
               <div style="display:flex;gap:6px">
                    <button class="ms-trans-btn" onclick="msTransition('On Hold')" onmouseenter="msShowTransTooltip(this,'On Hold','The ticket is temporarily paused due to pending information, dependencies, or external factors such as awaiting user response, external support or required approvals.','On Hold')">On Hold</button>
                    <button class="ms-trans-btn" onclick="msTransition('Work In Progress')" onmouseenter="msShowTransTooltip(this,'Work In Progress','The ticket is actively being worked on by an assigned officer.','Work In Progress')">Work In Progress</button>
                </div>
            </div>
        </div>

        <!-- Conversation tabs -->
        <div class="ms-conv-tabs">
            <div class="ms-conv-tab active" onclick="msConvTab(this,'conv')">Conversations</div>
            <div class="ms-conv-tab" onclick="msConvTab(this,'details')">Details</div>
            <div class="ms-conv-tab" onclick="msConvTab(this,'tasks')">Tasks</div>
            <div class="ms-conv-tab" onclick="msConvTab(this,'resolution')">Resolution</div>
            <div class="ms-conv-tab" onclick="msConvTab(this,'reminders')">Reminders</div>
        </div>

        <!-- Conversations content -->
        <div class="ms-conv-body" id="msConvBody">
            <div class="ms-conv-filter">
                Filter:
                <label><input type="checkbox" checked onchange="msConvFilter()"> Emails</label>
<label><input type="checkbox" checked onchange="msConvFilter()"> Auto Notifications</label>
                <label><input type="checkbox" checked onchange="msConvFilter()"> Notes</label>
            </div>
            <div class="ms-conv-section-label">Conversations</div>
            <div id="msConvMessages"></div>
        </div>

        <!-- Details content -->
        <div class="ms-conv-body" id="msDetailsBody" style="display:none">
            <div id="msDetailsContent"></div>
        </div>
    </div>
</div>

<!-- ══ DELETE MODAL ══ -->
<div class="modal-overlay" id="delModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-trash" style="color:#ef4444"></i> Delete Notification</h3>
            <button class="modal-close" onclick="closeModal('delModal')">×</button>
        </div>
        <div class="modal-body">
            <div class="modal-icon danger"><i class="fas fa-trash"></i></div>
            <h3>Are you sure?</h3>
            <p>This notification will be permanently deleted and cannot be recovered.</p>
        </div>
        <div class="modal-footer">
            <button class="sec-btn" onclick="closeModal('delModal')"><i class="fas fa-times"></i> Cancel</button>
            <button class="sec-btn danger" id="delConfirmBtn"><i class="fas fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<!-- ══ BULK MODAL ══ -->
<div class="modal-overlay" id="bulkModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="bulkModalTitle"></h3>
            <button class="modal-close" onclick="closeModal('bulkModal')">×</button>
        </div>
        <div class="modal-body">
            <div class="modal-icon" id="bulkModalIcon"></div>
            <h3 id="bulkModalH3"></h3>
            <p id="bulkModalMsg"></p>
        </div>
        <div class="modal-footer">
            <button class="sec-btn" onclick="closeModal('bulkModal')">Cancel</button>
            <button class="sec-btn" id="bulkConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ══ TOAST ══ -->
<div class="toast" id="toast"><i class="fas fa-check-circle"></i><span id="toastMsg"></span></div>

<script>
/* ══ Row click — navigate + mark read ══ */
document.querySelectorAll('#notifTable tbody tr').forEach(row => {
    row.addEventListener('click', e => {
        if (e.target.closest('input') || e.target.closest('.req-icons')) return;
        const nid = row.dataset.nid, url = row.dataset.url || 'notification-detail.php?id='+nid;
        if (row.dataset.read === '0') {
            const fd = new FormData(); fd.append('notification_id', nid);
           fetch('<?= BASE_URL ?>/modules/notifications/mark-as-read.php', {method:'POST',body:fd,keepalive:true}).catch(()=>{});
        }
        window.location.href = url;
    });
    const icons = row.querySelector('.req-icons');
    if (icons) {
        icons.addEventListener('click', e => {
            e.stopPropagation();
            document.querySelectorAll('.req-detail-row').forEach(r => r.remove());
            const existing = row.nextElementSibling;
            if (existing && existing.classList.contains('req-detail-row-open')) return;
            const nid = row.dataset.nid;
            const url = row.dataset.url || 'notification-detail.php?id='+nid;
            const detail = document.createElement('tr');
            detail.className = 'req-detail-row req-detail-row-open';
            const title = row.querySelector('.req-subject') ? row.querySelector('.req-subject').textContent.trim() : '';
            const preview = row.querySelector('[style*="9ca3af"]') ? row.querySelector('[style*="9ca3af"]').textContent.trim() : '';
            detail.innerHTML = `<td colspan="8">
              <div class="req-detail-content">
                <div class="req-detail-item"><div class="req-detail-label">Ticket ID</div><div class="req-detail-value" style="color:#1976d2;font-weight:700">ID-${String(nid).padStart(4,'0')}</div></div>
                <div class="req-detail-item"><div class="req-detail-label">Status</div><div class="req-detail-value">${row.dataset.read==='0'?'Unread':'Read'}</div></div>
                <div class="req-detail-item"><div class="req-detail-label">Subject</div><div class="req-detail-value" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${title}</div></div>
                <div class="req-detail-item"><div class="req-detail-label">Preview</div><div class="req-detail-value" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280">${preview}</div></div>
                <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
                  <a href="${url}" class="sec-btn primary" style="font-size:11px"><i class="fas fa-eye"></i> View</a>
                  ${row.dataset.read==='0'?`<button class="sec-btn success" style="font-size:11px" onclick="markReadInline(${nid},this.closest('tr').previousElementSibling,this.closest('tr'))"><i class="fas fa-check"></i> Mark Read</button>`:''}
                  <button class="sec-btn danger" style="font-size:11px" onclick="confirmDel(${nid});this.closest('tr').remove()"><i class="fas fa-trash"></i> Delete</button>
                  <button class="sec-btn" style="font-size:11px" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i> Close</button>
                </div>
              </div>
            </td>`;
            row.after(detail);
        });
    }
});
function positionPreviewCard(e) {
    if (!previewCard) return;
    const cardW = 360;
    const cardH = previewCard.offsetHeight || 120;
    let left = e.clientX + 16;
    let top  = e.clientY + 16;

    // Flip left if going off right edge
    if (left + cardW > window.innerWidth - 10) left = e.clientX - cardW - 10;
    // Flip up if going off bottom edge
    if (top + cardH > window.innerHeight - 10) top = e.clientY - cardH - 10;

    previewCard.style.left = left + 'px';
    previewCard.style.top  = top + 'px';
    previewCard.style.position = 'fixed';
}
/* ══ Row hover preview card ══ */
let previewCard = null;
let previewTimer = null;

function positionPreviewCard(e) {
    if (!previewCard) return;
    const cardW = 380;
    const cardH = previewCard.offsetHeight || 140;
    let left = e.clientX + 16;
    let top  = e.clientY + 16;
    if (left + cardW > window.innerWidth - 10) left = e.clientX - cardW - 10;
    if (top + cardH > window.innerHeight - 10) top = e.clientY - cardH - 10;
    previewCard.style.left = left + 'px';
    previewCard.style.top  = top + 'px';
}

document.querySelectorAll('#notifTable tbody tr:not(.req-detail-row)').forEach(row => {
    row.addEventListener('mouseenter', e => {
        previewTimer = setTimeout(() => {
            if (previewCard) previewCard.remove();

            const nid      = row.dataset.nid || '';
            const title    = row.querySelector('.req-subject')?.textContent?.trim() || '';
            // Full message is stored in data-search as "title message" — extract message part
            const search   = row.dataset.search || '';
            const fullMsg  = search.replace(title.toLowerCase(), '').trim();
            const iconEl   = row.querySelector('.req-icon i');
            const iconCls  = iconEl ? Array.from(iconEl.classList).find(c => c.startsWith('fa-') && c !== 'fas') || 'fa-bell' : 'fa-bell';
            const iconBg   = row.querySelector('.req-icon')?.style?.background || '#f9fafb';
            const iconClr  = row.querySelector('.req-icon')?.style?.color || '#6b7280';

            if (!title) return;

            previewCard = document.createElement('div');
            previewCard.className = 'row-preview-card';
            previewCard.innerHTML = `
                <div style="font-size:11px;color:#9ca3af;font-weight:500;margin-bottom:8px">
                    Incident Request ID: <strong style="color:#6b7280">ID-${String(nid).padStart(4,'0')}</strong>
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                    <span style="width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;background:${iconBg};color:${iconClr}">
                        <i class="fas ${iconCls}"></i>
                    </span>
                    <span style="font-size:13.5px;font-weight:700;color:#374151">${title}</span>
                </div>
                ${fullMsg ? `<div style="font-size:12px;color:#6b7280;line-height:1.7;max-height:120px;overflow:hidden">${fullMsg}</div>` : ''}`;
            document.body.appendChild(previewCard);
            positionPreviewCard(e);
        }, 250);
    });

    row.addEventListener('mousemove', e => {
        if (previewCard) positionPreviewCard(e);
    });

    row.addEventListener('mouseleave', () => {
        clearTimeout(previewTimer);
        if (previewCard) { previewCard.remove(); previewCard = null; }
    });
});

/* ══ Mark read inline ══ */
function markReadInline(nid, row, detailRow) {
    const fd = new FormData(); fd.append('notification_id', nid);
fetch('<?= BASE_URL ?>/modules/notifications/mark-as-read.php', {method:'POST',body:fd}).then(()=>{
        if (row) { row.classList.remove('unread','unread-email'); row.dataset.read='1'; }
        if (detailRow) detailRow.remove();
        showToast('Marked as read','success');
    }).catch(()=>{});
}

/* ══ Dropdown ══ */
function toggleDD(id) {
    document.querySelectorAll('.dd-wrapper.open').forEach(el => { if(el.id!==id) el.classList.remove('open'); });
    document.getElementById(id).classList.toggle('open');
}
document.addEventListener('click', e => { if(!e.target.closest('.dd-wrapper')) document.querySelectorAll('.dd-wrapper.open').forEach(el=>el.classList.remove('open')); });

/* ══ Modal ══ */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if(e.target===m) closeModal(m.id); }));
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>closeModal(m.id)); });

/* ══ Checkbox ══ */
function toggleAll(cb) { document.querySelectorAll('.notif-cb').forEach(c => c.checked=cb.checked); updateSelBar(); }
function selAllRows()  { document.querySelectorAll('.notif-cb').forEach(c => c.checked=true);  document.getElementById('selAll').checked=true;  updateSelBar(); }
function deselAllRows(){ document.querySelectorAll('.notif-cb').forEach(c => c.checked=false); document.getElementById('selAll').checked=false; updateSelBar(); }
function getChecked()  { return [...document.querySelectorAll('.notif-cb:checked')].map(c=>c.value); }
function updateSelBar(){}

/* ══ Bulk actions ══ */
function bulkRead() {
    const ids = getChecked();
    if (!ids.length) { showToast('Select at least one notification.','error'); return; }
    showBulkModal('success','Mark as Read','fa-check-circle',`Mark ${ids.length} notification(s) as read?`,'bulk_read','success');
}
function bulkDelete() {
    const ids = getChecked();
    if (!ids.length) { showToast('Select at least one notification.','error'); return; }
    showBulkModal('danger','Delete Notifications','fa-trash',`Permanently delete ${ids.length} notification(s)?`,'bulk_delete','danger');
}
function showBulkModal(type,title,icon,msg,action,btnCls) {
    document.getElementById('bulkModalTitle').innerHTML = `<i class="fas ${icon}" style="color:${type==='danger'?'#ef4444':'#16a34a'}"></i> ${title}`;
    document.getElementById('bulkModalIcon').className  = `modal-icon ${type}`;
    document.getElementById('bulkModalIcon').innerHTML  = `<i class="fas ${icon}"></i>`;
    document.getElementById('bulkModalH3').textContent  = title;
    document.getElementById('bulkModalMsg').textContent = msg;
    const btn=document.getElementById('bulkConfirmBtn'), nb=btn.cloneNode(true);
    btn.parentNode.replaceChild(nb,btn);
    nb.className=`sec-btn ${btnCls}`; nb.innerHTML=`<i class="fas ${icon}"></i> Confirm`;
    nb.addEventListener('click',()=>{ document.getElementById('bulkAction').value=action; document.getElementById('bulkForm').submit(); closeModal('bulkModal'); });
    openModal('bulkModal');
}

/* ══ Single delete ══ */
function confirmDel(nid) {
    const btn=document.getElementById('delConfirmBtn'), nb=btn.cloneNode(true);
    btn.parentNode.replaceChild(nb,btn);
    nb.addEventListener('click',()=>{
        const frm=document.createElement('form'); frm.method='POST'; frm.style.display='none';
        frm.innerHTML=`<input name="action" value="delete"><input name="notification_id" value="${nid}">`;
        document.body.appendChild(frm); frm.submit();
    });
    openModal('delModal');
}

function liveSearch(q) {
    const term = q.toLowerCase().trim();
    document.querySelectorAll('#notifTable tbody tr:not(.req-detail-row)').forEach(row => {
        if (!term) { row.style.display = ''; return; }
        const searchText = (row.dataset.search || '');
        // Also match against the padded ID (e.g. "0302" or "302" matches ID-0302)
        const nid        = row.dataset.nid || '';
        const paddedId   = String(nid).padStart(4, '0');
        const matches    = searchText.includes(term)
                        || paddedId.includes(term)
                        || nid.includes(term)
                        || ('id-' + paddedId).includes(term);
        row.style.display = matches ? '' : 'none';
    });
}

/* ══ Toast ══ */
let toastTimer;
function showToast(msg,type='success') {
    const t=document.getElementById('toast'), i=t.querySelector('i');
    document.getElementById('toastMsg').textContent=msg;
    t.className=`toast ${type}`; i.className=type==='success'?'fas fa-check-circle':'fas fa-exclamation-circle';
    t.classList.add('show'); clearTimeout(toastTimer);
    toastTimer=setTimeout(()=>t.classList.remove('show'),3000);
}

/* ══ Toolbar actions ══ */
function pickUpSelected() {
    const ids = getChecked();
    if (!ids.length) { showToast('Select at least one notification first.', 'error'); return; }
    showToast(ids.length + ' notification(s) picked up.', 'success');
}
function closeSelected() {
    const ids = getChecked();
    if (!ids.length) { showToast('Select at least one notification first.', 'error'); return; }
    showBulkModal('danger','Close Notifications','fa-times-circle',`Close ${ids.length} notification(s)?`,'bulk_delete','danger');
}
function assignTo(name) {
    const ids = getChecked();
    if (!ids.length) { showToast('Select at least one notification first.', 'error'); return; }
    showToast('Assigned to ' + name, 'success');
    document.querySelectorAll('.dd-wrapper.open').forEach(el => el.classList.remove('open'));
    document.querySelectorAll('.notif-cb:checked').forEach(cb => {
        const row = cb.closest('tr');
        if (row) { const tc = row.querySelector('.tech-name'); if (tc) tc.innerHTML = '<span class="tech-dot"></span> ' + name; }
    });
    deselAllRows();
}

/* ══ Auto-dismiss alerts ══ */
setTimeout(()=>{
    ['sdAlert','sdAlertErr'].forEach(id => {
        const el=document.getElementById(id);
        if(el){ el.style.transition='opacity .4s'; el.style.opacity='0'; setTimeout(()=>el.remove(),400); }
    });
},5000);

/* ══════════════════════════════════════════════════════
   MY SUMMARY PANEL
══════════════════════════════════════════════════════ */

(function() {
    const ictNums = document.querySelectorAll('.ict-stat-num');
    const total  = ictNums[0] ? parseInt(ictNums[0].textContent) : 0;
    const unread = ictNums[1] ? parseInt(ictNums[1].textContent) : 0;
    const today  = parseInt(document.querySelector('.stat-card.teal .stat-num')?.textContent || '0');
    document.getElementById('msUnreadNum').textContent = unread;
    document.getElementById('msTodayNum').textContent  = today;
    document.getElementById('msTotalNum').textContent  = total;
})();

let msCurrentFilter = 'overdue';
let msActiveItem    = null;
let msSortAsc       = false;
const msTicketStatus = {}; // stores transition status per nid

function msExpandToSplit() {
    const panel = document.getElementById('msPanel');
    panel.style.width    = '860px';
    panel.style.maxWidth = '96vw';
    const detail = document.getElementById('msDetail');
    detail.style.left  = '300px';
    detail.style.right = '0';
    detail.style.top   = '0';
    detail.style.bottom = '0';
}
function msCollapseToNormal() {
    const panel = document.getElementById('msPanel');
    panel.style.width    = '420px';
    panel.style.maxWidth = '';
}

function msGetItems(filter) {
    const rows = document.querySelectorAll('#notifTable tbody tr:not(.req-detail-row)');
    let items = [];
    rows.forEach(row => {
        const nid       = row.dataset.nid;
        const isRead    = row.dataset.read === '1';
        const url       = row.dataset.url || 'notification-detail.php?id=' + nid;
        const title     = row.querySelector('.req-subject')?.textContent?.trim() || 'Notification';
        const preview   = row.querySelectorAll('td')[2]?.querySelector('[style*="9ca3af"]')?.textContent?.trim() || '';
        const iconColor = row.querySelector('.req-icon')?.getAttribute('style') || '';
        const techName  = row.querySelector('.tech-name')?.textContent?.trim() || 'Admin';
        // Get sender email from the row (set via data-email on the tr by PHP)
        const senderEmail = row.dataset.email || '';
        const senderName  = row.dataset.sender || '';
        let show = true;
        if (filter === 'overdue' && isRead) show = false;
       if (show) items.push({ nid, isRead, url, title, preview, iconColor, techName, senderEmail, senderName, row });
    });
    return items;
}

const typeIconMap = {
    'fa-exclamation-triangle': { bg:'#fff8e1', color:'#f59e0b' },
    'fa-gavel':                { bg:'#fef2f2', color:'#ef4444' },
    'fa-comments':             { bg:'#fff3e0', color:'#f97316' },
    'fa-file-alt':             { bg:'#e3f2fd', color:'#1976d2' },
    'fa-envelope':             { bg:'#e3f2fd', color:'#1976d2' },
    'fa-bullhorn':             { bg:'#ede9fe', color:'#8b5cf6' },
    'fa-calendar-check':       { bg:'#f0fdfa', color:'#14b8a6' },
    'fa-hand-holding-medical': { bg:'#faf5ff', color:'#8b5cf6' },
    'fa-bell':                 { bg:'#f9fafb', color:'#6b7280' },
};

function msGetIconStyle(item) {
    const rowIcon = item.row?.querySelector('.req-icon i');
    let iconClass = 'fa-bell';
    if (rowIcon) {
        const cls = Array.from(rowIcon.classList).find(c => c.startsWith('fa-') && c !== 'fas');
        if (cls) iconClass = cls;
    }
    return { iconClass, style: typeIconMap[iconClass] || { bg:'#f9fafb', color:'#6b7280' } };
}

function msBuildList(filter) {
    const list = document.getElementById('msList');
    list.innerHTML = '<div class="ms-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

    const allNids = [...document.querySelectorAll('#notifTable tbody tr:not(.req-detail-row)')]
        .map(r => r.dataset.nid).filter(Boolean);

    const loadStatuses = allNids.length
        ? Promise.all(allNids.map(nid =>
            msTicketStatus[nid]
                ? Promise.resolve()
                : fetch(`<?= BASE_URL ?>/modules/notifications/update-status.php?nid=${nid}&action=get`)
                    .then(r => r.json())
                    .then(data => { if (data.status) msTicketStatus[nid] = data.status; })
                    .catch(() => {})
          ))
        : Promise.resolve();

    loadStatuses.then(() => {
        const items = msGetItems(filter);
        if (msSortAsc) items.reverse();
        const labelMap = { overdue:'Overdue', due_today:'Due Today', pending:'Pending' };
        document.getElementById('msFilterLabel').textContent =
            'Module: Notification \u00a0|\u00a0 Value: ' + (labelMap[filter] || filter);
        document.getElementById('msFilterCount').textContent = items.length;
        list.innerHTML = '';
        if (!items.length) {
            list.innerHTML = '<div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px"><i class="fas fa-bell-slash" style="font-size:22px;display:block;margin-bottom:8px"></i>No notifications found</div>';
            return;
        }
        items.forEach(item => {
            const { iconClass, style } = msGetIconStyle(item);
            const cachedStatus = msTicketStatus[item.nid] || (item.isRead ? 'Read' : 'Open');
            const wip  = cachedStatus === 'Work In Progress';
            const hold = cachedStatus === 'On Hold';
            const read = cachedStatus === 'Read';
            const dotColor = read ? '#22c55e' : hold ? '#6b7280' : wip ? '#f59e0b' : '#ef4444';
            const lblColor = read ? '#16a34a' : hold ? '#6b7280' : wip ? '#d97706' : '#ef4444';
            const div = document.createElement('div');
            div.className = 'ms-item';
            div.dataset.nid = item.nid;
            div.innerHTML = `
                <input type="checkbox" class="ms-item-cb" style="position:absolute;top:13px;right:12px;width:13px;height:13px;accent-color:#1976d2;cursor:pointer" onclick="event.stopPropagation()">
                <div class="ms-item-header">
                    <div class="ms-item-icon" style="background:${style.bg};color:${style.color}"><i class="fas ${iconClass}"></i></div>
                    <span class="ms-item-id">ID-${String(item.nid).padStart(4,'0')}</span>
                    <span class="ms-item-title">${item.title}</span>
                </div>
                <div class="ms-item-meta">
                    By: ${item.techName} &nbsp;|&nbsp; On: — &nbsp;|&nbsp; Status:
                    <span class="ms-item-status-dot" style="background:${dotColor}"></span>
                    <strong style="color:${lblColor}">${cachedStatus}</strong>
                </div>`;
            div.addEventListener('click', e => {
                if (e.target.type === 'checkbox') return;
                msShowDetail(item, style, iconClass);
            });
            list.appendChild(div);
        });
    });
}

function msShowDetail(item, typeStyle, iconClass) {
    document.querySelectorAll('.ms-item').forEach(i => i.classList.remove('active'));
    document.querySelector(`.ms-item[data-nid="${item.nid}"]`)?.classList.add('active');
    msActiveItem = item;
    msExpandToSplit();
    msUpdatePinBtn(item.nid);

    document.getElementById('msDetailId').textContent        = 'ID-' + String(item.nid).padStart(4,'0');
    document.getElementById('msDetailTitle').textContent     = item.title;
    document.getElementById('msDetailIcon').style.background = typeStyle.bg;
    document.getElementById('msDetailIcon').style.color      = typeStyle.color;
    document.getElementById('msDetailIcon').innerHTML        = `<i class="fas ${iconClass}"></i>`;

    const isRead   = item.isRead;
    const statusEl = document.getElementById('msDetailStatus');
   if (msTicketStatus[item.nid]) {
    msApplyStatus(msTicketStatus[item.nid]);
} else {
    fetch('<?= BASE_URL ?>/modules/notifications/update-status.php?nid=' + item.nid + '&action=get')
        .then(r => r.json())
        .then(data => {
            const s = data.status || (isRead ? 'Read' : 'Open');
            msTicketStatus[item.nid] = s;
            msApplyStatus(s);
        })
        .catch(() => msApplyStatus(isRead ? 'Read' : 'Open'));
}

    document.getElementById('msDetailMeta').innerHTML =
        `<span style="background:#fff8e1;color:#d97706;padding:2px 7px;border-radius:3px;font-size:10.5px;font-weight:700">Incident Request</span>
         &nbsp; Priority: <strong>Not Assigned</strong>
         &nbsp;|&nbsp; Requested By <span style="color:#1976d2">${item.techName}</span>
         &nbsp; on <span style="color:#9ca3af">—</span>`;

 document.getElementById('msConvMessages').innerHTML = `
        <div class="ms-msg" data-type="email">
            <div class="ms-msg-icon" style="background:${typeStyle.bg};color:${typeStyle.color}"><i class="fas ${iconClass}"></i></div>
            <div class="ms-msg-body">
                <div class="ms-msg-sender">${item.senderName || item.techName}</div>
                <div class="ms-msg-time">—</div>
                <div class="ms-msg-text">${item.preview || item.title}</div>
                <div class="ms-msg-actions">
                    <button class="ms-msg-act-btn" title="Reply" onclick="msOpenReply('reply','${item.nid}','${(item.senderEmail||'').replace(/'/g,"\\'")}','${(item.senderName||item.techName||'').replace(/'/g,"\\'")}')"><i class="fas fa-reply"></i></button>
                    <button class="ms-msg-act-btn" title="Reply All" onclick="msOpenReply('reply_all','${item.nid}','${(item.senderEmail||'').replace(/'/g,"\\'")}','${(item.senderName||item.techName||'').replace(/'/g,"\\'")}')"><i class="fas fa-reply-all"></i></button>
                    <button class="ms-msg-act-btn" title="Forward" onclick="msOpenReply('forward','${item.nid}','${(item.senderEmail||'').replace(/'/g,"\\'")}','${(item.senderName||item.techName||'').replace(/'/g,"\\'")}')"><i class="fas fa-share"></i></button>
                </div>
            </div>
        </div>
     <div class="ms-msg" data-type="auto" style="display:none" id="msAutoNotifMsg">
            <div class="ms-msg-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-robot"></i></div>
            <div class="ms-msg-body">
                <div class="ms-msg-sender" style="color:#16a34a">System</div>
                <div class="ms-msg-time">—</div>
                <div class="ms-msg-text" style="background:#f0fdf4;border-color:#bbf7d0" id="msAutoNotifContent">
                    <strong>Notification Created</strong><br>
                    Ticket ID: ID-${String(item.nid).padStart(4,'0')}<br>
                    Subject: ${item.title}<br>
                    Status: ${item.isRead ? 'Read' : 'Open'}<br>
                    Assigned To: ${item.techName}
                </div>
            </div>
        </div>
        <div class="ms-msg" data-type="note" style="display:flex">
            <div class="ms-msg-icon" style="background:#fff8e1;color:#f59e0b"><i class="fas fa-sticky-note"></i></div>
            <div class="ms-msg-body">
                <div class="ms-msg-sender" style="color:#d97706">System Note</div>
                <div class="ms-msg-time">—</div>
                <div class="ms-msg-text" style="background:#fff8e1;border-color:#fde68a">
                    Notification delivered. Status: ${item.isRead ? '<span style="color:#16a34a">Read</span>' : '<span style="color:#ef4444">Unread</span>'}
                </div>
            </div>
        </div>
        <div id="msReplyForm" style="display:none;margin-top:12px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff">
            <div style="padding:10px 14px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:8px;background:#f9fafb">
                <span style="font-size:11px;font-weight:700;color:#1976d2" id="msReplyType">Reply All</span>
                <div style="flex:1"></div>
                <button onclick="msCloseReply()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:14px">×</button>
            </div>
            <div style="padding:10px 14px;display:flex;flex-direction:column;gap:8px">
                <div style="display:flex;align-items:center;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px">To <span style="color:#ef4444">*</span></label>
                    <input id="msReplyTo" type="text" style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:5px 9px;font-size:12px;outline:none;font-family:inherit" placeholder="Recipient email">
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px">Cc</label>
                    <input type="text" style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:5px 9px;font-size:12px;outline:none;font-family:inherit" placeholder="">
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px">Bcc</label>
                    <input type="text" style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:5px 9px;font-size:12px;outline:none;font-family:inherit" placeholder="">
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px">Template</label>
                    <select style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:5px 9px;font-size:12px;outline:none;font-family:inherit;background:#fff">
                        <option>Default Reply Template</option>
                        <option>Follow Up Template</option>
                        <option>Resolution Template</option>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px">Subject <span style="color:#ef4444">*</span></label>
                    <input id="msReplySubject" type="text" style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:5px 9px;font-size:12px;outline:none;font-family:inherit">
                </div>
                <div style="display:flex;align-items:flex-start;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px;padding-top:6px">Description</label>
                    <div style="flex:1">
                        <div style="border:1px solid #e5e7eb;border-radius:4px;overflow:hidden">
                            <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:5px 8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <button onclick="document.execCommand('bold')" style="background:none;border:none;cursor:pointer;font-weight:700;font-size:12px;padding:2px 5px;border-radius:3px" title="Bold"><b>B</b></button>
                                <button onclick="document.execCommand('italic')" style="background:none;border:none;cursor:pointer;font-style:italic;font-size:12px;padding:2px 5px;border-radius:3px" title="Italic"><i>I</i></button>
                                <button onclick="document.execCommand('underline')" style="background:none;border:none;cursor:pointer;font-size:12px;padding:2px 5px;border-radius:3px;text-decoration:underline" title="Underline">U</button>
                                <span style="width:1px;height:14px;background:#e5e7eb;display:inline-block"></span>
                                <select onchange="document.execCommand('fontSize',false,this.value);this.value=''" style="border:1px solid #e5e7eb;border-radius:3px;font-size:11px;padding:1px 3px;background:#fff">
                                    <option value="">Size</option>
                                    <option value="1">8</option><option value="2">10</option><option value="3">12</option>
                                    <option value="4">14</option><option value="5">18</option><option value="6">24</option>
                                </select>
                                <button onclick="document.execCommand('insertUnorderedList')" style="background:none;border:none;cursor:pointer;font-size:12px;padding:2px 5px" title="Bullet list">≡</button>
                            </div>
                            <div id="msReplyBody" contenteditable="true" style="min-height:100px;padding:10px 12px;font-size:12.5px;color:#374151;outline:none;line-height:1.6"></div>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px">Request Status</label>
                    <select style="width:120px;border:1px solid #e5e7eb;border-radius:4px;padding:5px 9px;font-size:12px;outline:none;font-family:inherit;background:#fff">
                        <option>Open</option>
                        <option>On Hold</option>
                        <option>Work In Progress</option>
                        <option>Resolved</option>
                        <option>Closed</option>
                    </select>
                </div>
                <div style="display:flex;align-items:flex-start;gap:10px">
                    <label style="font-size:12px;color:#374151;font-weight:600;width:60px;padding-top:4px">Attachments</label>
                    <div style="flex:1">
                        <div style="border:1.5px dashed #d1d5db;border-radius:6px;padding:14px;text-align:center;color:#9ca3af;font-size:12px;cursor:pointer" onclick="document.getElementById('msReplyFile').click()">
                            Drag and drop files here
                        </div>
                        <input type="file" id="msReplyFile" multiple style="display:none">
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:6px;padding-left:70px">
                    <input type="checkbox" checked id="msReplyShowMail" style="accent-color:#1976d2;cursor:pointer">
                    <label for="msReplyShowMail" style="font-size:12px;color:#374151;cursor:pointer">Show this mail to requester also</label>
                </div>
            </div>
            <div style="padding:10px 14px;border-top:1px solid #e5e7eb;display:flex;gap:8px;background:#f9fafb">
                <button onclick="msSendReply()" style="padding:6px 18px;background:#1976d2;color:#fff;border:none;border-radius:4px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit">Send</button>
                <button style="padding:6px 14px;background:#fff;color:#374151;border:1px solid #e5e7eb;border-radius:4px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit">Save</button>
                <button style="padding:6px 14px;background:#fff;color:#374151;border:1px solid #e5e7eb;border-radius:4px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit">Send for review</button>
                <button onclick="msCloseReply()" style="padding:6px 14px;background:#fff;color:#374151;border:1px solid #e5e7eb;border-radius:4px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit">Cancel</button>
            </div>

            
        </div>`;

    document.getElementById('msDetailsContent').innerHTML = `
        <div class="ms-detail-field"><div class="ms-detail-field-label">Ticket ID</div>
            <div class="ms-detail-field-value" style="color:#1976d2;font-weight:700">ID-${String(item.nid).padStart(4,'0')}</div></div>
        <div class="ms-detail-field"><div class="ms-detail-field-label">Status</div>
            <div class="ms-detail-field-value"><span class="ms-detail-badge ${isRead?'read':'open'}">${isRead?'Read':'Open'}</span></div></div>
        <div class="ms-detail-field"><div class="ms-detail-field-label">Subject</div>
            <div class="ms-detail-field-value">${item.title}</div></div>
        <div class="ms-detail-field"><div class="ms-detail-field-label">Assigned Officer</div>
            <div class="ms-detail-field-value">${item.techName}</div></div>
        <div class="ms-detail-field"><div class="ms-detail-field-label">Preview</div>
            <div class="ms-detail-field-value" style="color:#6b7280">${item.preview||'—'}</div></div>
        <div style="margin-top:14px">
            <a href="${item.url}" class="ms-tb-btn" style="background:#1976d2;color:#fff;border-color:#1976d2">
                <i class="fas fa-eye"></i> View Full Details
            </a>
        </div>`;

    const detail = document.getElementById('msDetail');
    detail.style.display       = 'flex';
    detail.style.flexDirection = 'column';

    document.querySelectorAll('.ms-conv-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.ms-conv-tab').classList.add('active');
    document.getElementById('msConvBody').style.display    = 'block';
    document.getElementById('msDetailsBody').style.display = 'none';

   // Load reply history into the Auto Notifications tab
    if (item.nid) {
        fetch('<?= BASE_URL ?>/modules/notifications/get-replies.php?nid=' + item.nid)
            .then(r => r.json())
            .then(data => {
                if (data.replies && data.replies.length > 0) {
                    const autoEl = document.getElementById('msAutoNotifMsg');
                    const contentEl = document.getElementById('msAutoNotifContent');
                    if (autoEl && contentEl) {
                        let html = '<strong>Reply History</strong><br><br>';
                        data.replies.forEach(r => {
                            html += `<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #e5e7eb">
                                <div style="font-size:11px;color:#6b7280;margin-bottom:3px">
                                    Sent to: <strong>${r.recipient_email}</strong> &nbsp;·&nbsp; ${r.created_at}
                                </div>
                                <div style="font-size:12px;color:#374151"><strong>${r.subject}</strong></div>
                                <div style="font-size:11.5px;color:#6b7280;margin-top:2px">${r.body}</div>
                            </div>`;
                        });
                        contentEl.innerHTML = html;
                        autoEl.style.display = 'flex';
                    }
                }
            })
            .catch(() => {});
    }

    if (!item.isRead) {
        const fd = new FormData(); fd.append('notification_id', item.nid);
        fetch('<?= BASE_URL ?>/modules/notifications/mark-as-read.php', {method:'POST', body:fd, keepalive:true}).catch(()=>{});
    }
}

function msSummaryBack() {
    const detail = document.getElementById('msDetail');
    detail.style.display = 'none';
    detail.style.left = '';
    detail.style.right = '';
    msCollapseToNormal();
    msActiveItem = null;
    document.querySelectorAll('.ms-item').forEach(i => i.classList.remove('active'));
}

function msFilter(filter) {
    msCurrentFilter = filter;
    document.querySelectorAll('.ms-stat-card').forEach(c => c.classList.remove('active'));
    const map = { overdue:'msStatOverdue', due_today:'msStatDueToday', pending:'msStatPending' };
    document.getElementById(map[filter])?.classList.add('active');
    document.getElementById('msDetail').style.display = 'none';
    msCollapseToNormal();
    msActiveItem = null;
    msBuildList(filter);
}

function msSortToggle() {
    msSortAsc = !msSortAsc;
    document.getElementById('msSortIcon').className = msSortAsc ? 'fas fa-sort-amount-up' : 'fas fa-sort-amount-down';
    msBuildList(msCurrentFilter);
}

function msPickUp()       { showToast('Notification picked up', 'success'); }
function msAssignTo(name) { showToast('Assigned to ' + name, 'success'); closeAllMsDD(); }
function msMarkAllRead() {
    const link = document.querySelector('a[href*="mark_all_read"]');
    if (link) window.location.href = link.href;
    else showToast('All notifications marked as read', 'success');
}
function msDetailEdit()   { if (msActiveItem) window.location.href = msActiveItem.url; }
function msDetailPickUp() { showToast('Picked up', 'success'); }
function msDetailAssign() { showToast('Assign feature — select officer from list', 'success'); }
function msDetailPrint()  { window.print(); }
function msMarkReadDetail() {
    if (!msActiveItem) return;
    const fd = new FormData(); fd.append('notification_id', msActiveItem.nid);
   fetch('<?= BASE_URL ?>/modules/notifications/mark-as-read.php', {method:'POST', body:fd}).then(() => {
        showToast('Marked as read', 'success');
        msBuildList(msCurrentFilter);
        msSummaryBack();
    }).catch(()=>{});
}
function msDeleteDetail() {
    if (!msActiveItem) return;
    const frm = document.createElement('form');
    frm.method = 'POST'; frm.style.display = 'none';
    frm.innerHTML = `<input name="action" value="delete"><input name="notification_id" value="${msActiveItem.nid}">`;
    document.body.appendChild(frm); frm.submit();
}
function msConvTab(tab, panel) {
    document.querySelectorAll('.ms-conv-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('msConvBody').style.display    = panel === 'conv'    ? 'block' : 'none';
    document.getElementById('msDetailsBody').style.display = panel === 'details' ? 'block' : 'none';
}
function msTransition(status) {
    document.querySelectorAll('.ms-trans-tooltip').forEach(t => t.remove());
    if (msActiveItem) {
        msTicketStatus[msActiveItem.nid] = status;
        const fd = new FormData();
        fd.append('nid', msActiveItem.nid);
        fd.append('status', status);
        fetch('<?= BASE_URL ?>/modules/notifications/update-status.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('Status changed to: ' + status, 'success');
                    // Refresh auto-notifications tab to show status update email
                    if (msActiveItem?.nid) {
                        fetch('<?= BASE_URL ?>/modules/notifications/get-replies.php?nid=' + msActiveItem.nid)
                            .then(r => r.json())
                            .then(data => {
                                if (data.replies && data.replies.length > 0) {
                                    const autoEl = document.getElementById('msAutoNotifMsg');
                                    const contentEl = document.getElementById('msAutoNotifContent');
                                    if (autoEl && contentEl) {
                                        let html = '<strong>Reply History</strong><br><br>';
                                        data.replies.forEach(r => {
                                            html += `<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #e5e7eb">
                                                <div style="font-size:11px;color:#6b7280;margin-bottom:3px">
                                                    Sent to: <strong>${r.recipient_email}</strong> &nbsp;·&nbsp; ${r.created_at}
                                                </div>
                                                <div style="font-size:12px;color:#374151"><strong>${r.subject}</strong></div>
                                                <div style="font-size:11.5px;color:#6b7280;margin-top:2px">${r.body}</div>
                                            </div>`;
                                        });
                                        contentEl.innerHTML = html;
                                        autoEl.style.display = 'flex';
                                    }
                                }
                            }).catch(() => {});
                    }
                } else showToast('Failed to save status', 'error');
            })
            .catch(() => showToast('Network error', 'error'));
    }
    msApplyStatus(status);
}
function msApplyStatus(status) {
    const el = document.getElementById('msDetailStatus');
    if (!el) return;
    const wip  = status === 'Work In Progress';
    const hold = status === 'On Hold';
    const read = status === 'Read';
    const open = status === 'Open';
    el.innerHTML    = `<span class="ms-status-dot" style="background:${read?'#22c55e':hold?'#6b7280':wip?'#f59e0b':'#ef4444'}"></span> ${status}`;
    el.style.background  = read ? '#f0fdf4' : hold ? '#f3f4f6' : wip ? '#fff8e1' : '#fef2f2';
    el.style.color       = read ? '#16a34a' : hold ? '#6b7280' : wip ? '#d97706' : '#ef4444';
    el.style.borderColor = read ? '#bbf7d0' : hold ? '#e5e7eb' : wip ? '#fde68a' : '#fecaca';

    // ── Also update the list item's status indicator ──
    if (msActiveItem) {
        const listItem = document.querySelector(`.ms-item[data-nid="${msActiveItem.nid}"]`);
        if (listItem) {
            const dot   = listItem.querySelector('.ms-item-status-dot');
            const label = listItem.querySelector('strong');
            const dotColor  = read ? '#22c55e' : hold ? '#6b7280' : wip ? '#f59e0b' : '#ef4444';
            const lblColor  = read ? '#16a34a' : hold ? '#6b7280' : wip ? '#d97706' : '#ef4444';
            if (dot)   dot.style.background = dotColor;
            if (label) { label.style.color = lblColor; label.textContent = status; }
        }
    }
}

function msShowTransTooltip(btn, name, desc, target) {
    document.querySelectorAll('.ms-trans-tooltip').forEach(t => t.remove());
    const tip = document.createElement('div');
    tip.className = 'ms-trans-tooltip';
    tip.style.cssText = 'position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;min-width:240px;max-width:300px;font-size:12px;color:#374151';
    tip.innerHTML = `
        <div style="font-weight:700;margin-bottom:4px;font-size:12.5px">Name: ${name}</div>
        <div style="color:#6b7280;line-height:1.6;margin-bottom:6px">Description: ${desc}</div>
        <div style="font-weight:600;color:#1976d2">Target Status: ${target}</div>`;
    document.body.appendChild(tip);
    const r = btn.getBoundingClientRect();
    tip.style.top  = (r.bottom + window.scrollY + 6) + 'px';
    tip.style.left = (r.left + window.scrollX) + 'px';
    const hideOnClick = (e) => { if (!tip.contains(e.target) && e.target !== btn) { tip.remove(); document.removeEventListener('click', hideOnClick); } };
    setTimeout(() => document.addEventListener('click', hideOnClick), 50);
}
function msConvFilter() {
    const cbs    = document.querySelectorAll('.ms-conv-filter input[type="checkbox"]');
    const showEmails = cbs[0]?.checked;
    const showAuto   = cbs[1]?.checked;
    const showNotes  = cbs[2]?.checked;
    document.querySelectorAll('#msConvMessages .ms-msg[data-type]').forEach(msg => {
        const t = msg.dataset.type;
        if (t === 'email')  msg.style.display = showEmails ? 'flex' : 'none';
        if (t === 'auto')   msg.style.display = showAuto   ? 'flex' : 'none';
        if (t === 'note')   msg.style.display = showNotes  ? 'flex' : 'none';
    });
}

function toggleMsDD(id) {
    document.querySelectorAll('.ms-tb-dd-wrap.open, .ms-dd-wrap.open').forEach(el => {
        if (el.id !== id) el.classList.remove('open');
    });
    document.getElementById(id)?.classList.toggle('open');
}
function closeAllMsDD() {
    document.querySelectorAll('.ms-tb-dd-wrap.open, .ms-dd-wrap.open').forEach(el => el.classList.remove('open'));
}
document.addEventListener('click', e => {
    if (!e.target.closest('.ms-tb-dd-wrap') && !e.target.closest('.ms-dd-wrap')) closeAllMsDD();
});
function msOpenReply(type, nid, senderEmail, senderName) {
    const form = document.getElementById('msReplyForm');
    if (!form) return;
    const typeLabel = type === 'reply' ? 'Reply' : type === 'reply_all' ? 'Reply All' : 'Forward';
    const typeEl = document.getElementById('msReplyType');
    if (typeEl) typeEl.textContent = typeLabel;

    const toEl = document.getElementById('msReplyTo');
    if (toEl) {
        if (type === 'forward') {
            toEl.value = '';
        } else {
            // Use actual sender email from the notification row
            toEl.value = senderEmail || '';
        }
    }

    const subjectEl = document.getElementById('msReplySubject');
    if (subjectEl) {
        const prefix = type === 'forward' ? 'Fwd' : 'Re';
        subjectEl.value = prefix + ': [Request ID :##ID-' + String(nid).padStart(4,'0') + '##] : ' + (msActiveItem?.title || '');
    }

    const bodyEl = document.getElementById('msReplyBody');
    if (bodyEl) {
        const from = senderName ? senderName + (senderEmail ? ' <' + senderEmail + '>' : '') : (senderEmail || '');
        bodyEl.innerHTML = '<br><br>'
            + '<p style="color:#9ca3af;font-size:11px;margin:0">--- Original Message ---</p>'
            + (from ? '<p style="color:#9ca3af;font-size:11px;margin:0">From: ' + from + '</p>' : '')
            + '<p style="color:#9ca3af;font-size:11px;margin:0">' + (msActiveItem?.preview || '') + '</p>';
    }

    form.style.display = 'block';
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function msCloseReply() {
    const form = document.getElementById('msReplyForm');
    if (form) form.style.display = 'none';
}

function msSendReply() {
    const to      = document.getElementById('msReplyTo')?.value?.trim();
    const subject = document.getElementById('msReplySubject')?.value?.trim();
    const body    = document.getElementById('msReplyBody')?.innerHTML?.trim();

    if (!to) { showToast('Please enter a recipient email.', 'error'); return; }
    if (!subject) { showToast('Please enter a subject.', 'error'); return; }

    const btn = document.querySelector('#msReplyForm button[onclick="msSendReply()"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

    const fd = new FormData();
    fd.append('action',     'send_reply');
    fd.append('to',         to);
    fd.append('subject',    subject);
    fd.append('body',       body);
    fd.append('nid',        msActiveItem?.nid || '');

    fetch('<?= BASE_URL ?>/modules/notifications/send-reply.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Reply sent successfully!', 'success');
                msCloseReply();
            } else {
                showToast('Failed to send: ' + (data.error || 'Unknown error'), 'error');
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'error'))
        .finally(() => {
            if (btn) { btn.disabled = false; btn.textContent = 'Send'; }
        });
}

function toggleMySummary() {
    const panel   = document.getElementById('msPanel');
    const overlay = document.getElementById('msOverlay');
    if (panel.classList.contains('open')) {
        closeMySummary();
    } else {
        panel.classList.add('open');
        overlay.classList.add('open');
        msBuildList(msCurrentFilter);
    }
}
function closeMySummary() {
    document.getElementById('msPanel').classList.remove('open');
    document.getElementById('msOverlay').classList.remove('open');
    msCollapseToNormal();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMySummary(); });

/* ══ Pin ticket to top of list ══ */
function msPinTicket() {
    if (!msActiveItem) return;
    const nid = msActiveItem.nid;
    const btn = document.getElementById('msPinBtn');
    const key = 'pinned_' + nid;
    const isPinned = sessionStorage.getItem(key);
    if (isPinned) {
        sessionStorage.removeItem(key);
        btn.style.background = '';
        btn.style.color = '';
        btn.style.borderColor = '';
        showToast('Unpinned', 'success');
    } else {
        sessionStorage.setItem(key, '1');
        btn.style.background = '#e3f2fd';
        btn.style.color = '#1976d2';
        btn.style.borderColor = '#1976d2';
        showToast('Pinned to top', 'success');
    }
    const list = document.getElementById('msList');
    const items = [...list.querySelectorAll('.ms-item')];
    items.sort((a, b) => {
        const aPinned = sessionStorage.getItem('pinned_' + a.dataset.nid) ? 1 : 0;
        const bPinned = sessionStorage.getItem('pinned_' + b.dataset.nid) ? 1 : 0;
        return bPinned - aPinned;
    });
    items.forEach(el => list.appendChild(el));
    const listItem = list.querySelector(`.ms-item[data-nid="${nid}"]`);
    if (listItem) {
        if (!isPinned) {
            if (!listItem.querySelector('.ms-pin-badge')) {
                const badge = document.createElement('span');
                badge.className = 'ms-pin-badge';
                badge.style.cssText = 'position:absolute;top:10px;left:0;width:3px;height:calc(100% - 20px);background:#1976d2;border-radius:0 2px 2px 0';
                listItem.appendChild(badge);
            }
        } else {
            listItem.querySelector('.ms-pin-badge')?.remove();
        }
    }
}

function msUpdatePinBtn(nid) {
    const btn = document.getElementById('msPinBtn');
    if (!btn) return;
    const isPinned = sessionStorage.getItem('pinned_' + nid);
    if (isPinned) {
        btn.style.background = '#e3f2fd';
        btn.style.color = '#1976d2';
        btn.style.borderColor = '#1976d2';
    } else {
        btn.style.background = '';
        btn.style.color = '';
        btn.style.borderColor = '';
    }
}

let msSearchHighlights = [];
let msSearchIndex = 0;

function msSearchInMessage() {
    const existing = document.getElementById('msSearchBar');
    if (existing) { existing.remove(); msSearchHighlights = []; return; }
    const convBody = document.getElementById('msConvBody');
    if (!convBody) return;
    const bar = document.createElement('div');
    bar.id = 'msSearchBar';
    bar.style.cssText = 'position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid #e5e7eb;padding:7px 12px;display:flex;align-items:center;gap:6px;flex-shrink:0';
    bar.innerHTML = `
        <i class="fas fa-search" style="font-size:11px;color:#9ca3af"></i>
        <input id="msSearchInput" type="text" placeholder="Search in message..."
            style="flex:1;border:none;outline:none;font-size:12.5px;color:#374151;font-family:inherit"
            oninput="msDoSearch(this.value)">
        <span id="msSearchCount" style="font-size:11px;color:#9ca3af;white-space:nowrap"></span>
        <button onclick="msSearchNav(-1)" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:11px;padding:2px 4px"><i class="fas fa-chevron-up"></i></button>
        <button onclick="msSearchNav(1)" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:11px;padding:2px 4px"><i class="fas fa-chevron-down"></i></button>
        <button onclick="document.getElementById('msSearchBar').remove();msSearchHighlights=[]"
            style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:13px;line-height:1">×</button>`;
    convBody.insertBefore(bar, convBody.firstChild);
    document.getElementById('msSearchInput').focus();
}

function msDoSearch(q) {
    document.querySelectorAll('#msConvMessages mark.ms-highlight').forEach(m => {
        m.replaceWith(document.createTextNode(m.textContent));
    });
    msSearchHighlights = [];
    msSearchIndex = 0;
    if (!q.trim()) { document.getElementById('msSearchCount').textContent = ''; return; }
    const container = document.getElementById('msConvMessages');
    if (!container) return;
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    const regex = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
    nodes.forEach(node => {
        const text = node.textContent;
        if (!regex.test(text)) return;
        regex.lastIndex = 0;
        const frag = document.createDocumentFragment();
        let last = 0, m;
        while ((m = regex.exec(text)) !== null) {
            frag.appendChild(document.createTextNode(text.slice(last, m.index)));
            const mark = document.createElement('mark');
            mark.className = 'ms-highlight';
            mark.style.cssText = 'background:#fff176;color:#374151;border-radius:2px;padding:0 1px';
            mark.textContent = m[0];
            frag.appendChild(mark);
            msSearchHighlights.push(mark);
            last = m.index + m[0].length;
        }
        frag.appendChild(document.createTextNode(text.slice(last)));
        node.replaceWith(frag);
    });
    const countEl = document.getElementById('msSearchCount');
    if (msSearchHighlights.length > 0) {
        msSearchNav(0);
        countEl.textContent = `1 / ${msSearchHighlights.length}`;
    } else {
        countEl.textContent = 'No results';
    }
}

function msSearchNav(dir) {
    if (!msSearchHighlights.length) return;
    msSearchHighlights.forEach(m => { m.style.background = '#fff176'; });
    if (dir !== 0) msSearchIndex = (msSearchIndex + dir + msSearchHighlights.length) % msSearchHighlights.length;
    const current = msSearchHighlights[msSearchIndex];
    current.style.background = '#ff9800';
    current.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const countEl = document.getElementById('msSearchCount');
    if (countEl) countEl.textContent = `${msSearchIndex + 1} / ${msSearchHighlights.length}`;
}

</script>

<?php include '../../includes/footer.php'; ?>