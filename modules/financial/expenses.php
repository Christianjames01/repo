<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();
$page_title = 'Expense Management';
$user_role  = getCurrentUserRole();
if (!in_array($user_role, ['Super Admin', 'Treasurer', 'Admin'])) { header('Location: ../../modules/dashboard/index.php'); exit(); }
$current_user_id = getCurrentUserId();

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    $eid=intval($_POST['expense_id']);
    if ($_POST['action']==='approve') {
        $s=$conn->prepare("UPDATE tbl_expenses SET status='Approved',approved_by=?,approval_date=NOW() WHERE expense_id=?");
        $s->bind_param("ii",$current_user_id,$eid); if($s->execute()) $_SESSION['success_message']="Expense approved!"; $s->close();
    } elseif ($_POST['action']==='release') {
        $es=$conn->prepare("SELECT amount FROM tbl_expenses WHERE expense_id=?"); $es->bind_param("i",$eid); $es->execute();
        $amount=$es->get_result()->fetch_assoc()['amount']; $es->close();
        $s=$conn->prepare("UPDATE tbl_expenses SET status='Released',released_by=?,release_date=NOW() WHERE expense_id=?");
        $s->bind_param("ii",$current_user_id,$eid); if($s->execute()){
            $ub=$conn->prepare("UPDATE tbl_fund_balance SET current_balance=current_balance-?,updated_by=? ORDER BY balance_id DESC LIMIT 1");
            $ub->bind_param("di",$amount,$current_user_id); $ub->execute(); $ub->close();
            $_SESSION['success_message']="Expense released!";} $s->close();
    } elseif ($_POST['action']==='reject') {
        $s=$conn->prepare("UPDATE tbl_expenses SET status='Rejected' WHERE expense_id=?");
        $s->bind_param("i",$eid); if($s->execute()) $_SESSION['success_message']="Expense rejected!"; $s->close();
    } elseif ($_POST['action']==='cancel') {
        $s=$conn->prepare("UPDATE tbl_expenses SET status='Cancelled' WHERE expense_id=?");
        $s->bind_param("i",$eid); if($s->execute()) $_SESSION['success_message']="Expense cancelled!"; $s->close();
    }
    header('Location: expenses.php'); exit();
}

$sf=$_GET['status']??''; $cf=intval($_GET['category']??0); $df=$_GET['date_from']??''; $dt=$_GET['date_to']??''; $sr=trim($_GET['search']??'');
$pg=intval($_GET['page']??1); $pp=15; $ofs=($pg-1)*$pp;
$wc=[]; $par=[]; $tp='';
if($sf){$wc[]="e.status=?";$par[]=$sf;$tp.='s';}
if($cf){$wc[]="e.category_id=?";$par[]=$cf;$tp.='i';}
if($df){$wc[]="e.expense_date>=?";$par[]=$df;$tp.='s';}
if($dt){$wc[]="e.expense_date<=?";$par[]=$dt;$tp.='s';}
if($sr){$wc[]="(e.reference_number LIKE ? OR e.payee LIKE ? OR e.description LIKE ?)";$sp="%$sr%";$par[]=$sp;$par[]=$sp;$par[]=$sp;$tp.='sss';}
$ws=$wc?'WHERE '.implode(' AND ',$wc):'';
$cs=$conn->prepare("SELECT COUNT(*) as t FROM tbl_expenses e $ws"); if($par) $cs->bind_param($tp,...$par); $cs->execute();
$tr=$cs->get_result()->fetch_assoc()['t']; $cs->close(); $tpg=ceil($tr/$pp);
$sql="SELECT e.*,ec.category_name,u1.username as req_name,u2.username as app_name,u3.username as rel_name FROM tbl_expenses e LEFT JOIN tbl_expense_categories ec ON e.category_id=ec.category_id LEFT JOIN tbl_users u1 ON e.requested_by=u1.user_id LEFT JOIN tbl_users u2 ON e.approved_by=u2.user_id LEFT JOIN tbl_users u3 ON e.released_by=u3.user_id $ws ORDER BY e.created_at DESC LIMIT ? OFFSET ?";
$fp=array_merge($par,[$pp,$ofs]); $ft=$tp.'ii';
$st=$conn->prepare($sql); $st->bind_param($ft,...$fp); $st->execute();
$expenses=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
$cats=fetchAll($conn,"SELECT * FROM tbl_expense_categories WHERE is_active=1 ORDER BY category_name");
$total_pending =fetchOne($conn,"SELECT COALESCE(SUM(amount),0) as t FROM tbl_expenses WHERE status='Pending'")['t'];
$total_approved=fetchOne($conn,"SELECT COALESCE(SUM(amount),0) as t FROM tbl_expenses WHERE status='Approved'")['t'];
$total_released=fetchOne($conn,"SELECT COALESCE(SUM(amount),0) as t FROM tbl_expenses WHERE status='Released'")['t'];
include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#7f1d1d 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(225,29,72,.12);}
.fm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(245,158,11,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#9f1239,var(--db-rose));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);text-decoration:none;color:inherit;transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__num{font-size:18px;font-weight:800;line-height:1;letter-spacing:-.6px;font-family:'DM Mono',monospace;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
.db-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:all .15s;}
.db-icon-btn--default{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-icon-btn--default:hover{background:var(--db-border);color:var(--db-text);}
.db-icon-btn--success{background:var(--db-success-light);color:#065f46;}
.db-icon-btn--success:hover{background:#a7f3d0;}
.db-icon-btn--rose{background:var(--db-rose-light);color:#9f1239;}
.db-icon-btn--rose:hover{background:#fecaca;}
.db-icon-btn--sky{background:var(--db-sky-light);color:#0369a1;}
.db-icon-btn--sky:hover{background:#bae6fd;}
.db-icon-btn--amber{background:var(--db-amber-light);color:#92400e;}
.db-icon-btn--amber:hover{background:#fde68a;}
.db-pagination{display:flex;justify-content:center;gap:6px;padding:18px 22px;border-top:1px solid var(--db-border);}
.db-page-link{padding:6px 12px;border:1.5px solid var(--db-border);background:var(--db-surf);color:var(--db-text);text-decoration:none;border-radius:var(--db-radius-sm);font-size:12px;font-weight:600;transition:all .15s;}
.db-page-link:hover{background:var(--db-surf2);}
.db-page-link.active{background:var(--db-navy);color:#fff;border-color:var(--db-navy);}
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:520px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));}
.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--muted{background:linear-gradient(135deg,#374151,#6b7280);}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}
.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}
.db-confirm-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;}
.db-confirm-row:last-child{border-bottom:none;}
.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;}
.db-confirm-row .val{font-weight:600;color:var(--db-text);}
.db-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-bottom:14px;}
.db-notice--success{background:var(--db-success-light);color:#065f46;}
.db-notice--rose{background:var(--db-rose-light);color:#9f1239;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;}
.db-notice--sky{background:var(--db-sky-light);color:#0369a1;}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}
body.dark-mode .db-table tbody tr:hover{background:#1e293b!important;}
body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-id{color:#a5b4fc!important;}
body.dark-mode .db-input,body.dark-mode .db-select{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-filter-label{color:#94a3b8!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-btn--ghost:hover{background:#334155!important;}
body.dark-mode .db-page-link{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-page-link.active{background:var(--db-navy)!important;color:#fff!important;}
body.dark-mode .db-pagination{border-top-color:#334155!important;}
body.dark-mode .db-confirm-grid{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}
body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}
body.dark-mode .db-confirm-row .val{color:#e2e8f0!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .db-empty i{color:#334155!important;}
body.dark-mode .db-empty p{color:#64748b!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}}
</style>
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div><div class="fm-hero__ring fm-hero__ring--3"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-arrow-up"></i></div>
            <div><div class="fm-hero__title">Expense Management</div><div class="fm-hero__sub">Track and manage all barangay expenses</div></div>
        </div>
        <a href="expenses-add.php" class="db-btn db-btn--rose"><i class="fas fa-plus-circle"></i> Add Expense</a>
    </div>
</div>
<div style="padding:0 24px 24px;">
<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<div class="db-stats-row">
    <a href="expenses.php?status=Pending" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)">₱<?php echo number_format($total_pending,2); ?></div><div class="db-stat-card__label">Pending</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="expenses.php?status=Approved" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-check"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)">₱<?php echo number_format($total_approved,2); ?></div><div class="db-stat-card__label">Approved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
    <a href="expenses.php?status=Released" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-check-double"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)">₱<?php echo number_format($total_released,2); ?></div><div class="db-stat-card__label">Released</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </a>
</div>
<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-search"></i></div><h2>Filter Expenses</h2></div></div>
    <div class="db-panel__body">
        <form method="GET"><div class="db-form-row">
            <div><label class="db-filter-label">Status</label><select name="status" class="db-select"><option value="">All</option><?php foreach(['Pending','Approved','Released','Rejected','Cancelled'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $sf===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
            <div><label class="db-filter-label">Category</label><select name="category" class="db-select"><option value="0">All</option><?php foreach($cats as $c): ?><option value="<?php echo $c['category_id']; ?>" <?php echo $cf==$c['category_id']?'selected':''; ?>><?php echo htmlspecialchars($c['category_name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="db-filter-label">Date From</label><input type="date" name="date_from" class="db-input" value="<?php echo $df; ?>"></div>
            <div><label class="db-filter-label">Date To</label><input type="date" name="date_to" class="db-input" value="<?php echo $dt; ?>"></div>
            <div style="flex:1;min-width:180px;"><label class="db-filter-label">Search</label><input type="text" name="search" class="db-input" style="width:100%;" placeholder="Reference, payee…" value="<?php echo htmlspecialchars($sr); ?>"></div>
            <div style="padding-top:18px;display:flex;gap:8px;"><button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button><a href="expenses.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i></a></div>
        </div></form>
    </div>
</div>
<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-list"></i></div><h2>Expense Records</h2><span class="db-badge db-badge--rose"><?php echo number_format($tr); ?></span></div>
    </div>
    <div class="db-table-wrap"><table class="db-table">
        <thead><tr><th>Reference #</th><th>Date</th><th>Category</th><th>Payee</th><th>Amount</th><th>Method</th><th>Requested By</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($expenses)): ?><tr><td colspan="9"><div class="db-empty"><i class="fas fa-inbox"></i><p>No expense records found</p></div></td></tr>
        <?php else: foreach($expenses as $e):
            $sc=['Pending'=>'amber','Approved'=>'sky','Released'=>'success','Rejected'=>'rose','Cancelled'=>'muted'];
            $sbadge=$sc[$e['status']]??'muted';
        ?>
        <tr>
            <td><span class="db-id"><?php echo htmlspecialchars($e['reference_number']); ?></span></td>
            <td><span class="db-text-sm"><?php echo date('M d, Y',strtotime($e['expense_date'])); ?></span></td>
            <td><span class="db-badge db-badge--muted"><?php echo htmlspecialchars($e['category_name']); ?></span></td>
            <td><strong><?php echo htmlspecialchars($e['payee']); ?></strong></td>
            <td><strong style="color:var(--db-rose);font-family:'DM Mono',monospace;">₱<?php echo number_format($e['amount'],2); ?></strong></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($e['payment_method']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($e['req_name']??'—'); ?></span></td>
            <td><span class="db-badge db-badge--<?php echo $sbadge; ?>"><?php echo $e['status']; ?></span></td>
            <td><div style="display:flex;gap:4px;">
                <button class="db-icon-btn db-icon-btn--default" onclick='viewExpense(<?php echo json_encode($e); ?>)' title="View"><i class="fas fa-eye"></i></button>
                <?php if($e['status']==='Pending'): ?>
                    <button class="db-icon-btn db-icon-btn--success" onclick='openApprove(<?php echo json_encode($e); ?>)' title="Approve"><i class="fas fa-check"></i></button>
                    <button class="db-icon-btn db-icon-btn--rose" onclick='openReject(<?php echo json_encode($e); ?>)' title="Reject"><i class="fas fa-times"></i></button>
                <?php elseif($e['status']==='Approved'): ?>
                    <button class="db-icon-btn db-icon-btn--sky" onclick='openRelease(<?php echo json_encode($e); ?>)' title="Release Payment"><i class="fas fa-money-bill-wave"></i></button>
                    <button class="db-icon-btn db-icon-btn--amber" onclick='openCancel(<?php echo json_encode($e); ?>)' title="Cancel"><i class="fas fa-ban"></i></button>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
    <?php if($tpg>1): $qp=$_GET; unset($qp['page']); $bu='expenses.php?'.http_build_query($qp).'&page='; ?>
    <div class="db-pagination">
        <?php if($pg>1): ?><a href="<?php echo $bu.($pg-1); ?>" class="db-page-link"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php for($i=max(1,$pg-2);$i<=min($tpg,$pg+2);$i++): ?><a href="<?php echo $bu.$i; ?>" class="db-page-link <?php echo $i===$pg?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
        <?php if($pg<$tpg): ?><a href="<?php echo $bu.($pg+1); ?>" class="db-page-link"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- VIEW -->
<div id="viewModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--teal"><h3><i class="fas fa-eye"></i> Expense Details</h3><button class="db-modal__close" onclick="closeModal('viewModal')">×</button></div><div class="db-modal__body"><div id="expDetails"></div><div class="db-modal__footer"><button class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button></div></div></div></div>
<!-- APPROVE -->
<div id="approveModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--sky"><h3><i class="fas fa-check-circle"></i> Approve Expense</h3><button class="db-modal__close" onclick="closeModal('approveModal')">×</button></div><div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="approve"><input type="hidden" name="expense_id" id="app_eid"><div class="db-notice db-notice--sky"><i class="fas fa-info-circle" style="flex-shrink:0;"></i><span>Approving will move this to <strong>Approved</strong> status, ready for release.</span></div><div id="appInfo" class="db-confirm-grid"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('approveModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--primary"><i class="fas fa-check"></i> Approve</button></div></form></div></div></div>
<!-- REJECT -->
<div id="rejectModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-times-circle"></i> Reject Expense</h3><button class="db-modal__close" onclick="closeModal('rejectModal')">×</button></div><div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="reject"><input type="hidden" name="expense_id" id="rej_eid"><div class="db-notice db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i><span>This expense will be marked as <strong>Rejected</strong>. This cannot be undone.</span></div><div id="rejInfo" class="db-confirm-grid"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('rejectModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--rose"><i class="fas fa-times-circle"></i> Reject</button></div></form></div></div></div>
<!-- RELEASE -->
<div id="releaseModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--teal"><h3><i class="fas fa-money-bill-wave"></i> Release Payment</h3><button class="db-modal__close" onclick="closeModal('releaseModal')">×</button></div><div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="release"><input type="hidden" name="expense_id" id="rel_eid"><div class="db-notice db-notice--success"><i class="fas fa-info-circle" style="flex-shrink:0;"></i><span>This will <strong>deduct the amount from the fund balance</strong> and mark as Released.</span></div><div id="relInfo" class="db-confirm-grid"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('releaseModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--primary"><i class="fas fa-check-double"></i> Release Payment</button></div></form></div></div></div>
<!-- CANCEL -->
<div id="cancelModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--muted"><h3><i class="fas fa-ban"></i> Cancel Expense</h3><button class="db-modal__close" onclick="closeModal('cancelModal')">×</button></div><div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="cancel"><input type="hidden" name="expense_id" id="can_eid"><div class="db-notice db-notice--amber"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i><span>This will cancel the approved expense. <strong>This cannot be undone.</strong></span></div><div id="canInfo" class="db-confirm-grid"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('cancelModal')"><i class="fas fa-times"></i> Back</button><button type="submit" class="db-btn db-btn--primary" style="background:linear-gradient(135deg,#374151,#6b7280)"><i class="fas fa-ban"></i> Cancel Expense</button></div></form></div></div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});
function buildGrid(e){return`<div class="db-confirm-row"><span class="lbl">Reference #</span><span class="val db-id">${e.reference_number}</span></div><div class="db-confirm-row"><span class="lbl">Payee</span><span class="val">${e.payee}</span></div><div class="db-confirm-row"><span class="lbl">Amount</span><span class="val" style="color:var(--db-rose)">₱${parseFloat(e.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div><div class="db-confirm-row"><span class="lbl">Category</span><span class="val">${e.category_name||'—'}</span></div>`;}
function viewExpense(e){
    const sc={Pending:'var(--db-amber-dark)',Approved:'var(--db-sky)',Released:'var(--db-success)',Rejected:'var(--db-rose)',Cancelled:'var(--db-muted)'};
    document.getElementById('expDetails').innerHTML=`<div class="db-confirm-grid">
        <div class="db-confirm-row"><span class="lbl">Reference #</span><span class="val db-id">${e.reference_number}</span></div>
        <div class="db-confirm-row"><span class="lbl">Category</span><span class="val">${e.category_name||'—'}</span></div>
        <div class="db-confirm-row"><span class="lbl">Amount</span><span class="val" style="color:var(--db-rose);font-family:'DM Mono',monospace">₱${parseFloat(e.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
        <div class="db-confirm-row"><span class="lbl">Payee</span><span class="val">${e.payee}</span></div>
        <div class="db-confirm-row"><span class="lbl">Expense Date</span><span class="val">${new Date(e.expense_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>
        <div class="db-confirm-row"><span class="lbl">Payment Method</span><span class="val">${e.payment_method}</span></div>
        ${e.invoice_number?`<div class="db-confirm-row"><span class="lbl">Invoice #</span><span class="val">${e.invoice_number}</span></div>`:''}
        <div class="db-confirm-row"><span class="lbl">Requested By</span><span class="val">${e.req_name||'—'}</span></div>
        ${e.app_name?`<div class="db-confirm-row"><span class="lbl">Approved By</span><span class="val" style="color:var(--db-sky)">${e.app_name}</span></div>`:''}
        ${e.rel_name?`<div class="db-confirm-row"><span class="lbl">Released By</span><span class="val" style="color:var(--db-success)">${e.rel_name}</span></div>`:''}
        <div class="db-confirm-row"><span class="lbl">Status</span><span class="val" style="color:${sc[e.status]||'inherit'}">${e.status}</span></div>
        ${e.description?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Description</span><span class="val" style="text-align:left">${e.description}</span></div>`:''}
    </div>`;
    openModal('viewModal');
}
function openApprove(e){document.getElementById('app_eid').value=e.expense_id;document.getElementById('appInfo').innerHTML=buildGrid(e);openModal('approveModal');}
function openReject(e){document.getElementById('rej_eid').value=e.expense_id;document.getElementById('rejInfo').innerHTML=buildGrid(e);openModal('rejectModal');}
function openRelease(e){document.getElementById('rel_eid').value=e.expense_id;document.getElementById('relInfo').innerHTML=buildGrid(e);openModal('releaseModal');}
function openCancel(e){document.getElementById('can_eid').value=e.expense_id;document.getElementById('canInfo').innerHTML=buildGrid(e);openModal('cancelModal');}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include '../../includes/footer.php'; ?>