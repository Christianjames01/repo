<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Community Events';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'edit') {
        $event_id=intval($_POST['event_id']); $title=trim($_POST['title']); $description=trim($_POST['description']);
        $event_date=$_POST['event_date']; $start_time=$_POST['start_time']; $end_time=$_POST['end_time'];
        $location=trim($_POST['location']); $event_type=$_POST['event_type']; $status=$_POST['status'];
        if(empty($title)){ echo json_encode(['success'=>false,'message'=>'Title is required']); exit(); }
        $stmt=$conn->prepare("UPDATE tbl_events SET title=?,description=?,event_date=?,start_time=?,end_time=?,location=?,event_type=?,status=? WHERE event_id=?");
        $stmt->bind_param("ssssssssi",$title,$description,$event_date,$start_time,$end_time,$location,$event_type,$status,$event_id);
        echo json_encode($stmt->execute()?['success'=>true,'message'=>'Event updated']:['success'=>false,'message'=>'Failed']); exit();
    }
    if ($_POST['action'] === 'cancel') {
        $event_id=intval($_POST['event_id']); $stmt=$conn->prepare("UPDATE tbl_events SET status='cancelled' WHERE event_id=?"); $stmt->bind_param("i",$event_id);
        echo json_encode($stmt->execute()?['success'=>true,'message'=>'Event cancelled']:['success'=>false,'message'=>'Failed']); exit();
    }
    if ($_POST['action'] === 'delete') {
        $event_id=intval($_POST['event_id']);
        $img_stmt=$conn->prepare("SELECT event_image FROM tbl_events WHERE event_id=?"); $img_stmt->bind_param("i",$event_id); $img_stmt->execute();
        $img=$img_stmt->get_result()->fetch_assoc(); $event_image=$img['event_image']??null; $img_stmt->close();
        $conn->query("DELETE FROM tbl_event_attendees WHERE event_id=$event_id");
        $stmt=$conn->prepare("DELETE FROM tbl_events WHERE event_id=?"); $stmt->bind_param("i",$event_id);
        if($stmt->execute()){
            if($event_image){ $p='../../../uploads/events/'.$event_image; if(file_exists($p))unlink($p); }
            echo json_encode(['success'=>true,'message'=>'Event deleted']);
        } else echo json_encode(['success'=>false,'message'=>'Failed']);
        exit();
    }
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter   = isset($_GET['type'])   ? $_GET['type']   : 'all';
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_conditions=[]; $params=[]; $types='';
if($status_filter!=='all'){ $where_conditions[]="e.status=?"; $params[]=$status_filter; $types.='s'; }
if($type_filter!=='all')  { $where_conditions[]="e.event_type=?"; $params[]=$type_filter; $types.='s'; }
if(!empty($search)){ $where_conditions[]="(e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)"; $sp="%{$search}%"; $params[]=$sp;$params[]=$sp;$params[]=$sp; $types.='sss'; }
$where_clause=!empty($where_conditions)?'WHERE '.implode(' AND ',$where_conditions):'';

$query="SELECT e.*, CONCAT(r.first_name,' ',r.last_name) as organizer_name, COUNT(DISTINCT a.attendee_id) as attendee_count
    FROM tbl_events e LEFT JOIN tbl_resident r ON e.organizer_id=r.resident_id LEFT JOIN tbl_event_attendees a ON e.event_id=a.event_id
    $where_clause GROUP BY e.event_id ORDER BY e.event_date ASC";
$stmt=$conn->prepare($query);
if(!empty($params)){ $stmt->bind_param($types,...$params); }
$stmt->execute(); $events=$stmt->get_result();

$stats_result=$conn->query("SELECT COUNT(DISTINCT e.event_id) as total_events, COUNT(DISTINCT a.attendee_id) as total_attendees, COUNT(DISTINCT CASE WHEN e.status='upcoming' THEN e.event_id END) as upcoming_events, COUNT(DISTINCT CASE WHEN e.status='completed' THEN e.event_id END) as completed_events FROM tbl_events e LEFT JOIN tbl_event_attendees a ON e.event_id=a.event_id");
$stats=$stats_result->fetch_assoc();

include '../../../includes/header.php';
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

.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1e40af,var(--db-sky));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}

.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}

.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}

.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--danger{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));color:#fff;}
.db-btn--danger:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--secondary{background:linear-gradient(135deg,#374151,#6b7280);color:#fff;}
.db-btn--secondary:hover{transform:translateY(-1px);color:#fff;}

.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--upcoming{background:var(--db-sky-light);color:#0369a1;}
.db-badge--ongoing{background:var(--db-success-light);color:#065f46;}
.db-badge--completed{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--cancelled{background:var(--db-rose-light);color:#9f1239;}

.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* Event items */
.event-item{padding:20px 22px;border-bottom:1px solid var(--db-border);display:flex;gap:18px;transition:background .12s;}
.event-item:last-child{border-bottom:none;}
.event-item:hover{background:var(--db-surf2);}
.event-thumb{flex-shrink:0;width:110px;height:110px;border-radius:var(--db-radius-sm);background:var(--db-surf2);border:1px solid var(--db-border);overflow:hidden;display:flex;align-items:center;justify-content:center;}
.event-thumb img{width:100%;height:100%;object-fit:cover;}
.event-thumb-placeholder{color:var(--db-border);font-size:2rem;}
.event-date-badge{flex-shrink:0;width:72px;height:72px;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));border-radius:var(--db-radius-sm);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;box-shadow:var(--db-shadow);}
.event-month{font-size:9px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:1px;}
.event-day{font-size:22px;font-weight:800;color:#fff;line-height:1;font-family:'DM Mono',monospace;}
.event-content{flex:1;min-width:0;}
.event-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px;gap:10px;}
.event-title{font-size:15px;font-weight:700;color:var(--db-text);line-height:1.4;}
.event-description{color:var(--db-muted);line-height:1.6;font-size:12.5px;margin-bottom:10px;}
.event-meta{display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--db-muted);margin-bottom:12px;}
.event-meta span{display:flex;align-items:center;gap:5px;}
.event-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.event-id{font-family:'DM Mono',monospace;font-size:10px;color:var(--db-indigo);font-weight:500;}
.event-actions{display:flex;gap:6px;flex-wrap:wrap;}

.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box.large{max-width:740px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--rose{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-field{margin-bottom:16px;}
.db-field label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.db-field input,.db-field textarea,.db-field select{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-field textarea{min-height:90px;resize:vertical;}
.db-field input:focus,.db-field textarea:focus,.db-field select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-detail-row{margin-bottom:14px;}
.db-detail-label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--db-muted);letter-spacing:.5px;margin-bottom:4px;}
.db-detail-value{font-size:13.5px;color:var(--db-text);line-height:1.6;}
.db-detail-value.large{font-size:15px;font-weight:700;}
.db-detail-image{width:100%;max-height:260px;object-fit:cover;border-radius:var(--db-radius-sm);border:1px solid var(--db-border);margin-bottom:4px;}
.db-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.db-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-field input,body.dark-mode .db-field textarea,body.dark-mode .db-field select{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .event-item:hover{background:#1e293b !important;}
body.dark-mode .event-title{color:#f1f5f9 !important;}
body.dark-mode .event-thumb{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-badge--completed{background:#1e293b !important;border-color:#475569 !important;}
body.dark-mode .db-detail-value{color:#e2e8f0 !important;}
@media(max-width:768px){
    .event-item{flex-direction:column;}
    .event-thumb{width:100%;height:180px;}
    .db-grid-2,.db-grid-3{grid-template-columns:1fr;}
}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-calendar-alt"></i></div>
            <div>
                <div class="rm-hero__title">Manage Community Events</div>
                <div class="rm-hero__sub">Schedule, edit, and track community events and attendance</div>
            </div>
        </div>
        <a href="create-event.php" class="db-btn db-btn--success"><i class="fas fa-plus"></i> New Event</a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-calendar-alt"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_events']); ?></div><div class="db-stat-card__label">Total Events</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_attendees']); ?></div><div class="db-stat-card__label">Total Attendees</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo number_format($stats['upcoming_events']); ?></div><div class="db-stat-card__label">Upcoming</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($stats['completed_events']); ?></div><div class="db-stat-card__label">Completed</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div>
            <h2>Filter Events</h2>
        </div>
        <?php if ($search || $status_filter !== 'all' || $type_filter !== 'all'): ?>
            <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div style="flex:1;min-width:180px;">
                    <label class="db-filter-label">Search</label>
                    <input type="text" name="search" class="db-input" style="width:100%;" placeholder="Search events…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="min-width:150px;">
                    <label class="db-filter-label">Status</label>
                    <select name="status" class="db-select" style="width:100%;">
                        <option value="all" <?php echo $status_filter==='all'?'selected':''; ?>>All Status</option>
                        <option value="upcoming"  <?php echo $status_filter==='upcoming'?'selected':''; ?>>Upcoming</option>
                        <option value="ongoing"   <?php echo $status_filter==='ongoing'?'selected':''; ?>>Ongoing</option>
                        <option value="completed" <?php echo $status_filter==='completed'?'selected':''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter==='cancelled'?'selected':''; ?>>Cancelled</option>
                    </select>
                </div>
                <div style="min-width:150px;">
                    <label class="db-filter-label">Type</label>
                    <select name="type" class="db-select" style="width:100%;">
                        <option value="all" <?php echo $type_filter==='all'?'selected':''; ?>>All Types</option>
                        <option value="meeting" <?php echo $type_filter==='meeting'?'selected':''; ?>>Meeting</option>
                        <option value="social"  <?php echo $type_filter==='social'?'selected':''; ?>>Social</option>
                        <option value="cleanup" <?php echo $type_filter==='cleanup'?'selected':''; ?>>Cleanup</option>
                        <option value="sports"  <?php echo $type_filter==='sports'?'selected':''; ?>>Sports</option>
                        <option value="other"   <?php echo $type_filter==='other'?'selected':''; ?>>Other</option>
                    </select>
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Events List -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-calendar-alt"></i></div>
            <h2>
                <?php 
                $label = 'All Events';
                if ($status_filter !== 'all') $label = ucfirst($status_filter).' Events';
                echo $label;
                ?>
            </h2>
            <span class="db-badge db-badge--upcoming"><?php echo $events->num_rows; ?></span>
        </div>
        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-info-circle"></i> Sorted by date ascending</span>
    </div>

    <?php if ($events->num_rows > 0): ?>
        <?php while ($event = $events->fetch_assoc()):
            $has_image = !empty($event['event_image']) && file_exists('../../../uploads/events/'.$event['event_image']);
        ?>
        <div class="event-item">
            <!-- Thumbnail -->
            <div class="event-thumb">
                <?php if ($has_image): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/events/<?php echo htmlspecialchars($event['event_image']); ?>" alt="">
                <?php else: ?>
                    <div class="event-thumb-placeholder"><i class="fas fa-calendar-alt"></i></div>
                <?php endif; ?>
            </div>

            <!-- Date badge -->
            <div class="event-date-badge" style="align-self:flex-start;margin-top:4px;">
                <div class="event-month"><?php echo date('M', strtotime($event['event_date'])); ?></div>
                <div class="event-day"><?php echo date('d', strtotime($event['event_date'])); ?></div>
            </div>

            <!-- Content -->
            <div class="event-content">
                <div class="event-header">
                    <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                    <span class="db-badge db-badge--<?php echo $event['status']; ?>"><?php echo ucfirst($event['status']); ?></span>
                </div>

                <div class="event-description">
                    <?php echo nl2br(htmlspecialchars(substr($event['description'], 0, 140))); ?>
                    <?php if (strlen($event['description']) > 140): ?>…<?php endif; ?>
                </div>

                <div class="event-meta">
                    <span><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($event['start_time'])); ?> – <?php echo date('h:i A', strtotime($event['end_time'])); ?></span>
                    <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></span>
                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($event['organizer_name']); ?></span>
                    <span><i class="fas fa-users"></i> <?php echo $event['attendee_count']; ?> attendees</span>
                    <span><i class="fas fa-tag"></i> <?php echo ucfirst($event['event_type']); ?></span>
                </div>

                <div class="event-footer">
                    <span class="event-id">#<?php echo str_pad($event['event_id'],5,'0',STR_PAD_LEFT); ?></span>
                    <div class="event-actions">
                        <button onclick="showViewModal(<?php echo $event['event_id']; ?>)" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-eye"></i> View</button>
                        <button onclick="showEditModal(<?php echo $event['event_id']; ?>)" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-edit"></i> Edit</button>
                        <?php if ($event['status']==='upcoming'): ?>
                            <button onclick="showCancelModal(<?php echo $event['event_id']; ?>, '<?php echo htmlspecialchars($event['title'],ENT_QUOTES); ?>')" class="db-btn db-btn--secondary db-btn--sm"><i class="fas fa-ban"></i> Cancel</button>
                        <?php endif; ?>
                        <button onclick="showDeleteModal(<?php echo $event['event_id']; ?>, '<?php echo htmlspecialchars($event['title'],ENT_QUOTES); ?>')" class="db-btn db-btn--danger db-btn--sm"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="db-empty">
            <i class="fas fa-calendar"></i>
            <p>No events found.</p>
            <?php if ($search || $status_filter!=='all' || $type_filter!=='all'): ?>
                <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filters</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</div><!-- /padding -->

<!-- View Modal -->
<div class="db-modal" id="viewModal">
    <div class="db-modal__box large">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-eye"></i> Event Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div id="viewImageSection" style="display:none;margin-bottom:14px;">
                <img id="viewImage" src="" alt="" class="db-detail-image">
            </div>
            <div class="db-detail-row"><div class="db-detail-label">Event Title</div><div class="db-detail-value large" id="viewTitle"></div></div>
            <div class="db-detail-row"><div class="db-detail-label">Description</div><div class="db-detail-value" id="viewDescription" style="white-space:pre-wrap;"></div></div>
            <div class="db-grid-2">
                <div class="db-detail-row"><div class="db-detail-label">Date</div><div class="db-detail-value" id="viewDate"></div></div>
                <div class="db-detail-row"><div class="db-detail-label">Time</div><div class="db-detail-value" id="viewTime" style="font-family:'DM Mono',monospace;font-size:12px;"></div></div>
            </div>
            <div class="db-grid-2">
                <div class="db-detail-row"><div class="db-detail-label">Location</div><div class="db-detail-value" id="viewLocation"></div></div>
                <div class="db-detail-row"><div class="db-detail-label">Event Type</div><div class="db-detail-value" id="viewType"></div></div>
            </div>
            <div class="db-grid-2">
                <div class="db-detail-row"><div class="db-detail-label">Organizer</div><div class="db-detail-value" id="viewOrganizer"></div></div>
                <div class="db-detail-row"><div class="db-detail-label">Status</div><div class="db-detail-value" id="viewStatus"></div></div>
            </div>
            <div class="db-detail-row"><div class="db-detail-label">Attendees</div><div class="db-detail-value" id="viewAttendees"></div></div>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="db-modal" id="editModal">
    <div class="db-modal__box large">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-edit"></i> Edit Event</h3>
            <button class="db-modal__close" onclick="closeModal('editModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form id="editEventForm">
                <input type="hidden" id="editEventId" name="event_id">
                <div class="db-field"><label>Event Title *</label><input type="text" id="editTitle" name="title" required></div>
                <div class="db-field"><label>Description *</label><textarea id="editDescription" name="description" required></textarea></div>
                <div class="db-grid-2">
                    <div class="db-field"><label>Event Date *</label><input type="date" id="editEventDate" name="event_date" required></div>
                    <div class="db-field"><label>Event Type *</label>
                        <select id="editEventType" name="event_type" required>
                            <option value="meeting">Meeting</option><option value="social">Social</option>
                            <option value="cleanup">Cleanup</option><option value="sports">Sports</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="db-grid-3">
                    <div class="db-field"><label>Start Time *</label><input type="time" id="editStartTime" name="start_time" required></div>
                    <div class="db-field"><label>End Time *</label><input type="time" id="editEndTime" name="end_time" required></div>
                    <div class="db-field"><label>Status *</label>
                        <select id="editStatus" name="status" required>
                            <option value="upcoming">Upcoming</option><option value="ongoing">Ongoing</option>
                            <option value="completed">Completed</option><option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="db-field"><label>Location *</label><input type="text" id="editLocation" name="location" required></div>
            </form>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button>
                <button class="db-btn db-btn--primary" onclick="saveEditEvent()"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="db-modal" id="cancelModal">
    <div class="db-modal__box" style="max-width:440px;">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-ban"></i> Cancel Event</h3>
            <button class="db-modal__close" onclick="closeModal('cancelModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p style="color:var(--db-muted);line-height:1.7;">Cancel "<strong id="cancelEventTitle" style="color:var(--db-text);"></strong>"? This will notify all registered attendees.</p>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('cancelModal')"><i class="fas fa-arrow-left"></i> Keep Event</button>
                <button class="db-btn db-btn--danger" onclick="confirmCancelEvent()"><i class="fas fa-ban"></i> Cancel Event</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="db-modal" id="deleteModal">
    <div class="db-modal__box" style="max-width:440px;">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-trash"></i> Delete Event</h3>
            <button class="db-modal__close" onclick="closeModal('deleteModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p style="color:var(--db-muted);line-height:1.7;">Permanently delete "<strong id="deleteEventTitle" style="color:var(--db-text);"></strong>"? <strong style="color:var(--db-rose);">All attendee data will be permanently removed.</strong></p>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i> Cancel</button>
                <button class="db-btn db-btn--danger" onclick="confirmDeleteEvent()"><i class="fas fa-trash"></i> Delete Event</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentEventId = null;

function closeModal(id){ document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
window.addEventListener('click', e=>{ if(e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function showViewModal(id){ currentEventId=id; openModal('viewModal'); loadEventDetails(id); }

async function loadEventDetails(id){
    const r=await fetch('get-event-details.php?id='+id); const d=await r.json();
    if(d.success){
        const ev=d.event;
        if(ev.event_image){ document.getElementById('viewImage').src='<?php echo BASE_URL; ?>/uploads/events/'+ev.event_image; document.getElementById('viewImageSection').style.display='block'; }
        else document.getElementById('viewImageSection').style.display='none';
        document.getElementById('viewTitle').textContent=ev.title;
        document.getElementById('viewDescription').textContent=ev.description;
        document.getElementById('viewDate').textContent=new Date(ev.event_date+'T00:00:00').toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'});
        document.getElementById('viewTime').textContent=formatTime(ev.start_time)+' – '+formatTime(ev.end_time);
        document.getElementById('viewLocation').textContent=ev.location;
        document.getElementById('viewType').textContent=cap(ev.event_type);
        document.getElementById('viewOrganizer').textContent=ev.organizer_name;
        document.getElementById('viewStatus').innerHTML=`<span class="db-badge db-badge--${ev.status}">${cap(ev.status)}</span>`;
        document.getElementById('viewAttendees').textContent=ev.attendee_count+' attendees';
    }
}

function showEditModal(id){
    currentEventId=id; openModal('editModal');
    fetch('get-event-details.php?id='+id).then(r=>r.json()).then(d=>{
        if(d.success){
            const ev=d.event;
            document.getElementById('editEventId').value=ev.event_id;
            document.getElementById('editTitle').value=ev.title;
            document.getElementById('editDescription').value=ev.description;
            document.getElementById('editEventDate').value=ev.event_date;
            document.getElementById('editStartTime').value=ev.start_time.substring(0,5);
            document.getElementById('editEndTime').value=ev.end_time.substring(0,5);
            document.getElementById('editLocation').value=ev.location;
            document.getElementById('editEventType').value=ev.event_type;
            document.getElementById('editStatus').value=ev.status;
        }
    });
}

function saveEditEvent(){
    const fd=new FormData(document.getElementById('editEventForm')); fd.append('action','edit');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.success)location.reload(); else alert('Error: '+d.message); });
}

function showCancelModal(id,t){ currentEventId=id; document.getElementById('cancelEventTitle').textContent=t; openModal('cancelModal'); }
function confirmCancelEvent(){
    const fd=new FormData(); fd.append('action','cancel'); fd.append('event_id',currentEventId);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.success)location.reload(); else{alert('Error: '+d.message);closeModal('cancelModal');} });
}

function showDeleteModal(id,t){ currentEventId=id; document.getElementById('deleteEventTitle').textContent=t; openModal('deleteModal'); }
function confirmDeleteEvent(){
    const fd=new FormData(); fd.append('action','delete'); fd.append('event_id',currentEventId);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.success)location.reload(); else{alert('Error: '+d.message);closeModal('deleteModal');} });
}

function formatTime(t){ const[h,m]=t.split(':'); const hr=parseInt(h); return`${hr%12||12}:${m} ${hr>=12?'PM':'AM'}`; }
function cap(s){ return s.charAt(0).toUpperCase()+s.slice(1); }

setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>

<?php include '../../../includes/footer.php'; ?>