<?php
/**
 * Evacuee Registration with Filters
 * Path: barangaylink/disasters/evacuee-registration.php
 */
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) { header('Location: ' . APP_URL . '/modules/auth/login.php'); exit(); }
$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin','Super Admin','Staff','Secretary'])) { header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit(); }
$page_title = 'Evacuee Registration';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'register_evacuee':
            $center_id=sanitizeInput($_POST['center_id']);$resident_id=sanitizeInput($_POST['resident_id']);$family_head_name=sanitizeInput($_POST['family_head_name']);$family_members=sanitizeInput($_POST['family_members']);$check_in_date=sanitizeInput($_POST['check_in_date']);$contact_number=sanitizeInput($_POST['contact_number']);$special_needs=sanitizeInput($_POST['special_needs']);$notes=sanitizeInput($_POST['notes']);
            $center=fetchOne($conn,"SELECT * FROM tbl_evacuation_centers WHERE center_id=?",[$center_id],'i');
            if(!$center){setMessage('Evacuation center not found','error');break;}
            if(isset($center['status'])&&in_array($center['status'],['Inactive','Full','Under Maintenance'])){$sm=['Inactive'=>'This evacuation center is currently inactive.','Full'=>'This evacuation center is full.','Under Maintenance'=>'This evacuation center is under maintenance.'];setMessage($sm[$center['status']],'error');break;}
            $cur=fetchOne($conn,"SELECT COALESCE(SUM(family_members),0) as total_members FROM tbl_evacuees WHERE center_id=? AND status='Active'",[$center_id],'i');
            $cur_occ=$cur['total_members']??0;
            if(($cur_occ+(int)$family_members)>$center['capacity']){$avail=$center['capacity']-$cur_occ;setMessage("Center capacity exceeded! Only {$avail} space(s) available.",'error');break;}
            $sql="INSERT INTO tbl_evacuees (center_id,resident_id,family_head_name,family_members,check_in_date,contact_number,special_needs,notes,status,registered_by,created_at) VALUES (?,?,?,?,?,?,?,?,'Active',?,NOW())";
            if(executeQuery($conn,$sql,[$center_id,$resident_id,$family_head_name,$family_members,$check_in_date,$contact_number,$special_needs,$notes,getCurrentUserId()],'iisissssi')){
                $new_occ=$cur_occ+(int)$family_members;
                if(columnExists($conn,'tbl_evacuation_centers','current_occupancy'))executeQuery($conn,"UPDATE tbl_evacuation_centers SET current_occupancy=? WHERE center_id=?",[$new_occ,$center_id],'ii');
                if(columnExists($conn,'tbl_evacuation_centers','status')&&$new_occ>=$center['capacity'])executeQuery($conn,"UPDATE tbl_evacuation_centers SET status='Full' WHERE center_id=?",[$center_id],'i');
                logActivity($conn,getCurrentUserId(),"Registered evacuee: $family_head_name at {$center['center_name']}");setMessage('Evacuee registered successfully','success');
            }else{setMessage('Failed to register evacuee','error');}
            break;
        case 'checkout_evacuee':
            $evacuee_id=sanitizeInput($_POST['evacuee_id']);
            $evacuee=fetchOne($conn,"SELECT * FROM tbl_evacuees WHERE evacuee_id=?",[$evacuee_id],'i');
            if(!$evacuee){setMessage('Evacuee not found','error');break;}
            if(executeQuery($conn,"UPDATE tbl_evacuees SET check_out_date=CURDATE(), status='Inactive' WHERE evacuee_id=?",[$evacuee_id],'i')){
                $ctr=fetchOne($conn,"SELECT * FROM tbl_evacuation_centers WHERE center_id=?",[$evacuee['center_id']],'i');
                if($ctr){$no=fetchOne($conn,"SELECT COALESCE(SUM(family_members),0) as total_members FROM tbl_evacuees WHERE center_id=? AND status='Active'",[$evacuee['center_id']],'i');$new_occ=$no['total_members'];
                    if(columnExists($conn,'tbl_evacuation_centers','current_occupancy'))executeQuery($conn,"UPDATE tbl_evacuation_centers SET current_occupancy=? WHERE center_id=?",[$new_occ,$evacuee['center_id']],'ii');
                    if(columnExists($conn,'tbl_evacuation_centers','status')&&isset($ctr['status'])&&$new_occ<$ctr['capacity']&&$ctr['status']==='Full')executeQuery($conn,"UPDATE tbl_evacuation_centers SET status='Active' WHERE center_id=?",[$evacuee['center_id']],'i');}
                logActivity($conn,getCurrentUserId(),"Checked out evacuee ID: $evacuee_id");setMessage('Evacuee checked out successfully','success');
            }else{setMessage('Failed to check out evacuee','error');}
            break;
        case 'delete_evacuee':
            $evacuee_id=sanitizeInput($_POST['evacuee_id']);
            $evacuee=fetchOne($conn,"SELECT * FROM tbl_evacuees WHERE evacuee_id=?",[$evacuee_id],'i');
            if(!$evacuee){setMessage('Evacuee not found','error');break;}
            if(executeQuery($conn,"DELETE FROM tbl_evacuees WHERE evacuee_id=?",[$evacuee_id],'i')){
                if($evacuee['status']==='Active'){
                    $ctr=fetchOne($conn,"SELECT * FROM tbl_evacuation_centers WHERE center_id=?",[$evacuee['center_id']],'i');
                    if($ctr){$no=fetchOne($conn,"SELECT COALESCE(SUM(family_members),0) as total_members FROM tbl_evacuees WHERE center_id=? AND status='Active'",[$evacuee['center_id']],'i');$new_occ=$no['total_members'];
                        if(columnExists($conn,'tbl_evacuation_centers','current_occupancy'))executeQuery($conn,"UPDATE tbl_evacuation_centers SET current_occupancy=? WHERE center_id=?",[$new_occ,$evacuee['center_id']],'ii');
                        if(columnExists($conn,'tbl_evacuation_centers','status')&&isset($ctr['status'])&&$new_occ<$ctr['capacity']&&$ctr['status']==='Full')executeQuery($conn,"UPDATE tbl_evacuation_centers SET status='Active' WHERE center_id=?",[$evacuee['center_id']],'i');}
                }
                logActivity($conn,getCurrentUserId(),"Deleted evacuee ID: $evacuee_id");setMessage('Evacuee deleted successfully','success');
            }else{setMessage('Failed to delete evacuee','error');}
            break;
    }
    header('Location: evacuee-registration.php'); exit();
}

$sql="SELECT e.evacuee_id,e.center_id,e.resident_id,e.family_head_name,e.family_members,e.check_in_date,e.check_out_date,e.contact_number,e.special_needs,e.notes,e.status,u.username AS registered_by_name,CONCAT(r.first_name,' ',r.last_name) AS full_name,r.address,r.gender,TIMESTAMPDIFF(YEAR,COALESCE(r.date_of_birth,r.birthdate),CURDATE()) AS age,c.center_name FROM tbl_evacuees e LEFT JOIN tbl_users u ON e.registered_by=u.user_id LEFT JOIN tbl_residents r ON e.resident_id=r.resident_id LEFT JOIN tbl_evacuation_centers c ON e.center_id=c.center_id ORDER BY e.check_in_date DESC";
$evacuees=fetchAll($conn,$sql);
$residents=fetchAll($conn,"SELECT resident_id,CONCAT(first_name,' ',last_name) as full_name,address FROM tbl_residents ORDER BY last_name,first_name");
$has_status_col=columnExists($conn,'tbl_evacuation_centers','status');
$csql="SELECT ec.*,COALESCE(SUM(CASE WHEN e.status='Active' THEN e.family_members ELSE 0 END),0) as current_evacuees,COUNT(CASE WHEN e.status='Active' THEN 1 END) as evacuee_families FROM tbl_evacuation_centers ec LEFT JOIN tbl_evacuees e ON ec.center_id=e.center_id".($has_status_col?" WHERE ec.status='Active'":'')." GROUP BY ec.center_id ORDER BY ec.center_name";
$centers=fetchAll($conn,$csql);
$all_centers=fetchAll($conn,"SELECT DISTINCT ec.center_id,ec.center_name FROM tbl_evacuation_centers ec INNER JOIN tbl_evacuees e ON ec.center_id=e.center_id ORDER BY ec.center_name");
$total_evacuees=count($evacuees);$active_count=count(array_filter($evacuees,fn($e)=>$e['status']==='Active'));$returned_count=count(array_filter($evacuees,fn($e)=>$e['status']==='Inactive'));$total_families=count(array_unique(array_column(array_filter($evacuees,fn($e)=>$e['status']==='Active'),'family_head_name')));
include __DIR__ . '/../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-violet:#7c3aed;--db-violet-light:#ede9fe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#4c1d95 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(124,58,237,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4c1d95,var(--db-violet));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(124,58,237,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}.db-alert--error{background:var(--db-rose-light);color:#7f1d1d;border-color:var(--db-rose);}.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}.db-stat-card__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}.db-stat-card__bar--violet{background:linear-gradient(90deg,var(--db-violet),transparent);}.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--violet{background:linear-gradient(135deg,#4c1d95,var(--db-violet));color:#fff;}.db-btn--violet:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(124,58,237,.35);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));color:#fff;}.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);}
.db-btn--outline-sm{background:transparent;color:var(--db-muted);border:1px solid var(--db-border);font-size:12px;padding:5px 10px;}.db-btn--outline-sm:hover{background:var(--db-border);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__body{padding:18px 22px;}.db-panel__title{display:flex;align-items:center;gap:10px;}.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}.db-panel__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}.db-badge--amber{background:var(--db-amber-light);color:#92400e;}.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-badge--violet{background:var(--db-violet-light);color:#5b21b6;}
/* filter grid */
.db-filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;}
.db-filter-label{font-size:10px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-input,.db-select,.db-textarea{width:100%;padding:8px 12px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;min-height:70px;}.db-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}.db-form-grid .full{grid-column:1/-1;}
.db-form-group{margin-bottom:14px;}.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-form-hint{font-size:11px;color:var(--db-muted);margin-top:3px;}
/* table */
.db-table-wrap{overflow-x:auto;}.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}.db-table tbody tr:last-child{border-bottom:none;}.db-table tbody tr:hover{background:#f5f8ff;}.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}.db-empty i{font-size:44px;color:var(--db-border);}.db-empty p{font-size:14px;color:var(--db-muted);}
.db-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:all .15s;}
.db-icon-btn--default{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-icon-btn--default:hover{background:var(--db-border);color:var(--db-text);}
.db-icon-btn--success{background:var(--db-success-light);color:#065f46;}.db-icon-btn--success:hover{background:#a7f3d0;}
.db-icon-btn--rose{background:var(--db-rose-light);color:#9f1239;}.db-icon-btn--rose:hover{background:#fecaca;}
/* modals */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:640px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}.db-modal__box--sm{max-width:420px;}.db-modal__box--lg{max-width:780px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}.db-modal__header--violet{background:linear-gradient(135deg,#4c1d95,var(--db-violet));}.db-modal__header--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));}.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}.db-modal__footer{display:flex;gap:10px;margin-top:18px;}.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}.db-confirm-row{display:flex;justify-content:space-between;align-items:flex-start;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;gap:10px;}.db-confirm-row:last-child{border-bottom:none;}.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;white-space:nowrap;flex-shrink:0;}.db-confirm-row .val{font-weight:600;color:var(--db-text);text-align:right;}
.db-notice--rose{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-rose-light);color:#9f1239;margin-bottom:14px;}
.db-notice--sky{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-sky-light);color:#0369a1;margin-bottom:14px;}
.db-notice--amber{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-amber-light);color:#92400e;margin-bottom:14px;}
.db-section-head{font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.6px;padding:0 0 6px;border-bottom:1px solid var(--db-border);margin:16px 0 10px;display:flex;align-items:center;gap:6px;}.db-section-head:first-child{margin-top:0;}
.db-filter-summary{display:flex;align-items:center;justify-content:space-between;padding-top:12px;border-top:1px solid var(--db-border);margin-top:12px;font-size:12.5px;flex-wrap:wrap;gap:8px;}
/* capacity warning */
.cap-warn{font-size:11px;color:var(--db-rose);margin-top:3px;display:none;}
/* dark mode */
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}body.dark-mode .db-panel__header,body.dark-mode .db-panel__body{border-bottom-color:#334155!important;}body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}body.dark-mode .db-table tbody tr:hover{background:#162032!important;}body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}body.dark-mode .db-btn--ghost,body.dark-mode .db-btn--outline-sm{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}body.dark-mode .db-form-label,body.dark-mode .db-filter-label{color:#94a3b8!important;}
body.dark-mode .db-confirm-grid{background:#162032!important;border-color:#334155!important;}body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}body.dark-mode .db-confirm-row .val{color:#e2e8f0!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}body.dark-mode .db-empty i{color:#334155!important;}body.dark-mode .db-empty p{color:#64748b!important;}
body.dark-mode .db-section-head{color:#64748b!important;border-bottom-color:#334155!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}.db-form-grid{grid-template-columns:1fr;}.db-form-grid .full{grid-column:1/1;}.db-filter-grid{grid-template-columns:1fr 1fr;}}
</style>

<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-user-plus"></i></div>
            <div><div class="fm-hero__title">Evacuee Registration</div><div class="fm-hero__sub">Register and manage evacuees during disasters</div></div>
        </div>
        <button class="db-btn db-btn--violet" onclick="openModal('registerModal')"><i class="fas fa-plus"></i> Register Evacuee</button>
    </div>
</div>

<div style="padding:0 24px 24px;">
<?php echo displayMessage(); ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--violet"><i class="fas fa-users"></i></div><div><div class="db-stat-card__num" style="color:var(--db-violet)"><?php echo $total_evacuees; ?></div><div class="db-stat-card__label">Total Evacuees</div></div><div class="db-stat-card__bar db-stat-card__bar--violet"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-user-check"></i></div><div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $active_count; ?></div><div class="db-stat-card__label">Active</div></div><div class="db-stat-card__bar db-stat-card__bar--success"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-sign-out-alt"></i></div><div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $returned_count; ?></div><div class="db-stat-card__label">Checked Out</div></div><div class="db-stat-card__bar db-stat-card__bar--sky"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-home"></i></div><div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $total_families; ?></div><div class="db-stat-card__label">Active Families</div></div><div class="db-stat-card__bar db-stat-card__bar--amber"></div></div>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div><h2>Filter Evacuees</h2></div>
        <button class="db-btn db-btn--outline-sm" onclick="resetFilters()"><i class="fas fa-redo"></i> Reset</button>
    </div>
    <div class="db-panel__body">
        <div class="db-filter-grid">
            <div><label class="db-filter-label">Status</label><select class="db-select" id="filterStatus" onchange="applyFilters()"><option value="">All Statuses</option><option value="Active">Active</option><option value="Checked Out">Checked Out</option></select></div>
            <div><label class="db-filter-label">Evacuation Center</label><select class="db-select" id="filterCenter" onchange="applyFilters()"><option value="">All Centers</option><?php foreach($all_centers as $c): ?><option value="<?php echo $c['center_id']; ?>"><?php echo htmlspecialchars($c['center_name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="db-filter-label">Check-in From</label><input type="date" class="db-input" id="filterDateFrom" onchange="applyFilters()"></div>
            <div><label class="db-filter-label">Check-in To</label><input type="date" class="db-input" id="filterDateTo" onchange="applyFilters()"></div>
            <div><label class="db-filter-label">Family Size</label><select class="db-select" id="filterFamilySize" onchange="applyFilters()"><option value="">All Sizes</option><option value="1-2">1–2 members</option><option value="3-4">3–4 members</option><option value="5-7">5–7 members</option><option value="8+">8+ members</option></select></div>
            <div><label class="db-filter-label">Special Needs</label><select class="db-select" id="filterSpecialNeeds" onchange="applyFilters()"><option value="">All</option><option value="yes">With Special Needs</option><option value="no">No Special Needs</option></select></div>
            <div style="grid-column:1/-1;"><label class="db-filter-label">Search Name / Address</label><input type="text" class="db-input" id="filterSearch" placeholder="Type to search…" oninput="applyFilters()"></div>
        </div>
        <div class="db-filter-summary">
            <span style="color:var(--db-muted);" id="filterResultsCount">Showing all evacuees</span>
            <div style="display:flex;gap:8px;">
                <button class="db-btn db-btn--outline-sm" onclick="exportFilteredData()"><i class="fas fa-download"></i> Export</button>
                <button class="db-btn db-btn--outline-sm" onclick="printFilteredData()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--violet"><i class="fas fa-list"></i></div><h2>Registered Evacuees</h2><span class="db-badge db-badge--violet" id="tableCount"><?php echo $total_evacuees; ?></span></div>
        <button class="db-btn db-btn--violet db-btn--sm" onclick="openModal('registerModal')"><i class="fas fa-plus"></i> Register</button>
    </div>
    <div class="db-table-wrap"><table class="db-table" id="evacueesTable">
        <thead><tr><th>Name</th><th>Family Members</th><th>Evacuation Center</th><th>Check-in Date</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($evacuees)): ?><tr><td colspan="7"><div class="db-empty"><i class="fas fa-user-plus"></i><p>No evacuees registered yet</p><button class="db-btn db-btn--violet db-btn--sm" onclick="openModal('registerModal')"><i class="fas fa-plus"></i> Register First Evacuee</button></div></td></tr>
        <?php else: foreach($evacuees as $ev):
            $sb=$ev['status']==='Active'?'success':'muted';
        ?>
        <tr data-id="<?php echo $ev['evacuee_id']; ?>">
            <td><strong><?php echo htmlspecialchars($ev['full_name']); ?></strong><?php if(!empty($ev['address'])): ?><br><span class="db-text-sm"><?php echo htmlspecialchars($ev['address']); ?></span><?php endif; ?></td>
            <td><strong><?php echo $ev['family_members']; ?></strong></td>
            <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($ev['center_name']); ?></span></td>
            <td><span class="db-text-sm"><?php echo formatDateTime($ev['check_in_date']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($ev['contact_number']??'N/A'); ?></span></td>
            <td><span class="db-badge db-badge--<?php echo $sb; ?>"><?php echo $ev['status']==='Inactive'?'Checked Out':$ev['status']; ?></span></td>
            <td><div style="display:flex;gap:4px;">
                <button class="db-icon-btn db-icon-btn--default" onclick="viewEvacuee(<?php echo $ev['evacuee_id']; ?>)" title="View"><i class="fas fa-eye"></i></button>
                <?php if($ev['status']==='Active'): ?>
                <button class="db-icon-btn db-icon-btn--success" onclick="showCheckoutModal(<?php echo $ev['evacuee_id']; ?>,'<?php echo htmlspecialchars($ev['full_name'],ENT_QUOTES); ?>')" title="Check Out"><i class="fas fa-sign-out-alt"></i></button>
                <?php endif; ?>
                <button class="db-icon-btn db-icon-btn--rose" onclick="showDeleteModal(<?php echo $ev['evacuee_id']; ?>,'<?php echo htmlspecialchars($ev['full_name'],ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- REGISTER MODAL -->
<div id="registerModal" class="db-modal"><div class="db-modal__box db-modal__box--lg">
    <div class="db-modal__header db-modal__header--violet"><h3><i class="fas fa-user-plus"></i> Register Evacuee</h3><button class="db-modal__close" onclick="closeModal('registerModal')">×</button></div>
    <div class="db-modal__body">
    <?php if(empty($centers)): ?>
    <div class="db-notice--amber"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span><strong>No Centers Available.</strong> There are currently no evacuation centers available. Please add centers first.</span></div>
    <?php else: ?>
    <form method="POST"><input type="hidden" name="action" value="register_evacuee">
        <div class="db-form-grid">
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Resident</label><select name="resident_id" class="db-select" required onchange="populateResidentInfo(this)"><?php foreach($residents as $r): ?><option value="<?php echo $r['resident_id']; ?>" data-name="<?php echo htmlspecialchars($r['full_name'],ENT_QUOTES); ?>"><?php echo htmlspecialchars($r['full_name'].' — '.$r['address']); ?></option><?php endforeach; ?></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Evacuation Center</label>
                <select name="center_id" class="db-select" required id="centerSelect" onchange="updateCenterInfo()"><option value="">Select Center</option><?php foreach($centers as $c): $avail=$c['capacity']-$c['current_evacuees'];$is_full=$avail<=0; ?><option value="<?php echo $c['center_id']; ?>" data-capacity="<?php echo $c['capacity']; ?>" data-current="<?php echo $c['current_evacuees']; ?>" data-available="<?php echo $avail; ?>"<?php echo $is_full?' disabled':''; ?>><?php echo htmlspecialchars($c['center_name']); ?> (<?php echo $c['current_evacuees']; ?>/<?php echo $c['capacity']; ?>)<?php echo $is_full?' — FULL':''; ?></option><?php endforeach; ?></select>
                <div class="db-form-hint" id="centerCapacityInfo"></div>
            </div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Family Head Name</label><input type="text" name="family_head_name" id="familyHeadName" class="db-input" required></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">No. of Family Members</label><input type="number" name="family_members" class="db-input" min="1" required id="familyMembers" oninput="checkCapacity()"><div class="cap-warn" id="capacityWarning"></div></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Check-in Date &amp; Time</label><input type="datetime-local" name="check_in_date" class="db-input" value="<?php echo date('Y-m-d\TH:i'); ?>" required></div>
            <div class="db-form-group"><label class="db-form-label">Contact Number</label><input type="text" name="contact_number" class="db-input" placeholder="09XX-XXX-XXXX"></div>
            <div class="db-form-group full"><label class="db-form-label">Special Needs / Medical Conditions</label><textarea name="special_needs" class="db-textarea" placeholder="List any medical conditions, disabilities, or special requirements…"></textarea></div>
            <div class="db-form-group full"><label class="db-form-label">Notes</label><textarea name="notes" class="db-textarea" placeholder="Additional information…"></textarea></div>
        </div>
        <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('registerModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--violet" id="submitBtn"><i class="fas fa-save"></i> Register Evacuee</button></div>
    </form>
    <?php endif; ?>
    </div>
</div></div>

<!-- VIEW MODAL -->
<div id="viewModal" class="db-modal"><div class="db-modal__box db-modal__box--lg">
    <div class="db-modal__header db-modal__header--sky"><h3><i class="fas fa-user-circle"></i> Evacuee Details</h3><button class="db-modal__close" onclick="closeModal('viewModal')">×</button></div>
    <div class="db-modal__body"><div id="viewContent"></div>
        <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button></div>
    </div>
</div></div>

<!-- CHECKOUT MODAL -->
<div id="checkoutModal" class="db-modal"><div class="db-modal__box db-modal__box--sm">
    <div class="db-modal__header db-modal__header--success"><h3><i class="fas fa-sign-out-alt"></i> Check Out Evacuee</h3><button class="db-modal__close" onclick="closeModal('checkoutModal')">×</button></div>
    <div class="db-modal__body">
        <div class="db-notice--sky"><i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i><span>This will update their status to <strong>Checked Out</strong> and free up space in the evacuation center.</span></div>
        <div class="db-confirm-grid"><div class="db-confirm-row"><span class="lbl">Evacuee</span><span class="val" id="coName"></span></div></div>
        <form method="POST" id="checkoutForm">
            <input type="hidden" name="action" value="checkout_evacuee"><input type="hidden" name="evacuee_id" id="coId">
            <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('checkoutModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Yes, Check Out</button></div>
        </form>
    </div>
</div></div>

<!-- DELETE MODAL -->
<div id="delEvacModal" class="db-modal"><div class="db-modal__box db-modal__box--sm">
    <div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-trash-alt"></i> Delete Evacuee</h3><button class="db-modal__close" onclick="closeModal('delEvacModal')">×</button></div>
    <div class="db-modal__body">
        <div class="db-confirm-grid"><div class="db-confirm-row"><span class="lbl">Evacuee</span><span class="val" id="delEvacName"></span></div></div>
        <div class="db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>This action <strong>cannot be undone</strong>. The evacuee record will be permanently deleted.</span></div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_evacuee"><input type="hidden" name="evacuee_id" id="delEvacId">
            <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('delEvacModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--rose"><i class="fas fa-trash"></i> Yes, Delete</button></div>
        </form>
    </div>
</div></div>

<script>
const evacueesData = <?php echo json_encode($evacuees); ?>;
const centersData = <?php echo json_encode($centers); ?>;

function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});
function esc(t){if(!t)return'';const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function mkBadge(cls,txt){return`<span class="db-badge ${cls}">${txt}</span>`;}

function populateResidentInfo(sel){
    const opt=sel.options[sel.selectedIndex];
    if(opt.value&&document.getElementById('familyHeadName'))document.getElementById('familyHeadName').value=opt.dataset.name||'';
}

function updateCenterInfo(){
    const sel=document.getElementById('centerSelect');
    const opt=sel.options[sel.selectedIndex];
    const info=document.getElementById('centerCapacityInfo');
    if(opt.value){
        const av=parseInt(opt.dataset.available);
        info.textContent=`Available spaces: ${av}`;
        info.style.color=av>0?'var(--db-success)':'var(--db-rose)';
    }else info.textContent='';
    checkCapacity();
}

function checkCapacity(){
    const sel=document.getElementById('centerSelect');
    const fi=document.getElementById('familyMembers');
    const warn=document.getElementById('capacityWarning');
    const btn=document.getElementById('submitBtn');
    const opt=sel.options[sel.selectedIndex];
    if(opt.value&&fi.value){
        const av=parseInt(opt.dataset.available);
        const req=parseInt(fi.value);
        if(req>av){warn.textContent=`Cannot accommodate ${req} members. Only ${av} space(s) available.`;warn.style.display='block';btn.disabled=true;}
        else{warn.style.display='none';btn.disabled=false;}
    }else{warn.style.display='none';if(btn)btn.disabled=false;}
}

const stBadge={'Active':'db-badge--success','Inactive':'db-badge--muted'};
function viewEvacuee(id){
    const e=evacueesData.find(x=>x.evacuee_id==id);if(!e)return;
    const sb=stBadge[e.status]||'db-badge--muted';
    const statusLabel=e.status==='Inactive'?'Checked Out':e.status;
    document.getElementById('viewContent').innerHTML=`
        <div class="db-section-head"><i class="fas fa-user" style="color:var(--db-sky)"></i> Personal Information</div>
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Full Name</span><span class="val">${esc(e.full_name)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Status</span><span class="val">${mkBadge(sb,statusLabel)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Address</span><span class="val">${esc(e.address)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Age</span><span class="val">${e.age?e.age+' years old':'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Gender</span><span class="val">${esc(e.gender)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Contact Number</span><span class="val">${esc(e.contact_number)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Family Members</span><span class="val">${e.family_members} members</span></div>
        </div>
        <div class="db-section-head"><i class="fas fa-home" style="color:var(--db-success)"></i> Evacuation Details</div>
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Evacuation Center</span><span class="val">${esc(e.center_name)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Check-in Date</span><span class="val">${e.check_in_date}</span></div>
            <div class="db-confirm-row"><span class="lbl">Check-out Date</span><span class="val">${e.status==='Inactive'?(e.check_out_date||'Checked Out'):'Still in center'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Registered By</span><span class="val">${esc(e.registered_by_name)||'N/A'}</span></div>
        </div>
        ${(e.special_needs&&e.special_needs.trim())||(e.notes&&e.notes.trim())?`
        <div class="db-section-head"><i class="fas fa-notes-medical" style="color:var(--db-amber-dark)"></i> Special Needs &amp; Notes</div>
        <div class="db-confirm-grid">
            ${e.special_needs&&e.special_needs.trim()?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Special Needs</span><span class="val" style="text-align:left;">${esc(e.special_needs)}</span></div>`:''}
            ${e.notes&&e.notes.trim()?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Notes</span><span class="val" style="text-align:left;">${esc(e.notes)}</span></div>`:''}
        </div>`:''}`;
    openModal('viewModal');
}

function showCheckoutModal(id,name){document.getElementById('coId').value=id;document.getElementById('coName').textContent=name;openModal('checkoutModal');}
function showDeleteModal(id,name){document.getElementById('delEvacId').value=id;document.getElementById('delEvacName').textContent=name;openModal('delEvacModal');}

// Filtering
function applyFilters(){
    const status=document.getElementById('filterStatus').value;
    const center=document.getElementById('filterCenter').value;
    const df=document.getElementById('filterDateFrom').value;
    const dt=document.getElementById('filterDateTo').value;
    const fs=document.getElementById('filterFamilySize').value;
    const sn=document.getElementById('filterSpecialNeeds').value;
    const search=document.getElementById('filterSearch').value.toLowerCase();
    let count=0;
    document.querySelectorAll('#evacueesTable tbody tr[data-id]').forEach(row=>{
        const id=row.dataset.id;
        const e=evacueesData.find(x=>x.evacuee_id==id);
        if(!e){row.style.display='none';return;}
        let show=true;
        if(status){const sc=status==='Checked Out'?'Inactive':status;if(e.status!==sc)show=false;}
        if(center&&e.center_id!=center)show=false;
        if(df&&e.check_in_date.substring(0,10)<df)show=false;
        if(dt&&e.check_in_date.substring(0,10)>dt)show=false;
        if(fs){const m=parseInt(e.family_members);if(fs==='1-2'&&(m<1||m>2))show=false;else if(fs==='3-4'&&(m<3||m>4))show=false;else if(fs==='5-7'&&(m<5||m>7))show=false;else if(fs==='8+'&&m<8)show=false;}
        if(sn){const has=e.special_needs&&e.special_needs.trim();if(sn==='yes'&&!has)show=false;else if(sn==='no'&&has)show=false;}
        if(search&&!(((e.full_name||'')+(e.address||'')+(e.contact_number||'')).toLowerCase()).includes(search))show=false;
        row.style.display=show?'':'none';
        if(show)count++;
    });
    const total=evacueesData.length;
    document.getElementById('filterResultsCount').textContent=count===total?`Showing all ${total} evacuees`:`Showing ${count} of ${total} evacuees`;
    document.getElementById('tableCount').textContent=count;
}

function resetFilters(){['filterStatus','filterCenter','filterFamilySize','filterSpecialNeeds'].forEach(id=>document.getElementById(id).value='');['filterDateFrom','filterDateTo','filterSearch'].forEach(id=>document.getElementById(id).value='');applyFilters();}

function exportFilteredData(){
    const visible=[...document.querySelectorAll('#evacueesTable tbody tr[data-id]')].filter(r=>r.style.display!=='none');
    let csv="Name,Family Members,Evacuation Center,Check-in Date,Contact,Status\n";
    visible.forEach(row=>{const e=evacueesData.find(x=>x.evacuee_id==row.dataset.id);if(e)csv+=`"${e.full_name}","${e.family_members}","${e.center_name}","${e.check_in_date}","${e.contact_number||'N/A'}","${e.status==='Inactive'?'Checked Out':e.status}"\n`;});
    const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='evacuees_'+new Date().toISOString().split('T')[0]+'.csv';document.body.appendChild(a);a.click();document.body.removeChild(a);
}

function printFilteredData(){
    const visible=[...document.querySelectorAll('#evacueesTable tbody tr[data-id]')].filter(r=>r.style.display!=='none');
    let rows=visible.map((row,i)=>{const e=evacueesData.find(x=>x.evacuee_id==row.dataset.id);return e?`<tr><td>${i+1}</td><td>${e.full_name}</td><td>${e.family_members}</td><td>${e.center_name}</td><td>${e.check_in_date}</td><td>${e.contact_number||'N/A'}</td><td>${e.status==='Inactive'?'Checked Out':e.status}</td></tr>`:''}).join('');
    const w=window.open('','','height=700,width=900');
    w.document.write(`<html><head><title>Evacuees Report</title><style>body{font-family:Arial,sans-serif;margin:20px;}h2{text-align:center;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ddd;padding:6px;font-size:12px;}th{background:#f3f4f6;}</style></head><body><h2>Evacuees Report</h2><p style="text-align:right;font-size:12px;">${new Date().toLocaleString()}</p><table><thead><tr><th>#</th><th>Name</th><th>Members</th><th>Center</th><th>Check-in</th><th>Contact</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table></body></html>`);
    w.document.close();w.print();
}

setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>