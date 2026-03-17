<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Revenue Management';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'verify' && isset($_POST['revenue_id'])) {
        $revenue_id = intval($_POST['revenue_id']);
        $stmt = $conn->prepare("UPDATE tbl_revenues SET status = 'Verified', verified_by = ?, verification_date = NOW() WHERE revenue_id = ?");
        $stmt->bind_param("ii", $current_user_id, $revenue_id); $stmt->execute();
        $rev_stmt = $conn->prepare("SELECT amount FROM tbl_revenues WHERE revenue_id = ?");
        $rev_stmt->bind_param("i", $revenue_id); $rev_stmt->execute();
        $amount = $rev_stmt->get_result()->fetch_assoc()['amount']; $rev_stmt->close();
        $ub = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = current_balance + ?, updated_by = ?, last_updated = NOW() ORDER BY balance_id DESC LIMIT 1");
        $ub->bind_param("di", $amount, $current_user_id); $ub->execute(); $ub->close();
        $_SESSION['success_message'] = "Revenue verified successfully!"; $stmt->close();
        header('Location: revenues.php'); exit();
    } elseif ($_POST['action'] === 'cancel' && isset($_POST['revenue_id'])) {
        $revenue_id = intval($_POST['revenue_id']);
        $stmt = $conn->prepare("UPDATE tbl_revenues SET status = 'Cancelled' WHERE revenue_id = ?");
        $stmt->bind_param("i", $revenue_id); if($stmt->execute()) $_SESSION['success_message'] = "Revenue rejected successfully!";
        $stmt->close(); header('Location: revenues.php'); exit();
    } elseif ($_POST['action'] === 'delete' && isset($_POST['revenue_id'])) {
        $revenue_id = intval($_POST['revenue_id']);
        $stmt = $conn->prepare("DELETE FROM tbl_revenues WHERE revenue_id = ? AND status = 'Pending'");
        $stmt->bind_param("i", $revenue_id); if($stmt->execute()) $_SESSION['success_message'] = "Revenue deleted successfully!";
        $stmt->close(); header('Location: revenues.php'); exit();
    }
}

$status_filter   = isset($_GET['status'])   ? $_GET['status']          : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$date_from       = isset($_GET['date_from'])? $_GET['date_from']        : '';
$date_to         = isset($_GET['date_to'])  ? $_GET['date_to']          : '';
$search          = isset($_GET['search'])   ? trim($_GET['search'])     : '';
$doc_only        = isset($_GET['doc_only']) && $_GET['doc_only'] === '1';
$page_num        = isset($_GET['page'])     ? intval($_GET['page'])     : 1;
$per_page        = 15;
$offset          = ($page_num - 1) * $per_page;

$where_clauses = []; $params = []; $types = '';
if ($status_filter)   { $where_clauses[] = "r.status = ?";        $params[] = $status_filter;   $types .= 's'; }
if ($category_filter) { $where_clauses[] = "r.category_id = ?";   $params[] = $category_filter; $types .= 'i'; }
if ($date_from)       { $where_clauses[] = "r.transaction_date >= ?"; $params[] = $date_from;   $types .= 's'; }
if ($date_to)         { $where_clauses[] = "r.transaction_date <= ?"; $params[] = $date_to;     $types .= 's'; }
if ($search)          { $where_clauses[] = "(r.reference_number LIKE ? OR r.source LIKE ? OR r.description LIKE ?)"; $sp="%$search%"; $params[]=$sp;$params[]=$sp;$params[]=$sp; $types.='sss'; }
if ($doc_only)        { $where_clauses[] = "r.description LIKE '%Payment for%Request #%'"; }
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$cs = $conn->prepare("SELECT COUNT(*) as total FROM tbl_revenues r $where_sql");
if ($params) $cs->bind_param($types, ...$params); $cs->execute();
$total_records = $cs->get_result()->fetch_assoc()['total']; $cs->close();
$total_pages = ceil($total_records / $per_page);

$sql = "SELECT r.*, rc.category_name, u1.username as received_by_name, u2.username as verified_by_name
        FROM tbl_revenues r
        LEFT JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
        LEFT JOIN tbl_users u1 ON r.received_by = u1.user_id
        LEFT JOIN tbl_users u2 ON r.verified_by = u2.user_id
        $where_sql ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$fp = array_merge($params, [$per_page, $offset]); $ft = $types . 'ii';
$stmt = $conn->prepare($sql); $stmt->bind_param($ft, ...$fp); $stmt->execute();
$revenues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$categories         = fetchAll($conn, "SELECT * FROM tbl_revenue_categories WHERE is_active = 1 ORDER BY category_name");
$total_pending      = fetchOne($conn, "SELECT COALESCE(SUM(amount),0) as total FROM tbl_revenues WHERE status='Pending'")['total'];
$total_verified     = fetchOne($conn, "SELECT COALESCE(SUM(amount),0) as total FROM tbl_revenues WHERE status='Verified'")['total'];
$total_doc_payments = fetchOne($conn, "SELECT COALESCE(SUM(amount),0) as total FROM tbl_revenues WHERE status='Verified' AND description LIKE '%Payment for%Request #%'")['total'];

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1f5c3a 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(16,185,129,.12);}
.fm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(14,165,233,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#065f46,var(--db-success));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(16,185,129,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;text-decoration:none;color:inherit;cursor:pointer;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca);}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--blue{background:#dbeafe;color:#1d4ed8;}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody tr.doc-pay-row{background:#f0f9ff;}
.db-table tbody tr.doc-pay-row:hover{background:#e0f2fe;}
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
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-pagination{display:flex;justify-content:center;gap:6px;padding:18px 22px;border-top:1px solid var(--db-border);}
.db-page-link{padding:6px 12px;border:1.5px solid var(--db-border);background:var(--db-surf);color:var(--db-text);text-decoration:none;border-radius:var(--db-radius-sm);font-size:12px;font-weight:600;transition:all .15s;}
.db-page-link:hover{background:var(--db-surf2);}
.db-page-link.active{background:var(--db-navy);color:#fff;border-color:var(--db-navy);}
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:500px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}
.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}
.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
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
.db-notice--blue{background:#dbeafe;color:#1e40af;}
.db-form-group{margin-bottom:14px;}
.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}
.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;resize:vertical;min-height:70px;}
.db-textarea:focus{border-color:var(--db-navy-light);}
.doc-badge{display:inline-block;margin-top:3px;font-size:10px;font-weight:700;background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:20px;font-family:'DM Mono',monospace;}
/* dark */
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
body.dark-mode .db-table tbody tr.doc-pay-row{background:#0c2340!important;}
body.dark-mode .db-table tbody tr.doc-pay-row:hover{background:#0e2e4e!important;}
body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-id{color:#a5b4fc!important;}
body.dark-mode .db-input,.db-select{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
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
body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-empty i{color:#334155!important;}
body.dark-mode .db-empty p{color:#64748b!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}}
</style>

<!-- Hero -->
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div>
    <div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__ring fm-hero__ring--3"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-arrow-down"></i></div>
            <div>
                <div class="fm-hero__title">Revenue Management</div>
                <div class="fm-hero__sub">Track and manage all barangay revenue</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="revenue-add.php" class="db-btn db-btn--success"><i class="fas fa-plus-circle"></i> Add Revenue</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <a href="revenues.php" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success);font-size:17px;">₱<?php echo number_format($total_verified, 2); ?></div><div class="db-stat-card__label">Verified Revenue</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </a>
    <a href="revenues.php?status=Pending" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark);font-size:17px;">₱<?php echo number_format($total_pending, 2); ?></div><div class="db-stat-card__label">Pending Verification</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="revenues.php?doc_only=1" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-file-invoice-dollar"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky);font-size:17px;">₱<?php echo number_format($total_doc_payments, 2); ?></div><div class="db-stat-card__label">Document Payments</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-search"></i></div>
            <h2>Filter Revenues</h2>
        </div>
        <?php if ($doc_only): ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <span class="db-badge db-badge--blue"><i class="fas fa-filter"></i> Document Payments Only</span>
            <a href="revenues.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div><label class="db-filter-label">Status</label><select name="status" class="db-select"><option value="">All</option><option value="Pending" <?php echo $status_filter==='Pending'?'selected':''; ?>>Pending</option><option value="Verified" <?php echo $status_filter==='Verified'?'selected':''; ?>>Verified</option><option value="Cancelled" <?php echo $status_filter==='Cancelled'?'selected':''; ?>>Cancelled</option></select></div>
                <div><label class="db-filter-label">Category</label><select name="category" class="db-select"><option value="0">All Categories</option><?php foreach($categories as $c): ?><option value="<?php echo $c['category_id']; ?>" <?php echo $category_filter==$c['category_id']?'selected':''; ?>><?php echo htmlspecialchars($c['category_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="db-filter-label">Date From</label><input type="date" name="date_from" class="db-input" value="<?php echo $date_from; ?>"></div>
                <div><label class="db-filter-label">Date To</label><input type="date" name="date_to" class="db-input" value="<?php echo $date_to; ?>"></div>
                <div style="flex:1;min-width:180px;"><label class="db-filter-label">Search</label><input type="text" name="search" class="db-input" style="width:100%;" placeholder="Reference, source…" value="<?php echo htmlspecialchars($search); ?>"></div>
                <div><label class="db-filter-label">Type</label><select name="doc_only" class="db-select"><option value="0" <?php echo !$doc_only?'selected':''; ?>>All Revenue</option><option value="1" <?php echo $doc_only?'selected':''; ?>>Doc Payments Only</option></select></div>
                <div style="padding-top:18px;display:flex;gap:8px;"><button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button><a href="revenues.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i></a></div>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--success"><i class="fas fa-list"></i></div>
            <h2><?php echo $doc_only ? 'Document Payment Records' : 'Revenue Records'; ?></h2>
            <span class="db-badge db-badge--success"><?php echo number_format($total_records); ?></span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Reference #</th><th>Date</th><th>Category</th><th>Source / Document</th><th>Amount</th><th>Method</th><th>Received By</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($revenues)): ?>
                <tr><td colspan="9"><div class="db-empty"><i class="fas fa-inbox"></i><p>No revenue records found</p></div></td></tr>
            <?php else: foreach ($revenues as $rev):
                $is_doc = (strpos($rev['description']??'','Payment for')!==false && strpos($rev['description']??'','Request #')!==false);
                $doc_type=''; $request_id='';
                if ($is_doc && preg_match('/Payment for (.+?) \(Request #0*(\d+)\)/',$rev['description'],$m)) { $doc_type=$m[1]; $request_id=intval($m[2]); }
                $src_parts = explode(' – ', $rev['source'], 2);
                $sc=['Verified'=>'success','Pending'=>'amber','Cancelled'=>'rose'];
                $sbadge=$sc[$rev['status']]??'muted';
            ?>
                <tr <?php echo $is_doc?'class="doc-pay-row"':''; ?>>
                    <td>
                        <span class="db-id"><?php echo htmlspecialchars($rev['reference_number']); ?></span>
                        <?php if ($is_doc): ?><br><span class="doc-badge"><i class="fas fa-file-alt"></i> Doc Payment</span><?php endif; ?>
                    </td>
                    <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($rev['transaction_date'])); ?></span></td>
                    <td><span class="db-badge db-badge--muted"><?php echo htmlspecialchars($rev['category_name']??'—'); ?></span></td>
                    <td>
                        <strong><?php echo htmlspecialchars($src_parts[0]); ?></strong>
                        <?php if ($is_doc && $doc_type): ?>
                            <br><span class="db-text-sm" style="color:#2563eb;"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($doc_type); ?>
                            <?php if ($request_id): ?> — <a href="../requests/view-request.php?id=<?php echo $request_id; ?>" style="color:#2563eb;text-decoration:none;">Req #<?php echo str_pad($request_id,5,'0',STR_PAD_LEFT); ?> <i class="fas fa-external-link-alt" style="font-size:9px;"></i></a><?php endif; ?></span>
                        <?php elseif (isset($src_parts[1])): ?>
                            <br><span class="db-text-sm"><?php echo htmlspecialchars($src_parts[1]); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><strong style="color:var(--db-success);font-family:'DM Mono',monospace;">₱<?php echo number_format($rev['amount'], 2); ?></strong></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($rev['payment_method']); ?></span></td>
                    <td>
                        <span class="db-text-sm"><?php echo htmlspecialchars($rev['received_by_name']??'—'); ?></span>
                        <?php if ($rev['verified_by_name'] && $rev['status']==='Verified'): ?><br><span class="db-text-sm" style="color:var(--db-success);"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($rev['verified_by_name']); ?></span><?php endif; ?>
                    </td>
                    <td><span class="db-badge db-badge--<?php echo $sbadge; ?>"><?php echo $rev['status']; ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <button class="db-icon-btn db-icon-btn--default" onclick='viewRevenue(<?php echo json_encode($rev); ?>)' title="View"><i class="fas fa-eye"></i></button>
                            <?php if ($rev['status']==='Pending'): ?>
                                <button class="db-icon-btn db-icon-btn--success" onclick='openVerifyModal(<?php echo json_encode($rev); ?>)' title="Verify"><i class="fas fa-check"></i></button>
                                <button class="db-icon-btn db-icon-btn--rose" onclick='openRejectModal(<?php echo json_encode($rev); ?>)' title="Reject"><i class="fas fa-times"></i></button>
                            <?php endif; ?>
                            <?php if ($is_doc && $request_id): ?>
                                <a class="db-icon-btn db-icon-btn--sky" href="../requests/view-request.php?id=<?php echo $request_id; ?>" title="View Request"><i class="fas fa-file-alt"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1):
        $qp = $_GET; unset($qp['page']); $bu = 'revenues.php?' . http_build_query($qp) . '&page='; ?>
    <div class="db-pagination">
        <?php if ($page_num > 1): ?><a href="<?php echo $bu.($page_num-1); ?>" class="db-page-link"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php for ($i=max(1,$page_num-2); $i<=min($total_pages,$page_num+2); $i++): ?><a href="<?php echo $bu.$i; ?>" class="db-page-link <?php echo $i===$page_num?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
        <?php if ($page_num < $total_pages): ?><a href="<?php echo $bu.($page_num+1); ?>" class="db-page-link"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- VIEW MODAL -->
<div id="viewRevenueModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-eye"></i> Revenue Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewRevenueModal')">×</button>
        </div>
        <div class="db-modal__body"><div id="revenueDetails"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewRevenueModal')"><i class="fas fa-times"></i> Close</button></div></div>
    </div>
</div>

<!-- VERIFY MODAL -->
<div id="verifyModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--success">
            <h3><i class="fas fa-check-circle"></i> Verify Revenue</h3>
            <button class="db-modal__close" onclick="closeModal('verifyModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST" id="verifyForm">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="revenue_id" id="verify_revenue_id">
                <div class="db-notice db-notice--success"><i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i><span>Verifying this revenue will <strong>add the amount to the fund balance</strong>.</span></div>
                <div id="verifyInfo" class="db-confirm-grid"></div>
                <div class="db-form-group" style="margin-top:14px;">
                    <label class="db-form-label">Remarks (Optional)</label>
                    <textarea name="remarks" class="db-textarea" placeholder="Add any notes…"></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('verifyModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Verify Revenue</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- REJECT MODAL -->
<div id="rejectModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-times-circle"></i> Reject Revenue</h3>
            <button class="db-modal__close" onclick="closeModal('rejectModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST" id="rejectForm">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="revenue_id" id="reject_revenue_id">
                <div id="rejectInfo" class="db-confirm-grid"></div>
                <div class="db-form-group" style="margin-top:14px;">
                    <label class="db-form-label db-form-label--req">Reason for Rejection</label>
                    <textarea name="remarks" class="db-textarea" placeholder="Please provide a reason…" required></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('rejectModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--rose"><i class="fas fa-times-circle"></i> Reject Revenue</button>
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

function buildInfoGrid(items){return items.map(i=>`<div class="db-confirm-row"><span class="lbl">${i[0]}</span><span class="val" ${i[2]?`style="color:${i[2]};"`:''}>${i[1]}</span></div>`).join('');}

function viewRevenue(r){
    const isDoc=r.description&&r.description.includes('Payment for')&&r.description.includes('Request #');
    let docLink='';
    if(isDoc){const m=r.description.match(/Payment for .+? \(Request #0*(\d+)\)/);if(m){const id=parseInt(m[1]);docLink=`<div class="db-confirm-row"><span class="lbl">Document Request</span><span class="val"><a href="../requests/view-request.php?id=${id}" style="color:#2563eb;font-weight:700;" target="_blank">Request #${String(id).padStart(5,'0')} <i class="fas fa-external-link-alt" style="font-size:9px;"></i></a></span></div>`;}}
    const sc={Verified:'var(--db-success)',Pending:'var(--db-amber-dark)',Cancelled:'var(--db-rose)'};
    document.getElementById('revenueDetails').innerHTML=`
        ${isDoc?'<div class="db-notice db-notice--blue"><i class="fas fa-file-alt" style="flex-shrink:0;"></i><span><strong>Document Payment Revenue</strong></span></div>':''}
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Reference #</span><span class="val db-id">${r.reference_number}</span></div>
            <div class="db-confirm-row"><span class="lbl">Category</span><span class="val">${r.category_name||'—'}</span></div>
            <div class="db-confirm-row"><span class="lbl">Amount</span><span class="val" style="color:var(--db-success);font-family:'DM Mono',monospace;">₱${parseFloat(r.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
            <div class="db-confirm-row"><span class="lbl">Source</span><span class="val">${r.source}</span></div>
            ${docLink}
            <div class="db-confirm-row"><span class="lbl">Transaction Date</span><span class="val">${new Date(r.transaction_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>
            <div class="db-confirm-row"><span class="lbl">Payment Method</span><span class="val">${r.payment_method}</span></div>
            ${r.receipt_number?`<div class="db-confirm-row"><span class="lbl">Receipt #</span><span class="val">${r.receipt_number}</span></div>`:''}
            <div class="db-confirm-row"><span class="lbl">Received By</span><span class="val">${r.received_by_name||'—'}</span></div>
            ${r.verified_by_name?`<div class="db-confirm-row"><span class="lbl">Verified By</span><span class="val" style="color:var(--db-success);">${r.verified_by_name}</span></div>`:''}
            <div class="db-confirm-row"><span class="lbl">Status</span><span class="val" style="color:${sc[r.status]||'inherit'}">${r.status}</span></div>
            ${r.description?`<div class="db-confirm-row" style="flex-direction:column;gap:4px;"><span class="lbl">Description</span><span class="val" style="text-align:left;">${r.description}</span></div>`:''}
        </div>`;
    openModal('viewRevenueModal');
}

function openVerifyModal(r){
    document.getElementById('verify_revenue_id').value=r.revenue_id;
    document.getElementById('verifyInfo').innerHTML=buildInfoGrid([['Reference #',r.reference_number],['Source',r.source],['Amount','₱'+parseFloat(r.amount).toLocaleString('en-PH',{minimumFractionDigits:2}),'var(--db-success)'],['Category',r.category_name||'—']]);
    openModal('verifyModal');
}
function openRejectModal(r){
    document.getElementById('reject_revenue_id').value=r.revenue_id;
    document.getElementById('rejectInfo').innerHTML=buildInfoGrid([['Reference #',r.reference_number],['Source',r.source],['Amount','₱'+parseFloat(r.amount).toLocaleString('en-PH',{minimumFractionDigits:2}),'var(--db-rose)'],['Category',r.category_name||'—']]);
    openModal('rejectModal');
}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>

<?php include '../../includes/footer.php'; ?>