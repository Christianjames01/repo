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
    $sc=['Pending'=>0,'Under Investigation'=>0,'Resolved'=>0,'Archived'=>0,'Closed'=>0];
    foreach($status_data as $s) $sc[$s['status']]=$s['count'];
    foreach(['Total Records'=>$total_count,'Pending'=>$sc['Pending'],'Under Investigation'=>$sc['Under Investigation'],'Resolved'=>$sc['Resolved'],'Closed'=>max($sc['Closed'],$sc['Archived'])] as $k=>$v) echo "<tr><td>$k</td><td>$v</td><td></td><td></td></tr>";
    echo "<tr><td colspan='4'>&nbsp;</td></tr><tr><th>Incident Type</th><th>Count</th><th>%</th><th></th></tr>";
    foreach($type_data as $t) echo "<tr><td>".htmlspecialchars($t['incident_type'])."</td><td>".$t['count']."</td><td>".($total_count?number_format($t['count']/$total_count*100,1):0)."%</td><td></td></tr>";
    echo "<tr><td colspan='4'>&nbsp;</td></tr><tr><th>Month</th><th>Cases</th><th></th><th></th></tr>";
    foreach($trend_data as $t) echo "<tr><td>".date('F Y',strtotime($t['month'].'-01'))."</td><td>".$t['count']."</td><td></td><td></td></tr>";
    echo "</table>"; exit();
}

// PDF export
if (isset($_GET['export']) && $_GET['export']==='pdf') {
    $sc=['Pending'=>0,'Under Investigation'=>0,'Resolved'=>0,'Archived'=>0,'Closed'=>0];
    foreach($status_data as $s) $sc[$s['status']]=$s['count'];
    ?><!DOCTYPE html><html><head><title>Blotter Report</title>
    <style>@page{margin:20px;}body{font-family:Arial,sans-serif;font-size:11px;}.hdr{text-align:center;border-bottom:2px solid #0d1b36;margin-bottom:12px;padding-bottom:8px;}table{width:100%;border-collapse:collapse;margin-bottom:10px;}th,td{border:1px solid #ddd;padding:4px 6px;text-align:left;}th{background:#f2f2f2;font-weight:700;}</style>
    </head><body><div class="hdr"><h2 style="margin:4px 0;">BARANGAY BLOTTER REPORT</h2>
    <p style="margin:2px 0;">Period: <?php echo $start_date&&$end_date?date('F d, Y',strtotime($start_date)).' to '.date('F d, Y',strtotime($end_date)):'All Records'; ?></p>
    <p style="margin:2px 0;">Generated: <?php echo date('F d, Y h:i A'); ?></p></div>
    <h3 style="font-size:12px;">Summary</h3>
    <table><tr><th>Metric</th><th>Count</th></tr>
    <?php foreach(['Total Records'=>$total_count,'Pending'=>$sc['Pending'],'Under Investigation'=>$sc['Under Investigation'],'Resolved'=>$sc['Resolved'],'Closed'=>max($sc['Closed'],$sc['Archived'])] as $k=>$v): ?>
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
<?php include '/home/claude/_db_shared.css'; ?>
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
                <div class="rm-hero__title">Blotter Reports & Statistics</div>
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

<!-- Filter bar -->
<div class="db-panel" style="margin-bottom:18px;">
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
                    <?php foreach (['Pending','Under Investigation','Resolved','Archived'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo ($status_filter===$s)?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="db-btn db-btn--primary" style="width:100%;justify-content:center;"><i class="fas fa-search"></i> Apply Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Stat cards -->
<div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;">
    <?php
    $stat_cards=[
        ['icon'=>'fa-clipboard-list','label'=>'Total Records','value'=>$total_count,'color'=>'navy','num_color'=>'var(--db-navy)'],
        ['icon'=>'fa-clock','label'=>'Pending','value'=>$sc['Pending'],'color'=>'amber','num_color'=>'var(--db-amber-dark)'],
        ['icon'=>'fa-search','label'=>'Investigating','value'=>$sc['Under Investigation'],'color'=>'sky','num_color'=>'var(--db-sky)'],
        ['icon'=>'fa-check-circle','label'=>'Resolved','value'=>$sc['Resolved'],'color'=>'success','num_color'=>'var(--db-success)'],
        ['icon'=>'fa-archive','label'=>'Closed','value'=>$closed_count,'color'=>'muted','num_color'=>'var(--db-muted)'],
    ];
    foreach($stat_cards as $sc_item): ?>
    <div style="flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);">
        <div class="db-panel__icon db-panel__icon--<?php echo $sc_item['color']; ?>" style="width:40px;height:40px;border-radius:10px;">
            <i class="fas <?php echo $sc_item['icon']; ?>"></i>
        </div>
        <div>
            <div style="font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;color:<?php echo $sc_item['num_color']; ?>;"><?php echo $sc_item['value']; ?></div>
            <div style="font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;"><?php echo $sc_item['label']; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts row -->
<div class="row g-3 mb-3">
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
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                    <thead><tr style="background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));">
                        <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;">Incident Type</th>
                        <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;text-align:right;">Count</th>
                        <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;text-align:right;">%</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($type_data as $t): $pct=$total_count?number_format($t['count']/$total_count*100,1):0; ?>
                    <tr style="border-bottom:1px solid var(--db-border);">
                        <td style="padding:11px 16px;vertical-align:middle;">
                            <i class="fas fa-circle" style="color:var(--db-amber);font-size:7px;margin-right:8px;"></i>
                            <?php echo htmlspecialchars($t['incident_type']); ?>
                        </td>
                        <td style="padding:11px 16px;text-align:right;font-weight:700;"><?php echo $t['count']; ?></td>
                        <td style="padding:11px 16px;text-align:right;"><span class="db-badge db-badge--amber"><?php echo $pct; ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

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
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                    <thead><tr style="background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));">
                        <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;">Status</th>
                        <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;text-align:right;">Count</th>
                        <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;text-align:right;">%</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($status_data as $s):
                        $pct=$total_count?number_format($s['count']/$total_count*100,1):0;
                        $bc=['Pending'=>'amber','Under Investigation'=>'sky','Resolved'=>'success'][$s['status']]??'muted';
                    ?>
                    <tr style="border-bottom:1px solid var(--db-border);">
                        <td style="padding:11px 16px;vertical-align:middle;">
                            <i class="fas fa-circle" style="color:var(--db-<?php echo $bc; ?>);font-size:7px;margin-right:8px;"></i>
                            <?php echo htmlspecialchars($s['status']); ?>
                        </td>
                        <td style="padding:11px 16px;text-align:right;font-weight:700;"><?php echo $s['count']; ?></td>
                        <td style="padding:11px 16px;text-align:right;"><span class="db-badge db-badge--<?php echo $bc; ?>"><?php echo $pct; ?>%</span></td>
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
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));">
                <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;">Month</th>
                <th style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px;text-align:right;">Cases</th>
            </tr></thead>
            <tbody>
            <?php foreach($trend_data as $t): ?>
            <tr style="border-bottom:1px solid var(--db-border);">
                <td style="padding:11px 16px;">
                    <i class="far fa-calendar-alt" style="color:var(--db-sky);margin-right:8px;font-size:11px;"></i>
                    <?php echo date('F Y',strtotime($t['month'].'-01')); ?>
                </td>
                <td style="padding:11px 16px;text-align:right;"><span class="db-badge db-badge--sky"><?php echo $t['count']; ?> cases</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Export -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-download"></i></div>
            <h2>Export Reports</h2>
        </div>
    </div>
    <div class="db-panel__body" style="text-align:center;">
        <p style="color:var(--db-muted);margin-bottom:16px;font-size:13px;">Download blotter reports in different formats</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <button type="button" class="db-btn db-btn--ghost" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>&export=pdf" target="_blank" class="db-btn db-btn--primary"><i class="fas fa-file-pdf"></i> Export PDF</a>
            <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>&export=excel" class="db-btn db-btn--success"><i class="fas fa-file-excel"></i> Export Excel</a>
        </div>
    </div>
</div>

</div>
<?php include '../../includes/footer.php'; ?>