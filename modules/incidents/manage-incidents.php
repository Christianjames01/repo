<?php
/**
 * Manage Incidents - Admin Dashboard
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Super Admin', 'Admin', 'Staff', 'Barangay Captain', 'Barangay Tanod']);

$page_title = 'Manage Incidents';

$success = isset($_GET['success']) ? sanitizeInput($_GET['success']) : '';
$error   = isset($_GET['error'])   ? sanitizeInput($_GET['error'])   : '';

$page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$status_filter   = isset($_GET['status'])    ? sanitizeInput($_GET['status'])    : '';
$severity_filter = isset($_GET['severity'])  ? sanitizeInput($_GET['severity'])  : '';
$date_from       = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$date_to         = isset($_GET['date_to'])   ? sanitizeInput($_GET['date_to'])   : '';

$table_check      = $conn->query("SHOW TABLES LIKE 'tbl_incident_images'");
$has_images_table = ($table_check && $table_check->num_rows > 0);

$count_sql    = "SELECT COUNT(*) as total FROM tbl_incidents WHERE 1=1";
$count_params = []; $count_types = '';
if ($status_filter)   { $count_sql .= " AND status = ?";               $count_params[] = $status_filter;   $count_types .= 's'; }
if ($severity_filter) { $count_sql .= " AND severity = ?";             $count_params[] = $severity_filter; $count_types .= 's'; }
if ($date_from)       { $count_sql .= " AND DATE(date_reported) >= ?"; $count_params[] = $date_from;       $count_types .= 's'; }
if ($date_to)         { $count_sql .= " AND DATE(date_reported) <= ?"; $count_params[] = $date_to;         $count_types .= 's'; }
if (!empty($count_params)) { $stmt = $conn->prepare($count_sql); $stmt->bind_param($count_types, ...$count_params); $stmt->execute(); $total_records = $stmt->get_result()->fetch_assoc()['total']; $stmt->close(); }
else { $total_records = $conn->query($count_sql)->fetch_assoc()['total']; }
$total_pages = ceil($total_records / $per_page);

$img_join = $has_images_table ? "LEFT JOIN tbl_incident_images ii ON i.incident_id = ii.incident_id" : "";
$img_col  = $has_images_table ? "COUNT(ii.image_id) as image_count" : "0 as image_count";
$img_grp  = $has_images_table ? "GROUP BY i.incident_id" : "";

$sql = "SELECT i.*, CONCAT(r.first_name, ' ', r.last_name) as reporter_name,
        r.contact_number as reporter_contact, $img_col
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        $img_join WHERE 1=1";
$params = []; $types = '';
if ($status_filter)   { $sql .= " AND i.status = ?";               $params[] = $status_filter;   $types .= 's'; }
if ($severity_filter) { $sql .= " AND i.severity = ?";             $params[] = $severity_filter; $types .= 's'; }
if ($date_from)       { $sql .= " AND DATE(i.date_reported) >= ?"; $params[] = $date_from;       $types .= 's'; }
if ($date_to)         { $sql .= " AND DATE(i.date_reported) <= ?"; $params[] = $date_to;         $types .= 's'; }
if ($img_grp) $sql .= " $img_grp";
$sql .= " ORDER BY i.date_reported DESC LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset; $types .= 'ii';
$stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
$incidents = $stmt->get_result(); $stmt->close();

$stats = $conn->query("SELECT COUNT(*) as total,
    SUM(CASE WHEN status='Reported' THEN 1 ELSE 0 END) as reported,
    SUM(CASE WHEN status='Under Investigation' THEN 1 ELSE 0 END) as investigating,
    SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status='Resolved' THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN severity='Critical' THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN DATE(date_reported)=CURDATE() THEN 1 ELSE 0 END) as today
    FROM tbl_incidents")->fetch_assoc();

function paginationQuery($overrides = []) {
    global $status_filter, $severity_filter, $date_from, $date_to;
    $merged = array_merge(['status'=>$status_filter,'severity'=>$severity_filter,'date_from'=>$date_from,'date_to'=>$date_to], $overrides);
    return http_build_query(array_filter($merged, fn($v) => $v !== ''));
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-info:#3b82f6;--db-info-light:#dbeafe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-rose),#be123c);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;transition:opacity .3s,transform .3s;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-alert__close:hover{opacity:1;}

.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);transform:translateY(-3px);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}

.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}

.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}

.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;cursor:pointer;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-rose);}
.db-table tbody td{padding:12px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}

.db-pagination{display:flex;gap:4px;justify-content:center;padding:18px 22px;border-top:1px solid var(--db-border);}
.db-page-btn{padding:6px 12px;border-radius:var(--db-radius-sm);border:1px solid var(--db-border);background:var(--db-surf);color:var(--db-text);font-family:'Sora',sans-serif;font-size:12px;font-weight:600;text-decoration:none;transition:all .15s;}
.db-page-btn:hover{background:var(--db-navy);color:#fff;border-color:var(--db-navy);}
.db-page-btn.active{background:var(--db-navy);color:#fff;border-color:var(--db-navy);}
.db-page-btn.disabled{opacity:.4;pointer-events:none;}

.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Hover preview card */
.db-preview{position:fixed;z-index:9999;width:320px;background:var(--db-surf);border-radius:var(--db-radius);box-shadow:var(--db-shadow-lg);border:1px solid var(--db-border);overflow:hidden;pointer-events:none;animation:dbPrevIn .18s ease;}
@keyframes dbPrevIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.db-preview__header{display:flex;align-items:center;gap:12px;padding:14px 16px 10px;border-bottom:1px solid #f0f0f0;}
.db-preview__icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.db-preview__header-text{flex:1;min-width:0;}
.db-preview__type{font-family:'DM Mono',monospace;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:2px;}
.db-preview__title{font-size:.88rem;font-weight:700;color:var(--db-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.db-preview__body{padding:12px 16px 14px;}
.db-preview__msg{font-size:.8rem;color:var(--db-muted);line-height:1.6;margin-bottom:10px;}
.db-preview__footer{font-size:.72rem;color:#adb5bd;display:flex;align-items:center;gap:8px;}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.rm-hero__title{font-size:18px;}.db-table thead th,.db-table tbody td{padding:9px 10px;font-size:11.5px;}.db-preview{display:none !important;}}
/* ══════════════════════════════════════
   DARK MODE — incidents pages
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }

/* Stat cards */
body.dark-mode .db-stat-card {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-stat-card:hover { color: #e2e8f0 !important; }
body.dark-mode .db-stat-card.active { border-color: #60a5fa !important; }
body.dark-mode .db-stat-card__label { color: #94a3b8 !important; }
body.dark-mode .db-stat-card__icon--rose    { background: rgba(225,29,72,.15)   !important; }
body.dark-mode .db-stat-card__icon--amber   { background: rgba(245,158,11,.15)  !important; }
body.dark-mode .db-stat-card__icon--sky     { background: rgba(14,165,233,.15)  !important; }
body.dark-mode .db-stat-card__icon--indigo  { background: rgba(99,102,241,.15)  !important; }
body.dark-mode .db-stat-card__icon--success { background: rgba(16,185,129,.15)  !important; }
body.dark-mode .db-stat-card__icon--teal    { background: rgba(13,148,136,.15)  !important; }
body.dark-mode .db-stat-card__icon--blue    { background: rgba(59,130,246,.15)  !important; }

/* Panels */
body.dark-mode .db-panel {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-panel__header { border-bottom-color: #334155 !important; }
body.dark-mode .db-panel__title h2 { color: #f1f5f9 !important; }
body.dark-mode .db-panel__icon--rose    { background: rgba(225,29,72,.15)  !important; }
body.dark-mode .db-panel__icon--teal    { background: rgba(13,148,136,.15) !important; }
body.dark-mode .db-panel__icon--amber   { background: rgba(245,158,11,.15) !important; }
body.dark-mode .db-panel__icon--indigo  { background: rgba(99,102,241,.15) !important; }

/* Mini stats (incident-reports.php) */
body.dark-mode .db-mini-stat {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-mini-stat .lbl { color: #94a3b8 !important; }

/* Chart cards (incident-reports.php) */
body.dark-mode .db-chart-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-chart-card__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-chart-card__header h3 { color: #f1f5f9 !important; }

/* Tabs (incident-reports.php) */
body.dark-mode .db-tab-nav { border-bottom-color: #334155 !important; }
body.dark-mode .db-tab-btn { color: #94a3b8 !important; }
body.dark-mode .db-tab-btn:hover { color: #e2e8f0 !important; }
body.dark-mode .db-tab-btn.active {
    color: #f1f5f9 !important;
    border-bottom-color: #60a5fa !important;
}

/* Inputs & selects */
body.dark-mode .db-input,
body.dark-mode .db-select {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-input:focus,
body.dark-mode .db-select:focus {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15) !important;
}
body.dark-mode .db-select option { background: #334155; color: #e2e8f0; }
body.dark-mode .db-filter-label { color: #94a3b8 !important; }

/* Buttons */
body.dark-mode .db-btn--ghost {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-btn--ghost:hover {
    background: #475569 !important;
    color: #f1f5f9 !important;
}

/* Table */
body.dark-mode .db-table tbody tr { border-bottom-color: #334155 !important; }
body.dark-mode .db-table tbody tr:hover {
    background: #243044 !important;
    box-shadow: inset 3px 0 0 var(--db-rose) !important;
}
body.dark-mode .db-table tbody td { color: #e2e8f0 !important; }
body.dark-mode .db-table tbody td strong { color: #f1f5f9 !important; }
body.dark-mode .db-id     { color: #a5b4fc !important; }
body.dark-mode .db-text-sm { color: #94a3b8 !important; }

/* Badges */
body.dark-mode .db-badge--rose    { background: rgba(225,29,72,.2)  !important; color: #fda4af !important; }
body.dark-mode .db-badge--amber   { background: rgba(245,158,11,.2) !important; color: #fcd34d !important; }
body.dark-mode .db-badge--sky     { background: rgba(14,165,233,.2) !important; color: #7dd3fc !important; }
body.dark-mode .db-badge--indigo  { background: rgba(99,102,241,.2) !important; color: #a5b4fc !important; }
body.dark-mode .db-badge--success { background: rgba(16,185,129,.2) !important; color: #6ee7b7 !important; }
body.dark-mode .db-badge--teal    { background: rgba(13,148,136,.2) !important; color: #5eead4 !important; }
body.dark-mode .db-badge--muted   { background: #334155 !important; color: #94a3b8 !important; border-color: #475569 !important; }
body.dark-mode .db-badge--info    { background: rgba(59,130,246,.2) !important; color: #93c5fd !important; }

/* Hover preview card */
body.dark-mode .db-preview {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-preview__header { border-bottom-color: #334155 !important; }
body.dark-mode .db-preview__title  { color: #f1f5f9 !important; }
body.dark-mode .db-preview__type   { color: #94a3b8 !important; }
body.dark-mode .db-preview__label  { color: #64748b !important; }
body.dark-mode .db-preview__val    { color: #e2e8f0 !important; }
body.dark-mode .db-preview__footer { color: #64748b !important; border-top-color: #334155 !important; }

/* Pagination */
body.dark-mode .db-page-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-page-btn:hover,
body.dark-mode .db-page-btn.active {
    background: #3b82f6 !important;
    border-color: #3b82f6 !important;
    color: #fff !important;
}

/* Alerts */
body.dark-mode .db-alert--success {
    background: rgba(16,185,129,.15) !important;
    color: #6ee7b7 !important;
    border-color: #10b981 !important;
}
body.dark-mode .db-alert--error {
    background: rgba(239,68,68,.15) !important;
    color: #fca5a5 !important;
    border-color: #ef4444 !important;
}

/* Empty state */
body.dark-mode .db-empty i { color: #334155 !important; }
body.dark-mode .db-empty p { color: #94a3b8 !important; }
</style>


<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="rm-hero__title">Manage Incidents</div>
                <div class="rm-hero__sub">View and manage all incident reports</div>
            </div>
        </div>
        <a href="incident-reports.php" class="db-btn db-btn--ghost" style="background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);">
            <i class="fas fa-chart-bar"></i> View Reports
        </a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($success === 'updated'): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> Incident updated successfully! <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($error === 'not_found'): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> Incident not found. <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <a href="manage-incidents.php" class="db-stat-card <?php echo (!$status_filter&&!$severity_filter&&!$date_from&&!$date_to)?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total']; ?></div><div class="db-stat-card__label">Total</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </a>
    <a href="?status=Reported" class="db-stat-card <?php echo $status_filter==='Reported'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['reported']; ?></div><div class="db-stat-card__label">Pending</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="?status=Under+Investigation" class="db-stat-card <?php echo $status_filter==='Under Investigation'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-search"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['investigating']; ?></div><div class="db-stat-card__label">Investigating</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
    <a href="?status=In+Progress" class="db-stat-card <?php echo $status_filter==='In Progress'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-spinner"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $stats['in_progress']; ?></div><div class="db-stat-card__label">In Progress</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </a>
    <a href="?severity=Critical" class="db-stat-card <?php echo $severity_filter==='Critical'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-fire"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $stats['critical']; ?></div><div class="db-stat-card__label">Critical</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </a>
    <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="db-stat-card <?php echo ($date_from===date('Y-m-d')&&$date_to===date('Y-m-d'))?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-calendar-day"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['today']; ?></div><div class="db-stat-card__label">Today</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </a>
</div>

<!-- Table Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-list"></i></div>
            <h2><?php
                if ($status_filter)                                              echo htmlspecialchars($status_filter).' Incidents';
                elseif ($severity_filter)                                        echo htmlspecialchars($severity_filter).' Severity';
                elseif ($date_from===date('Y-m-d')&&$date_to===date('Y-m-d'))  echo "Today's Incidents";
                else                                                             echo 'All Incident Reports';
            ?></h2>
            <span class="db-badge db-badge--rose"><?php echo $total_records; ?></span>
        </div>
        <span class="db-text-sm">Showing <?php echo $total_records?min($offset+1,$total_records):0; ?>–<?php echo min($offset+$per_page,$total_records); ?> of <?php echo $total_records; ?></span>
    </div>

    <?php if ($incidents && $incidents->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Reference</th><th>Type</th><th>Location</th><th>Reporter</th>
                    <th>Severity</th><th>Status</th><th>Date Reported</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($incident = $incidents->fetch_assoc()):
                $view_url = 'incident-details.php?id='.$incident['incident_id'];
                $sev = $incident['severity'];
                $prev_color = 'indigo';
                if ($sev==='Critical')   $prev_color='rose';
                elseif ($sev==='High')   $prev_color='amber';
                elseif ($sev==='Medium') $prev_color='sky';
                elseif ($sev==='Low')    $prev_color='success';
                $prev_title   = htmlspecialchars($incident['reference_no'].' – '.$incident['incident_type']);
                $prev_message = htmlspecialchars(mb_strimwidth($incident['description']??'',0,150,'…'));
                $prev_type    = htmlspecialchars($incident['incident_type']);
                $prev_time    = date('M j, Y', strtotime($incident['date_reported']));
            ?>
            <tr data-url="<?php echo htmlspecialchars($view_url); ?>"
                data-pt="<?php echo $prev_title; ?>" data-pm="<?php echo $prev_message; ?>"
                data-ptype="<?php echo $prev_type; ?>" data-pc="<?php echo $prev_color; ?>"
                data-ptime="<?php echo $prev_time; ?>">
                <td>
                    <strong><?php echo htmlspecialchars($incident['reference_no']); ?></strong>
                    <?php if ($incident['image_count']>0): ?>
                    <br><span class="db-text-sm"><i class="fas fa-images"></i> <?php echo $incident['image_count']; ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="db-badge db-badge--sky"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($incident['incident_type']); ?></span></td>
                <td><span class="db-text-sm"><i class="fas fa-map-marker-alt" style="color:var(--db-rose)"></i> <?php $loc=$incident['location']; echo htmlspecialchars(strlen($loc)>28?substr($loc,0,28).'…':$loc); ?></span></td>
                <td>
                    <?php echo htmlspecialchars($incident['reporter_name']??'Unknown'); ?>
                    <?php if ($incident['reporter_contact']): ?>
                    <br><span class="db-text-sm"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($incident['reporter_contact']); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo getSeverityBadge($incident['severity']); ?></td>
                <td><?php echo getStatusBadge($incident['status']); ?></td>
                <td>
                    <span class="db-text-sm"><?php echo formatDate($incident['date_reported'],'M d, Y'); ?></span>
                    <br><span class="db-text-sm" style="color:#94a3b8"><?php echo formatDate($incident['date_reported'],'h:i A'); ?></span>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-inbox"></i>
        <p>No incidents found</p>
        <?php if ($status_filter||$severity_filter||$date_from||$date_to): ?>
        <a href="manage-incidents.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($total_pages > 1): ?>
    <div class="db-pagination">
        <?php if ($page > 1): ?>
        <a href="?<?php echo paginationQuery(['page'=>$page-1]); ?>" class="db-page-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
        <a href="?<?php echo paginationQuery(['page'=>$i]); ?>" class="db-page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?<?php echo paginationQuery(['page'=>$page+1]); ?>" class="db-page-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</div><!-- /padding -->

<!-- Hover Preview -->
<div id="dbPreview" class="db-preview" style="display:none;">
    <div class="db-preview__header">
        <div class="db-preview__icon" id="dbPrevIcon"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="db-preview__header-text">
            <div class="db-preview__type" id="dbPrevType"></div>
            <div class="db-preview__title" id="dbPrevTitle"></div>
        </div>
    </div>
    <div class="db-preview__body">
        <p class="db-preview__msg" id="dbPrevMsg"></p>
        <div class="db-preview__footer"><i class="far fa-calendar-alt"></i><span id="dbPrevTime"></span></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => { a.style.opacity='0'; setTimeout(()=>a.remove(),400); }), 5000);

    const card = document.getElementById('dbPreview');
    const iconEl = card.querySelector('#dbPrevIcon i');
    const iconBox = card.querySelector('#dbPrevIcon');
    const colorMap = {
        rose   : { bg:'rgba(225,29,72,.1)',   text:'var(--db-rose)' },
        amber  : { bg:'rgba(245,158,11,.1)',  text:'var(--db-amber-dark)' },
        sky    : { bg:'rgba(14,165,233,.1)',  text:'var(--db-sky)' },
        indigo : { bg:'rgba(99,102,241,.1)',  text:'var(--db-indigo)' },
        success: { bg:'rgba(16,185,129,.1)',  text:'var(--db-success)' },
    };
    let timer;

    function pos(e) {
        const cw=card.offsetWidth||320, ch=card.offsetHeight||170, m=14;
        let x=e.clientX+m, y=e.clientY+m;
        if(x+cw>window.innerWidth-m) x=e.clientX-cw-m;
        if(y+ch>window.innerHeight-m) y=e.clientY-ch-m;
        card.style.left=x+'px'; card.style.top=y+'px';
    }

    document.querySelectorAll('.db-table tbody tr').forEach(row => {
        row.addEventListener('mouseenter', function(e) {
            clearTimeout(timer);
            const c=colorMap[this.dataset.pc]||colorMap.indigo;
            document.getElementById('dbPrevTitle').textContent=this.dataset.pt;
            document.getElementById('dbPrevMsg').textContent=this.dataset.pm;
            document.getElementById('dbPrevType').textContent=this.dataset.ptype;
            document.getElementById('dbPrevTime').textContent=this.dataset.ptime;
            iconEl.className='fas fa-exclamation-triangle';
            iconBox.style.background=c.bg; iconEl.style.color=c.text;
            pos(e); card.style.display='block';
        });
        row.addEventListener('mousemove', pos);
        row.addEventListener('mouseleave', () => { timer=setTimeout(()=>{ if(!card.matches(':hover')) card.style.display='none'; },150); });
        row.addEventListener('click', function() { if(this.dataset.url) location.href=this.dataset.url; });
    });
});
</script>
<?php include '../../includes/footer.php'; ?>