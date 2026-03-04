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
if (is_dir($upload_fs)) {
    foreach (scandir($upload_fs) as $file) {
        if ($file==='.'||$file==='..') continue;
        if (strpos($file,'complaint_'.$complaint_id.'_')===0) $attachments[]=$file;
    }
}
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_complaint_attachments'");
if ($table_check && $table_check->num_rows > 0) {
    $att_stmt = $conn->prepare("SELECT file_name,file_path FROM tbl_complaint_attachments WHERE complaint_id=?");
    $att_stmt->bind_param("i",$complaint_id); $att_stmt->execute();
    $att_result = $att_stmt->get_result();
    while ($att_row = $att_result->fetch_assoc()) {
        $bn = basename($att_row['file_path']);
        if (!in_array($bn,$attachments)) $attachments[]=$bn;
    }
    $att_stmt->close();
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

function cStatusBadge($s){$s=trim($s);$m=['Pending'=>['amber','clock'],'In Progress'=>['sky','spinner'],'Resolved'=>['success','check-circle'],'Closed'=>['muted','times-circle']];[$c,$i]=$m[$s]??['muted','circle'];return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($s)."</span>";}
function cPriorityBadge($p){$p=trim($p);$m=['Low'=>['success','circle'],'Medium'=>['amber','exclamation-circle'],'High'=>['rose','exclamation-triangle'],'Urgent'=>['danger','fire']];[$c,$i]=$m[$p]??['muted','circle'];return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($p)."</span>";}

include '../../includes/header.php';
?>
<style>
<?php include '/barangaylink1/assets/css/_db_shared.css'; ?>
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon rm-hero__icon--rose"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">Complaint Details</div>
                <div class="rm-hero__sub">
                    <i class="fas fa-hashtag" style="opacity:.6;margin-right:4px;"></i>
                    <?php echo htmlspecialchars($complaint['complaint_number']); ?>
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

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($is_tanod): ?>
<div class="db-alert db-alert--info"><i class="fas fa-info-circle"></i><strong>View Only Mode:</strong>&nbsp;As a Tanod you cannot make modifications.<button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT -->
    <div class="col-lg-8">
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-info-circle"></i></div>
                    <h2>Complaint Information</h2>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <?php echo cStatusBadge($complaint['status']??'Pending'); ?>
                    <?php echo cPriorityBadge($complaint['priority']??'Medium'); ?>
                </div>
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
                        <div class="db-info-label"><i class="fas fa-tag"></i> Category</div>
                        <div class="db-info-value">
                            <?php
                            $cat_icons=['Noise'=>'fa-volume-up','Garbage'=>'fa-trash','Property'=>'fa-home','Infrastructure'=>'fa-road','Public Safety'=>'fa-shield-alt','Services'=>'fa-concierge-bell','Animals'=>'fa-paw','Utilities'=>'fa-bolt'];
                            $ci=$cat_icons[$complaint['category']??'']??'fa-comment';
                            ?>
                            <i class="fas <?php echo $ci; ?>" style="color:var(--db-amber);font-size:11px;"></i>
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

                <?php if (!empty($attachments)): ?>
                <div style="margin-top:8px;">
                    <div class="db-info-label" style="margin-bottom:10px;">
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
                <div class="db-info-item" style="margin-top:8px;">
                    <div class="db-info-label"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div class="db-info-value" style="font-weight:400;color:var(--db-muted);"><i class="fas fa-folder-open me-1"></i>No attachments uploaded</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT -->
    <div class="col-lg-4">
        <!-- Complainant -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-user"></i></div>
                    <h2><?php echo $is_resident ? 'Your Information' : 'Complainant'; ?></h2>
                </div>
            </div>
            <div class="db-panel__body" style="padding:16px;">
                <?php foreach ([['fas fa-user','Name',htmlspecialchars($complaint['first_name'].' '.$complaint['last_name'])],['fas fa-phone','Contact',htmlspecialchars($complaint['contact_number'])],['fas fa-envelope','Email',htmlspecialchars($complaint['email'])],['fas fa-home','Address',htmlspecialchars($complaint['address'])]] as [$ico,$lbl,$val]): ?>
                <div class="db-info-item" style="margin-bottom:8px;">
                    <div class="db-info-label"><i class="<?php echo $ico; ?>"></i> <?php echo $lbl; ?></div>
                    <div class="db-info-value db-info-value--block" style="font-weight:<?php echo $lbl==='Address'?'400':'600'; ?>;"><?php echo $val; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Assignment -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-user-shield"></i></div>
                    <h2>Assignment Status</h2>
                </div>
            </div>
            <div class="db-panel__body" style="padding:16px;">
                <?php if (!empty($complaint['assigned_to_name'])): ?>
                <div class="db-info-item" style="margin-bottom:12px;">
                    <div class="db-info-label"><i class="fas fa-user-shield"></i> Assigned To</div>
                    <div class="db-info-value">
                        <i class="fas fa-user-check" style="color:var(--db-sky);font-size:11px;"></i>
                        <strong><?php echo htmlspecialchars($complaint['assigned_to_name']); ?></strong>
                    </div>
                </div>
                <?php else: ?>
                <div class="db-empty" style="padding:20px;">
                    <i class="fas fa-user-clock"></i>
                    <p><?php echo $is_resident ? 'Awaiting assignment' : 'Not yet assigned'; ?></p>
                </div>
                <?php endif; ?>

                <?php if ($can_modify): ?>
                <button class="db-btn db-btn--primary db-btn--block" data-bs-toggle="modal" data-bs-target="#updateComplaintModal">
                    <i class="fas fa-edit"></i> Update Complaint
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Lightbox -->
<div id="db-lightbox" onclick="dbLightboxClose()">
    <span id="db-lightbox-close" onclick="dbLightboxClose()">&times;</span>
    <img id="db-lightbox-img" src="" alt="" onclick="event.stopPropagation()">
</div>

<?php if ($can_modify): ?>
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
function dbLightbox(src){document.getElementById('db-lightbox-img').src=src;document.getElementById('db-lightbox').classList.add('active');document.body.style.overflow='hidden';}
function dbLightboxClose(){document.getElementById('db-lightbox').classList.remove('active');document.getElementById('db-lightbox-img').src='';document.body.style.overflow='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')dbLightboxClose();});

setTimeout(()=>document.querySelectorAll('.db-alert:not(.db-alert--info)').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);

<?php if ($can_modify): ?>
function confirmComplaintUpdate(){
    const s=document.getElementById('statusSelect').value;
    if(s==='Closed'){
        bootstrap.Modal.getInstance(document.getElementById('updateComplaintModal')).hide();
        setTimeout(()=>new bootstrap.Modal(document.getElementById('closeConfirmModal')).show(),300);
    } else { submitComplaintForm(); }
}
function submitComplaintForm(){
    const f=document.getElementById('updateComplaintForm');
    const i=document.createElement('input'); i.type='hidden'; i.name='update_complaint'; i.value='1'; f.appendChild(i);
    const m=document.getElementById('closeConfirmModal');
    const inst=bootstrap.Modal.getInstance(m); if(inst) inst.hide();
    f.submit();
}
<?php endif; ?>
</script>
<?php include '../../includes/footer.php'; ?>