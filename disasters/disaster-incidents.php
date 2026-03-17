<?php
/**
 * Disaster Incidents Management
 * Path: barangaylink/disasters/disaster-incidents.php
 */
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) { header('Location: ' . APP_URL . '/modules/auth/login.php'); exit(); }
$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff', 'Secretary'])) { header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit(); }
$page_title = 'Disaster Incidents';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_incident':
            $dn=sanitizeInput($_POST['disaster_name']);$dt=sanitizeInput($_POST['disaster_type']);$id_=sanitizeInput($_POST['incident_date']);$loc=sanitizeInput($_POST['location']);$sv=sanitizeInput($_POST['severity']);$af=sanitizeInput($_POST['affected_families']);$ca=sanitizeInput($_POST['casualties']);$de=sanitizeInput($_POST['description']);$st=sanitizeInput($_POST['status']);
            $sql="INSERT INTO tbl_disaster_incidents (disaster_name,disaster_type,incident_date,location,severity,affected_families,casualties,description,status,reported_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())";
            if(executeQuery($conn,$sql,[$dn,$dt,$id_,$loc,$sv,$af,$ca,$de,$st,$_SESSION['user_id']],'sssssiissi')){logActivity($conn,$_SESSION['user_id'],"Added disaster incident: $dn at $loc");setMessage('Disaster incident added successfully','success');}else{setMessage('Failed to add disaster incident','error');}
            break;
        case 'edit_incident':
            $ii=sanitizeInput($_POST['incident_id']);$dn=sanitizeInput($_POST['disaster_name']);$dt=sanitizeInput($_POST['disaster_type']);$id_=sanitizeInput($_POST['incident_date']);$loc=sanitizeInput($_POST['location']);$sv=sanitizeInput($_POST['severity']);$af=sanitizeInput($_POST['affected_families']);$ca=sanitizeInput($_POST['casualties']);$de=sanitizeInput($_POST['description']);$st=sanitizeInput($_POST['status']);
            $sql="UPDATE tbl_disaster_incidents SET disaster_name=?,disaster_type=?,incident_date=?,location=?,severity=?,affected_families=?,casualties=?,description=?,status=? WHERE incident_id=?";
            if(executeQuery($conn,$sql,[$dn,$dt,$id_,$loc,$sv,$af,$ca,$de,$st,$ii],'sssssiissi')){logActivity($conn,$_SESSION['user_id'],"Updated disaster incident: $dn");setMessage('Disaster incident updated successfully','success');}else{setMessage('Failed to update disaster incident','error');}
            break;
        case 'delete_incident':
            $ii=sanitizeInput($_POST['incident_id']);
            if(executeQuery($conn,"DELETE FROM tbl_disaster_incidents WHERE incident_id=?",[$ii],'i')){logActivity($conn,$_SESSION['user_id'],"Deleted disaster incident ID: $ii");setMessage('Disaster incident deleted successfully','success');}else{setMessage('Failed to delete disaster incident','error');}
            break;
    }
    header('Location: disaster-incidents.php'); exit();
}

$sql="SELECT di.*,CASE WHEN di.reported_by IS NOT NULL THEN u.username WHEN di.resident_reporter_id IS NOT NULL THEN CONCAT(r.first_name,' ',r.last_name) ELSE 'N/A' END as reported_by_name FROM tbl_disaster_incidents di LEFT JOIN tbl_users u ON di.reported_by=u.user_id LEFT JOIN tbl_residents r ON di.resident_reporter_id=r.resident_id ORDER BY di.incident_date DESC,di.created_at DESC";
$incidents=fetchAll($conn,$sql);
$total_incidents=count($incidents);
$active_count=count(array_filter($incidents,fn($i)=>in_array($i['status'],['Active','Ongoing'])));
$total_families=array_sum(array_column($incidents,'affected_families'));
$total_casualties=array_sum(array_column($incidents,'casualties'));
include __DIR__ . '/../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-violet:#7c3aed;--db-violet-light:#ede9fe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 60%,#78350f 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.14);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(245,158,11,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}.db-alert--error{background:var(--db-rose-light);color:#7f1d1d;border-color:var(--db-rose);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 150px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:20px;font-weight:800;line-height:1;letter-spacing:-.8px;font-family:'DM Mono',monospace;}.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}.db-btn--ghost:hover{background:var(--db-border);}.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}.db-badge--amber{background:var(--db-amber-light);color:#92400e;}.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}.db-badge--violet{background:var(--db-violet-light);color:#5b21b6;}
.db-table-wrap{overflow-x:auto;}.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}.db-table tbody tr:last-child{border-bottom:none;}.db-table tbody tr:hover{background:#f5f8ff;}.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}.db-empty i{font-size:44px;color:var(--db-border);}.db-empty p{font-size:14px;color:var(--db-muted);}
.db-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:all .15s;}
.db-icon-btn--default{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}.db-icon-btn--default:hover{background:var(--db-border);color:var(--db-text);}.db-icon-btn--amber{background:var(--db-amber-light);color:#92400e;}.db-icon-btn--amber:hover{background:#fde68a;}.db-icon-btn--rose{background:var(--db-rose-light);color:#9f1239;}.db-icon-btn--rose:hover{background:#fecaca;}
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:640px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}.db-modal__box--sm{max-width:440px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}.db-modal__header--sky{background:linear-gradient(135deg,#0c4a6e,var(--db-sky));}.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;}.db-modal__close:hover{background:rgba(255,255,255,.28);}
.db-modal__body{padding:22px;}.db-modal__footer{display:flex;gap:10px;margin-top:18px;}.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-form-group{margin-bottom:14px;}.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-input,.db-select,.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;min-height:80px;}.db-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}.db-form-grid .full{grid-column:1/-1;}
.db-confirm-grid{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin-bottom:14px;}.db-confirm-row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;}.db-confirm-row:last-child{border-bottom:none;}.db-confirm-row .lbl{color:var(--db-muted);font-weight:600;}.db-confirm-row .val{font-weight:600;color:var(--db-text);}
.db-notice--rose{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;background:var(--db-rose-light);color:#9f1239;margin-bottom:14px;}
.db-stat-box{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:16px;text-align:center;}.db-stat-box__val{font-family:'DM Mono',monospace;font-size:28px;font-weight:800;display:block;margin-bottom:4px;}.db-stat-box__lbl{font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155!important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9!important;}
body.dark-mode .db-stat-card{background:#1e293b!important;border-color:#334155!important;color:#e2e8f0!important;}
body.dark-mode .db-stat-card__label{color:#64748b!important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b)!important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9)!important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155!important;}
body.dark-mode .db-table tbody tr:hover{background:#162032!important;}
body.dark-mode .db-table tbody td{color:#e2e8f0!important;}
body.dark-mode .db-text-sm{color:#94a3b8!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-form-label{color:#94a3b8!important;}
body.dark-mode .db-confirm-grid,body.dark-mode .db-stat-box{background:#162032!important;border-color:#334155!important;}
body.dark-mode .db-confirm-row{border-bottom-color:#334155!important;}
body.dark-mode .db-confirm-row .lbl{color:#64748b!important;}.db-confirm-row .val{color:#e2e8f0!important;}
body.dark-mode .db-icon-btn--default{background:#1e293b!important;color:#94a3b8!important;border-color:#475569!important;}
body.dark-mode .db-empty i{color:#334155!important;}.dark-mode .db-empty p{color:#64748b!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}.db-form-grid{grid-template-columns:1fr;}.db-form-grid .full{grid-column:1/1;}}
</style>

<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div><div class="fm-hero__title">Disaster Incidents</div><div class="fm-hero__sub">Track and manage disaster incidents in the barangay</div></div>
        </div>
        <button class="db-btn db-btn--amber" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Report Incident</button>
    </div>
</div>

<div style="padding:0 24px 24px;">
<?php echo displayMessage(); ?>

<div class="db-stats-row">
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-exclamation-circle"></i></div><div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $total_incidents; ?></div><div class="db-stat-card__label">Total Incidents</div></div><div class="db-stat-card__bar db-stat-card__bar--rose"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-fire"></i></div><div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $active_count; ?></div><div class="db-stat-card__label">Active</div></div><div class="db-stat-card__bar db-stat-card__bar--amber"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-users"></i></div><div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $total_families; ?></div><div class="db-stat-card__label">Affected Families</div></div><div class="db-stat-card__bar db-stat-card__bar--sky"></div></div>
    <div class="db-stat-card"><div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-user-injured"></i></div><div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $total_casualties; ?></div><div class="db-stat-card__label">Casualties</div></div><div class="db-stat-card__bar db-stat-card__bar--indigo"></div></div>
</div>

<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list"></i></div><h2>Disaster Incidents</h2><span class="db-badge db-badge--amber"><?php echo $total_incidents; ?></span></div>
        <button class="db-btn db-btn--amber db-btn--sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Report</button>
    </div>
    <div class="db-table-wrap"><table class="db-table">
        <thead><tr><th>Date</th><th>Name</th><th>Type</th><th>Location</th><th>Severity</th><th>Families</th><th>Casualties</th><th>Status</th><th>Reported By</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($incidents)): ?><tr><td colspan="10"><div class="db-empty"><i class="fas fa-exclamation-triangle"></i><p>No disaster incidents found</p></div></td></tr>
        <?php else: foreach($incidents as $inc):
            $sv=['Low'=>'success','Medium'=>'amber','High'=>'rose','Critical'=>'rose'];
            $ss=['Active'=>'amber','Ongoing'=>'sky','Resolved'=>'success','Closed'=>'muted'];
            $svb=$sv[$inc['severity']]??'muted'; $ssb=$ss[$inc['status']]??'muted';
        ?>
        <tr>
            <td><span class="db-text-sm"><?php echo formatDate($inc['incident_date']); ?></span></td>
            <td><strong><?php echo htmlspecialchars($inc['disaster_name']); ?></strong></td>
            <td><span class="db-badge db-badge--muted"><?php echo htmlspecialchars($inc['disaster_type']); ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($inc['location']); ?></span></td>
            <td><span class="db-badge db-badge--<?php echo $svb; ?>"><?php echo $inc['severity']; ?></span></td>
            <td><strong><?php echo $inc['affected_families']; ?></strong></td>
            <td><?php echo $inc['casualties']; ?></td>
            <td><span class="db-badge db-badge--<?php echo $ssb; ?>"><?php echo $inc['status']; ?></span></td>
            <td><span class="db-text-sm"><?php echo htmlspecialchars($inc['reported_by_name']??'N/A'); ?></span></td>
            <td><div style="display:flex;gap:4px;">
                <button class="db-icon-btn db-icon-btn--default" onclick='viewIncident(<?php echo json_encode($inc); ?>)' title="View"><i class="fas fa-eye"></i></button>
                <button class="db-icon-btn db-icon-btn--amber" onclick='editIncident(<?php echo json_encode($inc); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                <button class="db-icon-btn db-icon-btn--rose" onclick="showDeleteModal(<?php echo $inc['incident_id']; ?>,'<?php echo addslashes(htmlspecialchars($inc['disaster_name'])); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ADD MODAL -->
<div id="addModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--amber"><h3><i class="fas fa-plus"></i> Report Disaster Incident</h3><button class="db-modal__close" onclick="closeModal('addModal')">×</button></div>
<div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="add_incident">
<div class="db-form-grid">
    <div class="db-form-group full"><label class="db-form-label db-form-label--req">Disaster Name</label><input type="text" name="disaster_name" class="db-input" placeholder="e.g., Typhoon Kristine" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Disaster Type</label><select name="disaster_type" class="db-select" required><option value="">Select Type</option><?php foreach(['Typhoon','Flood','Earthquake','Fire','Landslide','Storm Surge','Volcanic Eruption','Drought','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Incident Date</label><input type="date" name="incident_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Location</label><input type="text" name="location" class="db-input" placeholder="Specific location" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Severity</label><select name="severity" class="db-select" required><option value="">Select Severity</option><?php foreach(['Low','Medium','High','Critical'] as $s): ?><option><?php echo $s; ?></option><?php endforeach; ?></select></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Affected Families</label><input type="number" name="affected_families" class="db-input" min="0" value="0" required></div>
    <div class="db-form-group"><label class="db-form-label">Casualties</label><input type="number" name="casualties" class="db-input" min="0" value="0"></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Status</label><select name="status" class="db-select" required><option value="Active">Active</option><option value="Ongoing">Ongoing</option><option value="Resolved">Resolved</option><option value="Closed">Closed</option></select></div>
    <div class="db-form-group full"><label class="db-form-label">Description</label><textarea name="description" class="db-textarea" placeholder="Detailed description of the incident..."></textarea></div>
</div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--amber"><i class="fas fa-save"></i> Report Incident</button></div>
</form></div></div></div>

<!-- VIEW MODAL -->
<div id="viewModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--sky"><h3><i class="fas fa-eye"></i> Incident Details</h3><button class="db-modal__close" onclick="closeModal('viewModal')">×</button></div>
<div class="db-modal__body"><div id="viewContent"></div><div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button><button type="button" class="db-btn db-btn--amber" style="color:#fff" onclick="editIncidentFromView()"><i class="fas fa-edit"></i> Edit</button></div></div></div></div>

<!-- EDIT MODAL -->
<div id="editModal" class="db-modal"><div class="db-modal__box"><div class="db-modal__header db-modal__header--amber"><h3><i class="fas fa-edit"></i> Edit Disaster Incident</h3><button class="db-modal__close" onclick="closeModal('editModal')">×</button></div>
<div class="db-modal__body"><form method="POST" id="editForm"><input type="hidden" name="action" value="edit_incident"><input type="hidden" name="incident_id" id="edit_incident_id">
<div class="db-form-grid">
    <div class="db-form-group full"><label class="db-form-label db-form-label--req">Disaster Name</label><input type="text" name="disaster_name" id="edit_disaster_name" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Disaster Type</label><select name="disaster_type" id="edit_disaster_type" class="db-select" required><option value="">Select Type</option><?php foreach(['Typhoon','Flood','Earthquake','Fire','Landslide','Storm Surge','Volcanic Eruption','Drought','Other'] as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?></select></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Incident Date</label><input type="date" name="incident_date" id="edit_incident_date" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Location</label><input type="text" name="location" id="edit_location" class="db-input" required></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Severity</label><select name="severity" id="edit_severity" class="db-select" required><option value="">Select</option><?php foreach(['Low','Medium','High','Critical'] as $s): ?><option><?php echo $s; ?></option><?php endforeach; ?></select></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Affected Families</label><input type="number" name="affected_families" id="edit_affected_families" class="db-input" min="0" required></div>
    <div class="db-form-group"><label class="db-form-label">Casualties</label><input type="number" name="casualties" id="edit_casualties" class="db-input" min="0" value="0"></div>
    <div class="db-form-group"><label class="db-form-label db-form-label--req">Status</label><select name="status" id="edit_status" class="db-select" required><option value="Active">Active</option><option value="Ongoing">Ongoing</option><option value="Resolved">Resolved</option><option value="Closed">Closed</option></select></div>
    <div class="db-form-group full"><label class="db-form-label">Description</label><textarea name="description" id="edit_description" class="db-textarea"></textarea></div>
</div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--amber" style="color:#fff"><i class="fas fa-save"></i> Update Incident</button></div>
</form></div></div></div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="db-modal"><div class="db-modal__box db-modal__box--sm"><div class="db-modal__header db-modal__header--rose"><h3><i class="fas fa-trash-alt"></i> Confirm Deletion</h3><button class="db-modal__close" onclick="closeModal('deleteModal')">×</button></div>
<div class="db-modal__body"><form method="POST"><input type="hidden" name="action" value="delete_incident"><input type="hidden" name="incident_id" id="delete_incident_id">
<div class="db-confirm-grid"><div class="db-confirm-row"><span class="lbl">Incident</span><span class="val" id="deleteIncidentName"></span></div></div>
<div class="db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>This action <strong>cannot be undone</strong>. All incident data and related records will be permanently deleted.</span></div>
<div class="db-modal__footer"><button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i> Cancel</button><button type="submit" class="db-btn db-btn--rose"><i class="fas fa-trash"></i> Delete Permanently</button></div>
</form></div></div></div>

<script>
let currentViewingIncident = null;
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

const svMap={Low:'db-badge--success',Medium:'db-badge--amber',High:'db-badge--rose',Critical:'db-badge--rose'};
const stMap={Active:'db-badge--amber',Ongoing:'db-badge--sky',Resolved:'db-badge--success',Closed:'db-badge--muted'};
function mkBadge(cls,txt){return`<span class="db-badge ${cls}">${txt}</span>`;}
function esc(t){if(!t)return'';const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

function viewIncident(incident){
    currentViewingIncident=incident;
    document.getElementById('viewContent').innerHTML=`
        <div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:14px 16px;margin-bottom:14px;">
            <div style="font-size:16px;font-weight:800;margin-bottom:6px;">${esc(incident.disaster_name)}</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">${mkBadge('db-badge--muted',esc(incident.disaster_type))} ${mkBadge(stMap[incident.status]||'db-badge--muted',incident.status)} ${mkBadge(svMap[incident.severity]||'db-badge--muted',incident.severity)}</div>
        </div>
        <div class="db-confirm-grid">
            <div class="db-confirm-row"><span class="lbl">Incident Date</span><span class="val">${incident.incident_date}</span></div>
            <div class="db-confirm-row"><span class="lbl">Location</span><span class="val">${esc(incident.location)}</span></div>
            <div class="db-confirm-row"><span class="lbl">Affected Families</span><span class="val" style="color:var(--db-sky)">${incident.affected_families}</span></div>
            <div class="db-confirm-row"><span class="lbl">Casualties</span><span class="val" style="color:var(--db-rose)">${incident.casualties}</span></div>
            <div class="db-confirm-row"><span class="lbl">Reported By</span><span class="val">${esc(incident.reported_by_name||'N/A')}</span></div>
        </div>
        ${incident.description?`<div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px 14px;font-size:13px;white-space:pre-wrap;">${esc(incident.description)}</div>`:''}`;
    openModal('viewModal');
}
function editIncident(incident){
    document.getElementById('edit_incident_id').value=incident.incident_id;
    document.getElementById('edit_disaster_name').value=incident.disaster_name;
    document.getElementById('edit_disaster_type').value=incident.disaster_type;
    document.getElementById('edit_incident_date').value=incident.incident_date;
    document.getElementById('edit_location').value=incident.location;
    document.getElementById('edit_severity').value=incident.severity;
    document.getElementById('edit_affected_families').value=incident.affected_families;
    document.getElementById('edit_casualties').value=incident.casualties;
    document.getElementById('edit_status').value=incident.status;
    document.getElementById('edit_description').value=incident.description||'';
    openModal('editModal');
}
function editIncidentFromView(){if(currentViewingIncident){closeModal('viewModal');setTimeout(()=>editIncident(currentViewingIncident),300);}}
function showDeleteModal(id,name){document.getElementById('delete_incident_id').value=id;document.getElementById('deleteIncidentName').textContent=name;openModal('deleteModal');}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>