<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Fund Balance Management';
$user_role  = getCurrentUserRole();

if ($user_role !== 'Super Admin') {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'set_balance') {
        $new_balance = floatval($_POST['new_balance']);
        $notes       = trim($_POST['notes']);
        $conn->begin_transaction();
        try {
            $cs = $conn->prepare("SELECT balance_id, current_balance FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
            $cs->execute(); $existing = $cs->get_result()->fetch_assoc(); $cs->close();
            if ($existing) {
                $s = $conn->prepare("UPDATE tbl_fund_balance SET current_balance=?,updated_by=?,last_updated=NOW() WHERE balance_id=?");
                $s->bind_param("dii",$new_balance,$current_user_id,$existing['balance_id']); $s->execute(); $s->close();
                $action_type='Balance Updated'; $old_balance=$existing['current_balance'];
            } else {
                $s = $conn->prepare("INSERT INTO tbl_fund_balance (current_balance,updated_by,last_updated) VALUES (?,?,NOW())");
                $s->bind_param("di",$new_balance,$current_user_id); $s->execute(); $s->close();
                $action_type='Initial Balance Set'; $old_balance=0;
            }
            $amount_changed = $new_balance - $old_balance;
            $ls = $conn->prepare("INSERT INTO tbl_balance_history (action_type,old_balance,new_balance,amount_changed,notes,created_by,created_at) VALUES (?,?,?,?,?,?,NOW())");
            $ls->bind_param("sdddsi",$action_type,$old_balance,$new_balance,$amount_changed,$notes,$current_user_id); $ls->execute(); $ls->close();
            $conn->commit();
            $_SESSION['success_message'] = "Fund balance updated to ₱".number_format($new_balance,2);
        } catch (Exception $e) { $conn->rollback(); $_SESSION['error_message']="Failed: ".$e->getMessage(); }
        header('Location: fund-balance.php'); exit();

    } elseif ($_POST['action'] === 'adjust_balance') {
        $adjustment_type   = $_POST['adjustment_type'];
        $adjustment_amount = floatval($_POST['adjustment_amount']);
        $notes             = trim($_POST['notes']);
        $conn->begin_transaction();
        try {
            $cs = $conn->prepare("SELECT balance_id, current_balance FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
            $cs->execute(); $existing = $cs->get_result()->fetch_assoc(); $cs->close();
            if (!$existing) throw new Exception("No existing balance found. Please set initial balance first.");
            $old_balance = $existing['current_balance'];
            if ($adjustment_type === 'add') {
                $new_balance = $old_balance + $adjustment_amount;
                $action_type = 'Manual Addition'; $amount_changed = $adjustment_amount;
            } else {
                $new_balance = $old_balance - $adjustment_amount;
                $action_type = 'Manual Deduction'; $amount_changed = -$adjustment_amount;
            }
            if ($new_balance < 0) throw new Exception("Adjustment would result in negative balance. Current: ₱".number_format($old_balance,2));
            $s = $conn->prepare("UPDATE tbl_fund_balance SET current_balance=?,updated_by=?,last_updated=NOW() WHERE balance_id=?");
            $s->bind_param("dii",$new_balance,$current_user_id,$existing['balance_id']); $s->execute(); $s->close();
            $ls = $conn->prepare("INSERT INTO tbl_balance_history (action_type,old_balance,new_balance,amount_changed,notes,created_by,created_at) VALUES (?,?,?,?,?,?,NOW())");
            $ls->bind_param("sdddsi",$action_type,$old_balance,$new_balance,$amount_changed,$notes,$current_user_id); $ls->execute(); $ls->close();
            $conn->commit();
            $_SESSION['success_message'] = "Balance adjusted. New balance: ₱".number_format($new_balance,2);
        } catch (Exception $e) { $conn->rollback(); $_SESSION['error_message']="Failed: ".$e->getMessage(); }
        header('Location: fund-balance.php'); exit();
    }
}

$bs = $conn->prepare("SELECT * FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
$bs->execute(); $current_fund = $bs->get_result()->fetch_assoc(); $bs->close();

$hs = $conn->prepare("SELECT bh.*, u.username as performed_by FROM tbl_balance_history bh LEFT JOIN tbl_users u ON bh.created_by=u.user_id ORDER BY bh.created_at DESC LIMIT 50");
$hs->execute(); $history = $hs->get_result()->fetch_all(MYSQLI_ASSOC); $hs->close();

$budgets = $conn->prepare("SELECT COALESCE(SUM(allocated_amount),0) as total FROM tbl_budget_allocations WHERE status='Approved'");
$budgets->execute(); $total_allocated = $budgets->get_result()->fetch_assoc()['total']; $budgets->close();

$current_balance = $current_fund ? $current_fund['current_balance'] : 0;
$available_balance = $current_balance - $total_allocated;

include '../../includes/header.php';
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
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-violet:#7c3aed;--db-violet-light:#ede9fe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#134e4a 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:340px;height:340px;top:-150px;right:-70px;}
.fm-hero__ring--2{width:200px;height:200px;top:-60px;right:80px;border-color:rgba(13,148,136,.14);}
.fm-hero__ring--3{width:110px;height:110px;bottom:-45px;left:38%;border-color:rgba(245,158,11,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#065f46,var(--db-teal));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-rose-light);color:#7f1d1d;border-color:var(--db-rose);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* ── Balance Hero Card ── */
.fm-balance-hero{background:linear-gradient(135deg,var(--db-navy),var(--db-teal));border-radius:var(--db-radius-lg);padding:28px 32px;margin-bottom:20px;position:relative;overflow:hidden;box-shadow:var(--db-shadow-lg);animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.fm-balance-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.08);pointer-events:none;}
.fm-balance-hero__ring--1{width:220px;height:220px;top:-80px;right:-40px;}
.fm-balance-hero__ring--2{width:120px;height:120px;bottom:-30px;left:30%;}
.fm-balance-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;}
.fm-balance-hero__main{display:flex;align-items:center;gap:20px;}
.fm-balance-hero__icon{width:64px;height:64px;background:rgba(255,255,255,.15);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;backdrop-filter:blur(4px);}
.fm-balance-hero__label{font-size:11px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;}
.fm-balance-hero__amount{font-family:'DM Mono',monospace;font-size:36px;font-weight:800;color:#fff;letter-spacing:-1.5px;line-height:1;}
.fm-balance-hero__meta{font-size:12px;color:rgba(255,255,255,.5);margin-top:6px;display:flex;align-items:center;gap:6px;}
.fm-balance-hero__pills{display:flex;gap:10px;flex-wrap:wrap;}
.fm-balance-pill{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:10px 16px;backdrop-filter:blur(4px);min-width:140px;}
.fm-balance-pill__label{font-size:10px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:2px;}
.fm-balance-pill__val{font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:#fff;}
.fm-balance-pill__val--pos{color:#6ee7b7;}
.fm-balance-pill__val--neg{color:#fca5a5;}

/* ── Stats row ── */
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__num{font-size:18px;font-weight:800;line-height:1;letter-spacing:-.6px;font-family:'DM Mono',monospace;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--violet{background:linear-gradient(90deg,var(--db-violet),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}

/* ── Btns ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));color:#fff;}
.db-btn--teal:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.35);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}
.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);}
.db-btn:disabled{opacity:.5;cursor:not-allowed;pointer-events:none;}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}

/* ── Badge ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}

/* ── Table ── */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-mono{font-family:'DM Mono',monospace;font-size:12px;}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* ── Change pill ── */
.fm-change{display:inline-flex;align-items:center;gap:4px;font-family:'DM Mono',monospace;font-size:12px;font-weight:600;padding:2px 8px;border-radius:20px;}
.fm-change--pos{background:var(--db-success-light);color:#065f46;}
.fm-change--neg{background:var(--db-rose-light);color:#9f1239;}
.fm-change--neu{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}

/* ── Modal ── */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}

/* Form elements inside modal */
.db-form-group{margin-bottom:16px;}
.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}
.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-input,.db-select,.db-textarea{width:100%;padding:10px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-input:disabled,.db-select:disabled,.db-textarea:disabled{background:var(--db-surf2);color:var(--db-muted);cursor:not-allowed;}
.db-input--peso{padding-left:2rem;}
.db-peso-wrap{position:relative;}
.db-peso-wrap::before{content:'₱';position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--db-muted);font-weight:600;font-size:13px;pointer-events:none;z-index:1;}
.db-textarea{resize:vertical;min-height:80px;}
.db-form-hint{font-size:11px;color:var(--db-muted);margin-top:4px;}

/* Notice boxes */
.db-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-bottom:16px;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;}
.db-notice--sky{background:var(--db-sky-light);color:#0369a1;}
.db-notice--rose{background:var(--db-rose-light);color:#9f1239;}

/* ── Dark mode ── */
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-panel__icon--teal{background:#0d2e2a!important;color:#2dd4bf!important;}
body.dark-mode .db-panel__icon--amber{background:#27211a!important;color:#fbbf24!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-stat-card__icon--teal{background:#0d2e2a!important;color:#2dd4bf!important;}
body.dark-mode .db-stat-card__icon--violet{background:#2e1065!important;color:#c4b5fd!important;}
body.dark-mode .db-stat-card__icon--success{background:#052e16!important;color:#4ade80!important;}
body.dark-mode .db-stat-card__icon--amber{background:#27211a!important;color:#fbbf24!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}
body.dark-mode .db-table tbody tr:hover{background:#162032!important;}
body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-mono{color:#cbd5e1!important;}
body.dark-mode .db-badge--muted{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .fm-change--neu{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-btn--ghost:hover{background:#334155!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input:disabled,body.dark-mode .db-select:disabled,body.dark-mode .db-textarea:disabled{background:#1e293b!important;}
body.dark-mode .db-form-label{color:#94a3b8!important;}
body.dark-mode .db-form-hint{color:#64748b!important;}
body.dark-mode .db-notice--amber{background:#27211a!important;color:#fbbf24!important;}
body.dark-mode .db-notice--sky{background:#0c2340!important;color:#7dd3fc!important;}
body.dark-mode .db-notice--rose{background:#2d1c1c!important;color:#fca5a5!important;}
body.dark-mode .fm-balance-hero{background:linear-gradient(135deg,#0f172a,#0d2e2a)!important;}
body.dark-mode .db-empty i{color:#334155!important;}
body.dark-mode .db-empty p{color:#64748b!important;}
body.dark-mode .db-alert--success{background:#052e16!important;color:#86efac!important;border-color:#4ade80!important;}
body.dark-mode .db-alert--error{background:#2d1c1c!important;color:#fca5a5!important;border-color:#ef4444!important;}

@media(max-width:768px){
    .fm-hero{padding:20px;border-radius:0;}
    .fm-balance-hero{padding:20px;}
    .fm-balance-hero__amount{font-size:26px;}
    .fm-balance-hero__inner{flex-direction:column;align-items:flex-start;}
}
</style>

<!-- Hero -->
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div>
    <div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__ring fm-hero__ring--3"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-money-bill-wave"></i></div>
            <div>
                <div class="fm-hero__title">Fund Balance Management</div>
                <div class="fm-hero__sub">Manage and track barangay fund balance</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="db-btn db-btn--ghost-white" onclick="openModal('adjustModal')">
                <i class="fas fa-exchange-alt"></i> Adjust Balance
            </button>
            <button class="db-btn db-btn--teal" onclick="openModal('setModal')">
                <i class="fas fa-edit"></i> Set Balance
            </button>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Big Balance Card -->
<div class="fm-balance-hero">
    <div class="fm-balance-hero__ring fm-balance-hero__ring--1"></div>
    <div class="fm-balance-hero__ring fm-balance-hero__ring--2"></div>
    <div class="fm-balance-hero__inner">
        <div class="fm-balance-hero__main">
            <div class="fm-balance-hero__icon"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="fm-balance-hero__label">Current Fund Balance</div>
                <div class="fm-balance-hero__amount">₱<?php echo number_format($current_balance, 2); ?></div>
                <div class="fm-balance-hero__meta">
                    <i class="fas fa-clock"></i>
                    <?php echo $current_fund ? 'Last updated '.date('M d, Y g:i A', strtotime($current_fund['last_updated'])) : 'Not yet set'; ?>
                </div>
            </div>
        </div>
        <div class="fm-balance-hero__pills">
            <div class="fm-balance-pill">
                <div class="fm-balance-pill__label">Allocated in Budgets</div>
                <div class="fm-balance-pill__val fm-balance-pill__val--neg">₱<?php echo number_format($total_allocated, 2); ?></div>
            </div>
            <div class="fm-balance-pill">
                <div class="fm-balance-pill__label">Available Balance</div>
                <div class="fm-balance-pill__val <?php echo $available_balance >= 0 ? 'fm-balance-pill__val--pos' : 'fm-balance-pill__val--neg'; ?>">
                    <?php echo ($available_balance >= 0 ? '' : '−').'₱'.number_format(abs($available_balance), 2); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick stats row -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-wallet"></i></div>
        <div>
            <div class="db-stat-card__num" style="color:var(--db-teal)">₱<?php echo number_format($current_balance, 2); ?></div>
            <div class="db-stat-card__label">Total Balance</div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--violet"><i class="fas fa-chart-pie"></i></div>
        <div>
            <div class="db-stat-card__num" style="color:var(--db-violet)">₱<?php echo number_format($total_allocated, 2); ?></div>
            <div class="db-stat-card__label">Budget Allocated</div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--violet"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div>
            <div class="db-stat-card__num" style="color:<?php echo $available_balance>=0?'var(--db-success)':'var(--db-rose)'; ?>">
                ₱<?php echo number_format(abs($available_balance), 2); ?>
            </div>
            <div class="db-stat-card__label">Available</div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-history"></i></div>
        <div>
            <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo count($history); ?></div>
            <div class="db-stat-card__label">History Records</div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
</div>

<!-- Balance History Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-history"></i></div>
            <h2>Balance History</h2>
            <span class="db-badge db-badge--muted">Last <?php echo count($history); ?> records</span>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="db-btn db-btn--amber db-btn--sm" onclick="openModal('adjustModal')"><i class="fas fa-exchange-alt"></i> Adjust</button>
            <button class="db-btn db-btn--teal db-btn--sm" onclick="openModal('setModal')"><i class="fas fa-edit"></i> Set Balance</button>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Action Type</th>
                    <th>Old Balance</th>
                    <th>New Balance</th>
                    <th>Change</th>
                    <th>Notes</th>
                    <th>Performed By</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="7">
                    <div class="db-empty">
                        <i class="fas fa-history"></i>
                        <p>No balance history found</p>
                        <button class="db-btn db-btn--teal db-btn--sm" onclick="openModal('setModal')"><i class="fas fa-plus"></i> Set Initial Balance</button>
                    </div>
                </td></tr>
            <?php else: foreach ($history as $r):
                $is_add = strpos($r['action_type'],'Addition') !== false;
                $is_ded = strpos($r['action_type'],'Deduction') !== false;
                $badge_cls = $is_add ? 'success' : ($is_ded ? 'rose' : 'sky');
                $change    = floatval($r['amount_changed']);
                $change_cls = $change > 0 ? 'pos' : ($change < 0 ? 'neg' : 'neu');
            ?>
                <tr>
                    <td>
                        <span class="db-text-sm"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></span><br>
                        <span class="db-text-sm" style="font-size:10.5px;"><?php echo date('g:i A', strtotime($r['created_at'])); ?></span>
                    </td>
                    <td><span class="db-badge db-badge--<?php echo $badge_cls; ?>"><?php echo htmlspecialchars($r['action_type']); ?></span></td>
                    <td><span class="db-mono">₱<?php echo number_format($r['old_balance'], 2); ?></span></td>
                    <td><span class="db-mono" style="font-weight:700;">₱<?php echo number_format($r['new_balance'], 2); ?></span></td>
                    <td>
                        <span class="fm-change fm-change--<?php echo $change_cls; ?>">
                            <?php echo $change > 0 ? '+' : ($change < 0 ? '−' : ''); ?>₱<?php echo number_format(abs($change), 2); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($r['notes']): ?>
                            <span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($r['notes'], 0, 60, '…')); ?></span>
                        <?php else: ?>
                            <span class="db-text-sm" style="color:var(--db-border);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($r['performed_by'] ?? '—'); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /padding wrapper -->

<!-- ───────────── SET BALANCE MODAL ───────────── -->
<div id="setModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-edit"></i> Set Fund Balance</h3>
            <button class="db-modal__close" onclick="closeModal('setModal')">×</button>
        </div>
        <div class="db-modal__body">
            <?php if ($current_fund): ?>
            <div class="db-notice db-notice--amber">
                <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                <span>This will <strong>replace</strong> the current balance of <strong>₱<?php echo number_format($current_fund['current_balance'], 2); ?></strong> with a new value. Use <em>Adjust Balance</em> to add or deduct instead.</span>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="set_balance">
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">New Balance Amount (₱)</label>
                    <div class="db-peso-wrap">
                        <input type="number" name="new_balance" class="db-input db-input--peso" step="0.01" min="0"
                               placeholder="0.00"
                               value="<?php echo $current_fund ? htmlspecialchars($current_fund['current_balance']) : ''; ?>"
                               required>
                    </div>
                    <span class="db-form-hint">Enter the exact fund balance amount</span>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Notes / Reason</label>
                    <textarea name="notes" class="db-textarea" placeholder="Explain the reason for this balance change…" required></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('setModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--teal"><i class="fas fa-save"></i> Set Balance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ───────────── ADJUST BALANCE MODAL ───────────── -->
<div id="adjustModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-exchange-alt"></i> Adjust Fund Balance</h3>
            <button class="db-modal__close" onclick="closeModal('adjustModal')">×</button>
        </div>
        <div class="db-modal__body">
            <?php if ($current_fund): ?>
            <div class="db-notice db-notice--sky">
                <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
                <span><strong>Current Balance:</strong> ₱<?php echo number_format($current_fund['current_balance'], 2); ?></span>
            </div>
            <?php else: ?>
            <div class="db-notice db-notice--rose">
                <i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:1px;"></i>
                <span><strong>No balance set.</strong> Please set an initial balance first before adjusting.</span>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="adjust_balance">
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Adjustment Type</label>
                    <select name="adjustment_type" class="db-select" required <?php echo !$current_fund ? 'disabled' : ''; ?>>
                        <option value="">Select type…</option>
                        <option value="add">➕ Add to Balance</option>
                        <option value="deduct">➖ Deduct from Balance</option>
                    </select>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Adjustment Amount (₱)</label>
                    <div class="db-peso-wrap">
                        <input type="number" name="adjustment_amount" class="db-input db-input--peso" step="0.01" min="0.01"
                               placeholder="0.00" required <?php echo !$current_fund ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Notes / Reason</label>
                    <textarea name="notes" class="db-textarea" placeholder="Explain the reason for this adjustment…"
                              required <?php echo !$current_fund ? 'disabled' : ''; ?>></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('adjustModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--amber" <?php echo !$current_fund ? 'disabled' : ''; ?>>
                        <i class="fas fa-check"></i> Apply Adjustment
                    </button>
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
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>

<?php include '../../includes/footer.php'; ?>