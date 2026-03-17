<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin', 'Secretary']);

$page_title = 'Distribution Reports';

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$location_filter = $_GET['location'] ?? '';
$item_filter     = $_GET['item']     ?? '';

$check_columns = $conn->query("SHOW COLUMNS FROM tbl_relief_distributions");
$columns = [];
while ($row = $check_columns->fetch_assoc()) $columns[] = $row['Field'];
$date_column = in_array('distribution_date',$columns) ? 'distribution_date' : (in_array('date',$columns) ? 'date' : 'created_at');
$has_beneficiaries = in_array('total_beneficiaries', $columns);
$beneficiaries_column = $has_beneficiaries ? 'total_beneficiaries' : '0 as total_beneficiaries';

$where_clauses = ["1=1"];
$where_clauses_no_alias = ["1=1"];
$params = []; $types = '';
if (!empty($date_from)) { $where_clauses[]="DATE(rd.$date_column) >= ?"; $where_clauses_no_alias[]="DATE($date_column) >= ?"; $params[]=$date_from; $types.='s'; }
if (!empty($date_to))   { $where_clauses[]="DATE(rd.$date_column) <= ?"; $where_clauses_no_alias[]="DATE($date_column) <= ?"; $params[]=$date_to;   $types.='s'; }
if (!empty($location_filter)) { $where_clauses[]="rd.location LIKE ?"; $where_clauses_no_alias[]="location LIKE ?"; $params[]="%{$location_filter}%"; $types.='s'; }
$where_sql = implode(' AND ', $where_clauses);
$where_sql_no_alias = implode(' AND ', $where_clauses_no_alias);

$distributions_sql = "SELECT rd.*, rd.$date_column as distribution_date, rd.$beneficiaries_column,
                      u.username as distributed_by_name, COUNT(DISTINCT rdi.item_id) as items_count
                      FROM tbl_relief_distributions rd
                      LEFT JOIN tbl_users u ON rd.distributed_by = u.user_id
                      LEFT JOIN tbl_relief_distribution_items rdi ON rd.distribution_id = rdi.distribution_id
                      WHERE $where_sql GROUP BY rd.distribution_id
                      ORDER BY rd.$date_column DESC, rd.distribution_id DESC";
$distributions = !empty($params) ? fetchAll($conn,$distributions_sql,$params,$types) : fetchAll($conn,$distributions_sql);

$locations = fetchAll($conn,"SELECT DISTINCT location FROM tbl_relief_distributions ORDER BY location");
$items     = fetchAll($conn,"SELECT item_id, item_name FROM tbl_relief_items ORDER BY item_name");

$total_distributions = count($distributions);
$total_beneficiaries = array_sum(array_column($distributions,'total_beneficiaries'));

$items_summary_sql = "SELECT ri.item_name, ri.unit_of_measure, ri.item_category,
                      SUM(rdi.quantity_distributed) as total_distributed
                      FROM tbl_relief_distribution_items rdi
                      JOIN tbl_relief_items ri ON rdi.item_id = ri.item_id
                      JOIN tbl_relief_distributions rd ON rdi.distribution_id = rd.distribution_id
                      WHERE $where_sql GROUP BY rdi.item_id ORDER BY total_distributed DESC";
$items_summary = !empty($params) ? fetchAll($conn,$items_summary_sql,$params,$types) : fetchAll($conn,$items_summary_sql);

$location_summary_sql = "SELECT location, COUNT(*) as distribution_count,
                         ".($has_beneficiaries?"SUM(total_beneficiaries)":"0")." as beneficiary_count
                         FROM tbl_relief_distributions WHERE $where_sql_no_alias
                         GROUP BY location ORDER BY beneficiary_count DESC";
$location_summary = !empty($params) ? fetchAll($conn,$location_summary_sql,$params,$types) : fetchAll($conn,$location_summary_sql);

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
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.jb-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.jb-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.jb-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.jb-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.jb-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.jb-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.jb-hero__left{display:flex;align-items:center;gap:16px;}
.jb-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-indigo),#4338ca);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.jb-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.jb-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.jb-hero__actions{display:flex;gap:10px;flex-wrap:wrap;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 130px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__body{padding:20px 22px;}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));color:#fff;}
.db-btn--teal:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.3);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--sky{background:linear-gradient(135deg,#0369a1,var(--db-sky));color:#fff;}
.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}
.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);color:#fff;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;width:100%;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:700px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
/* Detail grid */
.db-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);overflow:hidden;margin-bottom:16px;}
.db-detail-cell{padding:10px 14px;border-bottom:1px solid var(--db-border);}
.db-detail-cell:nth-last-child(-n+2){border-bottom:none;}
.db-detail-cell .lbl{font-size:10px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.db-detail-cell .val{font-size:13px;font-weight:600;}
/* Spinner */
.db-spinner{width:32px;height:32px;border:3px solid var(--db-teal);border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;}
@keyframes spin{to{transform:rotate(360deg)}}
/* Print */
@media print{
    .no-print{display:none !important;}
    body{background:#fff !important;}
    .jb-hero{border-radius:0;border:2px solid #212529;background:#fff !important;}
    .jb-hero__title,.jb-hero__sub{color:#212529 !important;}
    .db-panel{box-shadow:none;border:1px solid #dee2e6;}
    .db-table thead tr{background:#f8f9fa !important;-webkit-print-color-adjust:exact;}
    .db-table thead th{color:#212529 !important;}
}
/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-stat-card__label{color:#64748b !important;}
body.dark-mode .db-input,body.dark-mode .db-select{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-input:focus,body.dark-mode .db-select:focus{border-color:#60a5fa !important;box-shadow:0 0 0 3px rgba(96,165,250,.15) !important;}
body.dark-mode .db-select option{background:#1e293b !important;color:#e2e8f0 !important;}
body.dark-mode .db-select option:hover,body.dark-mode .db-select option:checked{background:#334155 !important;color:#f1f5f9 !important;}
body.dark-mode .db-filter-label{color:#94a3b8 !important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b) !important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9) !important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155 !important;}
body.dark-mode .db-table tbody tr:hover{background:#1e293b !important;}
body.dark-mode .db-table tbody td{color:#e2e8f0 !important;}
body.dark-mode .db-text-sm{color:#94a3b8 !important;}
body.dark-mode .db-empty i{color:#334155 !important;}
body.dark-mode .db-empty p{color:#64748b !important;}
body.dark-mode .db-modal__box{background:#1e293b !important;}
body.dark-mode .db-modal__body{background:#1e293b !important;}
body.dark-mode .db-detail-grid{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-detail-cell{border-bottom-color:#334155 !important;}
body.dark-mode .db-detail-cell .lbl{color:#64748b !important;}
body.dark-mode .db-detail-cell .val{color:#e2e8f0 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
</style>

<!-- Hero -->
<div class="jb-hero">
    <div class="jb-hero__ring jb-hero__ring--1"></div>
    <div class="jb-hero__ring jb-hero__ring--2"></div>
    <div class="jb-hero__ring jb-hero__ring--3"></div>
    <div class="jb-hero__inner">
        <div class="jb-hero__left">
            <div class="jb-hero__icon"><i class="fas fa-chart-bar"></i></div>
            <div>
                <div class="jb-hero__title">Distribution Reports</div>
                <div class="jb-hero__sub">Comprehensive analysis of relief goods distribution</div>
            </div>
        </div>
        <div class="jb-hero__actions no-print">
            <button onclick="window.print()" class="db-btn db-btn--ghost-white"><i class="fas fa-print"></i> Print</button>
            <button onclick="exportToExcel()" class="db-btn db-btn--success"><i class="fas fa-file-excel"></i> Export</button>
            <a href="inventory.php" class="db-btn db-btn--ghost-white"><i class="fas fa-arrow-left"></i> Inventory</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-hands-helping"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($total_distributions); ?></div><div class="db-stat-card__label">Total Distributions</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($total_beneficiaries); ?></div><div class="db-stat-card__label">Total Beneficiaries</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-box"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo count($items_summary); ?></div><div class="db-stat-card__label">Item Types Distributed</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-map-marker-alt"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo count($location_summary); ?></div><div class="db-stat-card__label">Locations Served</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </div>
</div>

<!-- Filters -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div>
            <h2>Filter Reports</h2>
        </div>
        <?php if ($date_from != date('Y-m-01') || $date_to != date('Y-m-d') || $location_filter): ?>
        <a href="distribution-report.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-redo"></i> Reset</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div style="flex:1;min-width:140px;">
                    <label class="db-filter-label">Date From</label>
                    <input type="date" name="date_from" class="db-input" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div style="flex:1;min-width:140px;">
                    <label class="db-filter-label">Date To</label>
                    <input type="date" name="date_to" class="db-input" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div style="flex:2;min-width:160px;">
                    <label class="db-filter-label">Location</label>
                    <select name="location" class="db-select">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo htmlspecialchars($loc['location']); ?>" <?php echo $location_filter===$loc['location']?'selected':''; ?>>
                            <?php echo htmlspecialchars($loc['location']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Distribution by Location -->
<?php if (!empty($location_summary)): ?>
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-map-marked-alt"></i></div>
            <h2>Distribution by Location</h2>
            <span class="db-badge db-badge--indigo"><?php echo count($location_summary); ?></span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Location</th><th>Distributions</th><th>Total Beneficiaries</th><th>Avg per Distribution</th></tr></thead>
            <tbody>
            <?php foreach ($location_summary as $loc): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($loc['location']); ?></strong></td>
                <td><span class="db-badge db-badge--sky"><?php echo number_format($loc['distribution_count']); ?></span></td>
                <td><span class="db-badge db-badge--success"><?php echo number_format($loc['beneficiary_count']); ?></span></td>
                <td><span style="font-family:'DM Mono',monospace;font-size:12px;"><?php echo $loc['distribution_count']>0?number_format($loc['beneficiary_count']/$loc['distribution_count'],0):'0'; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Items Summary -->
<?php if (!empty($items_summary)): ?>
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-boxes"></i></div>
            <h2>Items Distributed Summary</h2>
            <span class="db-badge db-badge--teal"><?php echo count($items_summary); ?> items</span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Item Name</th><th>Category</th><th>Total Quantity</th><th>Unit</th></tr></thead>
            <tbody>
            <?php
            $cat_map=['Food'=>'success','Water'=>'sky','Medicine'=>'rose','Clothing'=>'indigo','Hygiene'=>'teal','Other'=>'muted'];
            foreach ($items_summary as $item):
                $cc=$cat_map[$item['item_category']]??'muted';
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                <td><span class="db-badge db-badge--<?php echo $cc; ?>"><?php echo htmlspecialchars($item['item_category']); ?></span></td>
                <td><strong style="font-family:'DM Mono',monospace;"><?php echo number_format($item['total_distributed'],2); ?></strong></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($item['unit_of_measure']); ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Detailed Records -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-list"></i></div>
            <h2>Detailed Distribution Records</h2>
            <span class="db-badge db-badge--sky"><?php echo count($distributions); ?></span>
        </div>
        <span class="db-text-sm no-print"><?php echo htmlspecialchars($date_from); ?> – <?php echo htmlspecialchars($date_to); ?></span>
    </div>
    <?php if (!empty($distributions)): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Date</th><th>Location</th><th>Beneficiaries</th><th>Items</th><th>Distributed By</th><th class="no-print">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($distributions as $dist): ?>
            <tr>
                <td><span class="db-text-sm"><?php echo formatDate($dist['distribution_date']); ?></span></td>
                <td><strong><?php echo htmlspecialchars($dist['location']); ?></strong></td>
                <td><span class="db-badge db-badge--success"><?php echo number_format($dist['total_beneficiaries']??0); ?></span></td>
                <td><span class="db-badge db-badge--sky"><?php echo $dist['items_count']; ?> items</span></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($dist['distributed_by_name']); ?></span></td>
                <td class="no-print">
                    <button class="db-btn db-btn--sky db-btn--sm" onclick="viewDistributionDetails(<?php echo $dist['distribution_id']; ?>)">
                        <i class="fas fa-eye"></i> Details
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="db-panel__body">
        <div class="db-empty"><i class="fas fa-inbox"></i><p>No distribution records found for the selected filters.</p></div>
    </div>
    <?php endif; ?>
</div>

</div><!-- /padding -->

<!-- Distribution Details Modal -->
<div id="detailsModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-info-circle"></i> Distribution Details</h3>
            <button class="db-modal__close" onclick="closeDetailsModal()">×</button>
        </div>
        <div class="db-modal__body" id="detailsContent">
            <div style="text-align:center;padding:32px;">
                <div class="db-spinner"></div>
                <p style="color:var(--db-muted);">Loading details…</p>
            </div>
        </div>
    </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
function closeDetailsModal(){ document.getElementById('detailsModal').classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click',e=>{ if(e.target.classList.contains('db-modal')) closeDetailsModal(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeDetailsModal(); });

function viewDistributionDetails(id) {
    const content = document.getElementById('detailsContent');
    content.innerHTML = '<div style="text-align:center;padding:32px;"><div class="db-spinner"></div><p style="color:var(--db-muted);">Loading…</p></div>';
    document.getElementById('detailsModal').classList.add('db-modal--open');
    document.body.style.overflow = 'hidden';

    fetch('get_distribution_details.php?id='+id)
        .then(r=>r.json())
        .then(data=>{
            if (!data.success) { content.innerHTML='<p style="color:var(--db-rose);">Error loading details.</p>'; return; }
            const d = data.distribution;
            const cat_map={Food:'success',Water:'sky',Medicine:'rose',Clothing:'indigo',Hygiene:'teal',Other:'muted'};
            let html = `
                <div class="db-detail-grid">
                    <div class="db-detail-cell"><div class="lbl">Date</div><div class="val">${d.distribution_date}</div></div>
                    <div class="db-detail-cell"><div class="lbl">Location</div><div class="val">${d.location}</div></div>
                    <div class="db-detail-cell"><div class="lbl">Beneficiaries</div><div class="val">${parseInt(d.total_beneficiaries||0).toLocaleString()}</div></div>
                    <div class="db-detail-cell"><div class="lbl">Distributed By</div><div class="val">${d.distributed_by_name}</div></div>
                    ${d.remarks?`<div class="db-detail-cell" style="grid-column:1/-1;"><div class="lbl">Remarks</div><div class="val" style="font-weight:400;color:var(--db-muted);">${d.remarks}</div></div>`:''}
                </div>
                <div style="font-size:13px;font-weight:700;margin:16px 0 10px;">Items Distributed</div>
                <div class="db-table-wrap">
                <table class="db-table">
                    <thead><tr><th>Item</th><th>Category</th><th>Quantity</th><th>Unit</th></tr></thead>
                    <tbody>
            `;
            data.items.forEach(item=>{
                const cc = cat_map[item.item_category]||'muted';
                html += `<tr>
                    <td><strong>${item.item_name}</strong></td>
                    <td><span class="db-badge db-badge--${cc}">${item.item_category}</span></td>
                    <td><strong style="font-family:'DM Mono',monospace;">${parseFloat(item.quantity_distributed).toFixed(2)}</strong></td>
                    <td><span class="db-text-sm">${item.unit_of_measure}</span></td>
                </tr>`;
            });
            html += `</tbody></table></div>
                <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--db-border);text-align:right;">
                    <button class="db-btn db-btn--ghost" onclick="closeDetailsModal()"><i class="fas fa-times"></i> Close</button>
                </div>`;
            content.innerHTML = html;
        })
        .catch(()=>{ content.innerHTML='<p style="color:var(--db-rose);">Error loading distribution details.</p>'; });
}

function exportToExcel() {
    let csv = 'Distribution Report\nGenerated: '+new Date().toLocaleDateString()+'\n';
    csv += 'Date Range: <?php echo $date_from; ?> to <?php echo $date_to; ?>\n\n';
    csv += 'SUMMARY\nTotal Distributions,<?php echo $total_distributions; ?>\nTotal Beneficiaries,<?php echo $total_beneficiaries; ?>\nItem Types,<?php echo count($items_summary); ?>\nLocations,<?php echo count($location_summary); ?>\n\n';
    csv += 'BY LOCATION\nLocation,Distributions,Beneficiaries,Avg\n';
    <?php foreach ($location_summary as $loc): ?>
    csv += '"<?php echo addslashes($loc['location']); ?>",<?php echo $loc['distribution_count']; ?>,<?php echo $loc['beneficiary_count']; ?>,<?php echo $loc['distribution_count']>0?round($loc['beneficiary_count']/$loc['distribution_count'],0):0; ?>\n';
    <?php endforeach; ?>
    csv += '\nITEMS SUMMARY\nItem,Category,Quantity,Unit\n';
    <?php foreach ($items_summary as $item): ?>
    csv += '"<?php echo addslashes($item['item_name']); ?>","<?php echo addslashes($item['item_category']); ?>",<?php echo $item['total_distributed']; ?>,"<?php echo addslashes($item['unit_of_measure']); ?>"\n';
    <?php endforeach; ?>
    csv += '\nDETAILED RECORDS\nDate,Location,Beneficiaries,Items,Distributed By\n';
    <?php foreach ($distributions as $dist): ?>
    csv += '"<?php echo formatDate($dist['distribution_date']); ?>","<?php echo addslashes($dist['location']); ?>",<?php echo $dist['total_beneficiaries']??0; ?>,<?php echo $dist['items_count']; ?>,"<?php echo addslashes($dist['distributed_by_name']); ?>"\n';
    <?php endforeach; ?>
    const blob = new Blob([csv],{type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href=url; a.download='distribution_report_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
}
</script>

<?php include '../../includes/footer.php'; ?>