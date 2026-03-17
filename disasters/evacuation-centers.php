<?php
/**
 * Evacuation Centers Management
 * Path: barangaylink/disasters/evacuation-centers.php
 */
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) { header('Location: ' . APP_URL . '/modules/auth/login.php'); exit(); }
$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff', 'Secretary'])) { header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit(); }
$page_title = 'Evacuation Centers';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_center':
            $cn=sanitizeInput($_POST['center_name']); $loc=sanitizeInput($_POST['location']); $cap=sanitizeInput($_POST['capacity']); $fac=sanitizeInput($_POST['facilities']); $cp=sanitizeInput($_POST['contact_person']); $cnum=sanitizeInput($_POST['contact_number']); $st=sanitizeInput($_POST['status']);
            $sql="INSERT INTO tbl_evacuation_centers (center_name,location,capacity,facilities,contact_person,contact_number,status,created_at) VALUES (?,?,?,?,?,?,?,NOW())";
            if(executeQuery($conn,$sql,[$cn,$loc,$cap,$fac,$cp,$cnum,$st],'ssissss')){logActivity($conn,getCurrentUserId(),"Added evacuation center: $cn");setMessage('Evacuation center added successfully','success');}else{setMessage('Failed to add evacuation center','error');}
            break;
        case 'update_center':
            $cid=sanitizeInput($_POST['center_id']); $cn=sanitizeInput($_POST['center_name']); $loc=sanitizeInput($_POST['location']); $cap=sanitizeInput($_POST['capacity']); $fac=sanitizeInput($_POST['facilities']); $cp=sanitizeInput($_POST['contact_person']); $cnum=sanitizeInput($_POST['contact_number']); $st=sanitizeInput($_POST['status']);
            $sql="UPDATE tbl_evacuation_centers SET center_name=?,location=?,capacity=?,facilities=?,contact_person=?,contact_number=?,status=? WHERE center_id=?";
            if(executeQuery($conn,$sql,[$cn,$loc,$cap,$fac,$cp,$cnum,$st,$cid],'ssissssi')){logActivity($conn,getCurrentUserId(),"Updated evacuation center ID: $cid");setMessage('Evacuation center updated successfully','success');}else{setMessage('Failed to update evacuation center','error');}
            break;
        case 'delete_center':
            $cid=sanitizeInput($_POST['center_id']);
            $chk=fetchOne($conn,"SELECT COUNT(*) as count FROM tbl_evacuees WHERE center_id=? AND status='Active'",[$cid],'i');
            if($chk&&$chk['count']>0){setMessage('Cannot delete center with active evacuees. Please check out all evacuees first.','error');}
            else{if(executeQuery($conn,"DELETE FROM tbl_evacuation_centers WHERE center_id=?",[$cid],'i')){logActivity($conn,getCurrentUserId(),"Deleted evacuation center ID: $cid");setMessage('Evacuation center deleted successfully','success');}else{setMessage('Failed to delete evacuation center','error');}}
            break;
    }
    header('Location: evacuation-centers.php'); exit();
}

$sql="SELECT ec.*,COUNT(e.evacuee_id) as evacuee_count,COALESCE(SUM(CASE WHEN e.status='Active' THEN e.family_members ELSE 0 END),0) as total_evacuees FROM tbl_evacuation_centers ec LEFT JOIN tbl_evacuees e ON ec.center_id=e.center_id AND e.status='Active' GROUP BY ec.center_id ORDER BY ec.center_name";
$centers=fetchAll($conn,$sql);
$total_centers=count($centers); $active_centers=count(array_filter($centers,fn($c)=>$c['status']==='Active'));
$total_capacity=array_sum(array_column($centers,'capacity')); $total_evacuees=array_sum(array_column($centers,'total_evacuees'));
$occupancy_rate=$total_capacity>0?($total_evacuees/$total_capacity)*100:0;

include __DIR__ . '/../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#312e81 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(99,102,241,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#312e81,var(--db-indigo));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}.db-alert--error{background:var(--db-rose-light);color:#7f1d1d;border-color:var(--db-rose);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--indigo{background:linear-gradient(135deg,#312e81,var(--db-indigo));color:#fff;}.db-btn--indigo:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(99,102,241,.35);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}.db-btn--ghost:hover{background:var(--db-border);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}.db-badge--amber{background:var(--db-amber-light);color:#92400e;}.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
/* Center cards grid */
.centers-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;padding:0 24px 24px;}
.center-card{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);overflow:hidden;transition:transform .2s,box-shadow .2s;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.center-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.center-card__head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--db-border);}
.center-card__title{font-size:14px;font-weight:700;}
.center-card__body{padding:16px 18px;}
.center-card__meta{display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:12.5px;color:var(--db-muted);}
.center-card__meta strong{color:var(--db-text);}
.fm-prog-wrap{margin:10px 0;}.fm-prog-track{height:8px;background:var(--db-surf2);border-radius:4px;overflow:hidden;border:1px solid var(--db-border);}.fm-prog-fill{height:100%;border-radius:4px;transition:width .5s ease;}
.fm-prog-fill--ok{background:linear-gradient(90deg,var(--db-success),#34d399);}.fm-prog-fill--warn{background:linear-gradient(90deg,var(--db-amber),#fbbf24);}.fm-prog-fill--danger{background:linear-gradient(90deg,var(--db-rose),#f43f5e);}
.center-card__actions{display:flex;gap:8px;padding:12px 18px;border-top:1px solid var(--db-border);background:var(--db-surf2);}
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}.db-modal__box--sm{max-width:440px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}.db-modal__header--indigo{background:linear-gradient(135deg,#312e81,var(--db-indigo));}.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}.db-modal__header--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));}.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}.db-modal__footer{display:flex;gap:10px;margin-top:18px;}.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-form-group{margin-bottom:14px;}.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-input,.db-select,.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;min-height:70px;}.db-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}.db-form-grid .full{grid-column:1/-1;}
.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}.db-confirm-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;}.db-confirm-row:last-child{border-bottom:none;}.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;}.db-confirm-row .val{font-weight:600;color:var(--db-text);}
.db-notice--rose{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-rose-light);color:#9f1239;margin-bottom:14px;}
/* Occupancy progress inside view modal */
.db-occ-row{display:flex;align-items:center;gap:10px;}.db-occ-track{flex:1;height:20px;background:var(--db-surf2);border-radius:5px;overflow:hidden;border:1px solid var(--db-border);}.db-occ-fill{height:100%;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .center-card,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .center-card__head,body.dark-mode .center-card__actions{border-color:#334155!important;background:#1e293b!important;}
body.dark-mode .center-card__meta{color:#94a3b8!important;}
body.dark-mode .center-card__meta strong{color:#e2e8f0!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .fm-prog-track,.dark-mode .db-occ-track{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-form-label{color:#94a3b8!important;}
body.dark-mode .db-confirm-grid{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}
body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}
body.dark-mode .db-confirm-row .val{color:#e2e8f0!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}.centers-grid{grid-template-columns:1fr;padding:0 12px 24px;}.db-form-grid{grid-template-columns:1fr;}.db-form-grid .full{grid-column:1/1;}}
</style>

<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-home"></i></div>
            <div><div class="fm-hero__title">Evacuation Centers</div><div class="fm-hero__sub">Manage evacuation center information and capacity</div></div>
        </div>
        <button class="db-btn db-btn--indigo" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Center</button>
    </div>
</div>

<div style="padding:0 24px 12px;">
<?php echo displayMessage(); ?>
<div class="db-stats-row">
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-building"></i></div><div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $total_centers; ?></div><div class="db-stat-card__label">Total Centers</div></div><div class="db-stat-card__bar db-stat-card__bar--indigo"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div><div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $active_centers; ?></div><div class="db-stat-card__label">Active Centers</div></div><div class="db-stat-card__bar db-stat-card__bar--success"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-users"></i></div><div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $total_capacity; ?></div><div class="db-stat-card__label">Total Capacity</div></div><div class="db-stat-card__bar db-stat-card__bar--sky"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-chart-pie"></i></div><div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo round($occupancy_rate,1); ?>%</div><div class="db-stat-card__label">Occupancy Rate</div></div><div class="db-stat-card__bar db-stat-card__bar--amber"></div></div>
</div>
</div>

<!-- Centers Grid -->
<div class="centers-grid">
<?php if(empty($centers)): ?>
<div style="grid-column:1/-1;text-align:center;padding:48px;background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);">
    <i class="fas fa-home" style="font-size:44px;color:var(--db-border);display:block;margin-bottom:12px;"></i>
    <p style="color:var(--db-muted);">No evacuation centers found</p>
    <button class="db-btn db-btn--indigo db-btn--sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add First Center</button>
</div>
<?php else: foreach($centers as $c):
    $occ=$c['capacity']>0?($c['total_evacuees']/$c['capacity'])*100:0;
    $oc=$occ>=90?'danger':($occ>=70?'warn':'ok');
    $sc=['Active'=>'success','Inactive'=>'muted','Full'=>'rose','Under Maintenance'=>'amber'];
    $sb=$sc[$c['status']]??'muted'; $avail=$c['capacity']-$c['total_evacuees'];
?>
<div class="center-card">
    <div class="center-card__head">
        <span class="center-card__title"><?php echo htmlspecialchars($c['center_name']); ?></span>
        <span class="db-badge db-badge--<?php echo $sb; ?>"><?php echo $c['status']; ?></span>
    </div>
    <div class="center-card__body">
        <div class="center-card__meta"><i class="fas fa-map-marker-alt"></i><span><?php echo htmlspecialchars($c['location']); ?></span></div>
        <div class="fm-prog-wrap">
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--db-muted);margin-bottom:4px;">
                <span><strong style="color:var(--db-text);"><?php echo $c['total_evacuees']; ?></strong> / <?php echo $c['capacity']; ?> people</span>
                <span><?php echo round($occ,1); ?>% · <?php echo $avail; ?> available</span>
            </div>
            <div class="fm-prog-track"><div class="fm-prog-fill fm-prog-fill--<?php echo $oc; ?>" style="width:<?php echo min($occ,100); ?>%"></div></div>
        </div>
        <?php if(!empty($c['facilities'])): ?><div class="center-card__meta" style="margin-top:8px;"><i class="fas fa-list"></i><span><?php echo htmlspecialchars(truncateText($c['facilities'],70)); ?></span></div><?php endif; ?>
        <div class="center-card__meta"><i class="fas fa-user"></i><span><?php echo htmlspecialchars($c['contact_person']); ?></span></div>
        <div class="center-card__meta"><i class="fas fa-phone"></i><span><?php echo htmlspecialchars($c['contact_number']); ?></span></div>
    </div>
    <div class="center-card__actions">
        <button class="db-btn db-btn--indigo db-btn--sm" style="flex:1;" onclick="viewCenter(<?php echo $c['center_id']; ?>)"><i class="fas fa-eye"></i> View</button>
        <button class="db-btn db-btn--amber db-btn--sm" onclick="editCenter(<?php echo $c['center_id']; ?>)"><i class="fas fa-edit"></i></button>
        <button class="db-btn db-btn--rose db-btn--sm" onclick="deleteCenter(<?php echo $c['center_id']; ?>)"><i class="fas fa-trash"></i></button>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ADD MODAL -->
<div id="addModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--indigo"><h3><i class="fas fa-plus"></i> Add Evacuation Center</h3><button class="db-modal__close" onclick="closeModal('addModal')">×</button></div>
<div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="add_center">
<div class="db-form-grid">
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Center Name</label><input type="text" name="center_name" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Capacity</label><input type="number" name="capacity" class="db-input" min="1" required><span style="font-size:11px;color:var(--db-muted);">Max number of people</span></div>
    <div class="db-form-group full"><label class="db-form-label db-form-label--req">Location</label><input type="text" name="location" class="db-input" required placeholder="Complete address"></div>
    <div class="db-form-group full"><label class="db-form-label">Facilities</label><textarea name="facilities" class="db-textarea" placeholder="e.g., Restrooms, Shower, Kitchen, Medical Tent..."></textarea></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Contact Person</label><input type="text" name="contact_person" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Contact Number</label><input type="text" name="contact_number" class="db-input" required placeholder="09XX-XXX-XXXX"></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Status</label><select name="status" class="db-select" required><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Under Maintenance">Under Maintenance</option></select></div>
</div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--indigo"><i class="fas fa-save"></i> Add Center</button></div>
</form></div></div></div>

<!-- VIEW MODAL -->
<div id="viewModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--sky"><h3><i class="fas fa-eye"></i> Evacuation Center Details</h3><button class="db-modal__close" onclick="closeModal('viewModal')">×</button></div>
<div class="db-modal__body"><div id="viewContent"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button><button type="button" class="db-btn db-btn--amber" style="color:#fff" onclick="editCenterFromView()"><i class="fas fa-edit"></i> Edit</button></div></div></div></div>

<!-- EDIT MODAL -->
<div id="editModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--amber"><h3><i class="fas fa-edit"></i> Edit Evacuation Center</h3><button class="db-modal__close" onclick="closeModal('editModal')">×</button></div>
<div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="update_center"><input type="hidden" name="center_id" id="editCenterId">
<div class="db-form-grid">
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Center Name</label><input type="text" name="center_name" id="editCenterName" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Capacity</label><input type="number" name="capacity" id="editCenterCapacity" class="db-input" min="1" required></div>
    <div class="db-form-group full"><label class="db-form-label db-form-label--req">Location</label><input type="text" name="location" id="editCenterLocation" class="db-input" required></div>
    <div class="db-form-group full"><label class="db-form-label">Facilities</label><textarea name="facilities" id="editCenterFacilities" class="db-textarea"></textarea></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Contact Person</label><input type="text" name="contact_person" id="editCenterContactPerson" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Contact Number</label><input type="text" name="contact_number" id="editCenterContactNumber" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Status</label><select name="status" id="editCenterStatus" class="db-select" required><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Full">Full</option><option value="Under Maintenance">Under Maintenance</option></select></div>
</div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--amber" style="color:#fff"><i class="fas fa-save"></i> Update</button></div>
</form></div></div></div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="db-modal"><div class="db-modal__box db-modal__box--sm"><div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-trash-alt"></i> Confirm Deletion</h3><button class="db-modal__close" onclick="closeModal('deleteModal')">×</button></div>
<div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="delete_center"><input type="hidden" name="center_id" id="deleteCenterId">
<div class="db-confirm-grid">
    <div class="db-confirm-row"><span class="lbl">Center Name</span><span class="val" id="deleteCenterName"></span></div>
    <div class="db-confirm-row"><span class="lbl">Location</span><span class="val" id="deleteCenterLocation"></span></div>
    <div class="db-confirm-row"><span class="lbl">Active Evacuees</span><span class="val" id="deleteCenterEvacuees"></span></div>
</div>
<div class="db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>Cannot delete a center with active evacuees. Check out all evacuees first. This action cannot be undone.</span></div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--rose" id="confirmDeleteBtn"><i class="fas fa-trash"></i> Delete Center</button></div>
</form></div></div></div>

<script>
const centersData = <?php echo json_encode($centers); ?>;
let currentCenterId=null;
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

const scm={Active:'db-badge--success',Inactive:'db-badge--muted',Full:'db-badge--rose','Under Maintenance':'db-badge--amber'};
function mkBadge(cls,txt){return`<span class="db-badge ${cls}">${txt}</span>`;}

function viewCenter(id){
    const c=centersData.find(x=>x.center_id==id); if(!c)return;
    currentCenterId=id;
    const occ=c.capacity>0?((c.total_evacuees/c.capacity)*100).toFixed(1):0;
    const avail=c.capacity-c.total_evacuees;
    const oc=occ>=90?'var(--db-rose)':occ>=70?'var(--db-amber)':'var(--db-success)';
    const ocbg=occ>=90?'#ef4444':occ>=70?'#f59e0b':'#10b981';
    document.getElementById('viewContent').innerHTML=`
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Center Name</span><span class="val">${c.center_name}</span></div>
            <div class="db-confirm-row"><span class="lbl">Status</span><span class="val">${mkBadge(scm[c.status]||'db-badge--muted',c.status)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Location</span><span class="val">${c.location}</span></div>
            <div class="db-confirm-row"><span class="lbl">Max Capacity</span><span class="val" style="font-family:'DM Mono',monospace;color:var(--db-indigo)">${c.capacity}</span></div>
            <div class="db-confirm-row"><span class="lbl">Current Evacuees</span><span class="val" style="font-family:'DM Mono',monospace;color:var(--db-success)">${c.total_evacuees}</span></div>
            <div class="db-confirm-row"><span class="lbl">Available Space</span><span class="val" style="font-family:'DM Mono',monospace;color:${oc}">${avail}</span></div>
        </div>
        <div style="margin:12px 0;">
            <div class="db-occ-row"><div class="db-occ-track"><div class="db-occ-fill" style="width:${Math.min(occ,100)}%;background:${ocbg}">${c.total_evacuees}/${c.capacity} (${occ}%)</div></div></div>
        </div>
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Contact Person</span><span class="val">${c.contact_person}</span></div>
            <div class="db-confirm-row"><span class="lbl">Contact Number</span><span class="val">${c.contact_number}</span></div>
            ${c.facilities?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Facilities</span><span class="val" style="text-align:left;">${c.facilities}</span></div>`:''}
        </div>`;
    openModal('viewModal');
}
function editCenterFromView(){closeModal('viewModal');setTimeout(()=>editCenter(currentCenterId),300);}
function editCenter(id){
    const c=centersData.find(x=>x.center_id==id); if(!c)return;
    document.getElementById('editCenterId').value=c.center_id;
    document.getElementById('editCenterName').value=c.center_name;
    document.getElementById('editCenterLocation').value=c.location;
    document.getElementById('editCenterCapacity').value=c.capacity;
    document.getElementById('editCenterFacilities').value=c.facilities||'';
    document.getElementById('editCenterContactPerson').value=c.contact_person;
    document.getElementById('editCenterContactNumber').value=c.contact_number;
    document.getElementById('editCenterStatus').value=c.status;
    openModal('editModal');
}
function deleteCenter(id){
    const c=centersData.find(x=>x.center_id==id); if(!c)return;
    document.getElementById('deleteCenterId').value=c.center_id;
    document.getElementById('deleteCenterName').textContent=c.center_name;
    document.getElementById('deleteCenterLocation').textContent=c.location;
    document.getElementById('deleteCenterEvacuees').textContent=c.total_evacuees;
    const btn=document.getElementById('confirmDeleteBtn');
    btn.disabled=c.total_evacuees>0;
    btn.innerHTML=c.total_evacuees>0?'<i class="fas fa-ban"></i> Cannot Delete':'<i class="fas fa-trash"></i> Delete Center';
    openModal('deleteModal');
}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>