<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();
$page_title = 'Transaction History';
$user_role  = getCurrentUserRole();
if (!in_array($user_role, ['Super Admin', 'Treasurer'])) { header('Location: ../../modules/dashboard/index.php'); exit(); }

$tx_type  = $_GET['type']      ?? '';
$date_from= $_GET['date_from'] ?? '';
$date_to  = $_GET['date_to']   ?? '';
$search   = trim($_GET['search'] ?? '');
$pg       = intval($_GET['page'] ?? 1);
$pp       = 20; $ofs = ($pg - 1) * $pp;

$rw = ["r.status='Verified'"]; $ew = ["e.status='Released'"]; $par=[]; $tp='';
if ($date_from){$rw[]="r.transaction_date>=?";$ew[]="e.expense_date>=?";$par[]=$date_from;$par[]=$date_from;$tp.='ss';}
if ($date_to)  {$rw[]="r.transaction_date<=?";$ew[]="e.expense_date<=?";$par[]=$date_to;$par[]=$date_to;$tp.='ss';}
$rwsql='WHERE '.implode(' AND ',$rw); $ewsql='WHERE '.implode(' AND ',$ew);

if ($tx_type==='revenue') {
    $sql="SELECT 'Revenue' as type,r.reference_number,rc.category_name,r.source as details,r.amount,r.transaction_date as trans_date,r.payment_method,u.username as processed_by,r.created_at FROM tbl_revenues r JOIN tbl_revenue_categories rc ON r.category_id=rc.category_id LEFT JOIN tbl_users u ON r.received_by=u.user_id $rwsql";
    if ($search){$sql.=" AND (r.reference_number LIKE ? OR r.source LIKE ?)";$sp="%$search%";$par[]=$sp;$par[]=$sp;$tp.='ss';}
} elseif ($tx_type==='expense') {
    $sql="SELECT 'Expense' as type,e.reference_number,ec.category_name,e.payee as details,e.amount,e.expense_date as trans_date,e.payment_method,u.username as processed_by,e.created_at FROM tbl_expenses e JOIN tbl_expense_categories ec ON e.category_id=ec.category_id LEFT JOIN tbl_users u ON e.requested_by=u.user_id $ewsql";
    if ($search){$sql.=" AND (e.reference_number LIKE ? OR e.payee LIKE ?)";$sp="%$search%";$par[]=$sp;$par[]=$sp;$tp.='ss';}
} else {
    $sql="(SELECT 'Revenue' as type,r.reference_number,rc.category_name,r.source as details,r.amount,r.transaction_date as trans_date,r.payment_method,u.username as processed_by,r.created_at FROM tbl_revenues r JOIN tbl_revenue_categories rc ON r.category_id=rc.category_id LEFT JOIN tbl_users u ON r.received_by=u.user_id $rwsql";
    if ($search){$sql.=" AND (r.reference_number LIKE ? OR r.source LIKE ?)";$sp="%$search%";$par[]=$sp;$par[]=$sp;$tp.='ss';}
    $sql.=") UNION (SELECT 'Expense' as type,e.reference_number,ec.category_name,e.payee as details,e.amount,e.expense_date as trans_date,e.payment_method,u.username as processed_by,e.created_at FROM tbl_expenses e JOIN tbl_expense_categories ec ON e.category_id=ec.category_id LEFT JOIN tbl_users u ON e.requested_by=u.user_id $ewsql";
    if ($search){$sql.=" AND (e.reference_number LIKE ? OR e.payee LIKE ?)";$sp="%$search%";$par[]=$sp;$par[]=$sp;$tp.='ss';}
    $sql.=")";
}
$sql.=" ORDER BY trans_date DESC, created_at DESC LIMIT ? OFFSET ?";
$fp=array_merge($par,[$pp,$ofs]); $ft=$tp.'ii';
$st=$conn->prepare($sql); if(!empty($fp)) $st->bind_param($ft,...$fp); $st->execute();
$transactions=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
$total_revenue=$total_expense=0;
foreach($transactions as $t){ if($t['type']==='Revenue') $total_revenue+=$t['amount']; else $total_expense+=$t['amount']; }
$net=$total_revenue-$total_expense;
include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#0f4c75 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(14,165,233,.12);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0c4a6e,#0ea5e9);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__num{font-size:18px;font-weight:800;line-height:1;letter-spacing:-.6px;font-family:'DM Mono',monospace;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__body{padding:20px 22px;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
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
.fm-tx-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.fm-tx-pill--revenue{background:var(--db-success-light);color:#065f46;}
.fm-tx-pill--expense{background:var(--db-rose-light);color:#9f1239;}
.fm-net-strip{display:flex;align-items:center;gap:16px;padding:14px 22px;background:var(--db-surf2);border-top:1px solid var(--db-border);flex-wrap:wrap;}
.fm-net-strip .lbl{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;}
.fm-net-strip .amt{font-family:'DM Mono',monospace;font-size:18px;font-weight:800;letter-spacing:-.5px;}
.fm-net-strip .amt--pos{color:var(--db-success);}
.fm-net-strip .amt--neg{color:var(--db-rose);}
.db-pagination{display:flex;justify-content:center;gap:6px;padding:18px 22px;border-top:1px solid var(--db-border);}
.db-page-link{padding:6px 12px;border:1.5px solid var(--db-border);background:var(--db-surf);color:var(--db-text);text-decoration:none;border-radius:var(--db-radius-sm);font-size:12px;font-weight:600;transition:all .15s;}
.db-page-link:hover{background:var(--db-surf2);}
.db-page-link.active{background:var(--db-navy);color:#fff;border-color:var(--db-navy);}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel{background:#1e293b!important;border-color:#334155!important;}
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
body.dark-mode .fm-net-strip{background:#162032!important;border-top-color:#334155!important;}
body.dark-mode .fm-net-strip .lbl{color:#64748b!important;}
body.dark-mode .db-empty i{color:#334155!important;}
body.dark-mode .db-empty p{color:#64748b!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}}
</style>
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-history"></i></div>
            <div><div class="fm-hero__title">Transaction History</div><div class="fm-hero__sub">Complete list of all financial transactions</div></div>
        </div>
        <button class="db-btn db-btn--ghost" style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.1);" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
    </div>
</div>
<div style="padding:0 24px 24px;">
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-arrow-down"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)">₱<?php echo number_format($total_revenue,2); ?></div><div class="db-stat-card__label">Revenue (filtered)</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-arrow-up"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)">₱<?php echo number_format($total_expense,2); ?></div><div class="db-stat-card__label">Expenses (filtered)</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-balance-scale"></i></div>
        <div><div class="db-stat-card__num" style="color:<?php echo $net>=0?'var(--db-teal)':'var(--db-rose)'; ?>"><?php echo ($net>=0?'+':'−').'₱'.number_format(abs($net),2); ?></div><div class="db-stat-card__label">Net Amount</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
</div>
<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-search"></i></div><h2>Filter Transactions</h2></div></div>
    <div class="db-panel__body">
        <form method="GET"><div class="db-form-row">
            <div><label class="db-filter-label">Type</label><select name="type" class="db-select"><option value="">All</option><option value="revenue" <?php echo $tx_type==='revenue'?'selected':''; ?>>Revenue</option><option value="expense" <?php echo $tx_type==='expense'?'selected':''; ?>>Expense</option></select></div>
            <div><label class="db-filter-label">Date From</label><input type="date" name="date_from" class="db-input" value="<?php echo $date_from; ?>"></div>
            <div><label class="db-filter-label">Date To</label><input type="date" name="date_to" class="db-input" value="<?php echo $date_to; ?>"></div>
            <div style="flex:1;min-width:180px;"><label class="db-filter-label">Search</label><input type="text" name="search" class="db-input" style="width:100%;" placeholder="Reference, details…" value="<?php echo htmlspecialchars($search); ?>"></div>
            <div style="padding-top:18px;display:flex;gap:8px;"><button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button><a href="transactions.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i></a></div>
        </div></form>
    </div>
</div>
<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></div><h2>All Transactions</h2><span class="db-badge db-badge--muted"><?php echo count($transactions); ?></span></div>
    </div>
    <div class="db-table-wrap"><table class="db-table" id="txTable">
        <thead><tr><th>Type</th><th>Date</th><th>Reference #</th><th>Category</th><th>Details</th><th>Amount</th><th>Method</th><th>Processed By</th></tr></thead>
        <tbody>
        <?php if(empty($transactions)): ?><tr><td colspan="8"><div class="db-empty"><i class="fas fa-inbox"></i><p>No transactions found</p></div></td></tr>
        <?php else: foreach($transactions as $t): $is_rev=$t['type']==='Revenue'; ?>
        <tr>
            <td><span class="fm-tx-pill fm-tx-pill--<?php echo $is_rev?'revenue':'expense'; ?>"><i class="fas fa-<?php echo $is_rev?'arrow-down':'arrow-up'; ?>"></i> <?php echo $t['type']; ?></span></td>
            <td><span class="db-text-sm"><?php echo date('M d, Y',strtotime($t['trans_date'])); ?></span></td>
            <td><span class="db-id"><?php echo htmlspecialchars($t['reference_number']); ?></span></td>
            <td><span class="db-badge db-badge--muted"><?php echo htmlspecialchars($t['category_name']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($t['details']??'',0,45,'…')); ?></span></td>
            <td><strong style="font-family:'DM Mono',monospace;color:<?php echo $is_rev?'var(--db-success)':'var(--db-rose)'; ?>"><?php echo $is_rev?'+':'−'; ?>₱<?php echo number_format($t['amount'],2); ?></strong></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($t['payment_method']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($t['processed_by']??'—'); ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
    <div class="fm-net-strip">
        <span class="lbl"><i class="fas fa-calculator"></i> Net</span>
        <span class="amt <?php echo $net>=0?'amt--pos':'amt--neg'; ?>"><?php echo ($net>=0?'+':'−').'₱'.number_format(abs($net),2); ?></span>
        <span class="db-text-sm" style="margin-left:auto;">Revenue ₱<?php echo number_format($total_revenue,2); ?> &nbsp;·&nbsp; Expenses ₱<?php echo number_format($total_expense,2); ?></span>
    </div>
</div>
</div>
<script>
function exportCSV(){
    const t=document.getElementById('txTable');
    const rows=[[...t.querySelectorAll('thead th')].map(h=>'"'+h.textContent.trim()+'"').join(',')];
    t.querySelectorAll('tbody tr').forEach(tr=>{if(tr.querySelector('td[colspan]'))return;rows.push([...tr.querySelectorAll('td')].map(td=>'"'+td.textContent.trim().replace(/"/g,'""')+'"').join(','));});
    const blob=new Blob([rows.join('\n')],{type:'text/csv;charset=utf-8;'});
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
    a.download='transactions_'+new Date().toISOString().split('T')[0]+'.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>
<?php include '../../includes/footer.php'; ?>