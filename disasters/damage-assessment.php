<?php
/**
 * Damage Assessment Management
 * Path: barangaylink/disasters/damage-assessment.php
 */
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) { header('Location: ' . APP_URL . '/modules/auth/login.php'); exit(); }
$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin','Super Admin','Staff','Secretary'])) { header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit(); }
$page_title = 'Damage Assessment';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_assessment':
            $resident_id=sanitizeInput($_POST['resident_id']);$disaster_type=sanitizeInput($_POST['disaster_type']);$assessment_date=sanitizeInput($_POST['assessment_date']);$location=sanitizeInput($_POST['location']);$damage_type=sanitizeInput($_POST['damage_type']);$severity=sanitizeInput($_POST['severity']);$estimated_cost=sanitizeInput($_POST['estimated_cost']);$description=sanitizeInput($_POST['description']);$status=sanitizeInput($_POST['status']);
            $rc=fetchOne($conn,"SELECT resident_id FROM tbl_residents WHERE resident_id=?",[$resident_id],'i');
            if(!$rc){setMessage('Invalid resident selected','error');header('Location: damage-assessment.php');exit();}
            $sql="INSERT INTO tbl_damage_assessments (resident_id,disaster_type,assessment_date,location,damage_type,severity,estimated_cost,description,status,assessed_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())";
            if(executeQuery($conn,$sql,[$resident_id,$disaster_type,$assessment_date,$location,$damage_type,$severity,$estimated_cost,$description,$status,getCurrentUserId()],'isssssdssi')){logActivity($conn,getCurrentUserId(),"Added damage assessment for resident ID: $resident_id");setMessage('Damage assessment added successfully','success');}else{setMessage('Failed to add damage assessment','error');}
            break;
        case 'update_assessment':
            $assessment_id=sanitizeInput($_POST['assessment_id']);$disaster_type=sanitizeInput($_POST['disaster_type']);$assessment_date=sanitizeInput($_POST['assessment_date']);$location=sanitizeInput($_POST['location']);$damage_type=sanitizeInput($_POST['damage_type']);$severity=sanitizeInput($_POST['severity']);$estimated_cost=sanitizeInput($_POST['estimated_cost']);$description=sanitizeInput($_POST['description']);$status=sanitizeInput($_POST['status']);
            if($user_role==='Super Admin'&&isset($_POST['assessed_by'])&&!empty($_POST['assessed_by'])){
                $assessed_by=sanitizeInput($_POST['assessed_by']);$user_check=null;$test=fetchOne($conn,"SELECT user_id FROM tbl_users WHERE user_id=? AND status='Active' LIMIT 1",[$assessed_by],'i');
                if($test){foreach(["SELECT u.user_id FROM tbl_users u LEFT JOIN tbl_roles r ON u.role_id=r.role_id WHERE u.user_id=? AND u.status='Active' AND (r.role_name='Tanod' OR r.role_name='Staff')","SELECT user_id FROM tbl_users WHERE user_id=? AND status='Active' AND (role='Tanod' OR role='Staff')","SELECT user_id FROM tbl_users WHERE user_id=? AND status='Active' AND (role_name='Tanod' OR role_name='Staff')"] as $q){try{$user_check=fetchOne($conn,$q,[$assessed_by],'i');if($user_check)break;}catch(Exception $e){continue;}}}
                if(!$user_check){$assessed_by=getCurrentUserId();setMessage('Invalid user selected. Only Tanod or Staff can be assigned. Assessment updated with your account.','warning');}
                $sql="UPDATE tbl_damage_assessments SET disaster_type=?,assessment_date=?,location=?,damage_type=?,severity=?,estimated_cost=?,description=?,status=?,assessed_by=? WHERE assessment_id=?";
                if(executeQuery($conn,$sql,[$disaster_type,$assessment_date,$location,$damage_type,$severity,$estimated_cost,$description,$status,$assessed_by,$assessment_id],'sssssdssii')){logActivity($conn,getCurrentUserId(),"Updated damage assessment ID: $assessment_id");if(isset($user_check))setMessage('Damage assessment updated successfully','success');}else{setMessage('Failed to update damage assessment','error');}
            }else{
                $sql="UPDATE tbl_damage_assessments SET disaster_type=?,assessment_date=?,location=?,damage_type=?,severity=?,estimated_cost=?,description=?,status=? WHERE assessment_id=?";
                if(executeQuery($conn,$sql,[$disaster_type,$assessment_date,$location,$damage_type,$severity,$estimated_cost,$description,$status,$assessment_id],'sssssdssi')){logActivity($conn,getCurrentUserId(),"Updated damage assessment ID: $assessment_id");setMessage('Damage assessment updated successfully','success');}else{setMessage('Failed to update damage assessment','error');}
            }
            break;
        case 'delete_assessment':
            $assessment_id=sanitizeInput($_POST['assessment_id']);
            if(executeQuery($conn,"DELETE FROM tbl_damage_assessments WHERE assessment_id=?",[$assessment_id],'i')){logActivity($conn,getCurrentUserId(),"Deleted damage assessment ID: $assessment_id");setMessage('Damage assessment deleted successfully','success');}else{setMessage('Failed to delete damage assessment','error');}
            break;
    }
    header('Location: damage-assessment.php'); exit();
}

$assessments=fetchAll($conn,"SELECT da.*,CONCAT(r.first_name,' ',r.last_name) as resident_name,r.address,r.contact_number,r.email,u.username as assessed_by_name FROM tbl_damage_assessments da LEFT JOIN tbl_residents r ON da.resident_id=r.resident_id LEFT JOIN tbl_users u ON da.assessed_by=u.user_id ORDER BY da.assessment_date DESC,da.created_at DESC");
$users=[];
if($user_role==='Super Admin'){
    $uc=$conn->query("SHOW COLUMNS FROM tbl_users");$ucols=[];while($row=$uc->fetch_assoc())$ucols[]=$row['Field'];
    $rc2=null;$urj=false;
    if(in_array('role_name',$ucols))$rc2='role_name';elseif(in_array('role',$ucols))$rc2='role';elseif(in_array('role_id',$ucols))$urj=true;
    if($urj){if(in_array('first_name',$ucols)&&in_array('last_name',$ucols))$users=fetchAll($conn,"SELECT u.user_id,CONCAT(u.first_name,' ',u.last_name) as full_name,r.role_name FROM tbl_users u LEFT JOIN tbl_roles r ON u.role_id=r.role_id WHERE u.status='Active' AND (r.role_name='Tanod' OR r.role_name='Staff') ORDER BY u.last_name,u.first_name");
    else $users=fetchAll($conn,"SELECT u.user_id,u.username as full_name,r.role_name FROM tbl_users u LEFT JOIN tbl_roles r ON u.role_id=r.role_id WHERE u.status='Active' AND (r.role_name='Tanod' OR r.role_name='Staff') ORDER BY u.username");}
    elseif($rc2){if(in_array('first_name',$ucols)&&in_array('last_name',$ucols))$users=fetchAll($conn,"SELECT user_id,CONCAT(first_name,' ',last_name) as full_name,$rc2 as role_name FROM tbl_users WHERE status='Active' AND ($rc2='Tanod' OR $rc2='Staff') ORDER BY last_name,first_name");
    elseif(in_array('username',$ucols))$users=fetchAll($conn,"SELECT user_id,username as full_name,$rc2 as role_name FROM tbl_users WHERE status='Active' AND ($rc2='Tanod' OR $rc2='Staff') ORDER BY username");}
}
$residents=fetchAll($conn,"SELECT resident_id,CONCAT(first_name,' ',last_name) as full_name,address FROM tbl_residents ORDER BY last_name,first_name");
$total_assessments=count($assessments);$pending_count=count(array_filter($assessments,fn($a)=>$a['status']==='Pending'));$completed_count=count(array_filter($assessments,fn($a)=>$a['status']==='Completed'));$total_cost=array_sum(array_column($assessments,'estimated_cost'));
include __DIR__ . '/../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-violet:#7c3aed;--db-violet-light:#ede9fe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#7f1d1d 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(225,29,72,.12);}.fm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(245,158,11,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#9f1239,var(--db-rose));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}.db-alert--error{background:var(--db-rose-light);color:#7f1d1d;border-color:var(--db-rose);}.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));color:#fff;}.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.3);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));color:#fff;}.db-btn--teal:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}.db-badge--amber{background:var(--db-amber-light);color:#92400e;}.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-table-wrap{overflow-x:auto;}.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}.db-table tbody tr:last-child{border-bottom:none;}.db-table tbody tr:hover{background:#f5f8ff;}.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}.db-empty i{font-size:44px;color:var(--db-border);}.db-empty p{font-size:14px;color:var(--db-muted);}
.db-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:all .15s;}
.db-icon-btn--default{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-icon-btn--default:hover{background:var(--db-border);color:var(--db-text);}
.db-icon-btn--sky{background:var(--db-sky-light);color:#0369a1;}.db-icon-btn--sky:hover{background:#bae6fd;}.db-icon-btn--amber{background:var(--db-amber-light);color:#92400e;}.db-icon-btn--amber:hover{background:#fde68a;}.db-icon-btn--rose{background:var(--db-rose-light);color:#9f1239;}.db-icon-btn--rose:hover{background:#fecaca;}
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:660px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}.db-modal__box--sm{max-width:440px;}.db-modal__box--lg{max-width:780px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}.db-modal__header--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));}.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}.db-modal__footer{display:flex;gap:10px;margin-top:18px;}.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-form-group{margin-bottom:14px;}.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-input,.db-select,.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;min-height:70px;}.db-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}.db-form-grid .full{grid-column:1/-1;}
.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}.db-confirm-row{display:flex;justify-content:space-between;align-items:flex-start;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;gap:10px;}.db-confirm-row:last-child{border-bottom:none;}.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;white-space:nowrap;flex-shrink:0;}.db-confirm-row .val{font-weight:600;color:var(--db-text);text-align:right;}
.db-notice--rose{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-rose-light);color:#9f1239;margin-bottom:14px;}
.db-section-head{font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.6px;padding:0 0 6px;border-bottom:1px solid var(--db-border);margin:16px 0 10px;display:flex;align-items:center;gap:6px;}.db-section-head:first-child{margin-top:0;}
.db-desc-block{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px 14px;font-size:13px;line-height:1.6;white-space:pre-wrap;}
.db-radio-group{display:flex;flex-direction:column;gap:8px;margin-bottom:14px;}.db-radio-item{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;}.db-radio-item input{accent-color:var(--db-navy-light);}
/* dark mode */
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}body.dark-mode .db-table tbody tr:hover{background:#162032!important;}body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}body.dark-mode .db-form-label{color:#94a3b8!important;}
body.dark-mode .db-confirm-grid,body.dark-mode .db-desc-block{background:#162032!important;border-color:#334155!important;}body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}body.dark-mode .db-confirm-row .val{color:#e2e8f0!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}body.dark-mode .db-empty i{color:#334155!important;}body.dark-mode .db-empty p{color:#64748b!important;}
body.dark-mode .db-section-head{color:#64748b!important;border-bottom-color:#334155!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}.db-form-grid{grid-template-columns:1fr;}.db-form-grid .full{grid-column:1/1;}}
</style>

<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div><div class="fm-hero__ring fm-hero__ring--3"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-house-damage"></i></div>
            <div><div class="fm-hero__title">Damage Assessment</div><div class="fm-hero__sub">Manage and track property damage assessments</div></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="db-btn db-btn--ghost-white" onclick="openModal('printAllModal')"><i class="fas fa-print"></i> Print All</button>
            <button class="db-btn db-btn--rose" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Assessment</button>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">
<?php echo displayMessage(); ?>

<div class="db-stats-row">
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-clipboard-list"></i></div><div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $total_assessments; ?></div><div class="db-stat-card__label">Total Assessments</div></div><div class="db-stat-card__bar db-stat-card__bar--sky"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div><div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $pending_count; ?></div><div class="db-stat-card__label">Pending</div></div><div class="db-stat-card__bar db-stat-card__bar--amber"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div><div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $completed_count; ?></div><div class="db-stat-card__label">Completed</div></div><div class="db-stat-card__bar db-stat-card__bar--success"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-peso-sign"></i></div><div><div class="db-stat-card__num" style="color:var(--db-rose);font-size:16px;">₱<?php echo number_format($total_cost,2); ?></div><div class="db-stat-card__label">Est. Total Cost</div></div><div class="db-stat-card__bar db-stat-card__bar--rose"></div></div>
</div>

<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-list"></i></div><h2>Damage Assessments</h2><span class="db-badge db-badge--rose"><?php echo $total_assessments; ?></span></div>
        <button class="db-btn db-btn--rose db-btn--sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Assessment</button>
    </div>
    <div class="db-table-wrap"><table class="db-table">
        <thead><tr><th>Date</th><th>Resident</th><th>Location</th><th>Disaster Type</th><th>Damage Type</th><th>Severity</th><th>Est. Cost</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($assessments)): ?><tr><td colspan="9"><div class="db-empty"><i class="fas fa-house-damage"></i><p>No damage assessments found</p><button class="db-btn db-btn--rose db-btn--sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add First</button></div></td></tr>
        <?php else: foreach($assessments as $a):
            $sv=['Minor'=>'sky','Moderate'=>'amber','Severe'=>'rose','Critical'=>'rose'];
            $ss=['Pending'=>'amber','In Progress'=>'sky','Completed'=>'success','Cancelled'=>'muted'];
            $svb=$sv[trim($a['severity']??'')]??'muted'; $ssb=$ss[$a['status']]??'muted';
        ?>
        <tr>
            <td><span class="db-text-sm"><?php echo formatDate($a['assessment_date']); ?></span></td>
            <td><strong><?php echo htmlspecialchars($a['resident_name']); ?></strong><br><span class="db-text-sm"><?php echo htmlspecialchars($a['address']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($a['location']); ?></span></td>
            <td><span class="db-badge db-badge--muted"><?php echo htmlspecialchars($a['disaster_type']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($a['damage_type']); ?></span></td>
            <td><span class="db-badge db-badge--<?php echo $svb; ?>"><?php echo !empty(trim($a['severity']??''))?$a['severity']:'Not Set'; ?></span></td>
            <td><span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;">₱<?php echo number_format($a['estimated_cost'],2); ?></span></td>
            <td><span class="db-badge db-badge--<?php echo $ssb; ?>"><?php echo $a['status']; ?></span></td>
            <td><div style="display:flex;gap:4px;">
                <button class="db-icon-btn db-icon-btn--default" onclick="viewAssessment(<?php echo $a['assessment_id']; ?>)" title="View"><i class="fas fa-eye"></i></button>
                <button class="db-icon-btn db-icon-btn--sky" onclick="printAssessmentDirect(<?php echo $a['assessment_id']; ?>)" title="Print"><i class="fas fa-print"></i></button>
                <button class="db-icon-btn db-icon-btn--amber" onclick="editAssessment(<?php echo $a['assessment_id']; ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="db-icon-btn db-icon-btn--rose" onclick="deleteAssessment(<?php echo $a['assessment_id']; ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ADD -->
<div id="addModal" class="db-modal"><div class="db-modal__box db-modal__box--lg">
    <div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-plus-circle"></i> Add Damage Assessment</h3><button class="db-modal__close" onclick="closeModal('addModal')">×</button></div>
    <div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="add_assessment">
        <div class="db-form-grid">
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Resident</label><select name="resident_id" class="db-select" required><option value="">Select Resident</option><?php foreach($residents as $r): ?><option value="<?php echo $r['resident_id']; ?>"><?php echo htmlspecialchars($r['full_name'].' — '.$r['address']); ?></option><?php endforeach; ?></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Assessment Date</label><input type="date" name="assessment_date" class="db-input" required value="<?php echo date('Y-m-d'); ?>"></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Disaster Type</label><select name="disaster_type" class="db-select" required><option value="">Select Type</option><?php foreach(['Flood','Fire','Earthquake','Typhoon','Landslide','Storm','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Specific Location</label><input type="text" name="location" class="db-input" required placeholder="e.g., Zone 1, Street Name"></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Damage Type</label><select name="damage_type" class="db-select" required><option value="">Select Damage Type</option><?php foreach(['Structural','Property','Agricultural','Infrastructure','Personal Belongings','Livelihood','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Severity</label><select name="severity" class="db-select" required><option value="">Select Severity</option><option value="Minor">Minor — Low impact</option><option value="Moderate">Moderate — Significant damage</option><option value="Severe">Severe — Major damage</option><option value="Critical">Critical — Catastrophic</option></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Estimated Cost (₱)</label><input type="number" name="estimated_cost" class="db-input" required min="0" step="0.01" value="0"></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Status</label><select name="status" class="db-select" required><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Completed">Completed</option></select></div>
            <div class="db-form-group full"><label class="db-form-label">Description</label><textarea name="description" class="db-textarea" placeholder="Detailed description of the damage..."></textarea></div>
        </div>
        <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--rose"><i class="fas fa-save"></i> Save Assessment</button></div>
    </form></div>
</div></div>

<!-- EDIT -->
<div id="editModal" class="db-modal"><div class="db-modal__box db-modal__box--lg">
    <div class="db-modal__header db-modal__header--amber"><h3><i class="fas fa-edit"></i> Edit Damage Assessment</h3><button class="db-modal__close" onclick="closeModal('editModal')">×</button></div>
    <div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="update_assessment"><input type="hidden" name="assessment_id" id="edit_assessment_id">
        <div class="db-form-grid">
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Disaster Type</label><select name="disaster_type" id="edit_disaster_type" class="db-select" required><?php foreach(['Flood','Fire','Earthquake','Typhoon','Landslide','Storm','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Assessment Date</label><input type="date" name="assessment_date" id="edit_assessment_date" class="db-input" required></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Location</label><input type="text" name="location" id="edit_location" class="db-input" required></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Damage Type</label><select name="damage_type" id="edit_damage_type" class="db-select" required><?php foreach(['Structural','Property','Agricultural','Infrastructure','Personal Belongings','Livelihood','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Severity</label><select name="severity" id="edit_severity" class="db-select" required><option value="Minor">Minor</option><option value="Moderate">Moderate</option><option value="Severe">Severe</option><option value="Critical">Critical</option></select></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Estimated Cost (₱)</label><input type="number" name="estimated_cost" id="edit_estimated_cost" class="db-input" required min="0" step="0.01"></div>
            <div class="db-form-group"><label class="db-form-label db-form-label--req">Status</label><select name="status" id="edit_status" class="db-select" required><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div>
            <?php if($user_role==='Super Admin'): ?>
            <div class="db-form-group"><label class="db-form-label">Assessed By (Tanod/Staff)</label><select name="assessed_by" id="edit_assessed_by" class="db-select"><option value="">-- Keep Current --</option><?php foreach($users as $u): ?><option value="<?php echo $u['user_id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?><?php if(isset($u['role_name'])): ?> (<?php echo htmlspecialchars($u['role_name']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="db-form-group full"><label class="db-form-label">Description</label><textarea name="description" id="edit_description" class="db-textarea"></textarea></div>
        </div>
        <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--amber" style="color:#fff"><i class="fas fa-save"></i> Update Assessment</button></div>
    </form></div>
</div></div>

<!-- VIEW -->
<div id="viewModal" class="db-modal"><div class="db-modal__box db-modal__box--lg">
    <div class="db-modal__header db-modal__header--sky"><h3><i class="fas fa-eye"></i> Assessment Details</h3><button class="db-modal__close" onclick="closeModal('viewModal')">×</button></div>
    <div class="db-modal__body"><div id="viewContent"></div>
        <div class="db-modal__footer">
            <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
            <button type="button" class="db-btn db-btn--sky" onclick="printFromView()"><i class="fas fa-print"></i> Print</button>
            <button type="button" class="db-btn db-btn--amber" style="color:#fff" onclick="editFromView()"><i class="fas fa-edit"></i> Edit</button>
        </div>
    </div>
</div></div>

<!-- DELETE -->
<div id="deleteModal" class="db-modal"><div class="db-modal__box db-modal__box--sm">
    <div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-trash-alt"></i> Confirm Deletion</h3><button class="db-modal__close" onclick="closeModal('deleteModal')">×</button></div>
    <div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="delete_assessment"><input type="hidden" name="assessment_id" id="del_id">
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Resident</span><span class="val" id="del_res"></span></div>
            <div class="db-confirm-row"><span class="lbl">Location</span><span class="val" id="del_loc"></span></div>
            <div class="db-confirm-row"><span class="lbl">Disaster Type</span><span class="val" id="del_dis"></span></div>
            <div class="db-confirm-row"><span class="lbl">Est. Cost</span><span class="val" id="del_cst"></span></div>
        </div>
        <div class="db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>This action <strong>cannot be undone</strong>. All data will be permanently deleted.</span></div>
        <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--rose"><i class="fas fa-trash"></i> Yes, Delete</button></div>
    </form></div>
</div></div>

<!-- PRINT ALL -->
<div id="printAllModal" class="db-modal"><div class="db-modal__box db-modal__box--sm">
    <div class="db-modal__header db-modal__header--teal"><h3><i class="fas fa-print"></i> Print Assessment Reports</h3><button class="db-modal__close" onclick="closeModal('printAllModal')">×</button></div>
    <div class="db-modal__body"><form action="print-all-assessments.php" method="POST" target="_blank">
        <div class="db-form-group"><label class="db-form-label">Filter Options</label>
            <div class="db-radio-group">
                <label class="db-radio-item"><input type="radio" name="filter_type" value="all" checked> Print All Assessments</label>
                <label class="db-radio-item"><input type="radio" name="filter_type" value="status"> Filter by Status</label>
                <label class="db-radio-item"><input type="radio" name="filter_type" value="severity"> Filter by Severity</label>
                <label class="db-radio-item"><input type="radio" name="filter_type" value="date"> Filter by Date Range</label>
            </div>
        </div>
        <div id="pf_status" style="display:none;" class="db-form-group"><label class="db-form-label">Status</label><select name="status" class="db-select"><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div>
        <div id="pf_severity" style="display:none;" class="db-form-group"><label class="db-form-label">Severity</label><select name="severity" class="db-select"><option value="Minor">Minor</option><option value="Moderate">Moderate</option><option value="Severe">Severe</option><option value="Critical">Critical</option></select></div>
        <div id="pf_date" style="display:none;"><div class="db-form-grid"><div class="db-form-group"><label class="db-form-label">From</label><input type="date" name="date_from" class="db-input"></div><div class="db-form-group"><label class="db-form-label">To</label><input type="date" name="date_to" class="db-input"></div></div></div>
        <div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('printAllModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--teal"><i class="fas fa-print"></i> Generate Report</button></div>
    </form></div>
</div></div>

<script>
const assessmentsData = <?php echo json_encode($assessments); ?>;
let currentViewingId = null;
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});
function esc(t){if(!t)return'';const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
const svBadge={Minor:'db-badge--sky',Moderate:'db-badge--amber',Severe:'db-badge--rose',Critical:'db-badge--rose'};
const stBadge={Pending:'db-badge--amber','In Progress':'db-badge--sky',Completed:'db-badge--success',Cancelled:'db-badge--muted'};
function mkBadge(cls,txt){return`<span class="db-badge ${cls}">${txt||'—'}</span>`;}
function fmtCost(v){return'₱'+Number(v).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}

function viewAssessment(id){
    const a=assessmentsData.find(x=>x.assessment_id==id);if(!a)return;
    currentViewingId=id;
    document.getElementById('viewContent').innerHTML=`
        <div class="db-section-head"><i class="fas fa-user" style="color:var(--db-sky)"></i> Resident Information</div>
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Name</span><span class="val">${esc(a.resident_name)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Address</span><span class="val">${esc(a.address)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Contact</span><span class="val">${esc(a.contact_number)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Email</span><span class="val">${esc(a.email)||'N/A'}</span></div>
        </div>
        <div class="db-section-head"><i class="fas fa-house-damage" style="color:var(--db-rose)"></i> Assessment Details</div>
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Date</span><span class="val">${a.assessment_date}</span></div>
            <div class="db-confirm-row"><span class="lbl">Disaster Type</span><span class="val">${mkBadge('db-badge--muted',esc(a.disaster_type))}</span></div>
            <div class="db-confirm-row"><span class="lbl">Location</span><span class="val">${esc(a.location)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Damage Type</span><span class="val">${esc(a.damage_type)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Severity</span><span class="val">${mkBadge(svBadge[a.severity]||'db-badge--muted',a.severity||'Not Set')}</span></div>
            <div class="db-confirm-row"><span class="lbl">Est. Cost</span><span class="val" style="font-family:'DM Mono',monospace;color:var(--db-rose)">${fmtCost(a.estimated_cost)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Status</span><span class="val">${mkBadge(stBadge[a.status]||'db-badge--muted',a.status)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Assessed By</span><span class="val">${esc(a.assessed_by_name)||'N/A'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Created At</span><span class="val">${a.created_at||'N/A'}</span></div>
        </div>
        ${a.description&&a.description.trim()?`<div class="db-section-head"><i class="fas fa-file-alt" style="color:var(--db-indigo)"></i> Description</div><div class="db-desc-block">${esc(a.description)}</div>`:''}`;
    openModal('viewModal');
}
function editAssessment(id){
    const a=assessmentsData.find(x=>x.assessment_id==id);if(!a)return;
    document.getElementById('edit_assessment_id').value=a.assessment_id;
    document.getElementById('edit_disaster_type').value=a.disaster_type;
    document.getElementById('edit_assessment_date').value=a.assessment_date;
    document.getElementById('edit_location').value=a.location;
    document.getElementById('edit_damage_type').value=a.damage_type;
    document.getElementById('edit_severity').value=a.severity||'Minor';
    document.getElementById('edit_estimated_cost').value=a.estimated_cost;
    document.getElementById('edit_status').value=a.status;
    document.getElementById('edit_description').value=a.description||'';
    <?php if($user_role==='Super Admin'): ?>if(document.getElementById('edit_assessed_by'))document.getElementById('edit_assessed_by').value=a.assessed_by||'';<?php endif; ?>
    openModal('editModal');
}
function deleteAssessment(id){
    const a=assessmentsData.find(x=>x.assessment_id==id);if(!a)return;
    document.getElementById('del_id').value=id;
    document.getElementById('del_res').textContent=a.resident_name||'N/A';
    document.getElementById('del_loc').textContent=a.location||'N/A';
    document.getElementById('del_dis').textContent=a.disaster_type||'N/A';
    document.getElementById('del_cst').textContent=fmtCost(a.estimated_cost);
    openModal('deleteModal');
}
function printAssessmentDirect(id){window.open('print-assessment.php?id='+id,'_blank');}
function printFromView(){if(currentViewingId)printAssessmentDirect(currentViewingId);}
function editFromView(){if(currentViewingId){closeModal('viewModal');setTimeout(()=>editAssessment(currentViewingId),300);}}
document.querySelectorAll('input[name="filter_type"]').forEach(r=>r.addEventListener('change',function(){['pf_status','pf_severity','pf_date'].forEach(i=>document.getElementById(i).style.display='none');if(this.value==='status')document.getElementById('pf_status').style.display='block';else if(this.value==='severity')document.getElementById('pf_severity').style.display='block';else if(this.value==='date')document.getElementById('pf_date').style.display='block';}));
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>