<?php
/**
 * Distribute Relief Goods
 * Path: barangaylink/disasters/distribute-relief.php
 */
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) { header('Location: ' . APP_URL . '/modules/auth/login.php'); exit(); }
$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff', 'Secretary'])) { header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit(); }
$page_title = 'Distribute Relief Goods';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'distribute_relief') {
        $resident_id = sanitizeInput($_POST['resident_id']);
        $ec = fetchOne($conn,"SELECT evacuee_id FROM tbl_evacuees WHERE resident_id=? AND status='Active'",[$resident_id],'i');
        if (!$ec) { setMessage('Error: This resident is not registered as an active evacuee.','error'); header('Location: distribute-relief.php'); exit(); }
        $disaster_id = null;
        if (!empty($_POST['disaster_id']) && $_POST['disaster_id'] !== '' && $_POST['disaster_id'] !== '0') $disaster_id=(int)$_POST['disaster_id'];
        $relief_type=sanitizeInput($_POST['relief_type']); $distribution_date=sanitizeInput($_POST['distribution_date']); $items=sanitizeInput($_POST['items']); $quantity=sanitizeInput($_POST['quantity']); $notes=sanitizeInput($_POST['notes']??''); $distributed_by=$_SESSION['user_id'];
        $sql="INSERT INTO tbl_relief_distributions (resident_id,disaster_id,relief_type,distribution_date,items_distributed,quantity,distributed_by,notes,status) VALUES (?,?,?,?,?,?,?,?,'Distributed')";
        if(executeQuery($conn,$sql,[$resident_id,$disaster_id,$relief_type,$distribution_date,$items,$quantity,$distributed_by,$notes],'iissssss')){logActivity($conn,getCurrentUserId(),"Distributed relief goods to resident ID: $resident_id");setMessage('Relief goods distributed successfully!','success');header('Location: distribute-relief.php');exit();}else{setMessage('Failed to distribute relief goods.','error');}
    } elseif ($action === 'delete_distribution') {
        $distribution_id=(int)$_POST['distribution_id'];
        if(executeQuery($conn,"DELETE FROM tbl_relief_distributions WHERE distribution_id=?",[$distribution_id],'i')){logActivity($conn,getCurrentUserId(),"Deleted relief distribution ID: $distribution_id");setMessage('Distribution record deleted successfully!','success');}else{setMessage('Failed to delete distribution record.','error');}
        header('Location: distribute-relief.php'); exit();
    }
}

$sql="SELECT rd.*,CONCAT(r.first_name,' ',r.last_name) as resident_name,r.address,u.username as distributed_by_name,ec.center_name,di.disaster_name FROM tbl_relief_distributions rd LEFT JOIN tbl_residents r ON rd.resident_id=r.resident_id LEFT JOIN tbl_users u ON rd.distributed_by=u.user_id LEFT JOIN tbl_evacuees e ON rd.resident_id=e.resident_id AND e.status='Active' LEFT JOIN tbl_evacuation_centers ec ON e.center_id=ec.center_id LEFT JOIN tbl_disaster_incidents di ON rd.disaster_id=di.incident_id ORDER BY rd.distribution_date DESC,rd.created_at DESC";
$distributions=fetchAll($conn,$sql);
$residents=fetchAll($conn,"SELECT DISTINCT r.resident_id,CONCAT(r.first_name,' ',r.last_name) as full_name,r.address,ec.center_name,e.family_members FROM tbl_residents r INNER JOIN tbl_evacuees e ON r.resident_id=e.resident_id INNER JOIN tbl_evacuation_centers ec ON e.center_id=ec.center_id WHERE e.status='Active' ORDER BY r.last_name,r.first_name");
$disasters=fetchAll($conn,"SELECT incident_id,disaster_name,disaster_type,incident_date FROM tbl_disaster_incidents WHERE status IN ('Active','Ongoing') ORDER BY incident_date DESC");

$total_distributions=count($distributions);
$today_count=count(array_filter($distributions,fn($d)=>date('Y-m-d',strtotime($d['distribution_date']))===date('Y-m-d')));
$total_quantity=array_sum(array_map(fn($d)=>is_numeric($d['quantity'])?(int)$d['quantity']:0,$distributions));
$unique_recipients=count(array_unique(array_column($distributions,'resident_id')));

include __DIR__ . '/../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#065f46 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(16,185,129,.12);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#065f46,var(--db-success));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(16,185,129,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}.db-alert--error{background:var(--db-rose-light);color:#7f1d1d;border-color:var(--db-rose);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__body{padding:20px 22px;}.db-panel__title{display:flex;align-items:center;gap:10px;}.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-table-wrap{overflow-x:auto;}.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}.db-table tbody tr:last-child{border-bottom:none;}.db-table tbody tr:hover{background:#f5f8ff;}.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}.db-empty i{font-size:44px;color:var(--db-border);}.db-empty p{font-size:14px;color:var(--db-muted);}
.db-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:all .15s;}
.db-icon-btn--default{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-icon-btn--default:hover{background:var(--db-border);color:var(--db-text);}.db-icon-btn--sky{background:var(--db-sky-light);color:#0369a1;}.db-icon-btn--sky:hover{background:#bae6fd;}.db-icon-btn--success{background:var(--db-success-light);color:#065f46;}.db-icon-btn--success:hover{background:#a7f3d0;}.db-icon-btn--rose{background:var(--db-rose-light);color:#9f1239;}.db-icon-btn--rose:hover{background:#fecaca;}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-input,.db-select,.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;min-height:70px;}.db-form-group{margin-bottom:14px;}.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}.db-form-grid .full{grid-column:1/-1;}
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:620px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}.db-modal__box--sm{max-width:440px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}.db-modal__header--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));}.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}.db-modal__footer{display:flex;gap:10px;margin-top:18px;}.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}.db-confirm-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;}.db-confirm-row:last-child{border-bottom:none;}.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;}.db-confirm-row .val{font-weight:600;color:var(--db-text);}
.db-notice--rose{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-rose-light);color:#9f1239;margin-bottom:14px;}
.db-notice--sky{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-sky-light);color:#0369a1;margin-bottom:14px;}
/* Print receipt area */
.print-area{padding:24px;}.print-area-header{text-align:center;border-bottom:2px solid var(--db-border);padding-bottom:16px;margin-bottom:16px;}
.print-sig-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:48px;}
.print-sig-line{border-top:1px solid var(--db-text);margin-top:40px;}
@media print{body *{visibility:hidden;}#printContent,#printContent *,#printAllContent,#printAllContent *{visibility:visible;}#printContent,#printAllContent{position:absolute;left:0;top:0;width:100%;}.db-modal-header,.db-modal__footer,.db-btn{display:none!important;}}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}
body.dark-mode .db-table tbody tr:hover{background:#162032!important;}
body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-form-label{color:#94a3b8!important;}
body.dark-mode .db-confirm-grid{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}
body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}
body.dark-mode .db-confirm-row .val{color:#e2e8f0!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .db-empty i{color:#334155!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}.db-form-grid{grid-template-columns:1fr;}.db-form-grid .full{grid-column:1/1;}}
</style>

<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-hands-helping"></i></div>
            <div><div class="fm-hero__title">Distribute Relief Goods</div><div class="fm-hero__sub">Manage and track relief goods distribution to registered evacuees</div></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="db-btn db-btn--ghost-white" onclick="printAllDistributions()"><i class="fas fa-print"></i> Print All</button>
            <button class="db-btn db-btn--success" onclick="openModal('distributeModal')"><i class="fas fa-plus"></i> Distribute Relief</button>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">
<?php echo displayMessage(); ?>

<div class="db-stats-row">
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-box"></i></div><div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $total_distributions; ?></div><div class="db-stat-card__label">Total Distributions</div></div><div class="db-stat-card__bar db-stat-card__bar--success"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-calendar-day"></i></div><div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $today_count; ?></div><div class="db-stat-card__label">Today</div></div><div class="db-stat-card__bar db-stat-card__bar--sky"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-boxes"></i></div><div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $total_quantity; ?></div><div class="db-stat-card__label">Total Quantity</div></div><div class="db-stat-card__bar db-stat-card__bar--amber"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div><div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $unique_recipients; ?></div><div class="db-stat-card__label">Unique Recipients</div></div><div class="db-stat-card__bar db-stat-card__bar--indigo"></div></div>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div><h2>Filters &amp; Export</h2></div></div>
    <div class="db-panel__body">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div><label class="db-filter-label">Date From</label><input type="date" id="filterDateFrom" class="db-input"></div>
            <div><label class="db-filter-label">Date To</label><input type="date" id="filterDateTo" class="db-input"></div>
            <div><label class="db-filter-label">Relief Type</label><select id="filterReliefType" class="db-select"><option value="">All Types</option><?php foreach(['Food Pack','Emergency Kit','Medicine','Clothing','Hygiene Kit','Water','Cash Assistance','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div><label class="db-filter-label">Evacuee</label><select id="filterEvacuee" class="db-select"><option value="">All Evacuees</option><?php foreach($residents as $r): ?><option value="<?php echo htmlspecialchars($r['full_name']); ?>"><?php echo htmlspecialchars($r['full_name']); ?></option><?php endforeach; ?></select></div>
            <div style="padding-top:18px;display:flex;gap:8px;">
                <button class="db-btn db-btn--primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
                <button class="db-btn db-btn--ghost" onclick="clearFilters()"><i class="fas fa-redo"></i></button>
                <button class="db-btn db-btn--success db-btn--sm" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            </div>
        </div>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--db-border);display:flex;align-items:center;justify-content:space-between;">
            <span class="db-text-sm"><i class="fas fa-info-circle"></i> <span id="recordCount"><?php echo count($distributions); ?> Records</span></span>
        </div>
    </div>
</div>

<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--success"><i class="fas fa-list"></i></div><h2>Relief Distribution Records</h2><span class="db-badge db-badge--success" id="tableCount"><?php echo count($distributions); ?></span></div></div>
    <div class="db-table-wrap"><table class="db-table" id="distributionsTable">
        <thead><tr><th>Date</th><th>Recipient</th><th>Center</th><th>Relief Type</th><th>Items</th><th>Qty</th><th>Distributed By</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($distributions)): ?><tr><td colspan="8"><div class="db-empty"><i class="fas fa-box"></i><p>No distribution records found</p></div></td></tr>
        <?php else: foreach($distributions as $d): ?>
        <tr>
            <td><span class="db-text-sm"><?php echo formatDate($d['distribution_date']); ?></span></td>
            <td><strong><?php echo htmlspecialchars($d['resident_name']); ?></strong><br><span class="db-text-sm"><?php echo htmlspecialchars($d['address']); ?></span></td>
            <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($d['center_name']??'N/A'); ?></span></td>
            <td><span class="db-badge db-badge--success"><?php echo htmlspecialchars($d['relief_type']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($d['items_distributed']??'',0,40,'…')); ?></span></td>
            <td><strong><?php echo htmlspecialchars($d['quantity']); ?></strong></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($d['distributed_by_name']??'N/A'); ?></span></td>
            <td><div style="display:flex;gap:4px;">
                <button class="db-icon-btn db-icon-btn--default" onclick='viewDistribution(<?php echo json_encode($d); ?>)' title="View"><i class="fas fa-eye"></i></button>
                <button class="db-icon-btn db-icon-btn--sky" onclick='printDistribution(<?php echo json_encode($d); ?>)' title="Print"><i class="fas fa-print"></i></button>
                <button class="db-icon-btn db-icon-btn--rose" onclick="confirmDelete(<?php echo $d['distribution_id']; ?>,'<?php echo htmlspecialchars($d['resident_name'],ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- DISTRIBUTE MODAL -->
<div id="distributeModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--success"><h3><i class="fas fa-hands-helping"></i> Distribute Relief Goods</h3><button class="db-modal__close" onclick="closeModal('distributeModal')">×</button></div>
<div class="db-modal__body">
<?php if(empty($residents)): ?>
<div class="db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span><strong>No Active Evacuees Available.</strong> Please register evacuees first in the Evacuee Registration page.</span></div>
<?php else: ?>
<div class="db-notice--sky"><i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i><span>Only active registered evacuees are shown in the recipient list.</span></div>
<form method="POST"><input type="hidden" name="action" value="distribute_relief">
<div class="db-form-grid">
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Recipient (Active Evacuees Only)</label><select name="resident_id" class="db-select" required><option value="">Select Recipient</option><?php foreach($residents as $r): ?><option value="<?php echo $r['resident_id']; ?>"><?php echo htmlspecialchars($r['full_name']); ?> — <?php echo htmlspecialchars($r['center_name']); ?> (<?php echo $r['family_members']; ?> members)</option><?php endforeach; ?></select></div>
    <div class="db-form-group"><label class="db-form-label">Related Disaster (Optional)</label><select name="disaster_id" class="db-select"><option value="">No specific disaster</option><?php foreach($disasters as $dis): ?><option value="<?php echo $dis['incident_id']; ?>"><?php echo htmlspecialchars($dis['disaster_name']); ?> (<?php echo formatDate($dis['incident_date']); ?>)</option><?php endforeach; ?></select></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Distribution Date</label><input type="date" name="distribution_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Relief Type</label><select name="relief_type" class="db-select" required><option value="">Select Type</option><?php foreach(['Food Pack','Emergency Kit','Medicine','Clothing','Hygiene Kit','Water','Cash Assistance','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
    <div class="db-form-group full"><label class="db-form-label db-form-label--req">Quantity</label><input type="text" name="quantity" class="db-input" placeholder="e.g., 1 pack, 5 pieces, 2 boxes" required></div>
    <div class="db-form-group full"><label class="db-form-label db-form-label--req">Items Description</label><textarea name="items" class="db-textarea" placeholder="List of items included..." required></textarea></div>
    <div class="db-form-group full"><label class="db-form-label">Notes</label><textarea name="notes" class="db-textarea" placeholder="Additional notes or special instructions..."></textarea></div>
</div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('distributeModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Distribute</button></div>
</form>
<?php endif; ?>
</div></div></div>

<!-- VIEW MODAL -->
<div id="viewModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--sky"><h3><i class="fas fa-eye"></i> Distribution Details</h3><button class="db-modal__close" onclick="closeModal('viewModal')">×</button></div>
<div class="db-modal__body"><div id="viewContent"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button></div></div></div></div>

<!-- PRINT MODAL -->
<div id="printModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--success"><h3><i class="fas fa-print"></i> Print Distribution Receipt</h3><button class="db-modal__close" onclick="closeModal('printModal')">×</button></div>
<div class="db-modal__body">
<div id="printContent" class="print-area">
    <div class="print-area-header"><h4 style="margin-bottom:4px;">BARANGAY RELIEF DISTRIBUTION</h4><h5 style="color:var(--db-muted);margin-bottom:2px;">Official Receipt</h5><p style="font-size:12px;color:var(--db-muted);margin:0;">Barangay Disaster Risk Reduction and Management Office</p></div>
    <div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><strong>Distribution ID:</strong> <span id="printDistId"></span></div><div><strong>Date:</strong> <span id="printDate"></span></div></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
        <tr><td style="padding:4px 0;width:140px;"><strong>Name:</strong></td><td id="printRecipientName"></td></tr>
        <tr><td style="padding:4px 0;"><strong>Address:</strong></td><td id="printAddress"></td></tr>
        <tr><td style="padding:4px 0;"><strong>Center:</strong></td><td id="printCenterName"></td></tr>
    </table>
    <table style="width:100%;border-collapse:collapse;border:1px solid var(--db-border);margin-bottom:16px;">
        <thead style="background:var(--db-surf2);"><tr><th style="padding:8px 12px;text-align:left;font-size:11px;border-bottom:1px solid var(--db-border);">Relief Type</th><th style="padding:8px 12px;text-align:left;font-size:11px;border-bottom:1px solid var(--db-border);">Quantity</th><th style="padding:8px 12px;text-align:left;font-size:11px;border-bottom:1px solid var(--db-border);">Items Description</th></tr></thead>
        <tbody><tr><td style="padding:8px 12px;" id="printReliefType"></td><td style="padding:8px 12px;" id="printQuantity"></td><td style="padding:8px 12px;" id="printItems"></td></tr></tbody>
    </table>
    <div id="printNotesSection" style="display:none;margin-bottom:16px;"><strong>Notes:</strong> <span id="printNotes"></span></div>
    <div class="print-sig-row">
        <div style="text-align:center;"><p style="margin-bottom:0;font-size:12px;">Received by:</p><div class="print-sig-line"></div><p style="margin-top:4px;font-size:11px;color:var(--db-muted);">Signature over Printed Name</p></div>
        <div style="text-align:center;"><p style="margin-bottom:0;font-size:12px;">Distributed by:</p><div style="margin-top:24px;font-weight:700;" id="printDistributedBy"></div><div class="print-sig-line"></div><p style="margin-top:4px;font-size:11px;color:var(--db-muted);">Authorized Personnel</p></div>
    </div>
    <div style="text-align:center;margin-top:20px;padding-top:16px;border-top:1px solid var(--db-border);font-size:11px;color:var(--db-muted);">This is an official document. Keep this receipt for your records.<br>Generated on: <?php echo date('F d, Y h:i A'); ?></div>
</div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('printModal')"><i class="fas fa-times"></i> Close</button><button type="button" class="db-btn db-btn--success" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button></div>
</div></div></div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="db-modal"><div class="db-modal__box db-modal__box--sm"><div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-trash-alt"></i> Confirm Deletion</h3><button class="db-modal__close" onclick="closeModal('deleteModal')">×</button></div>
<div class="db-modal__body">
<div class="db-confirm-grid"><div class="db-confirm-row"><span class="lbl">Recipient</span><span class="val" id="deleteRecipientName"></span></div><div class="db-confirm-row"><span class="lbl">Distribution ID</span><span class="val" id="deleteDistId"></span></div></div>
<div class="db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>This action <strong>cannot be undone</strong>. The distribution record will be permanently deleted.</span></div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i> Cancel</button><button type="button" class="db-btn db-btn--rose" onclick="deleteDistribution()"><i class="fas fa-trash"></i> Yes, Delete</button></div>
</div></div></div>

<script>
const distributionsData = <?php echo json_encode($distributions); ?>;
let currentDeleteId=null; let filteredData=[...distributionsData];

function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

function viewDistribution(dist){
    document.getElementById('viewContent').innerHTML=`
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Recipient</span><span class="val">${dist.resident_name}</span></div>
            <div class="db-confirm-row"><span class="lbl">Evacuation Center</span><span class="val">${dist.center_name||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Address</span><span class="val">${dist.address}</span></div>
            <div class="db-confirm-row"><span class="lbl">Distribution Date</span><span class="val">${dist.distribution_date}</span></div>
            <div class="db-confirm-row"><span class="lbl">Relief Type</span><span class="val"><span class="db-badge db-badge--success">${dist.relief_type}</span></span></div>
            <div class="db-confirm-row"><span class="lbl">Quantity</span><span class="val">${dist.quantity}</span></div>
            <div class="db-confirm-row"><span class="lbl">Distributed By</span><span class="val">${dist.distributed_by_name||'N/A'}</span></div>
            ${dist.disaster_name?`<div class="db-confirm-row"><span class="lbl">Related Disaster</span><span class="val">${dist.disaster_name}</span></div>`:''}
            <div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Items Distributed</span><span class="val" style="text-align:left;">${dist.items_distributed}</span></div>
            ${dist.notes&&dist.notes.trim()?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Notes</span><span class="val" style="text-align:left;">${dist.notes}</span></div>`:''}
        </div>`;
    openModal('viewModal');
}

function printDistribution(dist){
    document.getElementById('printDistId').textContent='#'+dist.distribution_id;
    document.getElementById('printDate').textContent=dist.distribution_date;
    document.getElementById('printRecipientName').textContent=dist.resident_name;
    document.getElementById('printAddress').textContent=dist.address;
    document.getElementById('printCenterName').textContent=dist.center_name||'N/A';
    document.getElementById('printReliefType').textContent=dist.relief_type;
    document.getElementById('printQuantity').textContent=dist.quantity;
    document.getElementById('printItems').textContent=dist.items_distributed;
    document.getElementById('printDistributedBy').textContent=dist.distributed_by_name||'N/A';
    if(dist.notes&&dist.notes.trim()){document.getElementById('printNotesSection').style.display='block';document.getElementById('printNotes').textContent=dist.notes;}
    else document.getElementById('printNotesSection').style.display='none';
    openModal('printModal');
}

function confirmDelete(id,name){currentDeleteId=id;document.getElementById('deleteRecipientName').textContent=name;document.getElementById('deleteDistId').textContent='#'+id;openModal('deleteModal');}
function deleteDistribution(){if(currentDeleteId){const f=document.createElement('form');f.method='POST';f.innerHTML='<input type="hidden" name="action" value="delete_distribution"><input type="hidden" name="distribution_id" value="'+currentDeleteId+'">';document.body.appendChild(f);f.submit();}}

function applyFilters(){
    const df=document.getElementById('filterDateFrom').value; const dt=document.getElementById('filterDateTo').value;
    const rt=document.getElementById('filterReliefType').value; const ev=document.getElementById('filterEvacuee').value;
    filteredData=distributionsData.filter(d=>{
        if(df&&d.distribution_date<df)return false;
        if(dt&&d.distribution_date>dt)return false;
        if(rt&&d.relief_type!==rt)return false;
        if(ev&&d.resident_name!==ev)return false;
        return true;
    });
    updateTable();
}
function clearFilters(){document.getElementById('filterDateFrom').value='';document.getElementById('filterDateTo').value='';document.getElementById('filterReliefType').value='';document.getElementById('filterEvacuee').value='';filteredData=[...distributionsData];updateTable();}
function updateTable(){
    const tbody=document.querySelector('#distributionsTable tbody'); tbody.innerHTML='';
    if(!filteredData.length){tbody.innerHTML='<tr><td colspan="8"><div class="db-empty"><i class="fas fa-box"></i><p>No records match the current filter</p></div></td></tr>';}
    else{filteredData.forEach(d=>{const tr=document.createElement('tr');tr.innerHTML=`<td><span class="db-text-sm">${d.distribution_date}</span></td><td><strong>${d.resident_name}</strong><br><span class="db-text-sm">${d.address}</span></td><td><span class="db-badge db-badge--sky">${d.center_name||'N/A'}</span></td><td><span class="db-badge db-badge--success">${d.relief_type}</span></td><td><span class="db-text-sm">${(d.items_distributed||'').substring(0,40)}</span></td><td><strong>${d.quantity}</strong></td><td><span class="db-text-sm">${d.distributed_by_name||'N/A'}</span></td><td><div style="display:flex;gap:4px;"><button class="db-icon-btn db-icon-btn--default" onclick='viewDistribution(${JSON.stringify(d).replace(/'/g,"&apos;")})'><i class="fas fa-eye"></i></button><button class="db-icon-btn db-icon-btn--sky" onclick='printDistribution(${JSON.stringify(d).replace(/'/g,"&apos;")})'><i class="fas fa-print"></i></button><button class="db-icon-btn db-icon-btn--rose" onclick="confirmDelete(${d.distribution_id},'${d.resident_name.replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button></div></td>`;tbody.appendChild(tr);});}
    document.getElementById('recordCount').textContent=filteredData.length+' Records';
    document.getElementById('tableCount').textContent=filteredData.length;
}

function exportToExcel(){
    const data=filteredData.map((d,i)=>({No:i+1,Date:d.distribution_date,Recipient:d.resident_name,Address:d.address,Center:d.center_name||'N/A','Relief Type':d.relief_type,Items:d.items_distributed,Quantity:d.quantity,'Distributed By':d.distributed_by_name||'N/A',Notes:d.notes||''}));
    if(!data.length){alert('No data to export!');return;}
    const headers=Object.keys(data[0]);
    const csv=headers.join(',')+'\n'+data.map(r=>headers.map(h=>{const v=String(r[h]||'').replace(/"/g,'""');return/[",\n]/.test(v)?`"${v}"`:v;}).join(',')).join('\n');
    const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='Relief_Distributions_'+new Date().toISOString().split('T')[0]+'.csv';document.body.appendChild(a);a.click();document.body.removeChild(a);
}

function printAllDistributions(){
    let w=window.open('','','height=700,width=900');
    const rows=filteredData.map((d,i)=>`<tr><td>${i+1}</td><td>${d.distribution_date}</td><td>${d.resident_name}</td><td>${d.center_name||'N/A'}</td><td>${d.relief_type}</td><td>${d.items_distributed}</td><td>${d.quantity}</td><td>${d.distributed_by_name||'N/A'}</td></tr>`).join('');
    w.document.write(`<html><head><title>Relief Distribution Report</title><style>body{font-family:Arial,sans-serif;margin:20px;}h2{text-align:center;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ddd;padding:6px;font-size:12px;}th{background:#f3f4f6;}</style></head><body><h2>Barangay Relief Distribution Report</h2><p style="text-align:right;font-size:12px;color:#6b7280;">Generated: ${new Date().toLocaleString()}</p><table><thead><tr><th>#</th><th>Date</th><th>Recipient</th><th>Center</th><th>Type</th><th>Items</th><th>Qty</th><th>Distributed By</th></tr></thead><tbody>${rows}</tbody></table></body></html>`);
    w.document.close();w.print();
}

setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>