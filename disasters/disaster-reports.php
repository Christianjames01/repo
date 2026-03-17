<?php
/**
 * Disaster Reports
 * Path: barangaylink/disasters/disaster-reports.php
 */
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) { header('Location: ' . APP_URL . '/modules/auth/login.php'); exit(); }
$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff', 'Secretary'])) { header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit(); }
$page_title = 'Disaster Reports';

$incidents = fetchAll($conn, "SELECT * FROM tbl_disaster_incidents ORDER BY incident_date DESC");
$assessments = fetchAll($conn, "SELECT da.*,CONCAT(r.first_name,' ',r.last_name) as resident_name FROM tbl_damage_assessments da LEFT JOIN tbl_residents r ON da.resident_id=r.resident_id ORDER BY da.assessment_date DESC");
$evacuation_centers = [];
if (tableExists($conn, 'tbl_evacuation_centers')) {
    if (tableExists($conn, 'tbl_evacuee_registrations')) {
        $evacuation_centers = fetchAll($conn, "SELECT ec.*,COUNT(CASE WHEN er.status='Active' THEN 1 END) as evacuee_count FROM tbl_evacuation_centers ec LEFT JOIN tbl_evacuee_registrations er ON ec.center_id=er.center_id GROUP BY ec.center_id");
    } else {
        $evacuation_centers = fetchAll($conn, "SELECT *,0 as evacuee_count FROM tbl_evacuation_centers");
    }
}

$total_incidents = count($incidents);
$total_assessments = count($assessments);
$total_damage_cost = array_sum(array_column($assessments, 'estimated_cost'));
$total_affected_families = array_sum(array_column($incidents, 'affected_families'));
$total_evacuees = array_sum(array_column($evacuation_centers, 'evacuee_count'));

$incident_types = [];
foreach ($incidents as $i) { $t=$i['disaster_type']??'Unknown'; $incident_types[$t]=($incident_types[$t]??0)+1; }
$severity_counts = ['Low'=>0,'Medium'=>0,'High'=>0,'Critical'=>0];
foreach ($incidents as $i) { $s=$i['severity']??'Medium'; if(isset($severity_counts[$s])) $severity_counts[$s]++; }

include __DIR__ . '/../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#0c4a6e 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(14,165,233,.12);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0c4a6e,var(--db-sky));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));color:#fff;}.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.35);color:#fff;}.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__body{padding:20px 22px;}.db-panel__title{display:flex;align-items:center;gap:10px;}.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}.db-badge--amber{background:var(--db-amber-light);color:#92400e;}.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-table-wrap{overflow-x:auto;}.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}.db-table tbody tr:last-child{border-bottom:none;}.db-table tbody tr:hover{background:#f5f8ff;}.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);}
.fm-summary-box{border-left:3px solid;padding:14px 16px;}.fm-summary-box--rose{border-color:var(--db-rose);background:var(--db-rose-light);}.fm-summary-box--amber{border-color:var(--db-amber);background:var(--db-amber-light);}.fm-summary-box--sky{border-color:var(--db-sky);background:var(--db-sky-light);}.fm-summary-box--success{border-color:var(--db-success);background:var(--db-success-light);}.fm-summary-box--teal{border-color:var(--db-teal);background:var(--db-teal-light);}.fm-summary-box--indigo{border-color:var(--db-indigo);background:var(--db-indigo-light);}
.fm-summary-box .lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:3px;}.fm-summary-box .val{font-family:'DM Mono',monospace;font-size:22px;font-weight:800;}
.fm-prog-track{height:6px;background:var(--db-surf2);border-radius:3px;overflow:hidden;border:1px solid var(--db-border);}.fm-prog-fill{height:100%;border-radius:3px;}.fm-prog-fill--success{background:linear-gradient(90deg,var(--db-success),#34d399);}.fm-prog-fill--amber{background:linear-gradient(90deg,var(--db-amber),#fbbf24);}.fm-prog-fill--rose{background:linear-gradient(90deg,var(--db-rose),#f43f5e);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}.db-empty i{font-size:44px;color:var(--db-border);}.db-empty p{font-size:14px;color:var(--db-muted);}
@media print{.fm-hero,.db-btn,.db-panel__header .db-btn{display:none!important;} body{background:white!important;}}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}
body.dark-mode .db-panel__body{color:#e2e8f0!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}
body.dark-mode .db-table tbody tr:hover{background:#162032!important;}
body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-input,body.dark-mode .db-select{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .fm-prog-track{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-empty i{color:#334155!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}}
</style>

<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div><div class="fm-hero__title">Disaster Reports</div><div class="fm-hero__sub">Generate and view disaster-related reports — <?php echo date('F d, Y'); ?></div></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="db-btn db-btn--ghost-white" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            <button class="db-btn db-btn--sky" onclick="generatePDFReport()"><i class="fas fa-file-pdf"></i> Export PDF</button>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-filter"></i></div><h2>Report Filters</h2></div></div>
    <div class="db-panel__body">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div><label class="db-filter-label">Date From</label><input type="date" id="date_from" class="db-input"></div>
            <div><label class="db-filter-label">Date To</label><input type="date" id="date_to" class="db-input" value="<?php echo date('Y-m-d'); ?>"></div>
            <div><label class="db-filter-label">Disaster Type</label><select id="disaster_type" class="db-select"><option value="">All Types</option><?php foreach(['Typhoon','Flood','Earthquake','Fire','Landslide'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div style="padding-top:18px;"><button class="db-btn db-btn--sky" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply Filters</button></div>
        </div>
    </div>
</div>

<!-- Summary Stats -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-chart-bar"></i></div><h2>Disaster Summary Report</h2></div></div>
    <div class="db-panel__body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
            <div class="fm-summary-box fm-summary-box--rose"><div class="lbl">Total Disaster Incidents</div><div class="val" style="color:var(--db-rose)"><?php echo $total_incidents; ?></div></div>
            <div class="fm-summary-box fm-summary-box--amber"><div class="lbl">Damage Assessments</div><div class="val" style="color:var(--db-amber-dark)"><?php echo $total_assessments; ?></div></div>
            <div class="fm-summary-box fm-summary-box--sky"><div class="lbl">Total Damage Cost</div><div class="val" style="color:var(--db-sky);font-size:16px;">₱<?php echo number_format($total_damage_cost,2); ?></div></div>
            <div class="fm-summary-box fm-summary-box--success"><div class="lbl">Affected Families</div><div class="val" style="color:var(--db-success)"><?php echo $total_affected_families; ?></div></div>
            <div class="fm-summary-box fm-summary-box--teal"><div class="lbl">Current Evacuees</div><div class="val" style="color:var(--db-teal)"><?php echo $total_evacuees; ?></div></div>
            <div class="fm-summary-box fm-summary-box--indigo"><div class="lbl">Evacuation Centers</div><div class="val" style="color:var(--db-indigo)"><?php echo count($evacuation_centers); ?></div></div>
        </div>
    </div>
</div>

<!-- Two-col: type + severity -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:18px;">
    <div class="db-panel">
        <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-chart-pie"></i></div><h2>Incidents by Type</h2></div></div>
        <div class="db-table-wrap"><table class="db-table"><thead><tr><th>Type</th><th>Count</th><th>%</th></tr></thead><tbody>
        <?php if(empty($incident_types)): ?><tr><td colspan="3"><div class="db-empty"><i class="fas fa-chart-pie"></i><p>No incidents</p></div></td></tr>
        <?php else: foreach($incident_types as $type=>$count): ?>
        <tr><td><?php echo htmlspecialchars($type); ?></td><td><strong><?php echo $count; ?></strong></td><td><?php echo $total_incidents>0?round(($count/$total_incidents)*100,1):0; ?>%</td></tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>
    <div class="db-panel">
        <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-exclamation-triangle"></i></div><h2>Severity Distribution</h2></div></div>
        <div class="db-table-wrap"><table class="db-table"><thead><tr><th>Severity</th><th>Count</th><th>%</th></tr></thead><tbody>
        <?php $svBadge=['Low'=>'success','Medium'=>'amber','High'=>'rose','Critical'=>'rose']; foreach($severity_counts as $sv=>$cnt): $pct=$total_incidents>0?round(($cnt/$total_incidents)*100,1):0; ?>
        <tr><td><span class="db-badge db-badge--<?php echo $svBadge[$sv]??'muted'; ?>"><?php echo $sv; ?></span></td><td><strong><?php echo $cnt; ?></strong></td><td><?php echo $pct; ?>%</td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>

<!-- Recent Incidents -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-history"></i></div><h2>Recent Disaster Incidents</h2><span class="db-badge db-badge--muted">Last 10</span></div></div>
    <div class="db-table-wrap"><table class="db-table"><thead><tr><th>Date</th><th>Name</th><th>Type</th><th>Location</th><th>Severity</th><th>Families</th><th>Status</th></tr></thead><tbody>
    <?php if(empty($incidents)): ?><tr><td colspan="7"><div class="db-empty"><i class="fas fa-exclamation-triangle"></i><p>No disaster incidents</p></div></td></tr>
    <?php else: $sv2=['Low'=>'success','Medium'=>'amber','High'=>'rose','Critical'=>'rose']; $st2=['Active'=>'amber','Ongoing'=>'sky','Resolved'=>'success','Closed'=>'muted']; foreach(array_slice($incidents,0,10) as $i): ?>
    <tr><td><span class="db-text-sm"><?php echo formatDate($i['incident_date']); ?></span></td><td><strong><?php echo htmlspecialchars($i['disaster_name']??'N/A'); ?></strong></td><td><span class="db-badge db-badge--muted"><?php echo htmlspecialchars($i['disaster_type']??'Unknown'); ?></span></td><td><span class="db-text-sm"><?php echo htmlspecialchars($i['location']??'N/A'); ?></span></td><td><span class="db-badge db-badge--<?php echo $sv2[$i['severity']??'Medium']??'muted'; ?>"><?php echo $i['severity']??'N/A'; ?></span></td><td><?php echo $i['affected_families']??0; ?></td><td><span class="db-badge db-badge--<?php echo $st2[$i['status']??'Active']??'muted'; ?>"><?php echo $i['status']??'N/A'; ?></span></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
</div>

<!-- Evacuation Centers -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-home"></i></div><h2>Evacuation Centers Status</h2></div></div>
    <?php if(empty($evacuation_centers)): ?>
    <div style="padding:20px;"><div class="db-empty"><i class="fas fa-home"></i><p>No evacuation centers registered</p></div></div>
    <?php else: ?>
    <div class="db-table-wrap"><table class="db-table"><thead><tr><th>Center Name</th><th>Location</th><th>Capacity</th><th>Evacuees</th><th>Occupancy</th><th>Status</th></tr></thead><tbody>
    <?php foreach($evacuation_centers as $c): $cap=$c['capacity']??0; $ec2=$c['evacuee_count']??0; $occ=$cap>0?($ec2/$cap)*100:0; $oc=$occ>=90?'rose':($occ>=70?'amber':'success'); ?>
    <tr><td><strong><?php echo htmlspecialchars($c['center_name']); ?></strong></td><td><span class="db-text-sm"><?php echo htmlspecialchars($c['location']); ?></span></td><td><?php echo $cap; ?></td><td><?php echo $ec2; ?></td>
    <td><div style="display:flex;align-items:center;gap:8px;"><div class="fm-prog-track" style="flex:1"><div class="fm-prog-fill fm-prog-fill--<?php echo $oc; ?>" style="width:<?php echo min($occ,100); ?>%"></div></div><span style="font-size:11px;color:var(--db-muted)"><?php echo round($occ,1); ?>%</span></div></td>
    <td><?php echo getStatusBadge($c['status']); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
</div>
<script>
function applyFilters(){alert('Filters applied:\nFrom: '+document.getElementById('date_from').value+'\nTo: '+document.getElementById('date_to').value+'\nType: '+(document.getElementById('disaster_type').value||'All'));}
function generatePDFReport(){alert('PDF generation will be implemented with a PDF library like TCPDF or FPDF');}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>