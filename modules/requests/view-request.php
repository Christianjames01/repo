<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id   = getCurrentUserId();
$user_role = getCurrentUserRole();

$page_title = 'Request Details';

if (!isset($_GET['id'])||intval($_GET['id'])<=0) { $_SESSION['error_message']='Request ID is required'; header('Location: my-requests.php'); exit(); }
$request_id = intval($_GET['id']);

$conn->query("CREATE TABLE IF NOT EXISTS tbl_request_replies (
    reply_id    INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    sender_type ENUM('admin','resident') NOT NULL,
    sender_id   INT NOT NULL,
    message     TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request_id (request_id)
)");

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])) {

    if ($_POST['action']==='update_status'&&$user_role!=='Resident') {
        $new_status=sanitizeInput($_POST['status']); $remarks=sanitizeInput($_POST['remarks']??'');
        $stmt=$conn->prepare("UPDATE tbl_requests SET status=?,remarks=?,processed_by=?,processed_date=NOW() WHERE request_id=?");
        $stmt->bind_param("ssii",$new_status,$remarks,$user_id,$request_id);
        if ($stmt->execute()) {
            $_SESSION['success_message']='Status updated successfully';
            $rs=$conn->prepare("SELECT u.user_id,rt.request_type_name FROM tbl_requests req INNER JOIN tbl_residents r ON req.resident_id=r.resident_id INNER JOIN tbl_users u ON r.resident_id=u.resident_id INNER JOIN tbl_request_types rt ON req.request_type_id=rt.request_type_id WHERE req.request_id=?");
            $rs->bind_param("i",$request_id); $rs->execute(); $rd=$rs->get_result()->fetch_assoc(); $rs->close();
            if ($rd) { $ns2=$conn->prepare("INSERT INTO tbl_notifications (user_id,type,reference_type,reference_id,title,message,is_read,created_at) VALUES (?,?,?,?,?,?,0,NOW())"); $nt="request_status_update";$rt2="request";$nm="Your request for {$rd['request_type_name']} updated to: {$new_status}"; $ns2->bind_param("ississ",$rd['user_id'],$nt,$rt2,$request_id,$new_status,$nm); $ns2->execute(); $ns2->close(); }
        } else { $_SESSION['error_message']='Failed to update status'; }
        $stmt->close();
        header('Location: view-request.php?id='.$request_id); exit();
    }

    if ($_POST['action']==='update_payment'&&$user_role!=='Resident') {
        $payment_status=intval($_POST['payment_status']);
        $cur=$conn->prepare("SELECT payment_status FROM tbl_requests WHERE request_id=?"); $cur->bind_param("i",$request_id); $cur->execute(); $cur_data=$cur->get_result()->fetch_assoc(); $cur->close();
        $was_paid=($cur_data&&$cur_data['payment_status']==1);
        $stmt=$conn->prepare("UPDATE tbl_requests SET payment_status=? WHERE request_id=?"); $stmt->bind_param("ii",$payment_status,$request_id);
        if ($stmt->execute()) {
            if ($payment_status==1&&!$was_paid) {
                $rs=$conn->prepare("SELECT u.user_id,rt.request_type_name,rt.fee,res.first_name,res.last_name FROM tbl_requests req INNER JOIN tbl_residents res ON req.resident_id=res.resident_id INNER JOIN tbl_users u ON res.resident_id=u.resident_id INNER JOIN tbl_request_types rt ON req.request_type_id=rt.request_type_id WHERE req.request_id=?");
                $rs->bind_param("i",$request_id); $rs->execute(); $rd=$rs->get_result()->fetch_assoc(); $rs->close();
                if ($rd&&$rd['fee']>0) {
                    $fee=(float)$rd['fee'];
                    $cat_id=null; $cat_res=$conn->query("SELECT category_id FROM tbl_revenue_categories WHERE category_name='Document Fees' LIMIT 1");
                    if ($cat_res&&$cat_res->num_rows>0) $cat_id=(int)$cat_res->fetch_assoc()['category_id'];
                    else { $conn->query("INSERT INTO tbl_revenue_categories (category_name,description,is_active) VALUES ('Document Fees','Revenue from document requests',1)"); $cat_id=(int)$conn->insert_id; }
                    if ($cat_id) {
                        $ref_no='REV-'.date('Ymd').'-'.str_pad($request_id,6,'0',STR_PAD_LEFT);
                        $rname=trim($rd['first_name'].' '.$rd['last_name']); $src=$rname.' – '.$rd['request_type_name']; $desc="Payment for {$rd['request_type_name']} (Request #{$request_id})";
                        $rev=$conn->prepare("INSERT INTO tbl_revenues (reference_number,category_id,source,amount,description,transaction_date,payment_method,received_by,status,created_at) VALUES (?,?,?,?,?,NOW(),'Cash',?,'Pending',NOW())");
                        $rev->bind_param("sisdsi",$ref_no,$cat_id,$src,$fee,$desc,$user_id); $rev->execute(); $rev->close();
                    }
                    $_SESSION['success_message']="Payment confirmed! Revenue entry created. Reference: {$ref_no}";
                } else { $_SESSION['success_message']='Payment status updated'; }
            } else { $_SESSION['success_message']='Payment status updated'; }
        } else { $_SESSION['error_message']='Failed to update payment'; }
        $stmt->close();
        header('Location: view-request.php?id='.$request_id); exit();
    }

    if ($_POST['action']==='admin_reply'&&$user_role!=='Resident') {
        $ck=$conn->prepare("SELECT status FROM tbl_requests WHERE request_id=?"); $ck->bind_param("i",$request_id); $ck->execute(); $ck_res=$ck->get_result()->fetch_assoc(); $ck->close();
        if ($ck_res&&$ck_res['status']==='Rejected') { $_SESSION['error_message']='Cannot reply to a rejected request'; header('Location: view-request.php?id='.$request_id); exit(); }
        $msg=trim($_POST['reply_message']??'');
        if ($msg!=='') {
            $ins=$conn->prepare("INSERT INTO tbl_request_replies (request_id,sender_type,sender_id,message) VALUES (?,'admin',?,?)");
            $ins->bind_param("iis",$request_id,$user_id,$msg);
            if ($ins->execute()) {
                $_SESSION['success_message']='Reply sent successfully';
                $info=$conn->prepare("SELECT u.user_id AS res_uid,rt.request_type_name,au.username AS admin_name FROM tbl_requests req INNER JOIN tbl_residents res ON req.resident_id=res.resident_id INNER JOIN tbl_users u ON res.resident_id=u.resident_id LEFT JOIN tbl_request_types rt ON req.request_type_id=rt.request_type_id LEFT JOIN tbl_users au ON au.user_id=? WHERE req.request_id=?");
                $info->bind_param("ii",$user_id,$request_id); $info->execute(); $ri=$info->get_result()->fetch_assoc(); $info->close();
                if ($ri&&!empty($ri['res_uid'])) {
                    $nt2=$conn->prepare("INSERT INTO tbl_notifications (user_id,type,reference_type,reference_id,title,message,is_read,created_at) VALUES (?,?,?,?,?,?,0,NOW())");
                    $ntype="request_status_update";$nrt="request";$ntit="New Reply on Your Request";$nmsg="{$ri['admin_name']} replied to your {$ri['request_type_name']} request.";$ruid=(int)$ri['res_uid'];
                    $nt2->bind_param("ississ",$ruid,$ntype,$nrt,$request_id,$ntit,$nmsg); $nt2->execute(); $nt2->close();
                }
            } else { $_SESSION['error_message']='Failed to send reply'; }
            $ins->close();
        } else { $_SESSION['error_message']='Reply cannot be empty'; }
        header('Location: view-request.php?id='.$request_id); exit();
    }
}

$sql="SELECT r.*,res.first_name,res.last_name,res.contact_number,res.email,res.address,rt.request_type_name,rt.fee,u.username as processed_by_name FROM tbl_requests r INNER JOIN tbl_residents res ON r.resident_id=res.resident_id LEFT JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id LEFT JOIN tbl_users u ON r.processed_by=u.user_id WHERE r.request_id=?";
if ($user_role==='Resident') $sql.=" AND res.resident_id=(SELECT resident_id FROM tbl_users WHERE user_id=?)";
$stmt=$conn->prepare($sql);
if ($user_role==='Resident') $stmt->bind_param("ii",$request_id,$user_id);
else $stmt->bind_param("i",$request_id);
$stmt->execute(); $request=$stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$request) { $_SESSION['error_message']='Request not found or access denied'; header('Location: my-requests.php'); exit(); }
$is_rejected=($request['status']==='Rejected');

$stmt=$conn->prepare("SELECT a.*,dr.requirement_name,dr.is_mandatory FROM tbl_request_attachments a LEFT JOIN tbl_document_requirements dr ON a.requirement_id=dr.requirement_id WHERE a.request_id=? ORDER BY dr.is_mandatory DESC,dr.requirement_name");
$stmt->bind_param("i",$request_id); $stmt->execute();
$attachments=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$rep_stmt=$conn->prepare("SELECT rr.*,au.username AS admin_username,res.first_name AS res_first_name,res.last_name AS res_last_name FROM tbl_request_replies rr LEFT JOIN tbl_users au ON rr.sender_id=au.user_id AND rr.sender_type='admin' LEFT JOIN tbl_users ru ON rr.sender_id=ru.user_id AND rr.sender_type='resident' LEFT JOIN tbl_residents res ON ru.resident_id=res.resident_id WHERE rr.request_id=? ORDER BY rr.created_at ASC");
$rep_stmt->bind_param("i",$request_id); $rep_stmt->execute();
$replies=$rep_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $rep_stmt->close();

$has_thread=(!empty($request['remarks'])||!empty($replies));

function reqStatusBadge($s){$m=['Pending'=>['amber','clock'],'Approved'=>['sky','check-circle'],'Released'=>['success','check-double'],'Rejected'=>['danger','times-circle']];$s=trim($s);[$c,$i]=$m[$s]??['muted','circle'];return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($s)."</span>";}

include '../../includes/header.php';
$status_key = strtolower(str_replace(' ','-',$request['status']));
?>

<link rel="stylesheet" href="/barangaylink1/assets/css/_db_shared.css">

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon rm-hero__icon--purple"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">Request Details</div>
                <div class="rm-hero__sub">Request #<?php echo str_pad($request['request_id'],5,'0',STR_PAD_LEFT); ?></div>
            </div>
        </div>
        <div class="rm-hero__badges">
            <?php echo reqStatusBadge($request['status']); ?>
            <a href="<?php echo $user_role==='Resident'?'my-requests.php':'admin-manage-requests.php'; ?>" class="db-btn db-btn--glass db-btn--sm">
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
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT -->
    <div class="col-lg-8">

        <?php if ($user_role!=='Resident'): ?>
        <!-- Admin action box -->
        <div class="db-action-box">
            <div class="db-action-box__title"><i class="fas fa-sliders-h"></i> Admin Actions</div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <label class="db-form-label">Update Status</label>
                <select name="status" class="db-form-control db-form-select" style="margin-bottom:12px;" required>
                    <?php foreach(['Pending','Approved','Released','Rejected'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo($request['status']===$s)?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="db-form-label">Remarks <span style="font-weight:400;color:var(--db-muted);">(optional)</span></label>
                <textarea name="remarks" class="db-form-control db-form-textarea" rows="3" style="margin-bottom:12px;" placeholder="Add remarks…"><?php echo htmlspecialchars($request['remarks']??''); ?></textarea>
                <button type="submit" class="db-btn db-btn--primary" style="width:100%;justify-content:center;"><i class="fas fa-save"></i> Save Status</button>
            </form>

            <?php if ($request['fee']>0): ?>
            <div class="db-divider"></div>
            <form method="POST">
                <input type="hidden" name="action" value="update_payment">
                <label class="db-form-label">Payment Status</label>
                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="payment_status" value="0" <?php echo !$request['payment_status']?'checked':''; ?>> Unpaid
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="payment_status" value="1" <?php echo $request['payment_status']?'checked':''; ?>>
                        Paid — <strong>₱<?php echo number_format($request['fee'],2); ?></strong>
                    </label>
                </div>
                <div class="db-alert db-alert--success" style="margin-bottom:12px;font-size:12px;">
                    <i class="fas fa-check-circle"></i>
                    Marking Paid creates a <strong>Pending</strong> revenue entry for finance review.
                </div>
                <button type="submit" class="db-btn db-btn--success" style="width:100%;justify-content:center;"><i class="fas fa-check"></i> Update Payment</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Request Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--purple"><i class="fas fa-info-circle"></i></div>
                    <h2>Request Information</h2>
                </div>
                <?php echo reqStatusBadge($request['status']); ?>
            </div>
            <div class="db-panel__body">
                <div class="db-info-grid">
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-file-text"></i> Document Type</div>
                        <div class="db-info-value"><?php echo htmlspecialchars($request['request_type_name']); ?></div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-calendar-alt"></i> Request Date</div>
                        <div class="db-info-value">
                            <i class="fas fa-clock" style="color:var(--db-sky);font-size:11px;"></i>
                            <?php echo date('M d, Y · g:i A',strtotime($request['request_date'])); ?>
                        </div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-peso-sign"></i> Fee</div>
                        <div class="db-info-value">
                            <?php if ($request['fee']>0): ?>
                                <span class="db-fee">₱<?php echo number_format($request['fee'],2); ?></span>
                            <?php else: ?>
                                <span class="db-badge db-badge--muted">Free</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-credit-card"></i> Payment</div>
                        <div class="db-info-value">
                            <?php if ($request['payment_status']): ?>
                                <span class="db-badge db-badge--success"><i class="fas fa-check"></i> Paid</span>
                            <?php elseif ($request['fee']>0): ?>
                                <span class="db-badge db-badge--amber">Unpaid</span>
                            <?php else: ?>
                                <span class="db-badge db-badge--muted">N/A</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="db-info-item db-info-item--full">
                        <div class="db-info-label"><i class="fas fa-align-left"></i> Purpose</div>
                        <div class="db-info-value db-info-value--block" style="font-weight:400;color:var(--db-muted);background:var(--db-surf);border-left:3px solid var(--db-sky);padding:10px 14px;border-radius:0 6px 6px 0;margin-top:4px;">
                            <?php echo nl2br(htmlspecialchars($request['purpose'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Remarks & Reply Thread -->
        <?php if ($has_thread||$user_role!=='Resident'): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-comments"></i></div>
                    <h2>Remarks &amp; Replies</h2>
                    <?php if (!empty($replies)): ?>
                    <span class="db-badge db-badge--sky"><?php echo count($replies); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="db-chat">
                <?php if (!$has_thread): ?>
                <p style="font-size:12.5px;color:var(--db-muted);">No remarks or replies yet.</p>
                <?php else: ?>
                    <?php if (!empty($request['remarks'])): ?>
                    <div class="db-chat-bubble db-chat-bubble--remark">
                        <span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;opacity:.65;display:block;margin-bottom:4px;"><i class="fas fa-user-shield me-1"></i>Admin Remark</span>
                        <?php echo nl2br(htmlspecialchars($request['remarks'])); ?>
                    </div>
                    <?php endif; ?>
                    <?php foreach($replies as $r): ?>
                    <div class="db-chat-bubble db-chat-bubble--<?php echo $r['sender_type']; ?>">
                        <?php echo nl2br(htmlspecialchars($r['message'])); ?>
                        <span class="db-chat-meta">
                            <?php if ($r['sender_type']==='admin'): ?>
                                <i class="fas fa-user-shield me-1"></i><?php echo htmlspecialchars($r['admin_username']??'Admin'); ?>
                            <?php else: ?>
                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars(($r['res_first_name']??'').' '.($r['res_last_name']??'')); ?>
                            <?php endif; ?>
                            · <?php echo date('M j, Y g:i A',strtotime($r['created_at'])); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($user_role!=='Resident'): ?>
            <div style="padding:14px 16px;border-top:1px solid var(--db-border);background:var(--db-surf2);<?php echo $is_rejected?'opacity:.6;pointer-events:none;':''; ?>">
                <?php if ($is_rejected): ?>
                <div class="db-alert db-alert--error" style="margin-bottom:10px;font-size:12px;"><i class="fas fa-ban"></i> Chat disabled — request is rejected.</div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="admin_reply">
                    <label class="db-form-label" style="margin-bottom:6px;">Reply to Resident</label>
                    <textarea name="reply_message" class="db-form-control db-form-textarea" rows="3" placeholder="Type your reply…" <?php echo $is_rejected?'disabled':''; ?> required style="resize:none;"></textarea>
                    <div style="margin-top:8px;">
                        <button type="submit" class="db-btn db-btn--primary db-btn--sm" <?php echo $is_rejected?'disabled':''; ?>><i class="fas fa-paper-plane"></i> Send Reply</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-paperclip"></i></div>
                    <h2>Submitted Requirements</h2>
                    <span class="db-badge db-badge--amber"><?php echo count($attachments); ?></span>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="row g-3">
                    <?php foreach($attachments as $att):
                        $furl='../../'.$att['file_path'];
                        $is_img=strpos($att['file_type'],'image/')===0;
                    ?>
                    <div class="col-md-6">
                        <div class="db-req-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid var(--db-border);">
                                <span style="font-size:12.5px;font-weight:700;flex:1;"><?php echo htmlspecialchars($att['requirement_name']??'Attachment'); ?></span>
                                <span class="db-badge <?php echo $att['is_mandatory']?'db-badge--rose':'db-badge--muted'; ?>">
                                    <?php echo $att['is_mandatory']?'Required':'Optional'; ?>
                                </span>
                            </div>
                            <?php if ($is_img): ?>
                            <img src="<?php echo htmlspecialchars($furl); ?>" class="db-req-card__preview"
                                 onclick="dbViewImage('<?php echo htmlspecialchars($furl,ENT_QUOTES); ?>','<?php echo htmlspecialchars($att['file_name'],ENT_QUOTES); ?>')"
                                 alt="Preview">
                            <?php else: ?>
                            <div class="db-req-card__pdf">
                                <i class="fas fa-file-pdf" style="font-size:2.5rem;color:var(--db-rose);margin-bottom:6px;"></i>
                                <span class="db-badge db-badge--muted">PDF Document</span>
                            </div>
                            <?php endif; ?>
                            <div class="db-req-card__body">
                                <div style="font-size:11px;color:var(--db-muted);line-height:1.9;margin-bottom:10px;">
                                    <div><i class="fas fa-file me-1"></i><?php echo htmlspecialchars($att['file_name']); ?></div>
                                    <div><i class="fas fa-weight me-1"></i><?php echo number_format($att['file_size']/1024,2); ?> KB</div>
                                    <div><i class="fas fa-clock me-1"></i><?php echo date('M d, Y h:i A',strtotime($att['uploaded_at'])); ?></div>
                                </div>
                                <a href="<?php echo htmlspecialchars($furl); ?>" target="_blank" class="db-btn db-btn--primary db-btn--sm" style="width:100%;justify-content:center;">
                                    <i class="fas fa-external-link-alt"></i> View / Download
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /col-lg-8 -->

    <!-- RIGHT SIDEBAR -->
    <div class="col-lg-4">

        <!-- Status panel -->
        <div class="db-panel">
            <div class="db-panel__body" style="padding:16px;">
                <?php
                $smap=['Pending'=>['fa-clock','Your request is being reviewed'],'Approved'=>['fa-check-circle','Your request has been approved'],'Released'=>['fa-check-double','Your document is ready for pickup'],'Rejected'=>['fa-times-circle','Your request was rejected']];
                $si=$smap[$request['status']]??['fa-info-circle',''];
                ?>
                <div class="db-status-panel db-status-panel--<?php echo $status_key; ?>">
                    <i class="fas <?php echo $si[0]; ?> db-status-icon"></i>
                    <?php echo reqStatusBadge($request['status']); ?>
                    <p style="font-size:12px;color:var(--db-muted);margin:8px 0 0;"><?php echo $si[1]; ?></p>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--muted"><i class="fas fa-history"></i></div>
                    <h2>Timeline</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-timeline">
                    <div class="db-timeline-item tl--muted">
                        <div class="db-timeline-card">
                            <div class="db-timeline-author" style="margin-bottom:4px;"><i class="fas fa-file-plus" style="color:var(--db-muted);font-size:11px;"></i> Request Submitted</div>
                            <div class="db-timeline-date"><?php echo date('F d, Y h:i A',strtotime($request['request_date'])); ?></div>
                        </div>
                    </div>
                    <?php if ($request['status']!=='Pending'): ?>
                    <?php $tl_color=['Approved'=>'sky','Released'=>'success','Rejected'=>'rose'][$request['status']]??'amber'; ?>
                    <div class="db-timeline-item tl--<?php echo $tl_color; ?>">
                        <div class="db-timeline-card">
                            <div class="db-timeline-author" style="margin-bottom:4px;"><i class="fas fa-edit" style="color:var(--db-<?php echo $tl_color; ?>);font-size:11px;"></i> Status: <?php echo htmlspecialchars($request['status']); ?></div>
                            <div class="db-timeline-date">
                                <?php echo $request['processed_date']?date('F d, Y h:i A',strtotime($request['processed_date'])):'N/A'; ?>
                                <?php if ($request['processed_by_name']): ?><br>By: <?php echo htmlspecialchars($request['processed_by_name']); ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($request['payment_status']&&$request['fee']>0): ?>
                    <div class="db-timeline-item tl--success">
                        <div class="db-timeline-card">
                            <div class="db-timeline-author" style="margin-bottom:4px;"><i class="fas fa-check" style="color:var(--db-success);font-size:11px;"></i> Payment Confirmed</div>
                            <div class="db-timeline-date">₱<?php echo number_format($request['fee'],2); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($replies)): ?>
                    <div class="db-timeline-item tl--purple">
                        <div class="db-timeline-card">
                            <div class="db-timeline-author" style="margin-bottom:4px;"><i class="fas fa-comments" style="color:var(--db-purple);font-size:11px;"></i> Reply Thread</div>
                            <div class="db-timeline-date"><?php echo count($replies); ?> message<?php echo count($replies)!==1?'s':''; ?> exchanged</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Resident info (staff only) -->
        <?php if ($user_role!=='Resident'): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-user"></i></div>
                    <h2>Resident Information</h2>
                </div>
            </div>
            <div class="db-panel__body" style="padding:16px;">
                <?php foreach([['Name',htmlspecialchars($request['first_name'].' '.$request['last_name'])],['Contact',htmlspecialchars($request['contact_number']??'N/A')],['Email',htmlspecialchars($request['email']??'N/A')],['Address',htmlspecialchars($request['address']??'N/A')]] as [$l,$v]): ?>
                <div class="db-info-item" style="margin-bottom:12px;">
                    <div class="db-info-label"><?php echo $l; ?></div>
                    <div class="db-info-value db-info-value--block" style="font-weight:<?php echo $l==='Address'?'400':'600'; ?>;"><?php echo $v; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /col-lg-4 -->
</div>
</div>

<!-- Image preview modal -->
<div class="modal fade db-modal" id="dbImgModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="dbImgTitle"><i class="fas fa-image"></i> Image Preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center" style="background:var(--db-surf2);padding:16px;">
                <img id="dbImgSrc" src="" class="img-fluid" style="border-radius:8px;" alt="Preview">
            </div>
        </div>
    </div>
</div>

<script>
function dbViewImage(src,title){document.getElementById('dbImgSrc').src=src;document.getElementById('dbImgTitle').innerHTML='<i class="fas fa-image"></i> '+title;new bootstrap.Modal(document.getElementById('dbImgModal')).show();}
setTimeout(()=>document.querySelectorAll('.db-alert:not(.db-alert--info)').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include '../../includes/footer.php'; ?>