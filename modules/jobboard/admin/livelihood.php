<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Livelihood Programs';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_program'])) {
        $f=['program_name','description','target_beneficiaries','funding_source','coordinator','requirements','benefits'];
        $v=[];foreach($f as $k)$v[$k]=trim($_POST[$k]??'');
        $budget=floatval($_POST['budget']);
        $stmt=$conn->prepare("INSERT INTO tbl_livelihood_programs (program_name,description,target_beneficiaries,budget,funding_source,start_date,end_date,coordinator,requirements,benefits,status) VALUES (?,?,?,?,?,?,?,?,?,?,'Active')");
        $stmt->bind_param("sssdssssss",$v['program_name'],$v['description'],$v['target_beneficiaries'],$budget,$v['funding_source'],$_POST['start_date'],$_POST['end_date'],$v['coordinator'],$v['requirements'],$v['benefits']);
        if($stmt->execute()) $message="Livelihood program added successfully!"; else $error="Error adding program.";
        $stmt->close();
    } elseif (isset($_POST['edit_program'])) {
        $pid=intval($_POST['program_id']);
        $f=['program_name','description','target_beneficiaries','funding_source','coordinator','requirements','benefits'];
        $v=[];foreach($f as $k)$v[$k]=trim($_POST[$k]??'');
        $budget=floatval($_POST['budget']);$status=$_POST['status'];
        $stmt=$conn->prepare("UPDATE tbl_livelihood_programs SET program_name=?,description=?,target_beneficiaries=?,budget=?,funding_source=?,start_date=?,end_date=?,coordinator=?,requirements=?,benefits=?,status=? WHERE program_id=?");
        $stmt->bind_param("sssdsssssssi",$v['program_name'],$v['description'],$v['target_beneficiaries'],$budget,$v['funding_source'],$_POST['start_date'],$_POST['end_date'],$v['coordinator'],$v['requirements'],$v['benefits'],$status,$pid);
        if($stmt->execute()) $message="Program updated successfully!"; else $error="Error updating program.";
        $stmt->close();
    } elseif (isset($_POST['delete_program'])) {
        $pid=intval($_POST['program_id']);
        $s=$conn->prepare("DELETE FROM tbl_livelihood_programs WHERE program_id=?");$s->bind_param("i",$pid);
        if($s->execute()) $message="Program deleted successfully!"; else $error="Error deleting program.";
        $s->close();
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$query = "SELECT * FROM tbl_livelihood_programs WHERE 1=1";
if ($search) $query .= " AND program_name LIKE '%".$conn->real_escape_string($search)."%'";
if ($status_filter) $query .= " AND status='".$conn->real_escape_string($status_filter)."'";
$query .= " ORDER BY created_at DESC";
$programs = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

$stats_q=$conn->query("SELECT COUNT(*) as total,SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) as active,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) as cancelled,COALESCE(SUM(budget),0) as total_budget FROM tbl_livelihood_programs");
$pstats=$stats_q->fetch_assoc();

include '../../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-violet:#8b5cf6;--db-violet-light:#ede9fe;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
/* Hero */
.jb-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.jb-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.jb-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.jb-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.jb-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.jb-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.jb-hero__left{display:flex;align-items:center;gap:16px;}
.jb-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-teal),#0f766e);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.jb-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.jb-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.jb-hero__actions{display:flex;gap:10px;flex-wrap:wrap;}
/* Alerts */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:#fee2e2;color:#7f1d1d;border-color:#ef4444;}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
/* Stats */
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 130px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--violet{background:linear-gradient(90deg,var(--db-violet),transparent);}
/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-panel__body{padding:20px 22px;}
/* Filter row */
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select,.db-textarea{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--xs{padding:4px 9px;font-size:11px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));color:#fff;}
.db-btn--teal:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.3);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--sky{background:linear-gradient(135deg,#0369a1,var(--db-sky));color:#fff;}
.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.3);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--violet{background:linear-gradient(135deg,#5b21b6,var(--db-violet));color:#fff;}
.db-btn--violet:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(139,92,246,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25);}
.db-btn--ghost-white:hover{background:rgba(255,255,255,.2);color:#fff;}
/* Badges */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--violet{background:var(--db-violet-light);color:#5b21b6;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
/* Table */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr.clickable:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-teal);cursor:pointer;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
/* Empty */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:600px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box--lg{max-width:780px;}
.db-modal__box--sm{max-width:440px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--sky{background:linear-gradient(135deg,#0369a1,var(--db-sky));}
.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}
.db-modal__header--violet{background:linear-gradient(135deg,#5b21b6,var(--db-violet));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--db-border);}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
/* Form elements inside modal */
.db-form-group{margin-bottom:14px;}
.db-form-group label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.db-form-group .db-input,.db-form-group .db-select,.db-form-group .db-textarea{width:100%;}
.db-form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.db-form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
/* Logo */
.db-logo-thumb{width:44px;height:44px;object-fit:contain;border-radius:8px;background:var(--db-surf2);border:1px solid var(--db-border);padding:4px;}
.db-logo-placeholder{width:44px;height:44px;display:flex;align-items:center;justify-content:center;background:var(--db-surf2);border:1px solid var(--db-border);border-radius:8px;color:var(--db-muted);font-size:16px;}
.db-upload-area{border:2px dashed var(--db-border);border-radius:var(--db-radius);padding:20px;text-align:center;background:var(--db-surf2);transition:all .2s;}
.db-upload-area:hover{border-color:var(--db-teal);background:var(--db-teal-light);}
.db-upload-area i{font-size:28px;color:var(--db-muted);margin-bottom:8px;}
/* Training/Program cards */
.db-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin-bottom:18px;}
.db-card{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);overflow:hidden;display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s;animation:dbFadeUp .35s ease both;}
.db-card:hover{transform:translateY(-4px);box-shadow:var(--db-shadow-lg);}
.db-card__header{padding:16px 18px 12px;border-bottom:1px solid var(--db-border);}
.db-card__header h3{font-size:14px;font-weight:700;margin-bottom:4px;line-height:1.3;}
.db-card__body{padding:16px 18px;flex:1;}
.db-card__row{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;margin-bottom:9px;color:var(--db-muted);}
.db-card__row i{width:14px;flex-shrink:0;margin-top:1px;color:var(--db-teal);}
.db-card__row strong{color:var(--db-text);}
.db-card__footer{padding:12px 18px;border-top:1px solid var(--db-border);display:flex;gap:8px;flex-wrap:wrap;}
/* App card (list view) */
.db-app-card{background:var(--db-surf);border-radius:var(--db-radius);border:1px solid var(--db-border);box-shadow:var(--db-shadow);padding:16px 20px;display:flex;align-items:center;gap:16px;margin-bottom:10px;transition:transform .15s,box-shadow .15s,border-color .15s;animation:dbFadeUp .3s ease both;}
.db-app-card:hover{transform:translateY(-2px);box-shadow:var(--db-shadow-lg);border-color:var(--db-teal);}
.db-app-card__logo{width:48px;height:48px;flex-shrink:0;border-radius:10px;object-fit:contain;background:var(--db-surf2);border:1px solid var(--db-border);padding:5px;}
.db-app-card__logo-placeholder{width:48px;height:48px;flex-shrink:0;border-radius:10px;background:var(--db-surf2);border:1px solid var(--db-border);display:flex;align-items:center;justify-content:center;color:var(--db-muted);font-size:18px;}
.db-app-card__info{flex:1;min-width:0;}
.db-app-card__name{font-size:13.5px;font-weight:700;margin-bottom:2px;}
.db-app-card__meta{font-size:11.5px;color:var(--db-muted);}
.db-app-card__job{font-size:12.5px;font-weight:600;margin-bottom:2px;}
.db-app-card__company{font-size:11.5px;color:var(--db-muted);}
.db-app-card__date{text-align:center;min-width:80px;}
.db-app-card__actions{display:flex;gap:6px;flex-shrink:0;}
/* Notice box */
.db-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-top:4px;}
.db-notice--rose{background:var(--db-rose-light);color:#9f1239;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;}
/* Slot bar */
.db-slot-track{height:6px;border-radius:3px;background:var(--db-surf2);border:1px solid var(--db-border);overflow:hidden;margin-top:4px;}
.db-slot-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--db-teal),var(--db-sky));}
/* ══ Dark mode ══ */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel,body.dark-mode .db-card,body.dark-mode .db-app-card{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header,.db-card__header,.db-card__footer{border-color:#334155 !important;}
body.dark-mode .db-panel__title h2,body.dark-mode .db-card__header h3{color:#f1f5f9 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-stat-card__label,body.dark-mode .db-text-sm,body.dark-mode .db-card__row,body.dark-mode .db-app-card__meta,body.dark-mode .db-app-card__company{color:#94a3b8 !important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-filter-label,body.dark-mode .db-form-group label{color:#94a3b8 !important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b) !important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9) !important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155 !important;}
body.dark-mode .db-table tbody tr.clickable:hover{background:#1e293b !important;box-shadow:inset 3px 0 0 #2dd4bf !important;}
body.dark-mode .db-table tbody td{color:#e2e8f0 !important;}
body.dark-mode .db-id{color:#a5b4fc !important;}
body.dark-mode .db-badge--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .db-badge--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-badge--sky{background:#0c2a40 !important;color:#38bdf8 !important;}
body.dark-mode .db-badge--success{background:#052e16 !important;color:#4ade80 !important;}
body.dark-mode .db-badge--rose{background:#2d1c1c !important;color:#fb7185 !important;}
body.dark-mode .db-badge--indigo{background:#1e1b4b !important;color:#a5b4fc !important;}
body.dark-mode .db-badge--violet{background:#2e1065 !important;color:#c4b5fd !important;}
body.dark-mode .db-badge--muted{background:#1e293b !important;color:#94a3b8 !important;border-color:#475569 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-btn--ghost:hover{background:#334155 !important;}
body.dark-mode .db-empty i{color:#334155 !important;}
body.dark-mode .db-empty p{color:#64748b !important;}
body.dark-mode .db-modal__box{background:#1e293b !important;}
body.dark-mode .db-modal__body{background:#1e293b !important;}
body.dark-mode .db-modal__footer{border-top-color:#334155 !important;}
body.dark-mode .db-upload-area{background:#1e293b !important;border-color:#475569 !important;}
body.dark-mode .db-upload-area:hover{border-color:#2dd4bf !important;background:#0d2e2a !important;}
body.dark-mode .db-logo-placeholder,body.dark-mode .db-app-card__logo-placeholder{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-notice--rose{background:#2d1c1c !important;color:#fca5a5 !important;}
body.dark-mode .db-notice--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-slot-track{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-alert--success{background:#052e16 !important;color:#86efac !important;border-color:#4ade80 !important;}
body.dark-mode .db-alert--error{background:#2d1c1c !important;color:#fca5a5 !important;border-color:#ef4444 !important;}
body.dark-mode .db-panel__icon--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .db-panel__icon--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-panel__icon--sky{background:#0c2a40 !important;color:#38bdf8 !important;}
body.dark-mode .db-panel__icon--success{background:#052e16 !important;color:#4ade80 !important;}
body.dark-mode .db-panel__icon--rose{background:#2d1c1c !important;color:#fb7185 !important;}
body.dark-mode .db-panel__icon--indigo{background:#1e1b4b !important;color:#a5b4fc !important;}
body.dark-mode .db-panel__icon--violet{background:#2e1065 !important;color:#c4b5fd !important;}
body.dark-mode .db-stat-card__icon--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .db-stat-card__icon--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-stat-card__icon--sky{background:#0c2a40 !important;color:#38bdf8 !important;}
body.dark-mode .db-stat-card__icon--success{background:#052e16 !important;color:#4ade80 !important;}
body.dark-mode .db-stat-card__icon--rose{background:#2d1c1c !important;color:#fb7185 !important;}
body.dark-mode .db-stat-card__icon--indigo{background:#1e1b4b !important;color:#a5b4fc !important;}
body.dark-mode .db-stat-card__icon--violet{background:#2e1065 !important;color:#c4b5fd !important;}
body.dark-mode .db-card__body .db-card__row i{color:#2dd4bf !important;}
body.dark-mode .db-app-card{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-app-card:hover{border-color:#2dd4bf !important;}
body.dark-mode .db-app-card__name{color:#f1f5f9 !important;}
body.dark-mode .db-card__footer{background:transparent !important;}

</style>

<!-- Hero -->
<div class="jb-hero">
    <div class="jb-hero__ring jb-hero__ring--1"></div>
    <div class="jb-hero__ring jb-hero__ring--2"></div>
    <div class="jb-hero__ring jb-hero__ring--3"></div>
    <div class="jb-hero__inner">
        <div class="jb-hero__left">
            <div class="jb-hero__icon" style="background:linear-gradient(135deg,#4338ca,var(--db-indigo));"><i class="fas fa-hands-helping"></i></div>
            <div>
                <div class="jb-hero__title">Livelihood Programs</div>
                <div class="jb-hero__sub">Manage community livelihood programs &amp; initiatives</div>
            </div>
        </div>
        <div class="jb-hero__actions">
            <button class="db-btn db-btn--violet" onclick="openModal('addProgramModal')"><i class="fas fa-plus-circle"></i> Add Program</button>
            <a href="index.php" class="db-btn db-btn--ghost-white"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($message): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <a href="?status=" class="db-stat-card <?php echo !$status_filter?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-hands-helping"></i></div>
        <div><div class="db-stat-card__num"><?php echo $pstats['total']; ?></div><div class="db-stat-card__label">Total Programs</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </a>
    <a href="?status=Active" class="db-stat-card <?php echo $status_filter==='Active'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $pstats['active']; ?></div><div class="db-stat-card__label">Active</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </a>
    <a href="?status=Completed" class="db-stat-card <?php echo $status_filter==='Completed'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-flag-checkered"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-teal)"><?php echo $pstats['completed']; ?></div><div class="db-stat-card__label">Completed</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </a>
    <a href="?status=Cancelled" class="db-stat-card <?php echo $status_filter==='Cancelled'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-ban"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $pstats['cancelled']; ?></div><div class="db-stat-card__label">Cancelled</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </a>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-money-bill-wave"></i></div>
        <div><div class="db-stat-card__num" style="font-size:18px;color:var(--db-amber-dark);">₱<?php echo number_format($pstats['total_budget'],0); ?></div><div class="db-stat-card__label">Total Budget</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-search"></i></div>
            <h2>Search &amp; Filter</h2>
        </div>
        <?php if ($search||$status_filter): ?>
        <a href="livelihood.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div style="flex:2;min-width:180px;">
                    <label class="db-filter-label">Search</label>
                    <input type="text" name="search" class="db-input" style="width:100%;" placeholder="Search programs…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="flex:1;min-width:140px;">
                    <label class="db-filter-label">Status</label>
                    <select name="status" class="db-select" style="width:100%;">
                        <option value="">All Status</option>
                        <?php foreach(['Active','Completed','Cancelled'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Search</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Program Cards -->
<?php if (empty($programs)): ?>
<div class="db-panel"><div class="db-panel__body"><div class="db-empty"><i class="fas fa-hands-helping"></i><p>No programs found. Click "Add Program" to create one.</p></div></div></div>
<?php else: ?>
<div class="db-card-grid">
<?php
$sc=['Active'=>'success','Completed'=>'teal','Cancelled'=>'rose'];
foreach($programs as $p):
    $cls=$sc[$p['status']]??'muted';
?>
<div class="db-card">
    <div class="db-card__header">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
            <h3><?php echo htmlspecialchars($p['program_name']); ?></h3>
            <span class="db-badge db-badge--<?php echo $cls; ?>"><?php echo $p['status']; ?></span>
        </div>
    </div>
    <div class="db-card__body">
        <div class="db-card__row"><i class="fas fa-users"></i><span><strong>Target:</strong> <?php echo htmlspecialchars($p['target_beneficiaries']); ?></span></div>
        <div class="db-card__row"><i class="fas fa-money-bill-wave"></i><span><strong>Budget:</strong> <span style="color:var(--db-success);font-weight:700;">₱<?php echo number_format($p['budget'],2); ?></span></span></div>
        <div class="db-card__row"><i class="fas fa-hand-holding-usd"></i><span><strong>Funding:</strong> <?php echo htmlspecialchars($p['funding_source']); ?></span></div>
        <div class="db-card__row"><i class="fas fa-calendar-alt"></i>
            <span><?php echo date('M d, Y',strtotime($p['start_date'])).' – '.date('M d, Y',strtotime($p['end_date'])); ?></span>
        </div>
        <div class="db-card__row"><i class="fas fa-user-tie"></i><span><?php echo htmlspecialchars($p['coordinator']); ?></span></div>
        <div style="margin-top:10px;font-size:12px;color:var(--db-muted);line-height:1.5;">
            <?php echo htmlspecialchars(mb_strimwidth($p['description'],0,110,'…')); ?>
        </div>
    </div>
    <div class="db-card__footer">
        <button class="db-btn db-btn--sky db-btn--xs" onclick='openViewProgram(<?php echo json_encode($p); ?>)'><i class="fas fa-eye"></i> View</button>
        <button class="db-btn db-btn--amber db-btn--xs" onclick='openEditProgram(<?php echo json_encode($p); ?>)'><i class="fas fa-edit"></i> Edit</button>
        <button class="db-btn db-btn--rose db-btn--xs" onclick="openDeleteProgram(<?php echo $p['program_id']; ?>,'<?php echo addslashes($p['program_name']); ?>')"><i class="fas fa-trash"></i> Delete</button>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /padding -->

<!-- ═══════════ ADD PROGRAM MODAL ═══════════ -->
<div id="addProgramModal" class="db-modal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--violet">
            <h3><i class="fas fa-plus-circle"></i> Add Livelihood Program</h3>
            <button class="db-modal__close" onclick="closeModal('addProgramModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <div class="db-form-group"><label>Program Name *</label><input type="text" name="program_name" class="db-input" required></div>
                <div class="db-form-group"><label>Description *</label><textarea name="description" class="db-input db-textarea" rows="3" required></textarea></div>
                <div class="db-form-row-2">
                    <div class="db-form-group"><label>Target Beneficiaries *</label><input type="text" name="target_beneficiaries" class="db-input" placeholder="e.g., Low-income families" required></div>
                    <div class="db-form-group"><label>Budget (₱) *</label><input type="number" name="budget" class="db-input" step="0.01" min="0" required></div>
                    <div class="db-form-group"><label>Funding Source *</label><input type="text" name="funding_source" class="db-input" required></div>
                    <div class="db-form-group"><label>Program Coordinator *</label><input type="text" name="coordinator" class="db-input" required></div>
                    <div class="db-form-group"><label>Start Date *</label><input type="date" name="start_date" class="db-input" required></div>
                    <div class="db-form-group"><label>End Date *</label><input type="date" name="end_date" class="db-input" required></div>
                </div>
                <div class="db-form-group"><label>Requirements *</label><textarea name="requirements" class="db-input db-textarea" rows="3" required></textarea></div>
                <div class="db-form-group"><label>Benefits *</label><textarea name="benefits" class="db-input db-textarea" rows="3" required></textarea></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addProgramModal')">Cancel</button>
                    <button type="submit" name="add_program" class="db-btn db-btn--violet"><i class="fas fa-plus"></i> Add Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════ VIEW PROGRAM MODAL ═══════════ -->
<div id="viewProgramModal" class="db-modal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--sky">
            <h3><i class="fas fa-eye"></i> Program Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewProgramModal')">×</button>
        </div>
        <div class="db-modal__body" id="vp_body"></div>
    </div>
</div>

<!-- ═══════════ EDIT PROGRAM MODAL ═══════════ -->
<div id="editProgramModal" class="db-modal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-edit"></i> Edit Program</h3>
            <button class="db-modal__close" onclick="closeModal('editProgramModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="program_id" id="ep_id">
                <div class="db-form-group"><label>Program Name *</label><input type="text" name="program_name" id="ep_name" class="db-input" required></div>
                <div class="db-form-group"><label>Description *</label><textarea name="description" id="ep_desc" class="db-input db-textarea" rows="3" required></textarea></div>
                <div class="db-form-row-2">
                    <div class="db-form-group"><label>Target Beneficiaries *</label><input type="text" name="target_beneficiaries" id="ep_target" class="db-input" required></div>
                    <div class="db-form-group"><label>Budget (₱) *</label><input type="number" name="budget" id="ep_budget" class="db-input" step="0.01" min="0" required></div>
                    <div class="db-form-group"><label>Funding Source *</label><input type="text" name="funding_source" id="ep_funding" class="db-input" required></div>
                    <div class="db-form-group">
                        <label>Status *</label>
                        <select name="status" id="ep_status" class="db-select">
                            <option>Active</option><option>Completed</option><option>Cancelled</option>
                        </select>
                    </div>
                    <div class="db-form-group"><label>Start Date *</label><input type="date" name="start_date" id="ep_start" class="db-input" required></div>
                    <div class="db-form-group"><label>End Date *</label><input type="date" name="end_date" id="ep_end" class="db-input" required></div>
                    <div class="db-form-group"><label>Coordinator *</label><input type="text" name="coordinator" id="ep_coord" class="db-input" required></div>
                </div>
                <div class="db-form-group"><label>Requirements *</label><textarea name="requirements" id="ep_req" class="db-input db-textarea" rows="3" required></textarea></div>
                <div class="db-form-group"><label>Benefits *</label><textarea name="benefits" id="ep_benefits" class="db-input db-textarea" rows="3" required></textarea></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editProgramModal')">Cancel</button>
                    <button type="submit" name="edit_program" class="db-btn db-btn--amber"><i class="fas fa-save"></i> Update Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════ DELETE PROGRAM MODAL ═══════════ -->
<div id="deleteProgramModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-trash"></i> Delete Program</h3>
            <button class="db-modal__close" onclick="closeModal('deleteProgramModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="program_id" id="dp_id">
                <p style="margin-bottom:12px;">Are you sure you want to delete this program?</p>
                <div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px;margin-bottom:12px;font-weight:700;" id="dp_name"></div>
                <div class="db-notice db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i><span>This action <strong>cannot be undone.</strong></span></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteProgramModal')">Cancel</button>
                    <button type="submit" name="delete_program" class="db-btn db-btn--rose"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

function fmtDate(d){if(!d)return'N/A';return new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'});}
function statusBadge(s){const m={Active:'success',Completed:'teal',Cancelled:'rose'};return`<span class="db-badge db-badge--${m[s]||'muted'}">${s}</span>`;}

function openViewProgram(p){
    const row=(l,v)=>`<div style="padding:10px 0;border-bottom:1px solid var(--db-border);"><div class="db-text-sm">${l}</div><div style="font-size:13px;font-weight:600;margin-top:2px;">${v||'N/A'}</div></div>`;
    const sec=(l,v)=>v?`<div style="margin-top:14px;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:5px;">${l}</div><div style="font-size:13px;line-height:1.6;white-space:pre-line;">${v}</div></div>`:'';
    document.getElementById('vp_body').innerHTML=`
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div style="font-size:18px;font-weight:800;">${p.program_name}</div>
            ${statusBadge(p.status)}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
            ${row('Target Beneficiaries',p.target_beneficiaries)}
            ${row('Budget','₱'+parseFloat(p.budget).toLocaleString('en-US',{minimumFractionDigits:2}))}
            ${row('Funding Source',p.funding_source)}
            ${row('Coordinator',p.coordinator)}
            ${row('Start Date',fmtDate(p.start_date))}
            ${row('End Date',fmtDate(p.end_date))}
        </div>
        ${sec('Description',p.description)}
        ${sec('Requirements',p.requirements)}
        ${sec('Benefits',p.benefits)}
    `;
    openModal('viewProgramModal');
}
function openEditProgram(p){
    document.getElementById('ep_id').value=p.program_id;
    document.getElementById('ep_name').value=p.program_name||'';
    document.getElementById('ep_desc').value=p.description||'';
    document.getElementById('ep_target').value=p.target_beneficiaries||'';
    document.getElementById('ep_budget').value=p.budget||'';
    document.getElementById('ep_funding').value=p.funding_source||'';
    document.getElementById('ep_status').value=p.status||'Active';
    document.getElementById('ep_start').value=p.start_date||'';
    document.getElementById('ep_end').value=p.end_date||'';
    document.getElementById('ep_coord').value=p.coordinator||'';
    document.getElementById('ep_req').value=p.requirements||'';
    document.getElementById('ep_benefits').value=p.benefits||'';
    openModal('editProgramModal');
}
function openDeleteProgram(id,name){
    document.getElementById('dp_id').value=id;
    document.getElementById('dp_name').textContent=name;
    openModal('deleteProgramModal');
}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>

<?php include '../../../includes/footer.php'; ?>