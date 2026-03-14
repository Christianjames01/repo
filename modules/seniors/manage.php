<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Senior Citizen Management';
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register_senior') {
        $resident_id=$_POST['resident_id'];$pension_type=$_POST['pension_type'];
        $medical_conditions=sanitizeInput($_POST['medical_conditions']??'');
        $emergency_contact=sanitizeInput($_POST['emergency_contact']);
        $emergency_contact_number=sanitizeInput($_POST['emergency_contact_number']);
        $registration_date=$_POST['registration_date']??date('Y-m-d');
        $check=fetchOne($conn,"SELECT senior_id FROM tbl_senior_citizens WHERE resident_id=?",[$resident_id],'i');
        if ($check) $error_message="This resident is already registered as a senior citizen.";
        else { $sql="INSERT INTO tbl_senior_citizens (resident_id,pension_type,medical_conditions,emergency_contact,emergency_contact_number,registration_date) VALUES (?,?,?,?,?,?)";
            if (executeQuery($conn,$sql,[$resident_id,$pension_type,$medical_conditions,$emergency_contact,$emergency_contact_number,$registration_date],'isssss')) $success_message="Senior citizen registered successfully!";
            else $error_message="Failed to register senior citizen."; }
    }
    elseif ($action === 'manual_add_senior') {
        $first_name=sanitizeInput($_POST['first_name']);$middle_name=sanitizeInput($_POST['middle_name']??'');$last_name=sanitizeInput($_POST['last_name']);
        $birth_date=$_POST['birth_date'];$gender=$_POST['gender'];$address=sanitizeInput($_POST['address']);
        $contact_number=sanitizeInput($_POST['contact_number']??'');$pension_type=$_POST['pension_type'];
        $medical_conditions=sanitizeInput($_POST['medical_conditions']??'');$emergency_contact=sanitizeInput($_POST['emergency_contact']);
        $emergency_contact_number=sanitizeInput($_POST['emergency_contact_number']);$registration_date=$_POST['registration_date']??date('Y-m-d');
        $age=date_diff(date_create($birth_date),date_create('today'))->y;
        if ($age<60) $error_message="Person must be at least 60 years old.";
        else {
            $rsql="INSERT INTO tbl_residents (first_name,middle_name,last_name,birth_date,gender,address,contact_number,created_at) VALUES (?,?,?,?,?,?,?,NOW())";
            if (executeQuery($conn,$rsql,[$first_name,$middle_name,$last_name,$birth_date,$gender,$address,$contact_number],'sssssss')) {
                $rid=$conn->insert_id;
                $ssql="INSERT INTO tbl_senior_citizens (resident_id,pension_type,medical_conditions,emergency_contact,emergency_contact_number,registration_date) VALUES (?,?,?,?,?,?)";
                if (executeQuery($conn,$ssql,[$rid,$pension_type,$medical_conditions,$emergency_contact,$emergency_contact_number,$registration_date],'isssss')) $success_message="Senior citizen added successfully!";
                else $error_message="Failed to register as senior citizen.";
            } else $error_message="Failed to create resident record.";
        }
    }
    elseif ($action === 'add_benefit') {
        $senior_id=intval($_POST['senior_id']);$benefit_type=$_POST['benefit_type'];$amount=floatval($_POST['amount']);
        $benefit_date=$_POST['benefit_date'];$remarks=sanitizeInput($_POST['remarks']??'');$user_id=$_SESSION['user_id'];
        $sql="INSERT INTO tbl_senior_benefits (senior_id,benefit_type,amount,benefit_date,released_by,remarks) VALUES (?,?,?,?,?,?)";
        if (executeQuery($conn,$sql,[$senior_id,$benefit_type,$amount,$benefit_date,$user_id,$remarks],'isdsss')) {
            $success_message="Benefit recorded successfully!";
            $sd=fetchOne($conn,"SELECT resident_id FROM tbl_senior_citizens WHERE senior_id=?",[$senior_id],'i');
            if ($sd){$rd=fetchOne($conn,"SELECT user_id FROM tbl_users WHERE resident_id=?",[$sd['resident_id']],'i');if($rd)createNotification($conn,$rd['user_id'],'Senior Benefit Released',"You have received a {$benefit_type} worth ₱".number_format($amount,2),'benefit',$senior_id,'senior_benefit');}
        } else $error_message="Failed to record benefit.";
    }

    if ($success_message||$error_message) { $_SESSION['temp_success']=$success_message;$_SESSION['temp_error']=$error_message;header('Location: '.$_SERVER['PHP_SELF']);exit(); }
}

if (isset($_SESSION['temp_success'])){$success_message=$_SESSION['temp_success'];unset($_SESSION['temp_success']);}
if (isset($_SESSION['temp_error'])){$error_message=$_SESSION['temp_error'];unset($_SESSION['temp_error']);}

$seniors=fetchAll($conn,"SELECT sc.*,r.first_name,r.last_name,r.middle_name,r.birth_date,r.contact_number,r.address, TIMESTAMPDIFF(YEAR,r.birth_date,CURDATE()) as age,(SELECT SUM(amount) FROM tbl_senior_benefits WHERE senior_id=sc.senior_id) as total_benefits FROM tbl_senior_citizens sc JOIN tbl_residents r ON sc.resident_id=r.resident_id WHERE sc.is_active=1 ORDER BY r.last_name,r.first_name");
$eligible_residents=fetchAll($conn,"SELECT r.resident_id,r.first_name,r.last_name,r.middle_name,r.birth_date, TIMESTAMPDIFF(YEAR,r.birth_date,CURDATE()) as age FROM tbl_residents r LEFT JOIN tbl_senior_citizens sc ON r.resident_id=sc.resident_id WHERE TIMESTAMPDIFF(YEAR,r.birth_date,CURDATE())>=60 AND sc.senior_id IS NULL ORDER BY r.last_name,r.first_name");

$stats=['total_seniors'=>count($seniors),'eligible_not_registered'=>count($eligible_residents),'total_benefits_this_month'=>0,'total_amount_this_month'=>0];
$br=fetchOne($conn,"SELECT COUNT(*) as count,SUM(amount) as total FROM tbl_senior_benefits WHERE MONTH(benefit_date)=MONTH(CURRENT_DATE()) AND YEAR(benefit_date)=YEAR(CURRENT_DATE())");
$stats['total_benefits_this_month']=$br['count']??0;$stats['total_amount_this_month']=$br['total']??0;

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-violet:#7c3aed;--db-violet-light:#ede9fe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(124,58,237,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#5b21b6,var(--db-violet));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(124,58,237,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--violet{background:linear-gradient(90deg,var(--db-violet),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{color:#fff;transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);}
.db-btn--violet{background:linear-gradient(135deg,#5b21b6,var(--db-violet));color:#fff;}
.db-btn--violet:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(124,58,237,.3);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--violet{background:var(--db-violet-light);color:#5b21b6;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-info-banner{display:flex;align-items:center;gap:12px;padding:13px 18px;border-radius:var(--db-radius);background:var(--db-amber-light);border-left:4px solid var(--db-amber);color:var(--db-amber-dark);font-size:13px;font-weight:500;margin-bottom:14px;}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-resident-name{font-size:13px;font-weight:600;margin:0 0 2px;}
.db-resident-addr{font-size:11px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
.db-icon-btn{padding:6px 8px;border:none;background:transparent;color:var(--db-muted);cursor:pointer;border-radius:6px;transition:all .15s;font-size:13px;}
.db-icon-btn:hover{background:var(--db-surf2);color:var(--db-text);}
.db-icon-btn.violet:hover{background:var(--db-violet-light);color:var(--db-violet);}
.db-icon-btn.success:hover{background:var(--db-success-light);color:var(--db-success);}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box--lg{max-width:700px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;position:sticky;top:0;z-index:10;}
.db-modal__header--violet{background:linear-gradient(135deg,#5b21b6,var(--db-violet));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}
.db-section-title{color:var(--db-navy);font-size:.82rem;font-weight:700;margin:14px 0 8px;padding-bottom:5px;border-bottom:2px solid var(--db-border);display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.3px;}
.db-form-group{margin-bottom:12px;}
.db-form-group label{display:block;font-size:11px;font-weight:700;color:var(--db-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;}
.db-input{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;width:100%;}
.db-input:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.db-required{color:var(--db-rose);}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}

/* View details grid */
.db-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.db-detail-item label{font-size:10px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;display:block;}
.db-detail-item p{font-size:13px;font-weight:600;margin:0;}
.db-benefit-summary{background:linear-gradient(135deg,var(--db-success-light),#ecfdf5);border-radius:var(--db-radius);padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid rgba(16,185,129,.2);}
.db-benefit-recent{max-height:180px;overflow-y:auto;border-radius:var(--db-radius-sm);border:1px solid var(--db-border);}
.db-benefit-recent table{width:100%;border-collapse:collapse;font-size:12px;}
.db-benefit-recent th{background:var(--db-surf2);padding:8px 12px;font-size:10px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;}
.db-benefit-recent td{padding:8px 12px;border-top:1px solid var(--db-border);}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-form-row{grid-template-columns:1fr;}.db-detail-grid{grid-template-columns:1fr;}}
/* ══════════════════════════════════════
   DARK MODE — seniors/manage.php
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }

/* Alerts */
body.dark-mode .db-alert--success { background: #064e3b !important; color: #6ee7b7 !important; border-color: #10b981 !important; }
body.dark-mode .db-alert--error   { background: #450a0a !important; color: #fca5a5 !important; border-color: #ef4444 !important; }

/* Stat cards */
body.dark-mode .db-stat-card { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-stat-card:hover { background: #243044 !important; }
body.dark-mode .db-stat-card__label { color: #94a3b8 !important; }

/* Panels */
body.dark-mode .db-panel { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-panel__header { border-color: #334155 !important; }
body.dark-mode .db-panel__title h2 { color: #f1f5f9 !important; }

/* Info banner */
body.dark-mode .db-info-banner {
    background: rgba(180,83,9,.18) !important;
    border-color: var(--db-amber) !important;
    color: #fcd34d !important;
}

/* Table */
body.dark-mode .db-table tbody tr { border-color: #334155 !important; }
body.dark-mode .db-table tbody tr:hover { background: #243044 !important; }
body.dark-mode .db-table tbody td { color: #e2e8f0 !important; }
body.dark-mode .db-resident-name { color: #f1f5f9 !important; }
body.dark-mode .db-resident-addr { color: #64748b !important; }

/* Badges */
body.dark-mode .db-badge--muted {
    background: #243044 !important;
    color: #94a3b8 !important;
    border-color: #334155 !important;
}

/* Icon buttons */
body.dark-mode .db-icon-btn { color: #64748b !important; }
body.dark-mode .db-icon-btn:hover { background: #243044 !important; color: #e2e8f0 !important; }
body.dark-mode .db-icon-btn.violet:hover { background: rgba(124,58,237,.18) !important; color: #c4b5fd !important; }
body.dark-mode .db-icon-btn.success:hover { background: rgba(16,185,129,.18) !important; color: #6ee7b7 !important; }

/* Ghost button */
body.dark-mode .db-btn--ghost {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-mode .db-btn--ghost:hover {
    background: #2d3f58 !important;
    color: #e2e8f0 !important;
}

/* Empty state */
body.dark-mode .db-empty i { color: #334155 !important; }
body.dark-mode .db-empty p { color: #64748b !important; }

/* ── Modals ── */
body.dark-mode .db-modal { background: rgba(0,0,0,.7) !important; }
body.dark-mode .db-modal__box { background: #1e293b !important; }

/* Section titles inside modals */
body.dark-mode .db-section-title {
    color: #a5b4fc !important;
    border-color: #334155 !important;
}

/* Form inputs inside modals */
body.dark-mode .db-input {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-input:focus {
    background: #1e293b !important;
    border-color: var(--db-indigo) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,.2) !important;
}
body.dark-mode .db-input::placeholder { color: #64748b !important; }
body.dark-mode .db-input[readonly] {
    background: #162032 !important;
    color: #94a3b8 !important;
}
body.dark-mode .db-form-group label { color: #64748b !important; }
body.dark-mode .db-required { color: #fda4af !important; }

/* Modal body text */
body.dark-mode .db-modal__body p[style*="color:var(--db-muted)"] { color: #64748b !important; }

/* View details modal — detail grid */
body.dark-mode .db-detail-item label { color: #64748b !important; }
body.dark-mode .db-detail-item p { color: #e2e8f0 !important; }

/* Benefit summary banner */
body.dark-mode .db-benefit-summary {
    background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(16,185,129,.06)) !important;
    border-color: rgba(16,185,129,.25) !important;
}
body.dark-mode .db-benefit-summary span[style*="color:var(--db-muted)"] { color: #94a3b8 !important; }

/* Benefit recent table */
body.dark-mode .db-benefit-recent {
    border-color: #334155 !important;
}
body.dark-mode .db-benefit-recent th {
    background: #162032 !important;
    color: #64748b !important;
}
body.dark-mode .db-benefit-recent td {
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

/* Hero actions button */
body.dark-mode .rm-hero .db-btn--violet {
    background: linear-gradient(135deg, #4c1d95, #7c3aed) !important;
    color: #fff !important;
}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-user-friends"></i></div>
            <div>
                <div class="rm-hero__title">Senior Citizen Management</div>
                <div class="rm-hero__sub">Auto-detection for residents 60 years and older</div>
            </div>
        </div>
        <button class="db-btn db-btn--violet" onclick="openModal('manualAddModal')"><i class="fas fa-user-plus"></i> Manual Add Senior</button>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($success_message): ?><div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($success_message); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
<?php if ($error_message): ?><div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error_message); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--violet"><i class="fas fa-user-friends"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total_seniors']; ?></div><div class="db-stat-card__label">Registered Seniors</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--violet"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-user-plus"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['eligible_not_registered']; ?></div><div class="db-stat-card__label">Eligible (Not Registered)</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-gift"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['total_benefits_this_month']; ?></div><div class="db-stat-card__label">Benefits This Month</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-peso-sign"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success);font-size:20px;">₱<?php echo number_format($stats['total_amount_this_month'],0); ?></div><div class="db-stat-card__label">Amount Released</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
</div>

<!-- Eligible Residents Panel -->
<?php if (!empty($eligible_residents)): ?>
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-exclamation-circle"></i></div>
            <h2>Auto-Detected Eligible Residents (60+)</h2>
            <span class="db-badge db-badge--amber"><?php echo count($eligible_residents); ?> Unregistered</span>
        </div>
    </div>
    <div style="padding:14px 22px;">
        <div class="db-info-banner">
            <i class="fas fa-info-circle"></i>
            <span><?php echo count($eligible_residents); ?> resident(s) qualify for senior citizen registration based on age. Click <strong>Register</strong> to enroll them.</span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Resident</th><th>Age</th><th>Birth Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($eligible_residents as $r): ?>
            <tr>
                <td><span class="db-resident-name"><?php echo htmlspecialchars($r['first_name'].' '.($r['middle_name']??'').' '.$r['last_name']); ?></span></td>
                <td><span class="db-badge db-badge--amber"><i class="fas fa-birthday-cake"></i> <?php echo $r['age']; ?> years</span></td>
                <td><span style="font-size:12px;color:var(--db-muted)"><i class="fas fa-calendar-alt" style="margin-right:4px"></i><?php echo date('F d, Y',strtotime($r['birth_date'])); ?></span></td>
                <td><button class="db-btn db-btn--primary db-btn--sm" onclick='registerSenior(<?php echo json_encode($r); ?>)'><i class="fas fa-user-check"></i> Register</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Registered Seniors Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--violet"><i class="fas fa-users"></i></div>
            <h2>Registered Senior Citizens</h2>
            <span class="db-badge db-badge--violet"><?php echo count($seniors); ?></span>
        </div>
    </div>
    <?php if (empty($seniors)): ?>
    <div class="db-empty">
        <i class="fas fa-user-friends"></i>
        <p>No registered senior citizens yet</p>
        <?php if (!empty($eligible_residents)): ?><p style="font-size:12px;color:var(--db-muted)">Register eligible residents above to get started</p><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Senior Citizen</th><th>Age</th><th>Contact</th><th>Pension</th><th>Total Benefits</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($seniors as $s): ?>
            <tr>
                <td>
                    <div class="db-resident-name"><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></div>
                    <div class="db-resident-addr"><?php echo htmlspecialchars($s['address']); ?></div>
                </td>
                <td><span class="db-badge db-badge--violet"><i class="fas fa-user"></i> <?php echo $s['age']; ?> yrs</span></td>
                <td><span style="font-family:'DM Mono',monospace;font-size:12px"><?php echo htmlspecialchars($s['contact_number']??'—'); ?></span></td>
                <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($s['pension_type']); ?></span></td>
                <td><strong style="color:var(--db-success)">₱<?php echo number_format($s['total_benefits']??0,2); ?></strong></td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <button class="db-btn db-btn--success db-btn--sm" onclick='addBenefit(<?php echo json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-gift"></i> Benefit</button>
                        <button class="db-icon-btn violet" onclick='viewSenior(<?php echo json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="View Details"><i class="fas fa-eye"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- REGISTER SENIOR MODAL -->
<div id="registerModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-user-check"></i> Register Senior Citizen</h3>
            <button class="db-modal__close" onclick="closeModal('registerModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="register_senior">
                <input type="hidden" name="resident_id" id="register_resident_id">
                <div class="db-form-row" style="margin-bottom:12px;">
                    <div class="db-form-group"><label>Full Name</label><input type="text" id="register_name" class="db-input" readonly></div>
                    <div class="db-form-group"><label>Age</label><input type="text" id="register_age" class="db-input" readonly></div>
                </div>
                <div class="db-form-group"><label>Pension Type <span class="db-required">*</span></label><select name="pension_type" class="db-input" required><option value="None">None</option><option value="SSS">SSS</option><option value="GSIS">GSIS</option><option value="Other">Other</option></select></div>
                <div class="db-form-group"><label>Medical Conditions</label><textarea name="medical_conditions" class="db-input" rows="2" placeholder="List any known medical conditions…"></textarea></div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Emergency Contact <span class="db-required">*</span></label><input type="text" name="emergency_contact" class="db-input" required></div>
                    <div class="db-form-group"><label>Emergency Number <span class="db-required">*</span></label><input type="text" name="emergency_contact_number" class="db-input" required></div>
                </div>
                <div class="db-form-group"><label>Registration Date <span class="db-required">*</span></label><input type="date" name="registration_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('registerModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-user-check"></i> Register Senior</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MANUAL ADD MODAL -->
<div id="manualAddModal" class="db-modal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--violet">
            <h3><i class="fas fa-user-plus"></i> Manually Add Senior Citizen</h3>
            <button class="db-modal__close" onclick="closeModal('manualAddModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <p style="font-size:12px;color:var(--db-muted);margin:0 0 14px">For seniors not yet registered in the system.</p>
                <input type="hidden" name="action" value="manual_add_senior">
                <div class="db-section-title"><i class="fas fa-user"></i> Personal Information</div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>First Name <span class="db-required">*</span></label><input type="text" name="first_name" class="db-input" required></div>
                    <div class="db-form-group"><label>Last Name <span class="db-required">*</span></label><input type="text" name="last_name" class="db-input" required></div>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Middle Name</label><input type="text" name="middle_name" class="db-input"></div>
                    <div class="db-form-group"><label>Birth Date <span class="db-required">*</span></label><input type="date" name="birth_date" class="db-input" max="<?php echo date('Y-m-d',strtotime('-60 years')); ?>" required></div>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Gender <span class="db-required">*</span></label><select name="gender" class="db-input" required><option value="">Select</option><option>Male</option><option>Female</option></select></div>
                    <div class="db-form-group"><label>Contact Number</label><input type="text" name="contact_number" class="db-input" placeholder="09XXXXXXXXX"></div>
                </div>
                <div class="db-form-group"><label>Address <span class="db-required">*</span></label><textarea name="address" class="db-input" rows="2" required></textarea></div>
                <div class="db-section-title"><i class="fas fa-id-card"></i> Senior Registration</div>
                <div class="db-form-group"><label>Pension Type <span class="db-required">*</span></label><select name="pension_type" class="db-input" required><option value="None">None</option><option value="SSS">SSS</option><option value="GSIS">GSIS</option><option value="Other">Other</option></select></div>
                <div class="db-form-group"><label>Medical Conditions</label><textarea name="medical_conditions" class="db-input" rows="2" placeholder="List any known medical conditions…"></textarea></div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Emergency Contact <span class="db-required">*</span></label><input type="text" name="emergency_contact" class="db-input" required></div>
                    <div class="db-form-group"><label>Emergency Number <span class="db-required">*</span></label><input type="text" name="emergency_contact_number" class="db-input" required></div>
                </div>
                <div class="db-form-group"><label>Registration Date <span class="db-required">*</span></label><input type="date" name="registration_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('manualAddModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--violet"><i class="fas fa-user-plus"></i> Add Senior Citizen</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ADD BENEFIT MODAL -->
<div id="benefitModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--success">
            <h3><i class="fas fa-gift"></i> Add Benefit / Allowance</h3>
            <button class="db-modal__close" onclick="closeModal('benefitModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="add_benefit">
                <input type="hidden" name="senior_id" id="benefit_senior_id">
                <div class="db-form-group"><label>Senior Citizen</label><input type="text" id="benefit_name" class="db-input" readonly></div>
                <div class="db-form-group"><label>Benefit Type <span class="db-required">*</span></label><select name="benefit_type" class="db-input" required><option value="Monthly Allowance">Monthly Allowance</option><option value="Medical Assistance">Medical Assistance</option><option value="Food Subsidy">Food Subsidy</option><option value="Other">Other</option></select></div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Amount (₱) <span class="db-required">*</span></label><input type="number" step="0.01" name="amount" class="db-input" required placeholder="0.00"></div>
                    <div class="db-form-group"><label>Benefit Date <span class="db-required">*</span></label><input type="date" name="benefit_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                </div>
                <div class="db-form-group"><label>Remarks</label><textarea name="remarks" class="db-input" rows="2" placeholder="Optional remarks…"></textarea></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('benefitModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-gift"></i> Release Benefit</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div id="viewSeniorModal" class="db-modal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-user"></i> Senior Citizen Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewSeniorModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-section-title"><i class="fas fa-user"></i> Personal Information</div>
            <div class="db-detail-grid" style="margin-bottom:16px;">
                <div class="db-detail-item"><label>Full Name</label><p id="view_full_name"></p></div>
                <div class="db-detail-item"><label>Age</label><p id="view_age"></p></div>
                <div class="db-detail-item"><label>Birth Date</label><p id="view_birth_date"></p></div>
                <div class="db-detail-item"><label>Contact Number</label><p id="view_contact"></p></div>
                <div class="db-detail-item" style="grid-column:1/-1"><label>Address</label><p id="view_address"></p></div>
            </div>
            <div class="db-section-title"><i class="fas fa-id-card"></i> Senior Citizen Information</div>
            <div class="db-detail-grid" style="margin-bottom:16px;">
                <div class="db-detail-item"><label>Pension Type</label><p id="view_pension"></p></div>
                <div class="db-detail-item"><label>Registration Date</label><p id="view_reg_date"></p></div>
                <div class="db-detail-item" style="grid-column:1/-1"><label>Medical Conditions</label><p id="view_medical"></p></div>
            </div>
            <div class="db-section-title"><i class="fas fa-phone-alt"></i> Emergency Contact</div>
            <div class="db-detail-grid" style="margin-bottom:16px;">
                <div class="db-detail-item"><label>Contact Name</label><p id="view_emergency_name"></p></div>
                <div class="db-detail-item"><label>Contact Number</label><p id="view_emergency_number"></p></div>
            </div>
            <div class="db-section-title"><i class="fas fa-gift"></i> Benefits</div>
            <div class="db-benefit-summary" style="margin-bottom:12px;">
                <span style="font-size:13px;font-weight:600;color:var(--db-muted)">Total Benefits Received</span>
                <span id="view_total_benefits" style="font-size:22px;font-weight:800;color:var(--db-success)">₱0.00</span>
            </div>
            <div class="db-benefit-recent" id="view_benefits_list">
                <p style="text-align:center;padding:16px;color:var(--db-muted);font-size:13px">Loading…</p>
            </div>
            <div class="db-modal__footer" style="margin-top:18px;">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewSeniorModal')"><i class="fas fa-times"></i> Close</button>
                <button type="button" class="db-btn db-btn--success" onclick="openAddBenefitFromView()"><i class="fas fa-gift"></i> Add Benefit</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentViewSenior=null;
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

function registerSenior(r){
    document.getElementById('register_resident_id').value=r.resident_id;
    document.getElementById('register_name').value=r.first_name+' '+(r.middle_name||'')+' '+r.last_name;
    document.getElementById('register_age').value=r.age+' years old';
    openModal('registerModal');
}
function addBenefit(s){
    document.getElementById('benefit_senior_id').value=s.senior_id;
    document.getElementById('benefit_name').value=s.first_name+' '+s.last_name;
    openModal('benefitModal');
}
function openAddBenefitFromView(){if(currentViewSenior){closeModal('viewSeniorModal');addBenefit(currentViewSenior);}}
function viewSenior(s){
    currentViewSenior=s;
    document.getElementById('view_full_name').textContent=s.first_name+' '+(s.middle_name||'')+' '+s.last_name;
    document.getElementById('view_age').textContent=s.age+' years old';
    document.getElementById('view_birth_date').textContent=formatDate(s.birth_date);
    document.getElementById('view_contact').textContent=s.contact_number||'N/A';
    document.getElementById('view_address').textContent=s.address||'N/A';
    document.getElementById('view_pension').textContent=s.pension_type||'None';
    document.getElementById('view_reg_date').textContent=formatDate(s.registration_date);
    document.getElementById('view_medical').textContent=s.medical_conditions||'None reported';
    document.getElementById('view_emergency_name').textContent=s.emergency_contact||'N/A';
    document.getElementById('view_emergency_number').textContent=s.emergency_contact_number||'N/A';
    const tb=parseFloat(s.total_benefits||0);
    document.getElementById('view_total_benefits').textContent='₱'+tb.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    loadBenefits(s.senior_id);
    openModal('viewSeniorModal');
}
function loadBenefits(sid){
    const c=document.getElementById('view_benefits_list');
    fetch('get_senior_benefits.php?senior_id='+sid)
        .then(r=>r.json()).then(d=>{
            if(d.benefits&&d.benefits.length){
                let h='<table><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Remarks</th></tr></thead><tbody>';
                d.benefits.forEach(b=>{h+='<tr><td>'+formatDate(b.benefit_date)+'</td><td>'+b.benefit_type+'</td><td>₱'+parseFloat(b.amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</td><td>'+(b.remarks||'—')+'</td></tr>';});
                h+='</tbody></table>';c.innerHTML=h;
            } else c.innerHTML='<p style="text-align:center;padding:14px;color:var(--db-muted);font-size:12px">No benefits recorded yet.</p>';
        }).catch(()=>{c.innerHTML='<p style="text-align:center;padding:14px;color:var(--db-danger);font-size:12px">Error loading benefits.</p>';});
}
function formatDate(d){if(!d)return 'N/A';return new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.opacity='0';a.style.transition='opacity .3s';setTimeout(()=>a.remove(),300);}),5000);
</script>
<?php include '../../includes/footer.php'; ?>