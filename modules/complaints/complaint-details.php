<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Admin','Super Admin','Super Administrator','Barangay Captain','Barangay Tanod','Staff','Secretary','Treasurer','Tanod','Resident']);

$current_user_id = getCurrentUserId();
$current_role    = getCurrentUserRole();
$staff_roles     = ['Admin','Super Admin','Super Administrator','Barangay Captain','Barangay Tanod','Staff','Secretary','Treasurer','Tanod'];
$is_resident     = !in_array($current_role, $staff_roles);
$is_tanod        = ($current_role === 'Tanod' || $current_role === 'Barangay Tanod');
$can_modify      = !$is_resident && !$is_tanod;

$resident_id = null;
if ($is_resident) {
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $current_user_id); $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $resident_id = $row['resident_id'];
    $stmt->close();
    if (!$resident_id) { $_SESSION['error_message']='Invalid resident account'; header('Location: view-complaints.php'); exit(); }
}

$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$complaint_id) { $_SESSION['error_message']='Invalid complaint ID'; header('Location: view-complaints.php'); exit(); }

$sql = "SELECT c.*, r.first_name, r.last_name, r.address, r.contact_number, r.email, r.resident_id,
               u.username as assigned_to_name
        FROM tbl_complaints c
        LEFT JOIN tbl_residents r ON c.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON c.assigned_to = u.user_id
        WHERE c.complaint_id = ?";
$stmt = $conn->prepare($sql); $stmt->bind_param("i", $complaint_id); $stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) { $stmt->close(); $_SESSION['error_message']='Complaint not found'; header('Location: view-complaints.php'); exit(); }
$complaint = $result->fetch_assoc(); $stmt->close();

if ($is_resident && $complaint['resident_id'] != $resident_id) {
    $_SESSION['error_message']='You are not authorized to view this complaint.';
    header('Location: view-complaints.php'); exit();
}

$upload_fs  = $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/complaints/';
$upload_url = '/barangaylink1/uploads/complaints/';
$attachments = [];

// 1. Check tbl_complaint_attachments (most reliable — uses stored complaint_id)
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_complaint_attachments'");
if ($table_check && $table_check->num_rows > 0) {
    $att_stmt = $conn->prepare("SELECT file_name, file_path FROM tbl_complaint_attachments WHERE complaint_id = ?");
    $att_stmt->bind_param("i", $complaint_id); $att_stmt->execute();
    $att_result = $att_stmt->get_result();
    while ($att_row = $att_result->fetch_assoc()) {
        $bn = basename($att_row['file_path']);
        if (file_exists($upload_fs . $bn) && !in_array($bn, $attachments)) {
            $attachments[] = $bn;
        }
    }
    $att_stmt->close();
}

// 2. Filesystem scan fallback — only matches files for this specific complaint
if (is_dir($upload_fs)) {
    foreach (scandir($upload_fs) as $file) {
        if ($file === '.' || $file === '..') continue;
        if (strpos($file, 'complaint_' . $complaint_id . '_') === 0 && !in_array($file, $attachments)) {
            $attachments[] = $file;
        }
    }
}

$staff_users = [];
if ($can_modify) {
    $staff_result = $conn->query("SELECT user_id,username,role FROM tbl_users WHERE role IN ('Admin','Super Admin','Super Administrator','Barangay Captain','Barangay Tanod','Staff','Secretary','Treasurer','Tanod') ORDER BY username ASC");
    if ($staff_result) $staff_users = $staff_result->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $can_modify && isset($_POST['update_complaint'])) {
    $new_status   = trim($_POST['status']);
    $new_priority = trim($_POST['priority']);
    $responder_id = intval($_POST['responder_id']);
    $valid_statuses   = ['Pending','In Progress','Resolved','Closed'];
    $valid_priorities = ['Low','Medium','High','Urgent'];
    $errors = [];
    if (!in_array($new_status,$valid_statuses))   $errors[]='Invalid status.';
    if (!in_array($new_priority,$valid_priorities)) $errors[]='Invalid priority.';
    if ($responder_id<=0) $errors[]='Please select a valid responder.';
    if (empty($errors)) {
        $upd = $conn->prepare("UPDATE tbl_complaints SET status=?,priority=?,assigned_to=? WHERE complaint_id=?");
        $upd->bind_param("ssii",$new_status,$new_priority,$responder_id,$complaint_id);
        if ($upd->execute()) {
            $_SESSION['success_message']='Complaint updated successfully!';
            $upd->close();
            $user_stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE resident_id=?");
            $user_stmt->bind_param("i",$complaint['resident_id']); $user_stmt->execute();
            if ($u_row = $user_stmt->get_result()->fetch_assoc()) {
                $cuid = $u_row['user_id'];
                if ($new_status==='Resolved') createNotification($conn,$cuid,'Complaint Resolved',"Your complaint \"{$complaint['subject']}\" has been resolved.",'complaint_resolved',$complaint_id,'complaint');
                elseif ($new_status==='Closed') createNotification($conn,$cuid,'Complaint Closed',"Your complaint \"{$complaint['subject']}\" has been closed.",'complaint_closed',$complaint_id,'complaint');
                else createNotification($conn,$cuid,'Complaint Status Updated',"Your complaint \"{$complaint['subject']}\" status updated to: $new_status",'complaint_status_update',$complaint_id,'complaint');
            }
            $user_stmt->close();
            if (function_exists('logActivity')) logActivity($conn,$current_user_id,"Updated complaint - Status: $new_status, Priority: $new_priority",'tbl_complaints',$complaint_id);
        } else { $_SESSION['error_message']='Failed to update: '.$upd->error; $upd->close(); }
    } else { $_SESSION['error_message']=implode('<br>',$errors); }
    header("Location: complaint-details.php?id=$complaint_id"); exit();
}

$page_title = 'Complaint Details - '.htmlspecialchars($complaint['complaint_number']);

function cStatusBadge($s){
    $s=trim($s);
    $m=['Pending'=>['amber','clock'],'In Progress'=>['sky','spinner'],'Resolved'=>['success','check-circle'],'Closed'=>['muted','times-circle']];
    [$c,$i]=$m[$s]??['muted','circle'];
    return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($s)."</span>";
}
function cPriorityBadge($p){
    $p=trim($p);
    $m=['Low'=>['success','circle'],'Medium'=>['amber','exclamation-circle'],'High'=>['rose','exclamation-triangle'],'Urgent'=>['danger','fire']];
    [$c,$i]=$m[$p]??['muted','circle'];
    return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($p)."</span>";
}
function cCategoryIcon($cat){
    $map=['Noise'=>'fa-volume-up','Garbage'=>'fa-trash','Property'=>'fa-home','Infrastructure'=>'fa-road','Public Safety'=>'fa-shield-alt','Services'=>'fa-concierge-bell','Animals'=>'fa-paw','Utilities'=>'fa-bolt'];
    return $map[$cat]??'fa-comment';
}

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
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-info:#3b82f6;--db-info-light:#dbeafe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a4a2e 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#e11d48,#be123c);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--info{background:var(--db-info-light);color:#1e40af;border-color:var(--db-info);}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert--warning{background:var(--db-warning-light);color:#92400e;border-color:var(--db-warning);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__body{padding:22px;}

/* ── Info items ── */
.db-info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:12px;}
.db-info-item{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px 14px;transition:background .15s,transform .15s;}
.db-info-item:hover{background:#f0f4ff;transform:translateX(3px);}
.db-info-item--full{grid-column:1/-1;}
.db-info-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.db-info-value{font-size:13.5px;font-weight:600;color:var(--db-text);display:flex;align-items:center;gap:6px;line-height:1.5;}
.db-info-value--block{display:block;line-height:1.7;}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--navy{background:#e8edf7;color:var(--db-navy);}
.db-badge--danger{background:var(--db-danger-light);color:#7f1d1d;}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber),#d97706);color:#fff;}
.db-btn--amber:hover{background:linear-gradient(135deg,#d97706,#b45309);transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,var(--db-rose),#be123c);color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--glass{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.2);}
.db-btn--glass:hover{background:rgba(255,255,255,.2);color:#fff;}
.db-btn--block{width:100%;justify-content:center;margin-bottom:8px;}
.db-btn--block:last-child{margin-bottom:0;}

/* ── Image gallery ── */
.db-img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-top:14px;}
.db-img-card{border-radius:var(--db-radius-sm);overflow:hidden;border:1px solid var(--db-border);background:var(--db-surf);box-shadow:var(--db-shadow);transition:transform .2s,box-shadow .2s;}
.db-img-card:hover{transform:translateY(-4px);box-shadow:var(--db-shadow-lg);}
.db-img-card__preview{position:relative;height:160px;overflow:hidden;cursor:pointer;}
.db-img-card__preview img{width:100%;height:100%;object-fit:cover;transition:transform .3s;}
.db-img-card:hover .db-img-card__preview img{transform:scale(1.05);}
.db-img-card__overlay{position:absolute;inset:0;background:rgba(13,27,54,.45);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;}
.db-img-card__preview:hover .db-img-card__overlay{opacity:1;}
.db-img-card__foot{padding:10px 12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--db-border);}
.db-file-card{border-radius:var(--db-radius-sm);border:1px solid var(--db-border);background:var(--db-surf);box-shadow:var(--db-shadow);transition:transform .2s,box-shadow .2s;overflow:hidden;}
.db-file-card:hover{transform:translateY(-4px);box-shadow:var(--db-shadow-lg);}
.db-file-card__icon{padding:24px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--db-surf2);}
.db-file-card__body{padding:10px 12px;border-top:1px solid var(--db-border);}

/* ── Empty state ── */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px;text-align:center;gap:10px;}
.db-empty i{font-size:40px;color:var(--db-border);}
.db-empty p{font-size:13px;color:var(--db-muted);margin:0;}

/* ── Contact link ── */
.db-contact-link{color:var(--db-sky);font-weight:600;text-decoration:none;font-size:13px;}
.db-contact-link:hover{color:var(--db-navy);text-decoration:underline;}

/* ── Modal ── */
.db-modal .modal-content{border:none;border-radius:var(--db-radius-lg);box-shadow:var(--db-shadow-lg);}
.db-modal .modal-header{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));border-bottom:none;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal .modal-title{color:#fff;font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;}
.db-modal .btn-close{filter:invert(1) brightness(2);}
.db-modal .modal-body{padding:22px;}
.db-modal .modal-footer{background:var(--db-surf2);border-top:1px solid var(--db-border);padding:14px 22px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);}
.db-form-label{font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:6px;display:block;}
.db-form-control{display:block;width:100%;border:2px solid var(--db-border);border-radius:var(--db-radius-sm);font-size:13px;padding:9px 12px;font-family:'Sora',sans-serif;transition:border-color .18s,box-shadow .18s;background:var(--db-surf);color:var(--db-text);}
.db-form-control:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.12);outline:none;}

/* ── Lightbox ── */
#db-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;}
#db-lightbox.active{display:flex;}
#db-lightbox-img{max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
#db-lightbox-close{position:absolute;top:20px;right:28px;color:#fff;font-size:36px;cursor:pointer;line-height:1;opacity:.7;transition:opacity .2s;}
#db-lightbox-close:hover{opacity:1;}

@media(max-width:768px){
    .rm-hero{padding:20px;border-radius:0;}
    .db-info-grid{grid-template-columns:1fr;}
}
</style>

<!-- ── Hero ── -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">Complaint Details</div>
                <div class="rm-hero__sub">
                    <i class="fas fa-hashtag" style="opacity:.6;margin-right:4px;"></i>
                    Reference: <strong><?php echo htmlspecialchars($complaint['complaint_number']); ?></strong>
                </div>
            </div>
        </div>
        <div class="rm-hero__badges">
            <?php echo cStatusBadge($complaint['status']??'Pending'); ?>
            <?php echo cPriorityBadge($complaint['priority']??'Medium'); ?>
            <a href="view-complaints.php" class="db-btn db-btn--glass db-btn--sm">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<!-- Alerts -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($is_tanod): ?>
<div class="db-alert db-alert--info"><i class="fas fa-info-circle"></i><strong>View Only Mode:</strong>&nbsp;As a Tanod, you can view complaint details but cannot make modifications.<button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── LEFT COLUMN ── -->
    <div class="col-lg-8">

        <!-- Complaint Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-info-circle"></i></div>
                    <h2>Complaint Information</h2>
                </div>
                <span class="db-badge db-badge--amber">
                    <i class="fas <?php echo cCategoryIcon($complaint['category']??''); ?>"></i>
                    <?php echo htmlspecialchars($complaint['category']??'General'); ?>
                </span>
            </div>
            <div class="db-panel__body">
                <div class="db-info-grid">
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-calendar-alt"></i> Date Filed</div>
                        <div class="db-info-value">
                            <i class="fas fa-clock" style="color:var(--db-sky);font-size:11px;"></i>
                            <?php echo date('M d, Y · g:i A', strtotime($complaint['date_filed']??$complaint['created_at'])); ?>
                        </div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-chart-bar"></i> Priority</div>
                        <div class="db-info-value"><?php echo cPriorityBadge($complaint['priority']??'Medium'); ?></div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-circle-notch"></i> Status</div>
                        <div class="db-info-value"><?php echo cStatusBadge($complaint['status']??'Pending'); ?></div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-tag"></i> Category</div>
                        <div class="db-info-value">
                            <i class="fas <?php echo cCategoryIcon($complaint['category']??''); ?>" style="color:var(--db-amber);font-size:11px;"></i>
                            <?php echo htmlspecialchars($complaint['category']??'N/A'); ?>
                        </div>
                    </div>
                    <div class="db-info-item db-info-item--full">
                        <div class="db-info-label"><i class="fas fa-heading"></i> Subject</div>
                        <div class="db-info-value" style="font-size:15px;"><?php echo htmlspecialchars($complaint['subject']); ?></div>
                    </div>
                    <div class="db-info-item db-info-item--full">
                        <div class="db-info-label"><i class="fas fa-align-left"></i> Description</div>
                        <div class="db-info-value db-info-value--block" style="font-weight:400;color:var(--db-muted);">
                            <?php echo nl2br(htmlspecialchars($complaint['description'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Attachments -->
                <?php if (!empty($attachments)): ?>
                <div style="margin-top:8px;">
                    <div class="db-info-label" style="margin-bottom:4px;">
                        <i class="fas fa-paperclip"></i> Attachments
                        <span class="db-badge db-badge--navy" style="margin-left:6px;"><?php echo count($attachments); ?></span>
                    </div>
                    <div class="db-img-grid">
                        <?php foreach ($attachments as $file):
                            $ext = strtolower(pathinfo($file,PATHINFO_EXTENSION));
                            $is_img = in_array($ext,['jpg','jpeg','png','gif','webp']);
                            $furl = $upload_url.rawurlencode($file);
                        ?>
                        <?php if ($is_img): ?>
                        <div class="db-img-card">
                            <div class="db-img-card__preview" onclick="dbLightbox('<?php echo htmlspecialchars($furl,ENT_QUOTES); ?>')">
                                <img src="<?php echo htmlspecialchars($furl); ?>" alt="<?php echo htmlspecialchars($file); ?>" loading="lazy"
                                     onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:#aaa;font-size:.75rem;\'>Not found</div>'">
                                <div class="db-img-card__overlay"><i class="fas fa-search-plus text-white" style="font-size:1.4rem;"></i></div>
                            </div>
                            <div class="db-img-card__foot">
                                <span class="db-badge db-badge--amber"><i class="fas fa-image"></i> IMG</span>
                                <a href="<?php echo htmlspecialchars($furl); ?>" download class="db-btn db-btn--sm db-btn--ghost" onclick="event.stopPropagation()"><i class="fas fa-download"></i></a>
                            </div>
                        </div>
                        <?php else:
                            $fi=['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word','xls'=>'fa-file-excel','xlsx'=>'fa-file-excel'][$ext]??'fa-file';
                        ?>
                        <div class="db-file-card">
                            <a href="<?php echo htmlspecialchars($furl); ?>" target="_blank" class="text-decoration-none" download>
                                <div class="db-file-card__icon">
                                    <i class="fas <?php echo $fi; ?>" style="font-size:2.5rem;color:var(--db-sky);margin-bottom:6px;"></i>
                                    <span class="db-badge db-badge--muted"><?php echo strtoupper($ext); ?></span>
                                </div>
                                <div class="db-file-card__body">
                                    <small style="font-size:11px;color:var(--db-muted);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($file); ?></small>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="db-empty" style="padding:32px 24px;">
                    <i class="fas fa-folder-open"></i>
                    <p>No attachments uploaded</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /col-lg-8 -->

    <!-- ── RIGHT COLUMN ── -->
    <div class="col-lg-4">

        <!-- Complainant Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-user"></i></div>
                    <h2><?php echo $is_resident ? 'Your Information' : 'Complainant'; ?></h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-info-item" style="margin-bottom:10px;">
                    <div class="db-info-label"><i class="fas fa-user"></i> Name</div>
                    <div class="db-info-value"><?php echo htmlspecialchars($complaint['first_name'].' '.$complaint['last_name']); ?></div>
                </div>
                <?php if (!empty($complaint['contact_number'])): ?>
                <div class="db-info-item" style="margin-bottom:10px;">
                    <div class="db-info-label"><i class="fas fa-phone"></i> Contact</div>
                    <div class="db-info-value">
                        <a href="tel:<?php echo htmlspecialchars($complaint['contact_number']); ?>" class="db-contact-link">
                            <i class="fas fa-phone-alt" style="font-size:11px;"></i>
                            <?php echo htmlspecialchars($complaint['contact_number']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($complaint['email'])): ?>
                <div class="db-info-item" style="margin-bottom:10px;">
                    <div class="db-info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="db-info-value">
                        <a href="mailto:<?php echo htmlspecialchars($complaint['email']); ?>" class="db-contact-link">
                            <i class="fas fa-envelope" style="font-size:11px;"></i>
                            <?php echo htmlspecialchars($complaint['email']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <div class="db-info-item">
                    <div class="db-info-label"><i class="fas fa-home"></i> Address</div>
                    <div class="db-info-value db-info-value--block" style="font-weight:400;"><?php echo htmlspecialchars($complaint['address']); ?></div>
                </div>
            </div>
        </div>

        <!-- Assignment Status -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-user-shield"></i></div>
                    <h2>Assignment Status</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <?php if (!empty($complaint['assigned_to_name'])): ?>
                <div class="db-info-item" style="margin-bottom:14px;">
                    <div class="db-info-label"><i class="fas fa-user-shield"></i> Assigned To</div>
                    <div class="db-info-value" style="flex-direction:column;align-items:flex-start;gap:4px;">
                        <span>
                            <i class="fas fa-user-check" style="color:var(--db-sky);font-size:11px;margin-right:5px;"></i>
                            <strong><?php echo htmlspecialchars($complaint['assigned_to_name']); ?></strong>
                        </span>
                    </div>
                </div>
                <?php else: ?>
                <div class="db-empty" style="padding:24px;">
                    <i class="fas fa-user-clock"></i>
                    <p><?php echo $is_resident ? 'Awaiting assignment' : 'Not yet assigned'; ?></p>
                </div>
                <?php endif; ?>

                <?php if ($can_modify): ?>
                <div style="margin-top:4px;">
                    <button class="db-btn db-btn--primary db-btn--block" data-bs-toggle="modal" data-bs-target="#updateComplaintModal">
                        <i class="fas fa-edit"></i> Update Complaint
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /col-lg-4 -->
</div><!-- /row -->
</div><!-- /padding wrapper -->

<!-- ── Lightbox ── -->
<div id="db-lightbox" onclick="dbLightboxClose()">
    <span id="db-lightbox-close" onclick="dbLightboxClose()">&times;</span>
    <img id="db-lightbox-img" src="" alt="" onclick="event.stopPropagation()">
</div>

<!-- ══════════ MODALS ══════════ -->
<?php if ($can_modify): ?>

<!-- Update Complaint Modal -->
<div class="modal fade db-modal" id="updateComplaintModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Update Complaint</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="updateComplaintForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="db-form-label">Status <span style="color:var(--db-rose)">*</span></label>
                            <select class="db-form-control" name="status" id="statusSelect" required>
                                <?php foreach (['Pending','In Progress','Resolved','Closed'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo ($complaint['status']===$s)?'selected':''; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="db-form-label">Priority <span style="color:var(--db-rose)">*</span></label>
                            <select class="db-form-control" name="priority" required>
                                <?php foreach (['Low','Medium','High','Urgent'] as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo ($complaint['priority']===$p)?'selected':''; ?>><?php echo $p; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="db-form-label">Assign Responder <span style="color:var(--db-rose)">*</span></label>
                            <select class="db-form-control" name="responder_id" required>
                                <option value="">-- Select Responder --</option>
                                <?php foreach ($staff_users as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>" <?php echo ($complaint['assigned_to']==$u['user_id'])?'selected':''; ?>>
                                    <?php echo htmlspecialchars($u['username']).' ('.htmlspecialchars($u['role']).')'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="db-alert db-alert--info" style="margin-top:14px;margin-bottom:0;">
                        <i class="fas fa-info-circle"></i>
                        Updating will send notifications to the complainant and assigned staff.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="button" class="db-btn db-btn--primary" onclick="confirmComplaintUpdate()"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Close Confirmation Modal -->
<div class="modal fade db-modal" id="closeConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#b45309,#d97706);">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Close Complaint?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:14px;">Are you sure you want to close this complaint?</p>
                <div class="db-alert db-alert--warning"><i class="fas fa-info-circle"></i>This indicates the complaint has been fully resolved and addressed.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                <button type="button" class="db-btn db-btn--amber" onclick="submitComplaintForm()"><i class="fas fa-check"></i> Yes, Close</button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function dbLightbox(src){
    document.getElementById('db-lightbox-img').src=src;
    document.getElementById('db-lightbox').classList.add('active');
    document.body.style.overflow='hidden';
}
function dbLightboxClose(){
    document.getElementById('db-lightbox').classList.remove('active');
    document.getElementById('db-lightbox-img').src='';
    document.body.style.overflow='';
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')dbLightboxClose();});

document.addEventListener('DOMContentLoaded',function(){
    // Auto-dismiss non-info alerts
    setTimeout(()=>{
        document.querySelectorAll('.db-alert:not(.db-alert--info)').forEach(a=>{
            a.style.transition='opacity .4s';a.style.opacity='0';
            setTimeout(()=>a.remove(),400);
        });
    },5000);

    // Reset modals on close
    document.querySelectorAll('.modal').forEach(m=>{
        m.addEventListener('hidden.bs.modal',function(){
            const f=this.querySelector('form');
            if(f){f.reset();f.classList.remove('was-validated');}
        });
    });
});

<?php if ($can_modify): ?>
function confirmComplaintUpdate(){
    const s=document.getElementById('statusSelect').value;
    if(s==='Closed'){
        bootstrap.Modal.getInstance(document.getElementById('updateComplaintModal')).hide();
        setTimeout(()=>new bootstrap.Modal(document.getElementById('closeConfirmModal')).show(),300);
    } else {
        submitComplaintForm();
    }
}
function submitComplaintForm(){
    const f=document.getElementById('updateComplaintForm');
    const i=document.createElement('input');i.type='hidden';i.name='update_complaint';i.value='1';f.appendChild(i);
    const m=document.getElementById('closeConfirmModal');
    const inst=bootstrap.Modal.getInstance(m);if(inst)inst.hide();
    f.submit();
}
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>