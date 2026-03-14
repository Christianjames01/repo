<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireAnyRole(['Admin','Super Admin','Super Administrator','Barangay Captain','Barangay Tanod','Staff','Secretary','Treasurer','Tanod','Resident']);

$current_user_id = getCurrentUserId();
$current_role    = getCurrentUserRole();
$staff_roles = ['Admin','Super Admin','Super Administrator','Barangay Captain','Barangay Tanod','Staff','Secretary','Treasurer','Tanod'];
$is_resident = !in_array($current_role, $staff_roles);
$page_title = 'View Complaints';

$resident_id = null;
if ($is_resident) {
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $current_user_id); $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $resident_id = $row['resident_id'];
    $stmt->close();
    if ($resident_id) {
        $stmt = $conn->prepare("SELECT is_verified, id_photo FROM tbl_residents WHERE resident_id = ?");
        $stmt->bind_param("i", $resident_id); $stmt->execute();
        $resident_info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$resident_info || $resident_info['is_verified'] != 1 || empty($resident_info['id_photo'])) {
            header('Location: not-verified-complaints.php'); exit();
        }
    }
}

$success = isset($_GET['success']) ? sanitizeInput($_GET['success']) : '';
$error   = isset($_GET['error'])   ? sanitizeInput($_GET['error'])   : '';
$filter_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

$date_columns = ['created_at','date_filed','date_created'];
$date_column  = null;
foreach ($date_columns as $col) {
    if (columnExists($conn,'tbl_complaints',$col)) { $date_column=$col; break; }
}

$sql = "SELECT c.complaint_id, c.complaint_number, c.subject, c.description, c.category, c.priority, c.status, c.resident_id, c.assigned_to";
if ($date_column) $sql .= ", c.$date_column as complaint_date";
$sql .= ", CONCAT(COALESCE(r.first_name,''),' ',COALESCE(r.last_name,'')) as complainant_name, u.username as assigned_to_name
          FROM tbl_complaints c
          LEFT JOIN tbl_residents r ON c.resident_id=r.resident_id
          LEFT JOIN tbl_users u ON c.assigned_to=u.user_id
          WHERE c.complaint_id > 0";
$params=[]; $types='';
if ($is_resident && $resident_id) {
    // Match by resident_id OR by the user's own user_id in case resident_id link is broken
    $sql .= " AND c.resident_id=?";
    $params[] = $resident_id;
    $types .= 'i';
}
if ($filter_status) { $sql.=" AND TRIM(c.status)=?"; $params[]=$filter_status; $types.='s'; }
$sql .= $date_column ? " ORDER BY c.$date_column DESC" : " ORDER BY c.complaint_id DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute(); $complaints=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stats=['total'=>0,'pending'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0];
$stats_sql="SELECT TRIM(status) as status, COUNT(*) as count FROM tbl_complaints";
if ($is_resident && $resident_id) $stats_sql.=" WHERE resident_id=".(int)$resident_id;
$stats_sql.=" GROUP BY TRIM(status)";
$stats_result=$conn->query($stats_sql);
if ($stats_result) {
    while ($row=$stats_result->fetch_assoc()) {
        $s=trim($row['status']); $stats['total']+=$row['count'];
        if($s==='Pending')     $stats['pending']=$row['count'];
        if($s==='In Progress') $stats['in_progress']=$row['count'];
        if($s==='Resolved')    $stats['resolved']=$row['count'];
        if($s==='Closed')      $stats['closed']=$row['count'];
    }
}

function getComplaintStatusBadge($status) {
    $s=trim($status);
    $map=['Pending'=>['amber','clock'],'In Progress'=>['sky','spinner'],'Resolved'=>['success','check-circle'],'Closed'=>['muted','times-circle']];
    [$color,$icon]=$map[$s]??['muted','circle'];
    return "<span class='db-badge db-badge--$color'><i class='fas fa-$icon'></i> ".htmlspecialchars($s)."</span>";
}
function getComplaintPriorityBadge($priority) {
    $p=trim($priority);
    $map=['Low'=>['success','circle'],'Medium'=>['amber','exclamation-circle'],'High'=>['rose','exclamation-triangle'],'Urgent'=>['rose','fire']];
    [$color,$icon]=$map[$p]??['muted','circle'];
    return "<span class='db-badge db-badge--$color'><i class='fas fa-$icon'></i> ".htmlspecialchars($p)."</span>";
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-info:#3b82f6;--db-info-light:#dbeafe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{
    background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);
    padding:28px 28px;
    margin-bottom:24px;
    border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);
    position:relative;overflow:hidden;
    /* key fix: don't let the hero itself clip */
    min-width:0;
}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{
    position:relative;z-index:1;
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;
    min-width:0; /* prevent inner flex children from overflowing */
}
.rm-hero__left{
    display:flex;align-items:center;gap:14px;
    min-width:0;flex:1 1 0; /* allow shrinking */
}
.rm-hero__left > div { min-width:0; }
.rm-hero__icon{
    width:48px;height:48px;border-radius:12px;
    background:linear-gradient(135deg,var(--db-indigo),#4338ca);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;color:#fff;
    box-shadow:0 4px 16px rgba(99,102,241,.4);
    flex-shrink:0; /* never shrink the icon */
}
.rm-hero__title{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rm-hero__sub{font-size:12px;color:rgba(255,255,255,.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rm-hero__actions{
    flex-shrink:0; /* NEVER shrink the button — this was the bug */
    display:flex;align-items:center;gap:8px;
}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* ── Stats row ── */
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{
    flex:1 1 140px;min-width:0;
    background:var(--db-surf);border-radius:var(--db-radius);
    padding:16px 14px 12px;
    display:flex;flex-direction:column;gap:10px;
    box-shadow:var(--db-shadow);border:1px solid var(--db-border);
    transition:transform .2s,box-shadow .2s,border-color .2s;
    text-decoration:none;color:inherit;
}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);transform:translateY(-3px);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--muted{background:linear-gradient(90deg,#94a3b8,transparent);}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}

/* ── Table ── */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr.clickable:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-indigo);cursor:pointer;}
.db-table tbody tr.resident-row:hover{background:#f8fafc;}
.db-table tbody td{padding:12px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}

/* ── Empty ── */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);margin:0;}

/* ── Hover preview card ── */
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

@media(max-width:900px){
    .rm-hero{padding:18px 16px;}
    .rm-hero__title{font-size:16px;}
    .rm-hero__sub{display:none;}
}
@media(max-width:600px){
    .rm-hero__inner{flex-direction:column;align-items:flex-start;}
    .rm-hero__actions{width:100%;}
    .db-btn--primary{width:100%;justify-content:center;}
    .db-preview{display:none!important;}
}
/* ══════════════════════════════════════
   DARK MODE OVERRIDES
══════════════════════════════════════ */
body.dark-mode .db-panel {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-panel__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-panel__title h2 {
    color: #f1f5f9 !important;
}
body.dark-mode .db-stat-card {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-stat-card:hover,
body.dark-mode .db-stat-card.active {
    box-shadow: 0 0 0 3px rgba(148,163,184,.15), var(--db-shadow-lg) !important;
}
body.dark-mode .db-stat-card__icon--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-stat-card__icon--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-stat-card__icon--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-stat-card__icon--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-stat-card__icon--muted {
    background: #334155 !important;
    color: #94a3b8 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-stat-card__label {
    color: #64748b !important;
}
body.dark-mode .db-table thead tr {
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
}
body.dark-mode .db-table thead th {
    color: rgba(148,163,184,.9) !important;
}
body.dark-mode .db-table tbody tr {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-table tbody tr.clickable:hover {
    background: #1e293b !important;
    box-shadow: inset 3px 0 0 #a5b4fc !important;
}
body.dark-mode .db-table tbody tr.resident-row:hover {
    background: #243044 !important;
}
body.dark-mode .db-table tbody td {
    color: #e2e8f0 !important;
}
body.dark-mode .db-text-sm {
    color: #94a3b8 !important;
}
body.dark-mode .db-badge--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-badge--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-badge--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-badge--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-badge--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-badge--muted {
    background: #1e293b !important;
    color: #94a3b8 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-alert--success {
    background: #052e16 !important;
    color: #86efac !important;
    border-color: #4ade80 !important;
}
body.dark-mode .db-alert--error {
    background: #2d1c1c !important;
    color: #fca5a5 !important;
    border-color: #ef4444 !important;
}
body.dark-mode .db-btn--ghost {
    background: #1e293b !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-btn--ghost:hover {
    background: #334155 !important;
}
body.dark-mode .db-empty i {
    color: #334155 !important;
}
body.dark-mode .db-empty p {
    color: #64748b !important;
}
body.dark-mode .db-preview {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-preview__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-preview__title {
    color: #f1f5f9 !important;
}
body.dark-mode .db-preview__type,
body.dark-mode .db-preview__msg,
body.dark-mode .db-preview__footer {
    color: #94a3b8 !important;
}
body.dark-mode .db-panel__icon--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
</style>

<!-- ── Hero ── -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-comments"></i></div>
            <div>
                <div class="rm-hero__title"><?php echo $is_resident ? 'My Complaints' : 'Complaints Management'; ?></div>
                <div class="rm-hero__sub"><?php echo $is_resident ? 'View all your submitted complaints' : 'Manage all barangay complaints'; ?></div>
            </div>
        </div>
        <?php if ($is_resident): ?>
        <div class="rm-hero__actions">
            <a href="file-complaint.php" class="db-btn db-btn--primary">
                <i class="fas fa-plus"></i> File Complaint
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($success==='filed'): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> Complaint filed successfully! <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($error==='not_found'): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> Complaint not found. <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- ── Stats ── -->
<div class="db-stats-row">
    <a href="view-complaints.php" class="db-stat-card <?php echo empty($filter_status)?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-comments"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total']; ?></div><div class="db-stat-card__label">Total</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </a>
    <a href="?status=Pending" class="db-stat-card <?php echo $filter_status==='Pending'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['pending']; ?></div><div class="db-stat-card__label">Pending</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="?status=In+Progress" class="db-stat-card <?php echo $filter_status==='In Progress'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-spinner"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['in_progress']; ?></div><div class="db-stat-card__label">In Progress</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
    <a href="?status=Resolved" class="db-stat-card <?php echo $filter_status==='Resolved'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['resolved']; ?></div><div class="db-stat-card__label">Resolved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </a>
    <a href="?status=Closed" class="db-stat-card <?php echo $filter_status==='Closed'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--muted"><i class="fas fa-times-circle"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['closed']; ?></div><div class="db-stat-card__label">Closed</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--muted"></div>
    </a>
</div>

<!-- ── Table Panel ── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></div>
            <h2>
                <?php
                if ($filter_status) echo htmlspecialchars($filter_status).' Complaints';
                else echo $is_resident ? 'My Complaints' : 'All Complaints';
                ?>
            </h2>
            <span class="db-badge db-badge--indigo"><?php echo count($complaints); ?></span>
        </div>
        <?php if ($filter_status): ?>
        <a href="view-complaints.php" class="db-btn db-btn--ghost db-btn--sm">
            <i class="fas fa-times"></i> Clear Filter
        </a>
        <?php endif; ?>
    </div>

    <?php if (empty($complaints)): ?>
    <div class="db-empty">
        <i class="fas fa-comments"></i>
        <p>No complaints found</p>
        <?php if ($filter_status): ?>
        <a href="view-complaints.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a>
        <?php elseif ($is_resident): ?>
        <a href="file-complaint.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> File Your First Complaint</a>
        <?php else: ?>
        <p style="font-size:12px;color:var(--db-muted)">No complaints have been filed yet.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Complaint #</th>
                    <th>Date Filed</th>
                    <th>Category</th>
                    <?php if (!$is_resident): ?><th>Complainant</th><th>Assigned To</th><?php endif; ?>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <?php if ($is_resident): ?><th style="text-align:center">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($complaints as $complaint):
                if (!isset($complaint['complaint_id']) || $complaint['complaint_id'] === null) continue;
                $view_url  = 'complaint-details.php?id='.$complaint['complaint_id'];
                $priority  = trim($complaint['priority']??'Medium');
                $date_val  = $complaint['complaint_date']??null;
                $icon_class= 'fa-comment';
                switch ($complaint['category']) {
                    case 'Noise':        $icon_class='fa-volume-up';     break;
                    case 'Garbage':      $icon_class='fa-trash';         break;
                    case 'Property':     $icon_class='fa-home';          break;
                    case 'Infrastructure':$icon_class='fa-road';         break;
                    case 'Public Safety':$icon_class='fa-shield-alt';    break;
                    case 'Services':     $icon_class='fa-concierge-bell';break;
                }
                $prev_color='indigo';
                if ($priority==='Urgent')     $prev_color='rose';
                elseif ($priority==='High')   $prev_color='amber';
                elseif ($priority==='Medium') $prev_color='sky';
                elseif ($priority==='Low')    $prev_color='success';
                $prev_title   = htmlspecialchars(($complaint['complaint_number']??'').' – '.($complaint['subject']??''));
                $prev_message = htmlspecialchars(mb_strimwidth($complaint['description']??'',0,150,'…'));
                $prev_type    = htmlspecialchars($complaint['category']??'');
                $prev_time    = $date_val ? date('M j, Y', strtotime($date_val)) : 'N/A';
                $row_class    = $is_resident ? 'resident-row' : 'clickable';
            ?>
            <tr class="<?php echo $row_class; ?>"
                <?php if (!$is_resident): ?>
                data-url="<?php echo htmlspecialchars($view_url); ?>"
                data-pt="<?php echo $prev_title; ?>" data-pm="<?php echo $prev_message; ?>"
                data-ptype="<?php echo $prev_type; ?>" data-pc="<?php echo $prev_color; ?>"
                data-picon="<?php echo $icon_class; ?>" data-ptime="<?php echo $prev_time; ?>"
                <?php endif; ?>>
                <td><span class="db-id"><?php echo htmlspecialchars($complaint['complaint_number']??'N/A'); ?></span></td>
                <td>
                    <span class="db-text-sm"><?php echo $date_val ? date('M d, Y',strtotime($date_val)) : 'N/A'; ?></span>
                    <?php if ($date_val): ?><br><span class="db-text-sm" style="color:#94a3b8"><?php echo date('h:i A',strtotime($date_val)); ?></span><?php endif; ?>
                </td>
                <td>
                    <span class="db-badge db-badge--sky">
                        <i class="fas <?php echo $icon_class; ?>"></i>
                        <?php echo htmlspecialchars($complaint['category']??'N/A'); ?>
                    </span>
                </td>
                <?php if (!$is_resident): ?>
                <td><?php echo htmlspecialchars($complaint['complainant_name']??'Unknown'); ?></td>
                <td>
                    <?php if (!empty($complaint['assigned_to_name'])): ?>
                    <span class="db-text-sm"><i class="fas fa-user-tie" style="color:var(--db-sky)"></i> <?php echo htmlspecialchars($complaint['assigned_to_name']); ?></span>
                    <?php else: ?>
                    <span class="db-text-sm" style="font-style:italic;color:var(--db-muted)">Not assigned</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td>
                    <strong style="font-size:13px;"><?php echo htmlspecialchars($complaint['subject']??'N/A'); ?></strong>
                    <?php if (!empty($complaint['description'])): ?>
                    <br><span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($complaint['description'],0,55,'…')); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo getComplaintPriorityBadge($complaint['priority']??'Medium'); ?></td>
                <td><?php echo getComplaintStatusBadge($complaint['status']??'Pending'); ?></td>
                <?php if ($is_resident): ?>
                <td style="text-align:center">
                    <a href="<?php echo htmlspecialchars($view_url); ?>" class="db-btn db-btn--sm db-btn--primary">
                        <i class="fas fa-eye"></i> View
                    </a>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /padding wrapper -->

<?php if (!$is_resident): ?>
<!-- Hover preview for staff -->
<div id="dbPreview" class="db-preview" style="display:none;">
    <div class="db-preview__header">
        <div class="db-preview__icon" id="dbPrevIcon"><i class="fas fa-comment" id="dbPrevIconI"></i></div>
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
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alerts
    setTimeout(() => {
        document.querySelectorAll('.db-alert').forEach(a => {
            a.style.transition='opacity .4s'; a.style.opacity='0';
            setTimeout(()=>a.remove(),400);
        });
    }, 5000);

    <?php if (!$is_resident): ?>
    const card    = document.getElementById('dbPreview');
    const iconBox = document.getElementById('dbPrevIcon');
    const iconEl  = document.getElementById('dbPrevIconI');
    const cmap = {
        rose:   {bg:'rgba(225,29,72,.1)',  text:'#e11d48'},
        amber:  {bg:'rgba(245,158,11,.1)', text:'#b45309'},
        sky:    {bg:'rgba(14,165,233,.1)', text:'#0ea5e9'},
        indigo: {bg:'rgba(99,102,241,.1)', text:'#6366f1'},
        success:{bg:'rgba(16,185,129,.1)', text:'#10b981'},
    };
    let timer;
    function pos(e){
        const cw=card.offsetWidth||320, ch=card.offsetHeight||160, m=14;
        let x=e.clientX+m, y=e.clientY+m;
        if(x+cw>window.innerWidth-m)  x=e.clientX-cw-m;
        if(y+ch>window.innerHeight-m) y=e.clientY-ch-m;
        card.style.left=x+'px'; card.style.top=y+'px';
    }
    document.querySelectorAll('.clickable').forEach(row => {
        row.addEventListener('mouseenter', function(e){
            clearTimeout(timer);
            const c = cmap[this.dataset.pc] || cmap.indigo;
            document.getElementById('dbPrevTitle').textContent = this.dataset.pt;
            document.getElementById('dbPrevMsg').textContent   = this.dataset.pm;
            document.getElementById('dbPrevType').textContent  = this.dataset.ptype;
            document.getElementById('dbPrevTime').textContent  = this.dataset.ptime;
            iconEl.className  = 'fas ' + this.dataset.picon;
            iconBox.style.background = c.bg;
            iconEl.style.color       = c.text;
            pos(e); card.style.display='block';
        });
        row.addEventListener('mousemove', pos);
        row.addEventListener('mouseleave', () => {
            timer = setTimeout(()=>{ if(!card.matches(':hover')) card.style.display='none'; }, 150);
        });
        row.addEventListener('click', function(){ if(this.dataset.url) location.href=this.dataset.url; });
    });
    <?php endif; ?>
});
</script>

<?php include '../../includes/footer.php'; ?>