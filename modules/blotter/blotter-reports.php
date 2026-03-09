<?php
/**
 * Blotter Reports Page — redesigned to match db design system
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
if ($user_role === 'Resident') { header('Location: my-blotter.php'); exit(); }

$page_title = 'Blotter Reports & Statistics';

$start_date    = isset($_GET['start_date']) && $_GET['start_date']!='' ? $_GET['start_date'] : '';
$end_date      = isset($_GET['end_date'])   && $_GET['end_date']!=''   ? $_GET['end_date']   : '';
$status_filter = isset($_GET['status'])     && $_GET['status']!=''     ? $_GET['status']     : '';

$where_clause = "1=1"; $params=[]; $types="";
if ($start_date && $end_date) { $where_clause.=" AND incident_date BETWEEN ? AND ?"; $params[]=$start_date; $params[]=$end_date; $types.="ss"; }
if ($status_filter)           { $where_clause.=" AND status = ?"; $params[]=$status_filter; $types.="s"; }

$stmt=$conn->prepare("SELECT COUNT(*) as total FROM tbl_blotter WHERE $where_clause");
if (!empty($params)) $stmt->bind_param($types,...$params);
$stmt->execute(); $total_count=$stmt->get_result()->fetch_assoc()['total']; $stmt->close();

$stmt=$conn->prepare("SELECT status,COUNT(*) as count FROM tbl_blotter WHERE $where_clause GROUP BY status");
if (!empty($params)) $stmt->bind_param($types,...$params);
$stmt->execute(); $status_data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt=$conn->prepare("SELECT incident_type,COUNT(*) as count FROM tbl_blotter WHERE $where_clause GROUP BY incident_type ORDER BY count DESC LIMIT 10");
if (!empty($params)) $stmt->bind_param($types,...$params);
$stmt->execute(); $type_data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$trend_where="incident_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
if ($status_filter) {
    $stmt=$conn->prepare("SELECT DATE_FORMAT(incident_date,'%Y-%m') as month,COUNT(*) as count FROM tbl_blotter WHERE $trend_where AND status=? GROUP BY month ORDER BY month ASC");
    $stmt->bind_param("s",$status_filter); $stmt->execute(); $trend_data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} else {
    $trend_data=$conn->query("SELECT DATE_FORMAT(incident_date,'%Y-%m') as month,COUNT(*) as count FROM tbl_blotter WHERE $trend_where GROUP BY month ORDER BY month ASC")->fetch_all(MYSQLI_ASSOC);
}

// Excel export
if (isset($_GET['export']) && $_GET['export']==='excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="blotter_report_'.date('Y-m-d').'.xls"');
    echo "<table border='1'><tr><th colspan='4' style='background:#0d1b36;color:white;font-size:16px;'>BLOTTER REPORT</th></tr>";
    echo "<tr><th colspan='4'>Period: ".($start_date&&$end_date?date('F d, Y',strtotime($start_date))." to ".date('F d, Y',strtotime($end_date)):'All Records')."</th></tr>";
    if ($status_filter) echo "<tr><th colspan='4'>Status: ".htmlspecialchars($status_filter)."</th></tr>";
    echo "<tr><th colspan='4'>&nbsp;</th></tr><tr><th>Metric</th><th>Count</th><th></th><th></th></tr>";
    $sc_map=['Pending'=>0,'Under Investigation'=>0,'Resolved'=>0,'Archived'=>0,'Closed'=>0];
    foreach($status_data as $s) $sc_map[$s['status']]=$s['count'];
    foreach(['Total Records'=>$total_count,'Pending'=>$sc_map['Pending'],'Under Investigation'=>$sc_map['Under Investigation'],'Resolved'=>$sc_map['Resolved'],'Closed'=>max($sc_map['Closed'],$sc_map['Archived'])] as $k=>$v) echo "<tr><td>$k</td><td>$v</td><td></td><td></td></tr>";
    echo "<tr><td colspan='4'>&nbsp;</td></tr><tr><th>Incident Type</th><th>Count</th><th>%</th><th></th></tr>";
    foreach($type_data as $t) echo "<tr><td>".htmlspecialchars($t['incident_type'])."</td><td>".$t['count']."</td><td>".($total_count?number_format($t['count']/$total_count*100,1):0)."%</td><td></td></tr>";
    echo "<tr><td colspan='4'>&nbsp;</td></tr><tr><th>Month</th><th>Cases</th><th></th><th></th></tr>";
    foreach($trend_data as $t) echo "<tr><td>".date('F Y',strtotime($t['month'].'-01'))."</td><td>".$t['count']."</td><td></td><td></td></tr>";
    echo "</table>"; exit();
}

// PDF export
if (isset($_GET['export']) && $_GET['export']==='pdf') {
    $sc_pdf=['Pending'=>0,'Under Investigation'=>0,'Resolved'=>0,'Archived'=>0,'Closed'=>0];
    foreach($status_data as $s) $sc_pdf[$s['status']]=$s['count'];
    ?><!DOCTYPE html><html><head><title>Blotter Report</title>
    <style>@page{margin:20px;}body{font-family:Arial,sans-serif;font-size:11px;}.hdr{text-align:center;border-bottom:2px solid #0d1b36;margin-bottom:12px;padding-bottom:8px;}table{width:100%;border-collapse:collapse;margin-bottom:10px;}th,td{border:1px solid #ddd;padding:4px 6px;text-align:left;}th{background:#f2f2f2;font-weight:700;}</style>
    </head><body><div class="hdr"><h2 style="margin:4px 0;">BARANGAY BLOTTER REPORT</h2>
    <p style="margin:2px 0;">Period: <?php echo $start_date&&$end_date?date('F d, Y',strtotime($start_date)).' to '.date('F d, Y',strtotime($end_date)):'All Records'; ?></p>
    <p style="margin:2px 0;">Generated: <?php echo date('F d, Y h:i A'); ?></p></div>
    <h3 style="font-size:12px;">Summary</h3>
    <table><tr><th>Metric</th><th>Count</th></tr>
    <?php foreach(['Total Records'=>$total_count,'Pending'=>$sc_pdf['Pending'],'Under Investigation'=>$sc_pdf['Under Investigation'],'Resolved'=>$sc_pdf['Resolved'],'Closed'=>max($sc_pdf['Closed'],$sc_pdf['Archived'])] as $k=>$v): ?>
    <tr><td><?php echo $k; ?></td><td><strong><?php echo $v; ?></strong></td></tr><?php endforeach; ?>
    </table>
    <h3 style="font-size:12px;">Top Incident Types</h3>
    <table><tr><th>Incident Type</th><th>Count</th><th>%</th></tr>
    <?php foreach($type_data as $t): $pct=$total_count?number_format($t['count']/$total_count*100,1):0; ?>
    <tr><td><?php echo htmlspecialchars($t['incident_type']); ?></td><td><?php echo $t['count']; ?></td><td><?php echo $pct; ?>%</td></tr>
    <?php endforeach; ?>
    </table>
    <h3 style="font-size:12px;">Monthly Trend</h3>
    <table><tr><th>Month</th><th>Cases</th></tr>
    <?php foreach($trend_data as $t): ?>
    <tr><td><?php echo date('F Y',strtotime($t['month'].'-01')); ?></td><td><?php echo $t['count']; ?></td></tr>
    <?php endforeach; ?>
    </table>
    <script>window.print();setTimeout(()=>window.close(),100);</script>
    </body></html><?php exit();
}

$sc=['Pending'=>0,'Under Investigation'=>0,'Resolved'=>0,'Archived'=>0,'Closed'=>0];
foreach($status_data as $s) $sc[$s['status']]=$s['count'];
$closed_count = max($sc['Closed'],$sc['Archived']);

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0;}
.rm-hero__icon--amber{background:linear-gradient(135deg,var(--db-amber),var(--db-amber-dark));box-shadow:0 4px 16px rgba(245,158,11,.4);}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.db-panel__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--muted{background:var(--db-surf2);color:var(--db-muted);}
.db-panel__body{padding:20px 22px;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
.db-form-label{display:block;font-size:12px;font-weight:600;color:var(--db-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.db-form-control{width:100%;padding:10px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;}
.db-form-control:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
select.db-form-control{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 13px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);color:var(--db-text);}
.db-btn--glass{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}
.db-btn--glass:hover{background:rgba(255,255,255,.22);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--danger{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--danger:hover{transform:translateY(-1px);color:#fff;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:40px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
/* Shared dark-header table styles */
.db-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-tbl thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-tbl thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-tbl tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-tbl tbody tr:last-child{border-bottom:none;}
.db-tbl tbody tr:hover{background:var(--db-surf2);}
.db-tbl tbody td{padding:11px 16px;vertical-align:middle;}
@media print{.no-print{display:none !important}}
@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon rm-hero__icon--amber"><i class="fas fa-chart-bar"></i></div>
            <div>
                <div class="rm-hero__title">Blotter Reports &amp; Statistics</div>
                <div class="rm-hero__sub">Analyze blotter records and trends</div>
            </div>
        </div>
        <div class="rm-hero__badges">
            <?php if ($start_date && $end_date): ?>
            <span class="db-badge db-badge--sky"><i class="fas fa-calendar-alt"></i> <?php echo date('M d',strtotime($start_date)).' – '.date('M d, Y',strtotime($end_date)); ?></span>
            <?php endif; ?>
            <?php if ($status_filter): ?>
            <span class="db-badge db-badge--amber"><i class="fas fa-filter"></i> <?php echo htmlspecialchars($status_filter); ?></span>
            <?php endif; ?>
            <a href="manage-blotter.php" class="db-btn db-btn--glass db-btn--sm"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<!-- Filter Panel -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-filter"></i></div>
            <h2>Filter Reports</h2>
        </div>
        <?php if ($start_date||$end_date||$status_filter): ?>
        <a href="blotter-reports.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filters</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="db-form-label">Start Date</label>
                <input type="date" name="start_date" class="db-form-control" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="col-md-3">
                <label class="db-form-label">End Date</label>
                <input type="date" name="end_date" class="db-form-control" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="col-md-3">
                <label class="db-form-label">Status</label>
                <select name="status" class="db-form-control">
                    <option value="">All Statuses</option>
                    <?php foreach(['Pending','Under Investigation','Resolved','Archived'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo($status_filter===$s)?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="db-btn db-btn--primary" style="width:100%;justify-content:center;">
                    <i class="fas fa-search"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Stat Cards -->
<div class="db-stats-row">
    <?php
    $stat_cards = [
        ['icon'=>'fa-clipboard-list', 'label'=>'Total Records',   'value'=>$total_count,              'color'=>'navy',    'num_color'=>'var(--db-navy)'],
        ['icon'=>'fa-clock',          'label'=>'Pending',         'value'=>$sc['Pending'],             'color'=>'amber',   'num_color'=>'var(--db-amber-dark)'],
        ['icon'=>'fa-search',         'label'=>'Investigating',   'value'=>$sc['Under Investigation'], 'color'=>'sky',     'num_color'=>'var(--db-sky)'],
        ['icon'=>'fa-check-circle',   'label'=>'Resolved',        'value'=>$sc['Resolved'],            'color'=>'success', 'num_color'=>'var(--db-success)'],
        ['icon'=>'fa-archive',        'label'=>'Closed',          'value'=>$closed_count,              'color'=>'muted',   'num_color'=>'var(--db-muted)'],
    ];
    foreach($stat_cards as $c): ?>
    <div class="db-stat-card">
        <div class="db-panel__icon db-panel__icon--<?php echo $c['color']; ?>" style="width:40px;height:40px;border-radius:10px;">
            <i class="fas <?php echo $c['icon']; ?>"></i>
        </div>
        <div>
            <div class="db-stat-card__num" style="color:<?php echo $c['num_color']; ?>;"><?php echo $c['value']; ?></div>
            <div class="db-stat-card__label"><?php echo $c['label']; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-3">
    <!-- Top Incident Types -->
    <div class="col-lg-6">
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-chart-pie"></i></div>
                    <h2>Top Incident Types</h2>
                    <span class="db-badge db-badge--amber"><?php echo count($type_data); ?></span>
                </div>
            </div>
            <?php if (empty($type_data)): ?>
            <div class="db-empty"><i class="fas fa-inbox"></i><p>No data available</p></div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="db-tbl">
                    <thead><tr>
                        <th>Incident Type</th>
                        <th style="text-align:right;">Count</th>
                        <th style="text-align:right;">%</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($type_data as $t): $pct=$total_count?number_format($t['count']/$total_count*100,1):0; ?>
                    <tr>
                        <td>
                            <i class="fas fa-circle" style="color:var(--db-amber);font-size:7px;margin-right:8px;vertical-align:middle;"></i>
                            <?php echo htmlspecialchars($t['incident_type']); ?>
                        </td>
                        <td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace;"><?php echo $t['count']; ?></td>
                        <td style="text-align:right;"><span class="db-badge db-badge--amber"><?php echo $pct; ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Distribution -->
    <div class="col-lg-6">
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--success"><i class="fas fa-chart-bar"></i></div>
                    <h2>Status Distribution</h2>
                </div>
            </div>
            <?php if (empty($status_data)): ?>
            <div class="db-empty"><i class="fas fa-inbox"></i><p>No data available</p></div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="db-tbl">
                    <thead><tr>
                        <th>Status</th>
                        <th style="text-align:right;">Count</th>
                        <th style="text-align:right;">%</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $badge_map = ['Pending'=>'amber','Under Investigation'=>'sky','Resolved'=>'success','Archived'=>'muted','Closed'=>'muted'];
                    foreach($status_data as $s):
                        $pct = $total_count ? number_format($s['count']/$total_count*100,1) : 0;
                        $bc  = $badge_map[$s['status']] ?? 'muted';
                    ?>
                    <tr>
                        <td>
                            <i class="fas fa-circle" style="color:var(--db-<?php echo $bc === 'muted' ? 'muted' : $bc; ?>);font-size:7px;margin-right:8px;vertical-align:middle;"></i>
                            <span class="db-badge db-badge--<?php echo $bc; ?>"><?php echo htmlspecialchars($s['status']); ?></span>
                        </td>
                        <td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace;"><?php echo $s['count']; ?></td>
                        <td style="text-align:right;"><span class="db-badge db-badge--<?php echo $bc; ?>"><?php echo $pct; ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Monthly Trend -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-chart-line"></i></div>
            <h2>Monthly Trend <span style="font-weight:400;font-size:12px;color:var(--db-muted);">(last 12 months)</span></h2>
        </div>
    </div>
    <?php if (empty($trend_data)): ?>
    <div class="db-empty"><i class="fas fa-inbox"></i><p>No data available</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="db-tbl">
            <thead><tr>
                <th>Month</th>
                <th style="text-align:right;">Cases</th>
            </tr></thead>
            <tbody>
            <?php foreach($trend_data as $t): ?>
            <tr>
                <td>
                    <i class="far fa-calendar-alt" style="color:var(--db-sky);margin-right:8px;font-size:11px;"></i>
                    <?php echo date('F Y',strtotime($t['month'].'-01')); ?>
                </td>
                <td style="text-align:right;"><span class="db-badge db-badge--sky"><?php echo $t['count']; ?> cases</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Export Panel -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-download"></i></div>
            <h2>Export Reports</h2>
        </div>
    </div>
    <div class="db-panel__body" style="text-align:center;">
        <p style="color:var(--db-muted);margin-bottom:18px;font-size:13px;">Download blotter reports in your preferred format</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <button type="button" class="db-btn db-btn--ghost" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
            <a href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&status=<?php echo urlencode($status_filter); ?>&export=pdf"
               target="_blank" class="db-btn db-btn--primary">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
            <a href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&status=<?php echo urlencode($status_filter); ?>&export=excel"
               class="db-btn db-btn--success">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
        </div>
    </div>
</div>

</div>
<?php include '../../includes/footer.php'; ?>