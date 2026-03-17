<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Financial Management';
$user_role = getCurrentUserRole();

// Only Super Admin and Staff can access
if (!in_array($user_role, ['Super Admin', 'Staff', 'Treasurer', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$current_year = date('Y');
$current_month = date('m');

$fiscal_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Get fund balance
$balance_stmt = $conn->prepare("SELECT current_balance, last_updated FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
$balance_stmt->execute();
$balance_result = $balance_stmt->get_result();
$fund_data = $balance_result->fetch_assoc();
$current_balance = $fund_data ? $fund_data['current_balance'] : 0.00;
$balance_stmt->close();

// Get total allocated budget
$allocated_budget_stmt = $conn->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total_allocated FROM tbl_budget_allocations WHERE status = 'Approved'");
$allocated_budget_stmt->execute();
$total_allocated_budget = $allocated_budget_stmt->get_result()->fetch_assoc()['total_allocated'];
$allocated_budget_stmt->close();

// Get total revenue for current year
$revenue_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_revenue FROM tbl_revenues WHERE YEAR(transaction_date) = ? AND status = 'Verified'");
$revenue_stmt->bind_param("i", $fiscal_year);
$revenue_stmt->execute();
$total_revenue = $revenue_stmt->get_result()->fetch_assoc()['total_revenue'];
$revenue_stmt->close();

// Get total expenses for current year
$expense_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_expenses FROM tbl_expenses WHERE YEAR(expense_date) = ? AND status = 'Released'");
$expense_stmt->bind_param("i", $fiscal_year);
$expense_stmt->execute();
$total_expenses = $expense_stmt->get_result()->fetch_assoc()['total_expenses'];
$expense_stmt->close();

// Get pending expenses count
$pending_stmt = $conn->prepare("SELECT COUNT(*) as pending_count FROM tbl_expenses WHERE status IN ('Pending', 'Approved')");
$pending_stmt->execute();
$pending_expenses = $pending_stmt->get_result()->fetch_assoc()['pending_count'];
$pending_stmt->close();

// Get budget utilization
$budget_stmt = $conn->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total_allocated, COALESCE(SUM(spent_amount), 0) as total_spent, COALESCE(SUM(remaining_amount), 0) as total_remaining FROM tbl_budget_allocations WHERE fiscal_year = ? AND status = 'Approved'");
$budget_stmt->bind_param("i", $fiscal_year);
$budget_stmt->execute();
$budget_data = $budget_stmt->get_result()->fetch_assoc();
$budget_stmt->close();

// Get revenue by category
$revenue_by_category_stmt = $conn->prepare("SELECT rc.category_name, COALESCE(SUM(r.amount), 0) as total FROM tbl_revenue_categories rc LEFT JOIN tbl_revenues r ON rc.category_id = r.category_id AND YEAR(r.transaction_date) = ? AND r.status = 'Verified' WHERE rc.is_active = 1 GROUP BY rc.category_id, rc.category_name ORDER BY total DESC LIMIT 10");
$revenue_by_category_stmt->bind_param("i", $fiscal_year);
$revenue_by_category_stmt->execute();
$revenue_by_category = $revenue_by_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$revenue_by_category_stmt->close();

// Get expenses by category
$expense_by_category_stmt = $conn->prepare("SELECT ec.category_name, COALESCE(SUM(e.amount), 0) as total FROM tbl_expense_categories ec LEFT JOIN tbl_expenses e ON ec.category_id = e.category_id AND YEAR(e.expense_date) = ? AND e.status = 'Released' WHERE ec.is_active = 1 GROUP BY ec.category_id, ec.category_name ORDER BY total DESC LIMIT 10");
$expense_by_category_stmt->bind_param("i", $fiscal_year);
$expense_by_category_stmt->execute();
$expense_by_category = $expense_by_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$expense_by_category_stmt->close();

// Get monthly trend (last 12 months)
$monthly_stmt = $conn->prepare("SELECT DATE_FORMAT(month_date, '%b %Y') as month_label, COALESCE(revenue, 0) as revenue, COALESCE(expenses, 0) as expenses FROM (SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n MONTH), '%Y-%m-01') as month_date FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11) months) calendar LEFT JOIN (SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month, SUM(amount) as revenue FROM tbl_revenues WHERE status = 'Verified' GROUP BY DATE_FORMAT(transaction_date, '%Y-%m-01')) r ON calendar.month_date = r.month LEFT JOIN (SELECT DATE_FORMAT(expense_date, '%Y-%m-01') as month, SUM(amount) as expenses FROM tbl_expenses WHERE status = 'Released' GROUP BY DATE_FORMAT(expense_date, '%Y-%m-01')) e ON calendar.month_date = e.month ORDER BY month_date ASC");
$monthly_stmt->execute();
$monthly_trend = $monthly_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monthly_stmt->close();

// Recent transactions
$recent_stmt = $conn->prepare("(SELECT 'Revenue' as type, r.reference_number, rc.category_name, r.amount, r.transaction_date as trans_date, r.source as details, r.status FROM tbl_revenues r JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id ORDER BY r.created_at DESC LIMIT 5) UNION (SELECT 'Expense' as type, e.reference_number, ec.category_name, e.amount, e.expense_date as trans_date, e.payee as details, e.status FROM tbl_expenses e JOIN tbl_expense_categories ec ON e.category_id = ec.category_id ORDER BY e.created_at DESC LIMIT 5) ORDER BY trans_date DESC LIMIT 10");
$recent_stmt->execute();
$recent_transactions = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_stmt->close();

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root {
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-info:#3b82f6;--db-info-light:#dbeafe;
    --db-violet:#7c3aed;--db-violet-light:#ede9fe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Sora', sans-serif; background: var(--db-bg); color: var(--db-text); font-size: 13.5px; }

/* ── Hero ── */
.fm-hero {
    background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #1a3870 100%);
    padding: 28px 36px; margin-bottom: 24px;
    border-radius: 0 0 var(--db-radius-lg) var(--db-radius-lg);
    position: relative; overflow: hidden;
}
.fm-hero__ring { position: absolute; border-radius: 50%; border: 1px solid rgba(255,255,255,.06); pointer-events: none; }
.fm-hero__ring--1 { width: 320px; height: 320px; top: -140px; right: -70px; }
.fm-hero__ring--2 { width: 190px; height: 190px; top: -55px; right: 80px; border-color: rgba(16,185,129,.12); }
.fm-hero__ring--3 { width: 110px; height: 110px; bottom: -45px; left: 42%; border-color: rgba(99,102,241,.14); }
.fm-hero__inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.fm-hero__left { display: flex; align-items: center; gap: 16px; }
.fm-hero__icon { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, var(--db-success), #059669); display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; box-shadow: 0 4px 16px rgba(16,185,129,.4); flex-shrink: 0; }
.fm-hero__title { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.4px; margin-bottom: 3px; }
.fm-hero__sub { font-size: 13px; color: rgba(255,255,255,.55); }
.fm-hero__right { display: flex; align-items: center; gap: 10px; }
.fm-year-select { padding: 8px 14px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); border-radius: var(--db-radius-sm); color: #fff; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; backdrop-filter: blur(6px); }
.fm-year-select option { background: var(--db-navy); color: #fff; }

/* ── Stat Cards ── */
.db-stats-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.db-stat-card { flex: 1 1 150px; background: var(--db-surf); border-radius: var(--db-radius); padding: 16px 14px 12px; display: flex; flex-direction: column; gap: 9px; box-shadow: var(--db-shadow); border: 1px solid var(--db-border); transition: transform .2s, box-shadow .2s, border-color .2s; text-decoration: none; color: inherit; }
.db-stat-card:hover { transform: translateY(-3px); box-shadow: var(--db-shadow-lg); color: inherit; }
.db-stat-card__icon { width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; }
.db-stat-card__icon--success { background: var(--db-success-light); color: var(--db-success); }
.db-stat-card__icon--teal   { background: var(--db-teal-light);    color: var(--db-teal); }
.db-stat-card__icon--rose   { background: var(--db-rose-light);    color: var(--db-rose); }
.db-stat-card__icon--amber  { background: var(--db-amber-light);   color: var(--db-amber-dark); }
.db-stat-card__icon--indigo { background: var(--db-indigo-light);  color: var(--db-indigo); }
.db-stat-card__icon--violet { background: var(--db-violet-light);  color: var(--db-violet); }
.db-stat-card__num   { font-size: 24px; font-weight: 800; line-height: 1; letter-spacing: -1px; }
.db-stat-card__label { font-size: 10px; color: var(--db-muted); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
.db-stat-card__meta  { font-size: 10.5px; color: var(--db-muted); margin-top: 2px; }
.db-stat-card__bar   { height: 3px; border-radius: 2px; opacity: .4; }
.db-stat-card__bar--success { background: linear-gradient(90deg, var(--db-success), transparent); }
.db-stat-card__bar--teal    { background: linear-gradient(90deg, var(--db-teal),    transparent); }
.db-stat-card__bar--rose    { background: linear-gradient(90deg, var(--db-rose),    transparent); }
.db-stat-card__bar--amber   { background: linear-gradient(90deg, var(--db-amber),   transparent); }
.db-stat-card__bar--indigo  { background: linear-gradient(90deg, var(--db-indigo),  transparent); }
.db-stat-card__bar--violet  { background: linear-gradient(90deg, var(--db-violet),  transparent); }

/* ── Quick Actions ── */
.fm-actions-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
.fm-action-btn {
    display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px;
    border-radius: var(--db-radius-sm); font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600; text-decoration: none; border: none; cursor: pointer;
    transition: all .18s; white-space: nowrap;
}
.fm-action-btn--revenue { background: linear-gradient(135deg, #065f46, var(--db-success)); color: #fff; }
.fm-action-btn--revenue:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(16,185,129,.35); color: #fff; }
.fm-action-btn--expense { background: linear-gradient(135deg, #9f1239, var(--db-rose)); color: #fff; }
.fm-action-btn--expense:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(225,29,72,.35); color: #fff; }
.fm-action-btn--budget  { background: linear-gradient(135deg, #1e40af, var(--db-sky)); color: #fff; }
.fm-action-btn--budget:hover  { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(14,165,233,.35); color: #fff; }
.fm-action-btn--balance { background: linear-gradient(135deg, #4c1d95, var(--db-violet)); color: #fff; }
.fm-action-btn--balance:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(124,58,237,.35); color: #fff; }
.fm-action-btn--report  { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; }
.fm-action-btn--report:hover  { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(13,27,54,.35); color: #fff; }

/* ── Panel ── */
.db-panel { background: var(--db-surf); border-radius: var(--db-radius-lg); border: 1px solid var(--db-border); box-shadow: var(--db-shadow); margin-bottom: 18px; overflow: hidden; animation: dbFadeUp .35s ease both; }
@keyframes dbFadeUp { from { opacity: 0; transform: translateY(10px) } to { opacity: 1; transform: translateY(0) } }
.db-panel__header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid var(--db-border); gap: 10px; flex-wrap: wrap; }
.db-panel__title { display: flex; align-items: center; gap: 10px; }
.db-panel__title h2 { font-size: 15px; font-weight: 700; }
.db-panel__icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.db-panel__icon--success { background: var(--db-success-light); color: var(--db-success); }
.db-panel__icon--teal    { background: var(--db-teal-light);    color: var(--db-teal); }
.db-panel__icon--rose    { background: var(--db-rose-light);    color: var(--db-rose); }
.db-panel__icon--indigo  { background: var(--db-indigo-light);  color: var(--db-indigo); }
.db-panel__icon--amber   { background: var(--db-amber-light);   color: var(--db-amber-dark); }
.db-panel__body { padding: 20px 22px; }

/* ── Charts Row ── */
.db-charts-row { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 18px; }
.db-chart-card { flex: 1 1 300px; background: var(--db-surf); border-radius: var(--db-radius-lg); border: 1px solid var(--db-border); box-shadow: var(--db-shadow); overflow: hidden; animation: dbFadeUp .35s ease both; }
.db-chart-card__header { padding: 16px 22px; border-bottom: 1px solid var(--db-border); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.db-chart-card__header h3 { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.db-chart-card__body { padding: 20px 22px; }

/* ── Budget Utilization ── */
.fm-budget-grid { display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.fm-budget-item { flex: 1 1 100px; background: var(--db-surf2); border-radius: var(--db-radius-sm); padding: 12px 14px; border: 1px solid var(--db-border); text-align: center; }
.fm-budget-item .val { font-size: 16px; font-weight: 800; letter-spacing: -.5px; font-family: 'DM Mono', monospace; }
.fm-budget-item .lbl { font-size: 10px; color: var(--db-muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }
.fm-progress-wrap { margin: 14px 0 6px; }
.fm-progress-bar { height: 8px; background: var(--db-surf2); border-radius: 4px; overflow: hidden; border: 1px solid var(--db-border); }
.fm-progress-fill { height: 100%; border-radius: 4px; transition: width .6s cubic-bezier(.34,1.56,.64,1); }
.fm-progress-fill--ok      { background: linear-gradient(90deg, var(--db-success), #34d399); }
.fm-progress-fill--warning { background: linear-gradient(90deg, var(--db-amber), #fbbf24); }
.fm-progress-fill--danger  { background: linear-gradient(90deg, var(--db-rose), #f43f5e); }
.fm-progress-pct { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--db-muted); text-align: right; margin-top: 4px; }

/* ── Category Bars ── */
.fm-cat-list { display: flex; flex-direction: column; gap: 12px; }
.fm-cat-item { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; align-items: center; }
.fm-cat-name  { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fm-cat-amt   { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700; white-space: nowrap; }
.fm-cat-track { grid-column: 1 / -1; height: 5px; background: var(--db-surf2); border-radius: 3px; overflow: hidden; border: 1px solid var(--db-border); }
.fm-cat-fill  { height: 100%; border-radius: 3px; transition: width .5s ease; }
.fm-cat-fill--revenue { background: linear-gradient(90deg, var(--db-success), #34d399); }
.fm-cat-fill--expense { background: linear-gradient(90deg, var(--db-rose), #f43f5e); }
.fm-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 36px 24px; text-align: center; gap: 10px; }
.fm-empty i { font-size: 36px; color: var(--db-border); }
.fm-empty p  { font-size: 13px; color: var(--db-muted); }

/* ── Table ── */
.db-table-wrap { overflow-x: auto; }
.db-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.db-table thead tr { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); }
.db-table thead th { color: rgba(255,255,255,.8); font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; text-transform: uppercase; letter-spacing: .8px; padding: 11px 16px; white-space: nowrap; border: none; }
.db-table tbody tr { border-bottom: 1px solid var(--db-border); transition: background .12s; }
.db-table tbody tr:last-child { border-bottom: none; }
.db-table tbody tr:hover { background: #f5f8ff; }
.db-table tbody td { padding: 11px 16px; vertical-align: middle; }
.db-id { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--db-indigo); font-weight: 500; }
.db-text-sm { font-size: 11.5px; color: var(--db-muted); }

/* ── Badges ── */
.db-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 20px; font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: .3px; white-space: nowrap; }
.db-badge--success { background: var(--db-success-light); color: #065f46; }
.db-badge--rose    { background: var(--db-rose-light);    color: #9f1239; }
.db-badge--amber   { background: var(--db-amber-light);   color: #92400e; }
.db-badge--sky     { background: var(--db-sky-light);     color: #0369a1; }
.db-badge--indigo  { background: var(--db-indigo-light);  color: #4338ca; }
.db-badge--muted   { background: var(--db-surf2); color: var(--db-muted); border: 1px solid var(--db-border); }

/* ── Transaction type pill ── */
.fm-tx-type { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.fm-tx-type--revenue { background: var(--db-success-light); color: #065f46; }
.fm-tx-type--expense { background: var(--db-rose-light);    color: #9f1239; }

/* ── View-all link ── */
.fm-view-all { font-size: 12px; color: var(--db-teal); font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 5px; }
.fm-view-all:hover { color: var(--db-navy); }

/* ── Net P&L strip ── */
.fm-net-strip { display: flex; align-items: center; gap: 16px; padding: 14px 22px; background: var(--db-surf2); border-top: 1px solid var(--db-border); flex-wrap: wrap; }
.fm-net-strip .label { font-size: 12px; font-weight: 600; color: var(--db-muted); text-transform: uppercase; letter-spacing: .5px; }
.fm-net-strip .amount { font-family: 'DM Mono', monospace; font-size: 18px; font-weight: 800; letter-spacing: -.5px; }
.fm-net-strip .amount--pos { color: var(--db-success); }
.fm-net-strip .amount--neg { color: var(--db-rose); }

/* ═══════════════════════════════
   DARK MODE
═══════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }
body.dark-mode .db-panel,
body.dark-mode .db-chart-card { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-panel__header,
body.dark-mode .db-chart-card__header { border-bottom-color: #334155 !important; }
body.dark-mode .db-panel__title h2,
body.dark-mode .db-chart-card__header h3 { color: #f1f5f9 !important; }
body.dark-mode .db-panel__icon--success { background: #052e16 !important; color: #4ade80 !important; }
body.dark-mode .db-panel__icon--teal    { background: #0d2e2a !important; color: #2dd4bf !important; }
body.dark-mode .db-panel__icon--rose    { background: #2d1c1c !important; color: #fb7185 !important; }
body.dark-mode .db-panel__icon--indigo  { background: #1e1b4b !important; color: #a5b4fc !important; }
body.dark-mode .db-panel__icon--amber   { background: #27211a !important; color: #fbbf24 !important; }
body.dark-mode .db-stat-card { background: #1e293b !important; border-color: #334155 !important; color: #e2e8f0 !important; }
body.dark-mode .db-stat-card:hover { box-shadow: 0 8px 30px rgba(0,0,0,.3) !important; }
body.dark-mode .db-stat-card__icon--success { background: #052e16 !important; color: #4ade80 !important; }
body.dark-mode .db-stat-card__icon--teal    { background: #0d2e2a !important; color: #2dd4bf !important; }
body.dark-mode .db-stat-card__icon--rose    { background: #2d1c1c !important; color: #fb7185 !important; }
body.dark-mode .db-stat-card__icon--amber   { background: #27211a !important; color: #fbbf24 !important; }
body.dark-mode .db-stat-card__icon--indigo  { background: #1e1b4b !important; color: #a5b4fc !important; }
body.dark-mode .db-stat-card__icon--violet  { background: #2e1065 !important; color: #c4b5fd !important; }
body.dark-mode .db-stat-card__label,
body.dark-mode .db-stat-card__meta         { color: #64748b !important; }
body.dark-mode .db-table thead tr          { background: linear-gradient(135deg,#0f172a,#1e293b) !important; }
body.dark-mode .db-table thead th          { color: rgba(148,163,184,.9) !important; }
body.dark-mode .db-table tbody tr          { border-bottom-color: #334155 !important; }
body.dark-mode .db-table tbody tr:hover    { background: #1e293b !important; }
body.dark-mode .db-table tbody td          { color: #e2e8f0 !important; }
body.dark-mode .db-text-sm                 { color: #94a3b8 !important; }
body.dark-mode .db-id                      { color: #a5b4fc !important; }
body.dark-mode .db-badge--success { background: #052e16 !important; color: #4ade80 !important; }
body.dark-mode .db-badge--rose    { background: #2d1c1c !important; color: #fb7185 !important; }
body.dark-mode .db-badge--amber   { background: #27211a !important; color: #fbbf24 !important; }
body.dark-mode .db-badge--sky     { background: #0c2a40 !important; color: #38bdf8 !important; }
body.dark-mode .db-badge--indigo  { background: #1e1b4b !important; color: #a5b4fc !important; }
body.dark-mode .db-badge--muted   { background: #1e293b !important; color: #94a3b8 !important; border-color: #475569 !important; }
body.dark-mode .fm-budget-item    { background: #162032 !important; border-color: #334155 !important; }
body.dark-mode .fm-budget-item .lbl { color: #64748b !important; }
body.dark-mode .fm-progress-bar   { background: #162032 !important; border-color: #334155 !important; }
body.dark-mode .fm-cat-track      { background: #162032 !important; border-color: #334155 !important; }
body.dark-mode .fm-empty i        { color: #334155 !important; }
body.dark-mode .fm-empty p        { color: #64748b !important; }
body.dark-mode .fm-net-strip      { background: #162032 !important; border-top-color: #334155 !important; }
body.dark-mode .fm-net-strip .label { color: #64748b !important; }
body.dark-mode .fm-view-all       { color: #2dd4bf !important; }
body.dark-mode .fm-view-all:hover { color: #f1f5f9 !important; }
body.dark-mode .fm-tx-type--revenue { background: #052e16 !important; color: #4ade80 !important; }
body.dark-mode .fm-tx-type--expense { background: #2d1c1c !important; color: #fb7185 !important; }

@media (max-width: 768px) {
    .fm-hero { padding: 20px; border-radius: 0; }
    .fm-actions-bar { gap: 8px; }
}
</style>

<!-- Hero -->
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div>
    <div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__ring fm-hero__ring--3"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-coins"></i></div>
            <div>
                <div class="fm-hero__title">Financial Management</div>
                <div class="fm-hero__sub">Track revenues, expenses, and budget allocations</div>
            </div>
        </div>
        <div class="fm-hero__right">
            <select class="fm-year-select" onchange="window.location.href='?year='+this.value">
                <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $fiscal_year ? 'selected' : ''; ?>>
                        FY <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
</div>

<div style="padding: 0 24px 24px;">

    <!-- Stat Cards -->
    <div class="db-stats-row">
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-indigo);font-size:18px;">₱<?php echo number_format($current_balance, 2); ?></div>
                <div class="db-stat-card__label">Fund Balance</div>
                <?php if ($total_allocated_budget > 0): ?>
                    <div class="db-stat-card__meta"><i class="fas fa-info-circle"></i> ₱<?php echo number_format($total_allocated_budget, 2); ?> reserved</div>
                <?php endif; ?>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
        </div>
        <a href="revenues.php" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-arrow-down"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-success);font-size:18px;">₱<?php echo number_format($total_revenue, 2); ?></div>
                <div class="db-stat-card__label">Revenue FY <?php echo $fiscal_year; ?></div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--success"></div>
        </a>
        <a href="expenses.php" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-arrow-up"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-rose);font-size:18px;">₱<?php echo number_format($total_expenses, 2); ?></div>
                <div class="db-stat-card__label">Expenses FY <?php echo $fiscal_year; ?></div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
        </a>
        <?php $net = $total_revenue - $total_expenses; ?>
        <div class="db-stat-card">
            <div class="db-stat-card__icon" style="background:<?php echo $net>=0?'var(--db-teal-light)':'var(--db-rose-light)';?>;color:<?php echo $net>=0?'var(--db-teal)':'var(--db-rose)';?>"><i class="fas fa-balance-scale"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:<?php echo $net>=0?'var(--db-teal)':'var(--db-rose)';?>;font-size:18px;"><?php echo ($net>=0?'':'−').'₱'.number_format(abs($net), 2); ?></div>
                <div class="db-stat-card__label">Net <?php echo $net >= 0 ? 'Surplus' : 'Deficit'; ?></div>
            </div>
            <div class="db-stat-card__bar" style="background:linear-gradient(90deg,<?php echo $net>=0?'var(--db-teal)':'var(--db-rose)';?>,transparent);opacity:.4;"></div>
        </div>
        <a href="expenses.php?status=pending" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $pending_expenses; ?></div>
                <div class="db-stat-card__label">Pending Expenses</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
        </a>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-chart-pie"></i></div>
            <div>
                <?php $util = $budget_data['total_allocated'] > 0 ? ($budget_data['total_spent'] / $budget_data['total_allocated']) * 100 : 0; ?>
                <div class="db-stat-card__num" style="color:var(--db-teal)"><?php echo number_format($util, 1); ?>%</div>
                <div class="db-stat-card__label">Budget Utilized</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="fm-actions-bar">
        <a href="revenue-add.php" class="fm-action-btn fm-action-btn--revenue"><i class="fas fa-plus-circle"></i> Add Revenue</a>
        <a href="expenses-add.php" class="fm-action-btn fm-action-btn--expense"><i class="fas fa-minus-circle"></i> Add Expense</a>
        <a href="budget.php" class="fm-action-btn fm-action-btn--budget"><i class="fas fa-chart-pie"></i> Budget Management</a>
        <?php if ($user_role === 'Super Admin'): ?>
        <a href="fund-balance.php" class="fm-action-btn fm-action-btn--balance"><i class="fas fa-wallet"></i> Fund Balance</a>
        <?php endif; ?>
        <a href="reports.php" class="fm-action-btn fm-action-btn--report"><i class="fas fa-file-chart-line"></i> Generate Report</a>
    </div>

    <!-- Charts Row: Monthly Trend + Budget Utilization -->
    <div class="db-charts-row">
        <!-- Monthly Trend -->
        <div class="db-chart-card" style="flex: 2 1 400px;">
            <div class="db-chart-card__header">
                <h3><i class="fas fa-chart-line" style="color:var(--db-teal)"></i> Revenue vs Expenses (Last 12 Months)</h3>
            </div>
            <div class="db-chart-card__body">
                <canvas id="monthlyTrendChart" style="max-height:260px;"></canvas>
            </div>
        </div>

        <!-- Budget Utilization -->
        <div class="db-chart-card" style="flex: 1 1 260px;">
            <div class="db-chart-card__header">
                <h3><i class="fas fa-chart-donut" style="color:var(--db-indigo)"></i> Budget FY <?php echo $fiscal_year; ?></h3>
            </div>
            <div class="db-chart-card__body">
                <div class="fm-budget-grid">
                    <div class="fm-budget-item">
                        <div class="val" style="color:var(--db-indigo)">₱<?php echo number_format($budget_data['total_allocated'], 2); ?></div>
                        <div class="lbl">Allocated</div>
                    </div>
                    <div class="fm-budget-item">
                        <div class="val" style="color:var(--db-rose)">₱<?php echo number_format($budget_data['total_spent'], 2); ?></div>
                        <div class="lbl">Spent</div>
                    </div>
                    <div class="fm-budget-item">
                        <div class="val" style="color:var(--db-success)">₱<?php echo number_format($budget_data['total_remaining'], 2); ?></div>
                        <div class="lbl">Remaining</div>
                    </div>
                </div>
                <?php $uc = $util > 90 ? 'danger' : ($util > 75 ? 'warning' : 'ok'); ?>
                <div class="fm-progress-wrap">
                    <div class="fm-progress-bar">
                        <div class="fm-progress-fill fm-progress-fill--<?php echo $uc; ?>" style="width:<?php echo min($util,100); ?>%"></div>
                    </div>
                    <div class="fm-progress-pct"><?php echo number_format($util, 1); ?>% utilized</div>
                </div>
                <canvas id="budgetDoughnut" style="max-height:160px;margin-top:12px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Category Breakdown -->
    <div class="db-charts-row">
        <!-- Revenue by Category -->
        <div class="db-chart-card">
            <div class="db-chart-card__header">
                <h3><i class="fas fa-chart-bar" style="color:var(--db-success)"></i> Revenue by Category</h3>
                <a href="revenues.php" class="fm-view-all">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="db-chart-card__body">
                <?php $has_rev = array_filter($revenue_by_category, fn($c) => $c['total'] > 0); ?>
                <?php if ($has_rev): ?>
                    <div class="fm-cat-list">
                        <?php foreach ($revenue_by_category as $cat): if ($cat['total'] <= 0) continue; $pct = $total_revenue > 0 ? ($cat['total'] / $total_revenue) * 100 : 0; ?>
                        <div class="fm-cat-item">
                            <span class="fm-cat-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            <span class="fm-cat-amt" style="color:var(--db-success)">₱<?php echo number_format($cat['total'], 2); ?></span>
                            <div class="fm-cat-track"><div class="fm-cat-fill fm-cat-fill--revenue" style="width:<?php echo $pct; ?>%"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="fm-empty"><i class="fas fa-inbox"></i><p>No revenue recorded for <?php echo $fiscal_year; ?></p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expenses by Category -->
        <div class="db-chart-card">
            <div class="db-chart-card__header">
                <h3><i class="fas fa-chart-bar" style="color:var(--db-rose)"></i> Expenses by Category</h3>
                <a href="expenses.php" class="fm-view-all">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="db-chart-card__body">
                <?php $has_exp = array_filter($expense_by_category, fn($c) => $c['total'] > 0); ?>
                <?php if ($has_exp): ?>
                    <div class="fm-cat-list">
                        <?php foreach ($expense_by_category as $cat): if ($cat['total'] <= 0) continue; $pct = $total_expenses > 0 ? ($cat['total'] / $total_expenses) * 100 : 0; ?>
                        <div class="fm-cat-item">
                            <span class="fm-cat-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            <span class="fm-cat-amt" style="color:var(--db-rose)">₱<?php echo number_format($cat['total'], 2); ?></span>
                            <div class="fm-cat-track"><div class="fm-cat-fill fm-cat-fill--expense" style="width:<?php echo $pct; ?>%"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="fm-empty"><i class="fas fa-inbox"></i><p>No expenses recorded for <?php echo $fiscal_year; ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-history"></i></div>
                <h2>Recent Transactions</h2>
                <span class="db-badge db-badge--teal"><?php echo count($recent_transactions); ?></span>
            </div>
            <a href="transactions.php" class="fm-view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Category</th>
                        <th>Details</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_transactions)): ?>
                    <tr><td colspan="7"><div class="fm-empty"><i class="fas fa-inbox"></i><p>No transactions found</p></div></td></tr>
                <?php else: foreach ($recent_transactions as $trans):
                    $is_rev = $trans['type'] === 'Revenue';
                    $status_badges = [
                        'Verified'  => 'success', 'Released' => 'success',
                        'Pending'   => 'amber',
                        'Approved'  => 'sky',
                        'Rejected'  => 'rose',
                    ];
                    $sbadge = $status_badges[$trans['status']] ?? 'muted';
                ?>
                    <tr>
                        <td>
                            <span class="fm-tx-type fm-tx-type--<?php echo $is_rev ? 'revenue' : 'expense'; ?>">
                                <i class="fas fa-<?php echo $is_rev ? 'arrow-down' : 'arrow-up'; ?>"></i>
                                <?php echo $trans['type']; ?>
                            </span>
                        </td>
                        <td><span class="db-id"><?php echo htmlspecialchars($trans['reference_number']); ?></span></td>
                        <td><?php echo htmlspecialchars($trans['category_name']); ?></td>
                        <td><span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($trans['details'] ?? '', 0, 45, '…')); ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($trans['trans_date'])); ?></span></td>
                        <td>
                            <strong style="color:<?php echo $is_rev ? 'var(--db-success)' : 'var(--db-rose)'; ?>">
                                <?php echo ($is_rev ? '+' : '−'); ?>₱<?php echo number_format($trans['amount'], 2); ?>
                            </strong>
                        </td>
                        <td><span class="db-badge db-badge--<?php echo $sbadge; ?>"><?php echo htmlspecialchars($trans['status']); ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Net strip -->
        <div class="fm-net-strip">
            <span class="label"><i class="fas fa-calculator"></i> FY <?php echo $fiscal_year; ?> Net</span>
            <span class="amount <?php echo $net >= 0 ? 'amount--pos' : 'amount--neg'; ?>">
                <?php echo ($net >= 0 ? '+' : '−') . '₱' . number_format(abs($net), 2); ?>
            </span>
            <span class="db-text-sm" style="margin-left:auto;">
                Revenue ₱<?php echo number_format($total_revenue, 2); ?> &nbsp;·&nbsp; Expenses ₱<?php echo number_format($total_expenses, 2); ?>
            </span>
        </div>
    </div>

</div><!-- /padding -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const isDark = document.body.classList.contains('dark-mode');
    const gridColor  = isDark ? 'rgba(148,163,184,.12)' : 'rgba(0,0,0,.06)';
    const tickColor  = isDark ? '#64748b' : '#94a3b8';

    // Monthly Trend
    const mCtx = document.getElementById('monthlyTrendChart')?.getContext('2d');
    if (mCtx) {
        new Chart(mCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($monthly_trend, 'month_label')); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode(array_column($monthly_trend, 'revenue')); ?>,
                    borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.1)',
                    tension: 0.4, fill: true, pointRadius: 3, pointHoverRadius: 5
                }, {
                    label: 'Expenses',
                    data: <?php echo json_encode(array_column($monthly_trend, 'expenses')); ?>,
                    borderColor: '#e11d48', backgroundColor: 'rgba(225,29,72,.08)',
                    tension: 0.4, fill: true, pointRadius: 3, pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { color: tickColor, font: { family: 'Sora', size: 12 } } },
                    tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ₱' + ctx.parsed.y.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } }
                },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { color: tickColor, font: { family: 'DM Mono', size: 11 } } },
                    y: { grid: { color: gridColor }, beginAtZero: true, ticks: { color: tickColor, font: { family: 'DM Mono', size: 11 }, callback: v => '₱' + v.toLocaleString() } }
                }
            }
        });
    }

    // Budget Doughnut
    const bCtx = document.getElementById('budgetDoughnut')?.getContext('2d');
    if (bCtx) {
        const spent     = <?php echo (float)$budget_data['total_spent']; ?>;
        const remaining = <?php echo (float)$budget_data['total_remaining']; ?>;
        if (spent > 0 || remaining > 0) {
            new Chart(bCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Spent', 'Remaining'],
                    datasets: [{ data: [spent, remaining], backgroundColor: ['#e11d48', '#10b981'], borderWidth: 0, hoverOffset: 4 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true, cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: tickColor, font: { family: 'Sora', size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ctx.label + ': ₱' + ctx.parsed.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } }
                    }
                }
            });
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>