<?php
/**
 * Admin Blotter Management Page
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
if ($user_role === 'Resident') { header('Location: my-blotter.php'); exit(); }

$page_title = 'Manage Blotter Records';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $blotter_id = intval($_POST['blotter_id']);
    $status = $_POST['status'];
    $stmt = $conn->prepare("UPDATE tbl_blotter SET status=?, updated_at=NOW() WHERE blotter_id=?");
    $stmt->bind_param("si",$status,$blotter_id);
    if ($stmt->execute()) $_SESSION['success_message']="Status updated successfully!";
    else $_SESSION['error_message']="Error updating status.";
    $stmt->close();
    header("Location: manage-blotter.php"); exit();
}

$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "SELECT b.*, CONCAT(c.first_name,' ',c.last_name) as complainant_name, CONCAT(r.first_name,' ',r.last_name) as respondent_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents c ON b.complainant_id=c.resident_id
        LEFT JOIN tbl_residents r ON b.respondent_id=r.resident_id
        WHERE 1=1";
$params=[]; $types='';
if ($filter_status) { $sql.=" AND b.status=?"; $params[]=$filter_status; $types.='s'; }
$sql.=" ORDER BY b.incident_date DESC, b.created_at DESC";
$stmt=$conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types,...$params);
$stmt->execute(); $blotter_records=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stats=['total'=>0,'pending'=>0,'under_investigation'=>0,'resolved'=>0,'closed'=>0];
$sr=$conn->query("SELECT status,COUNT(*) as count FROM tbl_blotter GROUP BY status");
if ($sr) { while ($row=$sr->fetch_assoc()) { $s=trim($row['status']); $cnt=(int)$row['count']; $stats['total']+=$cnt; if($s==='Pending')$stats['pending']=$cnt; elseif($s==='Under Investigation')$stats['under_investigation']=$cnt; elseif($s==='Resolved')$stats['resolved']=$cnt; elseif($s==='Closed')$stats['closed']=$cnt; } }

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(180,83,9,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);transform:translateY(-3px);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--amber2{background:rgba(251,191,36,.15);color:#d97706;}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--muted{background:linear-gradient(90deg,#94a3b8,transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(180,83,9,.3);color:#fff;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;cursor:pointer;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-amber);}
.db-table tbody td{padding:12px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
.db-preview{position:fixed;z-index:9999;width:320px;background:var(--db-surf);border-radius:var(--db-radius);box-shadow:var(--db-shadow-lg);border:1px solid var(--db-border);overflow:hidden;pointer-events:none;animation:dbPrevIn .18s ease;}
@keyframes dbPrevIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.db-preview__header{display:flex;align-items:center;gap:12px;padding:14px 16px 10px;border-bottom:1px solid #f0f0f0;}
.db-preview__icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.db-preview__header-text{flex:1;min-width:0;}
.db-preview__type{font-family:'DM Mono',monospace;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:2px;}
.db-preview__title{font-size:.88rem;font-weight:700;color:var(--db-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.db-preview__body{padding:12px 16px 14px;}
.db-preview__msg{font-size:.8rem;color:var(--db-muted);line-height:1.6;margin-bottom:10px;}
.db-preview__footer{font-size:.72rem;color:#adb5bd;display:flex;align-items:center;gap:8px;}
@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-preview{display:none !important;}}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-clipboard-list"></i></div>
            <div>
                <div class="rm-hero__title">Blotter Records Management</div>
                <div class="rm-hero__sub">Manage all barangay blotter records</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="blotter-reports.php" class="db-btn db-btn--ghost" style="background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);"><i class="fas fa-chart-bar"></i> Reports</a>
            <a href="add-blotter.php" class="db-btn db-btn--amber"><i class="fas fa-plus"></i> Add New Blotter</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?><div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?><div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <a href="manage-blotter.php" class="db-stat-card <?php echo empty($filter_status)?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clipboard-list"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total']; ?></div><div class="db-stat-card__label">Total Blotter</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="?status=Pending" class="db-stat-card <?php echo $filter_status==='Pending'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber2"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['pending']; ?></div><div class="db-stat-card__label">Pending</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="?status=Under+Investigation" class="db-stat-card <?php echo $filter_status==='Under Investigation'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-search"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['under_investigation']; ?></div><div class="db-stat-card__label">Investigating</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
    <a href="?status=Resolved" class="db-stat-card <?php echo $filter_status==='Resolved'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['resolved']; ?></div><div class="db-stat-card__label">Resolved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </a>
    <a href="?status=Closed" class="db-stat-card <?php echo $filter_status==='Closed'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--muted"><i class="fas fa-archive"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['closed']; ?></div><div class="db-stat-card__label">Closed</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--muted"></div>
    </a>
</div>

<!-- Table Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list"></i></div>
            <h2><?php echo $filter_status?htmlspecialchars($filter_status).' Blotter Records':'All Blotter Records'; ?></h2>
            <span class="db-badge db-badge--amber"><?php echo count($blotter_records); ?></span>
        </div>
    </div>

    <?php if (empty($blotter_records)): ?>
    <div class="db-empty">
        <i class="fas fa-clipboard-list"></i>
        <p>No blotter records found</p>
        <?php if (!empty($filter_status)): ?><a href="manage-blotter.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a>
        <?php else: ?><a href="add-blotter.php" class="db-btn db-btn--amber db-btn--sm"><i class="fas fa-plus"></i> Add New Blotter</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Case No.</th><th>Incident Date</th><th>Type</th>
                    <th>Complainant</th><th>Respondent</th><th>Description</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($blotter_records as $record):
                $case_num = htmlspecialchars($record['case_number']??'#'.str_pad($record['blotter_id'],5,'0',STR_PAD_LEFT));
                $preview_title = htmlspecialchars($case_num.' - '.$record['incident_type']);
                $preview_message = htmlspecialchars(mb_strimwidth($record['description']??'',0,150,'...'));
                $preview_type = htmlspecialchars($record['incident_type']??'Blotter');
                $preview_time = date('M j, Y', strtotime($record['incident_date']));
                $view_url = 'view-blotter.php?id='.intval($record['blotter_id']);
                $icon_color='amber';
                if ($record['status']==='Closed')              $icon_color='muted';
                elseif ($record['status']==='Resolved')        $icon_color='success';
                elseif ($record['status']==='Under Investigation') $icon_color='sky';
            ?>
            <tr data-url="<?php echo htmlspecialchars($view_url); ?>"
                data-pt="<?php echo $preview_title; ?>" data-pm="<?php echo $preview_message; ?>"
                data-ptype="<?php echo $preview_type; ?>" data-pc="<?php echo $icon_color; ?>"
                data-ptime="<?php echo $preview_time; ?>">
                <td><span class="db-id"><?php echo $case_num; ?></span></td>
                <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($record['incident_date'])); ?></span></td>
                <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($record['incident_type']); ?></span></td>
                <td><?php echo htmlspecialchars($record['complainant_name']??'N/A'); ?></td>
                <td><?php echo htmlspecialchars($record['respondent_name']??'N/A'); ?></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars(substr($record['description'],0,50)); ?>…</span></td>
                <td><?php echo getStatusBadge($record['status']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<div id="dbPreview" class="db-preview" style="display:none;">
    <div class="db-preview__header">
        <div class="db-preview__icon" id="dbPrevIcon"><i class="fas fa-clipboard-list" id="dbPrevIconI"></i></div>
        <div class="db-preview__header-text">
            <div class="db-preview__type" id="dbPrevType"></div>
            <div class="db-preview__title" id="dbPrevTitle"></div>
        </div>
    </div>
    <div class="db-preview__body">
        <p class="db-preview__msg" id="dbPrevMsg"></p>
        <div class="db-preview__footer"><i class="far fa-calendar-alt"></i><span id="dbPrevTime"></span></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => { a.style.opacity='0'; setTimeout(()=>a.remove(),400); }), 5000);
    const card=document.getElementById('dbPreview'), iconBox=card.querySelector('#dbPrevIcon'), iconEl=card.querySelector('#dbPrevIconI');
    const cmap={amber:{bg:'rgba(245,158,11,.1)',text:'#b45309'},sky:{bg:'rgba(14,165,233,.1)',text:'#0ea5e9'},success:{bg:'rgba(16,185,129,.1)',text:'#10b981'},muted:{bg:'rgba(148,163,184,.1)',text:'#64748b'}};
    let timer;
    function pos(e){const cw=card.offsetWidth||320,ch=card.offsetHeight||160,m=14;let x=e.clientX+m,y=e.clientY+m;if(x+cw>window.innerWidth-m)x=e.clientX-cw-m;if(y+ch>window.innerHeight-m)y=e.clientY-ch-m;card.style.left=x+'px';card.style.top=y+'px';}
    document.querySelectorAll('.db-table tbody tr').forEach(row => {
        row.addEventListener('mouseenter',function(e){clearTimeout(timer);const c=cmap[this.dataset.pc]||cmap.amber;document.getElementById('dbPrevTitle').textContent=this.dataset.pt;document.getElementById('dbPrevMsg').textContent=this.dataset.pm;document.getElementById('dbPrevType').textContent=this.dataset.ptype;document.getElementById('dbPrevTime').textContent=this.dataset.ptime;iconEl.className='fas fa-clipboard-list';iconBox.style.background=c.bg;iconEl.style.color=c.text;pos(e);card.style.display='block';});
        row.addEventListener('mousemove',pos);
        row.addEventListener('mouseleave',()=>{timer=setTimeout(()=>{if(!card.matches(':hover'))card.style.display='none';},150);});
        row.addEventListener('click',function(){if(this.dataset.url)location.href=this.dataset.url;});
    });
});
</script>
<?php include '../../includes/footer.php'; ?>