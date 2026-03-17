<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Budget Management';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$current_year = date('Y');
$fiscal_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_budget') {
            $category_id = intval($_POST['category_id']);
            $allocated_amount = floatval($_POST['allocated_amount']);
            $notes = trim($_POST['notes']);
            $stmt = $conn->prepare("INSERT INTO tbl_budget_allocations (fiscal_year, category_id, allocated_amount, notes, created_by, status) VALUES (?, ?, ?, ?, ?, 'Draft')");
            $stmt->bind_param("iidsi", $fiscal_year, $category_id, $allocated_amount, $notes, $current_user_id);
            if ($stmt->execute()) $_SESSION['success_message'] = "Budget allocation added successfully!";
            $stmt->close();
            header('Location: budget.php?year=' . $fiscal_year); exit();

        } elseif ($_POST['action'] === 'update_budget') {
            $allocation_id = intval($_POST['allocation_id']);
            $allocated_amount = floatval($_POST['allocated_amount']);
            $notes = trim($_POST['notes']);
            $stmt = $conn->prepare("UPDATE tbl_budget_allocations SET allocated_amount = ?, notes = ? WHERE allocation_id = ? AND status = 'Draft'");
            $stmt->bind_param("dsi", $allocated_amount, $notes, $allocation_id);
            if ($stmt->execute()) $_SESSION['success_message'] = "Budget updated successfully!";
            $stmt->close();
            header('Location: budget.php?year=' . $fiscal_year); exit();

        } elseif ($_POST['action'] === 'approve_budget') {
            $allocation_id = intval($_POST['allocation_id']);
            $conn->begin_transaction();
            try {
                $check_stmt = $conn->prepare("SELECT allocated_amount FROM tbl_budget_allocations WHERE allocation_id = ? AND status = 'Draft'");
                $check_stmt->bind_param("i", $allocation_id); $check_stmt->execute();
                $budget = $check_stmt->get_result()->fetch_assoc(); $check_stmt->close();
                if (!$budget) throw new Exception("Budget allocation not found or already approved");
                $allocated_amount = $budget['allocated_amount'];
                $stmt = $conn->prepare("UPDATE tbl_budget_allocations SET status = 'Approved', approved_by = ?, approval_date = NOW() WHERE allocation_id = ?");
                $stmt->bind_param("ii", $current_user_id, $allocation_id); $stmt->execute(); $stmt->close();
                $balance_stmt = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = current_balance - ?, updated_by = ?, last_updated = NOW() WHERE balance_id = (SELECT balance_id FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1)");
                $balance_stmt->bind_param("di", $allocated_amount, $current_user_id); $balance_stmt->execute(); $balance_stmt->close();
                $conn->commit();
                $_SESSION['success_message'] = "Budget approved and ₱" . number_format($allocated_amount, 2) . " reserved from fund balance!";
            } catch (Exception $e) {
                $conn->rollback(); $_SESSION['error_message'] = "Failed to approve budget: " . $e->getMessage();
            }
            header('Location: budget.php?year=' . $fiscal_year); exit();

        } elseif ($_POST['action'] === 'delete_budget') {
            $allocation_id = intval($_POST['allocation_id']);
            $conn->begin_transaction();
            try {
                $check_stmt = $conn->prepare("SELECT allocated_amount, status FROM tbl_budget_allocations WHERE allocation_id = ?");
                $check_stmt->bind_param("i", $allocation_id); $check_stmt->execute();
                $budget = $check_stmt->get_result()->fetch_assoc(); $check_stmt->close();
                if (!$budget) throw new Exception("Budget allocation not found");
                if ($budget['status'] !== 'Draft') throw new Exception("Only draft budgets can be deleted");
                $stmt = $conn->prepare("DELETE FROM tbl_budget_allocations WHERE allocation_id = ? AND status = 'Draft'");
                $stmt->bind_param("i", $allocation_id); $stmt->execute(); $stmt->close();
                $conn->commit(); $_SESSION['success_message'] = "Budget allocation deleted successfully!";
            } catch (Exception $e) {
                $conn->rollback(); $_SESSION['error_message'] = "Failed to delete budget: " . $e->getMessage();
            }
            header('Location: budget.php?year=' . $fiscal_year); exit();
        }
    }
}

$budgets = fetchAll($conn, "
    SELECT ba.*, ec.category_name,
           u1.username as created_by_name,
           u2.username as approved_by_name
    FROM tbl_budget_allocations ba
    JOIN tbl_expense_categories ec ON ba.category_id = ec.category_id
    LEFT JOIN tbl_users u1 ON ba.created_by = u1.user_id
    LEFT JOIN tbl_users u2 ON ba.approved_by = u2.user_id
    WHERE ba.fiscal_year = ?
    ORDER BY ba.status, ec.category_name
", [$fiscal_year], 'i');

$used_categories = array_column($budgets, 'category_id');
$all_categories = fetchAll($conn, "SELECT * FROM tbl_expense_categories WHERE is_active = 1 ORDER BY category_name");
$available_categories = array_filter($all_categories, function($cat) use ($used_categories) {
    return !in_array($cat['category_id'], $used_categories);
});

$total_allocated = array_sum(array_column($budgets, 'allocated_amount'));
$total_spent     = array_sum(array_column($budgets, 'spent_amount'));
$total_remaining = array_sum(array_column($budgets, 'remaining_amount'));
$overall_util    = $total_allocated > 0 ? ($total_spent / $total_allocated) * 100 : 0;

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-violet:#7c3aed;--db-violet-light:#ede9fe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a3870 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(99,102,241,.12);}
.fm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(16,185,129,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4c1d95,var(--db-violet));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(124,58,237,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.fm-hero__right{display:flex;align-items:center;gap:10px;}
.fm-year-select{padding:8px 14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:var(--db-radius-sm);color:#fff;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;}
.fm-year-select option{background:var(--db-navy);}

.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--violet{background:linear-gradient(90deg,var(--db-violet),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}

.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--violet{background:linear-gradient(135deg,#4c1d95,var(--db-violet));color:#fff;}
.db-btn--violet:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(124,58,237,.35);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}

.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--violet{background:var(--db-violet-light);color:#5b21b6;}

.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-table tfoot td{padding:11px 16px;font-weight:700;background:var(--db-surf2);border-top:2px solid var(--db-border);}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Progress Bar */
.fm-prog-wrap{display:flex;align-items:center;gap:8px;min-width:140px;}
.fm-prog-track{flex:1;height:6px;background:var(--db-surf2);border-radius:3px;overflow:hidden;border:1px solid var(--db-border);}
.fm-prog-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.fm-prog-fill--ok{background:linear-gradient(90deg,var(--db-success),#34d399);}
.fm-prog-fill--warning{background:linear-gradient(90deg,var(--db-amber),#fbbf24);}
.fm-prog-fill--danger{background:linear-gradient(90deg,var(--db-rose),#f43f5e);}
.fm-prog-pct{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);white-space:nowrap;min-width:38px;text-align:right;}

/* Action icon buttons */
.db-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:all .15s;}
.db-icon-btn--default{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-icon-btn--default:hover{background:var(--db-border);color:var(--db-text);}
.db-icon-btn--success{background:var(--db-success-light);color:#065f46;}
.db-icon-btn--success:hover{background:#a7f3d0;}
.db-icon-btn--rose{background:var(--db-rose-light);color:#9f1239;}
.db-icon-btn--rose:hover{background:#fecaca;}
.db-icon-btn--violet{background:var(--db-violet-light);color:#5b21b6;}
.db-icon-btn--violet:hover{background:#ddd6fe;}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:500px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box--sm{max-width:440px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}
.db-modal__header--violet{background:linear-gradient(135deg,#4c1d95,var(--db-violet));}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}

.db-form-group{margin-bottom:16px;}
.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}
.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-input,.db-select,.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-input:disabled{background:var(--db-surf2);color:var(--db-muted);}
.db-textarea{resize:vertical;min-height:80px;}
.db-form-hint{font-size:11px;color:var(--db-muted);margin-top:4px;}

/* Confirm notice */
.db-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-bottom:14px;}
.db-notice--success{background:var(--db-success-light);color:#065f46;}
.db-notice--rose{background:var(--db-rose-light);color:#9f1239;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;}
.db-notice--indigo{background:var(--db-indigo-light);color:#3730a3;}

.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}
.db-confirm-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;}
.db-confirm-row:last-child{border-bottom:none;}
.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;}
.db-confirm-row .val{font-weight:600;color:var(--db-text);}

/* Dark mode */
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-panel__icon--violet{background:#2e1065!important;color:#c4b5fd!important;}
body.dark-mode .db-panel__icon--teal{background:#0d2e2a!important;color:#2dd4bf!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-stat-card__icon--violet{background:#2e1065!important;color:#c4b5fd!important;}
body.dark-mode .db-stat-card__icon--rose{background:#2d1c1c!important;color:#fb7185!important;}
body.dark-mode .db-stat-card__icon--success{background:#052e16!important;color:#4ade80!important;}
body.dark-mode .db-stat-card__icon--amber{background:#27211a!important;color:#fbbf24!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}
body.dark-mode .db-table tbody tr:hover{background:#1e293b!important;}
body.dark-mode .db-table tbody td,body.dark-mode .db-table tfoot td{color:#e2e8f0!important;}
body.dark-mode .db-table tfoot td{background:#162032!important;border-top-color:#334155!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-badge--success{background:#052e16!important;color:#4ade80!important;}
body.dark-mode .db-badge--rose{background:#2d1c1c!important;color:#fb7185!important;}
body.dark-mode .db-badge--amber{background:#27211a!important;color:#fbbf24!important;}
body.dark-mode .db-badge--muted{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .db-badge--violet{background:#2e1065!important;color:#c4b5fd!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-btn--ghost:hover{background:#334155!important;}
body.dark-mode .db-input,.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input:disabled{background:#1e293b!important;}
body.dark-mode .db-form-label{color:#94a3b8!important;}
body.dark-mode .db-form-hint{color:#64748b!important;}
body.dark-mode .db-confirm-grid{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}
body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}
body.dark-mode .db-confirm-row .val{color:#e2e8f0!important;}
body.dark-mode .db-notice--success{background:#052e16!important;color:#86efac!important;}
body.dark-mode .db-notice--rose{background:#2d1c1c!important;color:#fca5a5!important;}
body.dark-mode .db-notice--amber{background:#27211a!important;color:#fbbf24!important;}
body.dark-mode .db-notice--indigo{background:#1e1b4b!important;color:#a5b4fc!important;}
body.dark-mode .fm-prog-track{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .db-icon-btn--default:hover{background:#334155!important;}
body.dark-mode .db-empty i{color:#334155!important;}
body.dark-mode .db-empty p{color:#64748b!important;}
body.dark-mode .db-alert--success{background:#052e16!important;color:#86efac!important;border-color:#4ade80!important;}
body.dark-mode .db-alert--error{background:#2d1c1c!important;color:#fca5a5!important;border-color:#ef4444!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}}
</style>

<!-- Hero -->
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div>
    <div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__ring fm-hero__ring--3"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-chart-pie"></i></div>
            <div>
                <div class="fm-hero__title">Budget Management</div>
                <div class="fm-hero__sub">Allocate and monitor budget for FY <?php echo $fiscal_year; ?></div>
            </div>
        </div>
        <div class="fm-hero__right">
            <select class="fm-year-select" onchange="window.location.href='budget.php?year='+this.value">
                <?php for ($y = $current_year + 1; $y >= $current_year - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $fiscal_year ? 'selected' : ''; ?>>FY <?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <?php if (!empty($available_categories)): ?>
                <button class="db-btn db-btn--violet" onclick="openModal('addBudgetModal')">
                    <i class="fas fa-plus-circle"></i> Add Allocation
                </button>
            <?php endif; ?>
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

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--violet"><i class="fas fa-wallet"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-violet)">₱<?php echo number_format($total_allocated, 2); ?></div><div class="db-stat-card__label">Total Allocated</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--violet"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-arrow-up"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)">₱<?php echo number_format($total_spent, 2); ?></div><div class="db-stat-card__label">Total Spent</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-piggy-bank"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)">₱<?php echo number_format($total_remaining, 2); ?></div><div class="db-stat-card__label">Total Remaining</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-chart-bar"></i></div>
        <div>
            <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo number_format($overall_util, 1); ?>%</div>
            <div class="db-stat-card__label">Overall Utilization</div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--violet"><i class="fas fa-list"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-violet)"><?php echo count($budgets); ?></div><div class="db-stat-card__label">Categories</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--violet"></div>
    </div>
</div>

<!-- Budget Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--violet"><i class="fas fa-list"></i></div>
            <h2>Budget Allocations</h2>
            <span class="db-badge db-badge--violet"><?php echo count($budgets); ?></span>
        </div>
        <?php if (!empty($available_categories)): ?>
        <button class="db-btn db-btn--violet db-btn--sm" onclick="openModal('addBudgetModal')">
            <i class="fas fa-plus"></i> Add Allocation
        </button>
        <?php endif; ?>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>Category</th><th>Allocated</th><th>Spent</th><th>Remaining</th><th>Utilization</th><th>Status</th><th>Created By</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($budgets)): ?>
                <tr><td colspan="8"><div class="db-empty"><i class="fas fa-chart-pie"></i><p>No budget allocations for FY <?php echo $fiscal_year; ?></p><button class="db-btn db-btn--violet db-btn--sm" onclick="openModal('addBudgetModal')"><i class="fas fa-plus"></i> Add First Allocation</button></div></td></tr>
            <?php else: foreach ($budgets as $budget):
                $up = $budget['allocated_amount'] > 0 ? ($budget['spent_amount'] / $budget['allocated_amount']) * 100 : 0;
                $uc = $up > 90 ? 'danger' : ($up > 75 ? 'warning' : 'ok');
                $sc = ['Draft' => 'amber', 'Approved' => 'success', 'Rejected' => 'rose'];
                $sc_val = $sc[$budget['status']] ?? 'muted';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($budget['category_name']); ?></strong>
                        <?php if ($budget['notes']): ?><br><span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($budget['notes'], 0, 50, '…')); ?></span><?php endif; ?>
                    </td>
                    <td><span style="font-family:'DM Mono',monospace;font-weight:700;">₱<?php echo number_format($budget['allocated_amount'], 2); ?></span></td>
                    <td><span style="font-family:'DM Mono',monospace;font-weight:700;color:var(--db-rose);">₱<?php echo number_format($budget['spent_amount'], 2); ?></span></td>
                    <td><span style="font-family:'DM Mono',monospace;font-weight:700;color:var(--db-success);">₱<?php echo number_format($budget['remaining_amount'], 2); ?></span></td>
                    <td>
                        <div class="fm-prog-wrap">
                            <div class="fm-prog-track"><div class="fm-prog-fill fm-prog-fill--<?php echo $uc; ?>" style="width:<?php echo min($up, 100); ?>%"></div></div>
                            <span class="fm-prog-pct"><?php echo number_format($up, 1); ?>%</span>
                        </div>
                    </td>
                    <td><span class="db-badge db-badge--<?php echo $sc_val; ?>"><?php echo $budget['status']; ?></span></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($budget['created_by_name'] ?? '—'); ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                        <?php if ($budget['status'] === 'Draft'): ?>
                            <button class="db-icon-btn db-icon-btn--default" onclick='editBudget(<?php echo json_encode($budget); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="db-icon-btn db-icon-btn--success" onclick='approveBudget(<?php echo json_encode($budget); ?>)' title="Approve"><i class="fas fa-check"></i></button>
                            <button class="db-icon-btn db-icon-btn--rose" onclick='deleteBudget(<?php echo json_encode($budget); ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                        <?php else: ?>
                            <button class="db-icon-btn db-icon-btn--violet" onclick='viewBudget(<?php echo json_encode($budget); ?>)' title="View Details"><i class="fas fa-eye"></i></button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($budgets)): ?>
            <tfoot>
                <tr>
                    <td>TOTAL</td>
                    <td><span style="font-family:'DM Mono',monospace;">₱<?php echo number_format($total_allocated, 2); ?></span></td>
                    <td><span style="font-family:'DM Mono',monospace;color:var(--db-rose);">₱<?php echo number_format($total_spent, 2); ?></span></td>
                    <td><span style="font-family:'DM Mono',monospace;color:var(--db-success);">₱<?php echo number_format($total_remaining, 2); ?></span></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
</div>

<!-- ADD MODAL -->
<div id="addBudgetModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--violet">
            <h3><i class="fas fa-plus-circle"></i> Add Budget Allocation</h3>
            <button class="db-modal__close" onclick="closeModal('addBudgetModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="add_budget">
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Category</label>
                    <select name="category_id" class="db-select" required>
                        <option value="">Select Category</option>
                        <?php foreach ($available_categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Allocated Amount (₱)</label>
                    <input type="number" name="allocated_amount" class="db-input" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label">Notes</label>
                    <textarea name="notes" class="db-textarea" placeholder="Optional notes…"></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addBudgetModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--violet"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editBudgetModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-edit"></i> Edit Budget Allocation</h3>
            <button class="db-modal__close" onclick="closeModal('editBudgetModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="update_budget">
                <input type="hidden" name="allocation_id" id="edit_allocation_id">
                <div class="db-form-group">
                    <label class="db-form-label">Category</label>
                    <input type="text" id="edit_category_name" class="db-input" disabled>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Allocated Amount (₱)</label>
                    <input type="number" name="allocated_amount" id="edit_allocated_amount" class="db-input" step="0.01" min="0.01" required>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label">Notes</label>
                    <textarea name="notes" id="edit_notes" class="db-textarea"></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editBudgetModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW MODAL -->
<div id="viewBudgetModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--violet">
            <h3><i class="fas fa-eye"></i> Budget Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewBudgetModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div id="budgetDetails"></div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewBudgetModal')"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- APPROVE MODAL -->
<div id="approveBudgetModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-check-circle"></i> Approve Budget</h3>
            <button class="db-modal__close" onclick="closeModal('approveBudgetModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="approve_budget">
                <input type="hidden" name="allocation_id" id="approve_allocation_id">
                <div class="db-confirm-grid">
                    <div class="db-confirm-row"><span class="lbl">Category</span><span class="val" id="approve_category_name"></span></div>
                    <div class="db-confirm-row"><span class="lbl">Amount</span><span class="val" id="approve_amount" style="color:var(--db-violet);"></span></div>
                </div>
                <div class="db-notice db-notice--indigo"><i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i><span>Once approved, this budget will be <strong>reserved from the fund balance</strong> and cannot be modified or deleted.</span></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('approveBudgetModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div id="deleteBudgetModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-trash-alt"></i> Delete Budget</h3>
            <button class="db-modal__close" onclick="closeModal('deleteBudgetModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="delete_budget">
                <input type="hidden" name="allocation_id" id="delete_allocation_id">
                <div class="db-confirm-grid">
                    <div class="db-confirm-row"><span class="lbl">Category</span><span class="val" id="delete_category_name"></span></div>
                    <div class="db-confirm-row"><span class="lbl">Amount</span><span class="val" id="delete_amount"></span></div>
                </div>
                <div class="db-notice db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>This action <strong>cannot be undone</strong>. Only draft budgets can be deleted.</span></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteBudgetModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--rose"><i class="fas fa-trash"></i> Delete</button>
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

function editBudget(b){document.getElementById('edit_allocation_id').value=b.allocation_id;document.getElementById('edit_category_name').value=b.category_name;document.getElementById('edit_allocated_amount').value=b.allocated_amount;document.getElementById('edit_notes').value=b.notes||'';openModal('editBudgetModal');}

function approveBudget(b){document.getElementById('approve_allocation_id').value=b.allocation_id;document.getElementById('approve_category_name').textContent=b.category_name;document.getElementById('approve_amount').textContent='₱'+parseFloat(b.allocated_amount).toLocaleString('en-PH',{minimumFractionDigits:2});openModal('approveBudgetModal');}

function deleteBudget(b){document.getElementById('delete_allocation_id').value=b.allocation_id;document.getElementById('delete_category_name').textContent=b.category_name;document.getElementById('delete_amount').textContent='₱'+parseFloat(b.allocated_amount).toLocaleString('en-PH',{minimumFractionDigits:2});openModal('deleteBudgetModal');}

function viewBudget(b){
    const util=b.allocated_amount>0?(b.spent_amount/b.allocated_amount*100):0;
    const uc=util>90?'var(--db-rose)':util>75?'var(--db-amber)':'var(--db-success)';
    const sc={Draft:'db-badge--amber',Approved:'db-badge--success',Rejected:'db-badge--rose'};
    document.getElementById('budgetDetails').innerHTML=`
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Category</span><span class="val">${b.category_name}</span></div>
            <div class="db-confirm-row"><span class="lbl">Allocated</span><span class="val" style="color:var(--db-violet)">₱${parseFloat(b.allocated_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
            <div class="db-confirm-row"><span class="lbl">Spent</span><span class="val" style="color:var(--db-rose)">₱${parseFloat(b.spent_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
            <div class="db-confirm-row"><span class="lbl">Remaining</span><span class="val" style="color:var(--db-success)">₱${parseFloat(b.remaining_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
            <div class="db-confirm-row"><span class="lbl">Utilization</span><span class="val" style="color:${uc}">${util.toFixed(1)}%</span></div>
            <div class="db-confirm-row"><span class="lbl">Status</span><span class="val"><span class="db-badge ${sc[b.status]||'db-badge--muted'}">${b.status}</span></span></div>
            <div class="db-confirm-row"><span class="lbl">Created By</span><span class="val">${b.created_by_name||'—'}</span></div>
            ${b.approved_by_name?`<div class="db-confirm-row"><span class="lbl">Approved By</span><span class="val">${b.approved_by_name}</span></div>`:''}
            ${b.approval_date?`<div class="db-confirm-row"><span class="lbl">Approval Date</span><span class="val">${new Date(b.approval_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>`:''}
            ${b.notes?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;align-items:flex-start;"><span class="lbl">Notes</span><span class="val" style="text-align:left;">${b.notes}</span></div>`:''}
        </div>`;
    openModal('viewBudgetModal');
}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>

<?php include '../../includes/footer.php'; ?>