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

if (!isset($_GET['id']) || intval($_GET['id']) <= 0) {
    $_SESSION['error_message'] = 'Request ID is required';
    header('Location: my-requests.php');
    exit();
}

$request_id = intval($_GET['id']);

// Auto-create replies table if missing
$conn->query("CREATE TABLE IF NOT EXISTS tbl_request_replies (
    reply_id     INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT NOT NULL,
    sender_type  ENUM('admin','resident') NOT NULL,
    sender_id    INT NOT NULL,
    message      TEXT NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request_id (request_id)
)");

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Update Status
    if ($_POST['action'] === 'update_status' && $user_role !== 'Resident') {
        $new_status = sanitizeInput($_POST['status']);
        $remarks    = sanitizeInput($_POST['remarks'] ?? '');

        $stmt = $conn->prepare("UPDATE tbl_requests SET status=?, remarks=?, processed_by=?, processed_date=NOW() WHERE request_id=?");
        $stmt->bind_param("ssii", $new_status, $remarks, $user_id, $request_id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Request status updated successfully';

            $res_stmt = $conn->prepare("SELECT u.user_id, rt.request_type_name 
                FROM tbl_requests req
                INNER JOIN tbl_residents r  ON req.resident_id   = r.resident_id
                INNER JOIN tbl_users u      ON r.resident_id     = u.resident_id
                INNER JOIN tbl_request_types rt ON req.request_type_id = rt.request_type_id
                WHERE req.request_id = ?");
            $res_stmt->bind_param("i", $request_id);
            $res_stmt->execute();
            $rd = $res_stmt->get_result()->fetch_assoc();
            $res_stmt->close();

            if ($rd) {
                $notif_stmt = $conn->prepare("INSERT INTO tbl_notifications (user_id,type,reference_type,reference_id,title,message,is_read,created_at) VALUES (?,?,?,?,?,?,0,NOW())");
                $nt = "request_status_update"; $rt2 = "request";
                $nm = "Your request for {$rd['request_type_name']} has been updated to: {$new_status}";
                $notif_stmt->bind_param("ississ", $rd['user_id'], $nt, $rt2, $request_id, $new_status, $nm);
                $notif_stmt->execute(); $notif_stmt->close();
            }
        } else {
            $_SESSION['error_message'] = 'Failed to update status';
        }
        $stmt->close();
        header('Location: view-request.php?id=' . $request_id); exit();
    }

    if ($_POST['action'] === 'update_payment' && $user_role !== 'Resident') {
        $payment_status = intval($_POST['payment_status']);

        // ── Prevent duplicate revenue: check current payment status ──
        $cur_stmt = $conn->prepare("SELECT payment_status FROM tbl_requests WHERE request_id = ?");
        $cur_stmt->bind_param("i", $request_id);
        $cur_stmt->execute();
        $cur_data = $cur_stmt->get_result()->fetch_assoc();
        $cur_stmt->close();
        $was_already_paid = ($cur_data && $cur_data['payment_status'] == 1);

        $stmt = $conn->prepare("UPDATE tbl_requests SET payment_status=? WHERE request_id=?");
        $stmt->bind_param("ii", $payment_status, $request_id);

        if ($stmt->execute()) {

            // Only create revenue when newly marking as Paid (not already paid)
            if ($payment_status == 1 && !$was_already_paid) {

                $res_stmt = $conn->prepare(
                    "SELECT u.user_id, rt.request_type_name, rt.fee,
                            res.first_name, res.last_name
                     FROM tbl_requests req
                     INNER JOIN tbl_residents res ON req.resident_id   = res.resident_id
                     INNER JOIN tbl_users u        ON res.resident_id  = u.resident_id
                     INNER JOIN tbl_request_types rt ON req.request_type_id = rt.request_type_id
                     WHERE req.request_id = ?"
                );
                $res_stmt->bind_param("i", $request_id);
                $res_stmt->execute();
                $rd = $res_stmt->get_result()->fetch_assoc();
                $res_stmt->close();

                if ($rd && $rd['fee'] > 0) {
                    $fee = (float) $rd['fee'];

                    // Get or auto-create the "Document Fees" category
                    $category_id = null;
                    $cat_res = $conn->query("SELECT category_id FROM tbl_revenue_categories WHERE category_name = 'Document Fees' LIMIT 1");
                    if ($cat_res && $cat_res->num_rows > 0) {
                        $category_id = (int) $cat_res->fetch_assoc()['category_id'];
                    } else {
                        $conn->query("INSERT INTO tbl_revenue_categories (category_name, description, is_active) VALUES ('Document Fees', 'Revenue from document requests', 1)");
                        $category_id = (int) $conn->insert_id;
                    }

                    if ($category_id) {
                        $reference_number = 'REV-' . date('Ymd') . '-' . str_pad($request_id, 6, '0', STR_PAD_LEFT);
                        $resident_name    = trim($rd['first_name'] . ' ' . $rd['last_name']);
                        $source           = $resident_name . ' – ' . $rd['request_type_name'];
                        $description      = "Payment for {$rd['request_type_name']} (Request #{$request_id})";

                        // Insert as PENDING — finance staff verifies in revenues.php
                        // which then updates the fund balance upon verification.
                        $rev_stmt = $conn->prepare(
                            "INSERT INTO tbl_revenues
                                (reference_number, category_id, source, amount, description,
                                 transaction_date, payment_method, received_by,
                                 status, created_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), 'Cash', ?, 'Pending', NOW())"
                        );
                        $rev_stmt->bind_param("sisdsi",
                            $reference_number,  // s
                            $category_id,       // i
                            $source,            // s
                            $fee,               // d
                            $description,       // s
                            $user_id            // i - received_by
                        );
                        $rev_stmt->execute();
                        $rev_stmt->close();
                    }

                    // Notify resident
                    $notif_stmt = $conn->prepare(
                        "INSERT INTO tbl_notifications (user_id,type,reference_type,reference_id,title,message,is_read,created_at)
                         VALUES (?,?,?,?,?,?,0,NOW())"
                    );
                    $nt     = "payment_confirmed";
                    $rt2    = "request";
                    $ntitle = "Payment Confirmed";
                    $nmsg   = "Your payment of ₱" . number_format($fee, 2) . " for {$rd['request_type_name']} has been confirmed. Reference: {$reference_number}";
                    $notif_stmt->bind_param("ississ", $rd['user_id'], $nt, $rt2, $request_id, $ntitle, $nmsg);
                    $notif_stmt->execute();
                    $notif_stmt->close();

                    // Notify Treasurer(s) about payment
                    $tres_result = $conn->query("SELECT user_id FROM tbl_users WHERE role = 'Treasurer'");
                    if ($tres_result && $tres_result->num_rows > 0) {
                        $tres_notif = $conn->prepare(
                            "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read, created_at) VALUES (?, 'payment_confirmed', 'request', ?, ?, ?, 0, NOW())"
                        );
                        $tres_title = "Document Payment Received";
                        $tres_msg   = "{$resident_name} paid ₱" . number_format($fee, 2) . " for {$rd['request_type_name']} (Request #{$request_id}). Reference: {$reference_number}";
                        while ($tres_row = $tres_result->fetch_assoc()) {
                            $tres_uid = $tres_row['user_id'];
                            $tres_notif->bind_param("iiss", $tres_uid, $request_id, $tres_title, $tres_msg);
                            $tres_notif->execute();
                        }
                        $tres_notif->close();
                    }

                    $_SESSION['success_message'] = "Payment confirmed! Revenue entry created and pending finance verification. Reference: {$reference_number}";
                } else {
                    $_SESSION['success_message'] = 'Payment status updated successfully';
                }
            } else {
                $_SESSION['success_message'] = 'Payment status updated successfully';
            }
        } else {
            $_SESSION['error_message'] = 'Failed to update payment status';
        }
        $stmt->close();
        header('Location: view-request.php?id=' . $request_id); exit();
    }

    // Admin reply
    if ($_POST['action'] === 'admin_reply' && $user_role !== 'Resident') {
        // Check if request is rejected
        $check_stmt = $conn->prepare("SELECT status FROM tbl_requests WHERE request_id = ?");
        $check_stmt->bind_param("i", $request_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($check_result && $check_result['status'] === 'Rejected') {
            $_SESSION['error_message'] = 'Cannot send reply to a rejected request';
            header('Location: view-request.php?id=' . $request_id); exit();
        }
        
        $reply_msg = trim($_POST['reply_message'] ?? '');
        if ($reply_msg !== '') {
            $ins = $conn->prepare("INSERT INTO tbl_request_replies (request_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)");
            $ins->bind_param("iis", $request_id, $user_id, $reply_msg);
            if ($ins->execute()) {
                $_SESSION['success_message'] = 'Reply sent successfully';

                $info_stmt = $conn->prepare(
                    "SELECT u.user_id AS resident_user_id,
                            rt.request_type_name,
                            au.username AS admin_name
                     FROM tbl_requests req
                     INNER JOIN tbl_residents res ON req.resident_id = res.resident_id
                     INNER JOIN tbl_users u        ON res.resident_id = u.resident_id
                     LEFT JOIN  tbl_request_types rt ON req.request_type_id = rt.request_type_id
                     LEFT JOIN  tbl_users au        ON au.user_id = ?
                     WHERE req.request_id = ?"
                );
                $info_stmt->bind_param("ii", $user_id, $request_id);
                $info_stmt->execute();
                $req_info = $info_stmt->get_result()->fetch_assoc();
                $info_stmt->close();

                if ($req_info && !empty($req_info['resident_user_id'])) {
                    $doc_type    = $req_info['request_type_name'] ?? 'Document Request';
                    $admin_name  = $req_info['admin_name'] ?? 'Admin';
                    $notif_title = "New Reply on Your Request";
                    $notif_msg   = "{$admin_name} replied to your {$doc_type} request. Tap to view.";
                    $notif_type  = "request_status_update";
                    $ref_type    = "request";
                    $res_uid     = (int)$req_info['resident_user_id'];

                    $notif_stmt = $conn->prepare(
                        "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
                    );
                    $notif_stmt->bind_param("ississ", $res_uid, $notif_type, $ref_type, $request_id, $notif_title, $notif_msg);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }
            } else {
                $_SESSION['error_message'] = 'Failed to send reply';
            }
            $ins->close();
        } else {
            $_SESSION['error_message'] = 'Reply cannot be empty';
        }
        header('Location: view-request.php?id=' . $request_id); exit();
    }
}

// Fetch request
$sql = "SELECT r.*, 
        res.first_name, res.last_name, res.contact_number, res.email, res.address,
        rt.request_type_name, rt.fee,
        u.username as processed_by_name
        FROM tbl_requests r
        INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        LEFT JOIN tbl_users u ON r.processed_by = u.user_id
        WHERE r.request_id = ?";
if ($user_role === 'Resident') {
    $sql .= " AND res.resident_id = (SELECT resident_id FROM tbl_users WHERE user_id = ?)";
}
$stmt = $conn->prepare($sql);
if ($user_role === 'Resident') { $stmt->bind_param("ii", $request_id, $user_id); }
else { $stmt->bind_param("i", $request_id); }
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    $_SESSION['error_message'] = 'Request not found or access denied';
    header('Location: my-requests.php'); exit();
}

$is_rejected = ($request['status'] === 'Rejected');

// Fetch attachments
$stmt = $conn->prepare("SELECT a.*, dr.requirement_name, dr.is_mandatory
    FROM tbl_request_attachments a
    LEFT JOIN tbl_document_requirements dr ON a.requirement_id = dr.requirement_id
    WHERE a.request_id = ?
    ORDER BY dr.is_mandatory DESC, dr.requirement_name");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$attachments_result = $stmt->get_result();
$attachments = [];
while ($row = $attachments_result->fetch_assoc()) { $attachments[] = $row; }
$stmt->close();

// Fetch reply thread
$replies = [];
$rep_stmt = $conn->prepare(
    "SELECT rr.*, 
            au.username       AS admin_username,
            res.first_name    AS res_first_name,
            res.last_name     AS res_last_name
     FROM tbl_request_replies rr
     LEFT JOIN tbl_users au   ON rr.sender_id = au.user_id    AND rr.sender_type = 'admin'
     LEFT JOIN tbl_users ru   ON rr.sender_id = ru.user_id    AND rr.sender_type = 'resident'
     LEFT JOIN tbl_residents res ON ru.resident_id = res.resident_id
     WHERE rr.request_id = ?
     ORDER BY rr.created_at ASC"
);
$rep_stmt->bind_param("i", $request_id);
$rep_stmt->execute();
$rep_result = $rep_stmt->get_result();
while ($row = $rep_result->fetch_assoc()) { $replies[] = $row; }
$rep_stmt->close();

$has_thread = !empty($request['remarks']) || !empty($replies);

include '../../includes/header.php';
?>

<style>
:root {
    --bg: #f5f5f4; --surface: #ffffff; --border: #e5e5e5;
    --text: #1a1a1a; --muted: #6b6b6b; --accent: #2563eb;
    --accent-light: #eff6ff; --radius: 10px; --shadow: 0 1px 4px rgba(0,0,0,0.06);
}
body { background: var(--bg); color: var(--text); }

.modern-card { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 1.25rem; }
.modern-card-header { background: var(--surface); color: var(--text); padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); font-weight: 600; font-size: 0.9rem; letter-spacing: 0.02em; }
.modern-card-header h5, .modern-card-header h6 { margin: 0; font-weight: 600; color: var(--text); }
.modern-card-body { padding: 1.25rem; }

.status-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.35rem 0.85rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem; letter-spacing: 0.03em; text-transform: uppercase; }
.status-badge.pending  { background: #fef9ec; color: #92610a; border: 1px solid #f0d98a; }
.status-badge.approved { background: #edf6ff; color: #1a5faa; border: 1px solid #bbd8f7; }
.status-badge.released { background: #edfaf1; color: #16653a; border: 1px solid #9fe0be; }
.status-badge.rejected { background: #fff1f1; color: #a01b1b; border: 1px solid #f2b4b4; }

.timeline { padding: 0.5rem 0; }
.timeline-item { position: relative; padding-left: 2.5rem; padding-bottom: 1.75rem; }
.timeline-item:last-child { padding-bottom: 0; }
.timeline-item::before { content:''; position:absolute; left:10px; top:22px; bottom:0; width:1px; background:var(--border); }
.timeline-item:last-child::before { display:none; }
.timeline-marker { position:absolute; left:0; top:2px; width:20px; height:20px; border-radius:50%; border:2px solid var(--surface); box-shadow:0 0 0 1px var(--border); }
.timeline-marker.pending  { background:#d4a017; } .timeline-marker.approved { background:var(--accent); }
.timeline-marker.released { background:#16a34a; } .timeline-marker.rejected { background:#dc2626; }
.timeline-marker.payment  { background:#16a34a; } .timeline-marker.default  { background:#9ca3af; }
.timeline-content { background:var(--bg); padding:0.75rem 1rem; border-radius:8px; border:1px solid var(--border); }
.timeline-title { font-weight:600; font-size:0.875rem; color:var(--text); margin-bottom:0.2rem; }
.timeline-meta  { font-size:0.8rem; color:var(--muted); line-height:1.5; }

.attachment-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:1rem; transition:border-color 0.2s,box-shadow 0.2s; height:100%; display:flex; flex-direction:column; }
.attachment-card:hover { border-color:var(--accent); box-shadow:0 2px 10px rgba(37,99,235,0.08); }
.attachment-preview { width:100%; height:180px; object-fit:cover; border-radius:6px; cursor:pointer; transition:opacity 0.2s; border:1px solid var(--border); }
.attachment-preview:hover { opacity:0.9; }
.pdf-preview { height:180px; display:flex; flex-direction:column; align-items:center; justify-content:center; background:var(--bg); border-radius:6px; margin-bottom:0.75rem; border:1px solid var(--border); }
.pdf-preview i { font-size:3rem; color:#dc2626; margin-bottom:0.5rem; }

.info-row { padding:0.75rem 0; border-bottom:1px solid #f0f0f0; }
.info-row:last-child { border-bottom:none; padding-bottom:0; }
.info-label { font-size:0.72rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.2rem; }
.info-value { color:var(--text); font-weight:500; font-size:0.925rem; }

.admin-actions { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:1.25rem; margin-bottom:1.25rem; }
.admin-actions-title { font-size:0.9rem; font-weight:600; color:var(--text); margin-bottom:1.25rem; padding-bottom:0.75rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:0.5rem; }
.admin-actions .form-label { color:var(--text); font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.4rem; }
.admin-actions .form-control, .admin-actions .form-select { border:1px solid var(--border); border-radius:8px; font-size:0.9rem; background:var(--surface); color:var(--text); }
.admin-actions .form-control:focus, .admin-actions .form-select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.08); }
.admin-actions .section-divider { border-top:1px solid var(--border); margin:1.25rem 0; padding-top:1.25rem; }
.admin-actions .form-check-label { color:var(--text); font-size:0.9rem; }

/* Auto-verify notice inside payment form */
.auto-verify-notice {
    background: #ecfdf5;
    border: 1px solid #6ee7b7;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-size: 0.8rem;
    color: #065f46;
    margin-top: 0.75rem;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}
.auto-verify-notice i { color: #059669; margin-top: 1px; flex-shrink: 0; }

.purpose-box { background:var(--bg); border-left:3px solid var(--accent); padding:1rem 1.25rem; border-radius:0 8px 8px 0; white-space:pre-wrap; font-size:0.9rem; line-height:1.7; color:var(--text); }

.status-panel { padding:1.5rem; border-radius:8px; text-align:center; margin-bottom:0; }
.status-panel.pending  { background:#fef9ec; border:1px solid #f0d98a; }
.status-panel.approved { background:#edf6ff; border:1px solid #bbd8f7; }
.status-panel.released { background:#edfaf1; border:1px solid #9fe0be; }
.status-panel.rejected { background:#fff1f1; border:1px solid #f2b4b4; }
.status-icon { font-size:2.5rem; margin-bottom:0.75rem; display:block; }
.status-panel.pending  .status-icon { color:#d4a017; }
.status-panel.approved .status-icon { color:var(--accent); }
.status-panel.released .status-icon { color:#16a34a; }
.status-panel.rejected .status-icon { color:#dc2626; }
.status-description { font-size:0.85rem; color:var(--muted); margin:0.5rem 0 0; }

.btn-primary-minimal { background:var(--text); color:#fff; border:none; border-radius:7px; font-weight:600; font-size:0.875rem; padding:0.5rem 1.1rem; transition:background 0.2s,transform 0.15s; }
.btn-primary-minimal:hover { background:#333; color:#fff; transform:translateY(-1px); }
.btn-outline-minimal { background:transparent; color:var(--text); border:1px solid var(--border); border-radius:7px; font-weight:500; font-size:0.875rem; padding:0.5rem 1.1rem; transition:border-color 0.2s,background 0.2s; }
.btn-outline-minimal:hover { border-color:var(--text); background:var(--bg); color:var(--text); }
.btn-accent { background:var(--accent); color:#fff; border:none; border-radius:7px; font-weight:600; font-size:0.8rem; padding:0.45rem 1rem; transition:background 0.2s; }
.btn-accent:hover { background:#1d4ed8; color:#fff; }

.page-header { margin-bottom:1.5rem; padding-bottom:1.25rem; border-bottom:1px solid var(--border); }
.page-header h2 { font-size:1.4rem; font-weight:700; color:var(--text); margin:0 0 0.2rem 0; }
.page-header .request-id { font-size:0.82rem; color:var(--muted); }

.badge-required { background:#fff1f1; color:#a01b1b; border:1px solid #f2b4b4; padding:0.25rem 0.55rem; border-radius:5px; font-size:0.7rem; font-weight:600; letter-spacing:0.03em; }
.badge-optional { background:var(--bg); color:var(--muted); border:1px solid var(--border); padding:0.25rem 0.55rem; border-radius:5px; font-size:0.7rem; font-weight:600; letter-spacing:0.03em; }
.badge-paid   { background:#edfaf1; color:#16653a; border:1px solid #9fe0be; padding:0.25rem 0.6rem; border-radius:5px; font-size:0.8rem; font-weight:600; }
.badge-unpaid { background:#fef9ec; color:#92610a; border:1px solid #f0d98a; padding:0.25rem 0.6rem; border-radius:5px; font-size:0.8rem; font-weight:600; }
.badge-free   { background:var(--bg); color:var(--muted); border:1px solid var(--border); padding:0.25rem 0.6rem; border-radius:5px; font-size:0.8rem; font-weight:600; }
.badge-na     { background:var(--bg); color:var(--muted); border:1px solid var(--border); padding:0.25rem 0.6rem; border-radius:5px; font-size:0.8rem; }
.fee-value    { font-weight:700; color:var(--text); }

.alert-success { background:#edfaf1; border:1px solid #9fe0be; color:#16653a; border-radius:8px; }
.alert-danger  { background:#fff1f1; border:1px solid #f2b4b4; color:#a01b1b; border-radius:8px; }
.alert-info    { background:var(--accent-light); border:1px solid #bbd8f7; color:#1e40af; border-radius:8px; font-size:0.9rem; }

.count-badge { display:inline-flex; align-items:center; justify-content:center; background:var(--bg); color:var(--muted); border:1px solid var(--border); border-radius:5px; font-size:0.75rem; font-weight:600; min-width:22px; height:22px; padding:0 0.4rem; margin-left:0.4rem; }

.reply-thread-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; margin-bottom:1.25rem; }
.reply-thread-header { padding:0.875rem 1.25rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.reply-thread-header h6 { margin:0; font-weight:600; font-size:0.9rem; color:var(--text); }
.reply-count-badge { background:var(--accent-light); color:var(--accent); border:1px solid #bbd8f7; border-radius:5px; font-size:0.72rem; font-weight:700; padding:0.2rem 0.55rem; }

.reply-body { padding:1rem 1.25rem; display:flex; flex-direction:column; gap:0.75rem; }

.admin-remark-bubble {
    background: var(--accent-light); border:1px solid #bbd8f7;
    border-left:3px solid var(--accent); border-radius:0 10px 10px 0;
    padding:0.75rem 1rem; font-size:0.85rem; color:#1e40af;
    align-self: flex-start; max-width: 88%;
}
.admin-remark-bubble .bubble-label { font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; opacity:0.65; margin-bottom:0.3rem; display:block; }

.reply-bubble { padding:0.65rem 1rem; border-radius:10px; font-size:0.85rem; line-height:1.55; max-width:88%; }
.reply-bubble.admin    { background:var(--accent-light); border:1px solid #bbd8f7; color:#1e40af; align-self:flex-start; border-bottom-left-radius:3px; }
.reply-bubble.resident { background:#f0fdf4; border:1px solid #9fe0be; color:#166534; align-self:flex-end; border-bottom-right-radius:3px; text-align:right; }
.reply-meta { font-size:0.68rem; opacity:0.6; margin-top:0.25rem; display:block; }

.reply-form-area { padding:1rem 1.25rem; border-top:1px solid var(--border); background:var(--bg); }
.reply-form-label { font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--muted); margin-bottom:0.4rem; display:block; }
.reply-textarea { width:100%; border:1px solid var(--border); border-radius:8px; padding:0.6rem 0.875rem; font-size:0.875rem; resize:none; background:var(--surface); color:var(--text); outline:none; transition:border-color 0.18s; }
.reply-textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,0.08); }
.btn-send-reply { background:var(--accent); color:#fff; border:none; border-radius:7px; font-size:0.82rem; font-weight:600; padding:0.45rem 1rem; cursor:pointer; transition:background 0.18s; }
.btn-send-reply:hover { background:#1d4ed8; }

.reply-form-area.disabled { opacity: 0.6; pointer-events: none; }
.reply-textarea:disabled { background: #f5f5f5; cursor: not-allowed; }
.btn-send-reply:disabled { background: #9ca3af; cursor: not-allowed; }

.chat-disabled-notice { background: #fff1f1; border: 1px solid #f2b4b4; color: #a01b1b; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
.chat-disabled-notice i { font-size: 1rem; }

@media (max-width:991px) { .modern-card-body { padding:1rem; } }
</style>

<div class="container-fluid py-4">

    <div class="row mb-2">
        <div class="col-12">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2><i class="fas fa-file-alt me-2 text-muted" style="font-size:1.2rem;"></i>Request Details</h2>
                    <span class="request-id">Request #<?php echo str_pad($request['request_id'], 5, '0', STR_PAD_LEFT); ?></span>
                </div>
                <a href="<?php echo $user_role === 'Resident' ? 'my-requests.php' : 'admin-manage-requests.php'; ?>"
                   class="btn-outline-minimal btn">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8 mb-4">

            <?php if ($user_role !== 'Resident'): ?>
            <div class="admin-actions">
                <div class="admin-actions-title">
                    <i class="fas fa-sliders-h" style="color:var(--muted);"></i> Admin Actions
                </div>
                <form method="POST" class="mb-0">
                    <input type="hidden" name="action" value="update_status">
                    <label class="form-label">Update Status</label>
                    <select name="status" class="form-select mb-3" required>
                        <option value="Pending"  <?php echo $request['status']==='Pending'  ?'selected':''; ?>>Pending</option>
                        <option value="Approved" <?php echo $request['status']==='Approved' ?'selected':''; ?>>Approved</option>
                        <option value="Released" <?php echo $request['status']==='Released' ?'selected':''; ?>>Released</option>
                        <option value="Rejected" <?php echo $request['status']==='Rejected' ?'selected':''; ?>>Rejected</option>
                    </select>
                    <label class="form-label">Remarks <span style="font-weight:400;text-transform:none;color:var(--muted);">(optional)</span></label>
                    <textarea name="remarks" class="form-control mb-3" rows="3" placeholder="Add remarks..."><?php echo htmlspecialchars($request['remarks'] ?? ''); ?></textarea>
                    <button type="submit" class="btn btn-primary-minimal w-100"><i class="fas fa-save me-1"></i>Save Status</button>
                </form>
                <?php if ($request['fee'] > 0): ?>
                <div class="section-divider">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_payment">
                        <label class="form-label">Payment Status</label>
                        <div class="mb-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="payment_status" value="0" id="payment_unpaid"
                                       <?php echo !$request['payment_status']?'checked':''; ?>>
                                <label class="form-check-label" for="payment_unpaid">Unpaid</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_status" value="1" id="payment_paid"
                                       <?php echo $request['payment_status']?'checked':''; ?>>
                                <label class="form-check-label" for="payment_paid">Paid — ₱<?php echo number_format($request['fee'],2); ?></label>
                            </div>
                        </div>
                        <!-- Auto-verify notice -->
                        <div class="auto-verify-notice">
                            <i class="fas fa-check-circle"></i>
                           <span>Marking as <strong>Paid</strong> creates a <strong>Pending</strong> revenue entry for finance staff to review and verify.</span>
                        </div>
                        <button type="submit" class="btn btn-primary-minimal w-100 mt-3"><i class="fas fa-check me-1"></i>Update Payment</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Request Information -->
            <div class="modern-card">
                <div class="modern-card-header">
                    <h5><i class="fas fa-info-circle me-2" style="color:var(--muted);"></i>Request Information</h5>
                </div>
                <div class="modern-card-body">
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <div class="info-label">Document Type</div>
                            <div class="info-value"><?php echo htmlspecialchars($request['request_type_name']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Request Date</div>
                            <div class="info-value"><?php echo date('F d, Y h:i A', strtotime($request['request_date'])); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Fee</div>
                            <div class="info-value">
                                <?php if ($request['fee'] > 0): ?>
                                    <span class="fee-value">₱<?php echo number_format($request['fee'],2); ?></span>
                                <?php else: ?><span class="badge-free">Free</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Payment Status</div>
                            <div class="info-value">
                                <?php if ($request['payment_status']): ?>
                                    <span class="badge-paid"><i class="fas fa-check me-1"></i>Paid</span>
                                <?php elseif ($request['fee'] > 0): ?>
                                    <span class="badge-unpaid">Unpaid</span>
                                <?php else: ?><span class="badge-na">N/A</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Purpose</div>
                        <div class="purpose-box mt-2"><?php echo nl2br(htmlspecialchars($request['purpose'])); ?></div>
                    </div>
                </div>
            </div>

            <!-- Remarks & Reply Thread -->
            <?php if ($has_thread || $user_role !== 'Resident'): ?>
            <div class="reply-thread-card">
                <div class="reply-thread-header">
                    <h6><i class="fas fa-comments me-2" style="color:var(--muted);"></i>Remarks &amp; Replies</h6>
                    <?php if (!empty($replies)): ?>
                        <span class="reply-count-badge"><?php echo count($replies); ?> repl<?php echo count($replies) === 1 ? 'y' : 'ies'; ?></span>
                    <?php endif; ?>
                </div>

                <div class="reply-body">
                    <?php if (!$has_thread): ?>
                        <p style="font-size:0.85rem;color:var(--muted);margin:0;">No remarks or replies yet.</p>
                    <?php else: ?>
                        <?php if (!empty($request['remarks'])): ?>
                        <div class="admin-remark-bubble">
                            <span class="bubble-label"><i class="fas fa-user-shield me-1"></i>Admin Remark</span>
                            <?php echo nl2br(htmlspecialchars($request['remarks'])); ?>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($replies as $reply): ?>
                        <div class="reply-bubble <?php echo $reply['sender_type']; ?>">
                            <?php echo nl2br(htmlspecialchars($reply['message'])); ?>
                            <span class="reply-meta">
                                <?php if ($reply['sender_type'] === 'admin'): ?>
                                    <i class="fas fa-user-shield me-1"></i>
                                    <?php echo htmlspecialchars($reply['admin_username'] ?? 'Admin'); ?>
                                <?php else: ?>
                                    <i class="fas fa-user me-1"></i>
                                    <?php echo htmlspecialchars(($reply['res_first_name'] ?? '') . ' ' . ($reply['res_last_name'] ?? '')); ?>
                                <?php endif; ?>
                                · <?php echo date('M j, Y g:i A', strtotime($reply['created_at'])); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($user_role !== 'Resident'): ?>
                <div class="reply-form-area <?php echo $is_rejected ? 'disabled' : ''; ?>">
                    <?php if ($is_rejected): ?>
                    <div class="chat-disabled-notice">
                        <i class="fas fa-ban"></i>
                        <span>Chat is disabled because this request has been rejected.</span>
                    </div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="admin_reply">
                        <label class="reply-form-label"><i class="fas fa-reply me-1"></i>Reply to Resident</label>
                        <textarea name="reply_message" class="reply-textarea" rows="3"
                                  placeholder="Type your reply here…" <?php echo $is_rejected ? 'disabled' : ''; ?> required></textarea>
                        <div class="mt-2">
                            <button type="submit" class="btn-send-reply" <?php echo $is_rejected ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane me-1"></i>Send Reply
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Submitted Requirements -->
            <?php if (!empty($attachments)): ?>
            <div class="modern-card">
                <div class="modern-card-header">
                    <h5>
                        <i class="fas fa-paperclip me-2" style="color:var(--muted);"></i>Submitted Requirements
                        <span class="count-badge"><?php echo count($attachments); ?></span>
                    </h5>
                </div>
                <div class="modern-card-body">
                    <div class="row g-3">
                        <?php foreach ($attachments as $attachment):
                            $file_url = '../../' . $attachment['file_path'];
                            $is_image = strpos($attachment['file_type'], 'image/') === 0;
                        ?>
                        <div class="col-md-6">
                            <div class="attachment-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="mb-0 flex-grow-1" style="font-size:0.875rem;"><?php echo htmlspecialchars($attachment['requirement_name'] ?? 'Attachment'); ?></h6>
                                    <?php if ($attachment['is_mandatory']): ?><span class="badge-required">Required</span>
                                    <?php else: ?><span class="badge-optional">Optional</span><?php endif; ?>
                                </div>
                                <?php if ($is_image): ?>
                                    <img src="<?php echo htmlspecialchars($file_url); ?>" class="attachment-preview mb-2"
                                         onclick="viewImage('<?php echo htmlspecialchars($file_url); ?>','<?php echo htmlspecialchars($attachment['file_name']); ?>')" alt="Preview">
                                <?php else: ?>
                                    <div class="pdf-preview mb-2">
                                        <i class="fas fa-file-pdf"></i>
                                        <small class="text-muted" style="font-size:0.78rem;">PDF Document</small>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-2" style="font-size:0.78rem;color:var(--muted);line-height:1.8;">
                                    <div><i class="fas fa-file me-1"></i><?php echo htmlspecialchars($attachment['file_name']); ?></div>
                                    <div><i class="fas fa-weight me-1"></i><?php echo number_format($attachment['file_size']/1024,2); ?> KB</div>
                                    <div><i class="fas fa-clock me-1"></i><?php echo date('M d, Y h:i A', strtotime($attachment['uploaded_at'])); ?></div>
                                </div>
                                <a href="<?php echo htmlspecialchars($file_url); ?>" class="btn btn-accent w-100 btn-sm mt-auto" target="_blank">
                                    <i class="fas fa-external-link-alt me-1"></i>View / Download
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="modern-card">
                <div class="modern-card-body" style="padding:1.25rem;">
                    <?php
                    $status_map = [
                        'Pending'  => ['icon'=>'fa-clock',        'text'=>'Your request is being reviewed'],
                        'Approved' => ['icon'=>'fa-check-circle', 'text'=>'Your request has been approved'],
                        'Released' => ['icon'=>'fa-check-double', 'text'=>'Your document is ready for pickup'],
                        'Rejected' => ['icon'=>'fa-times-circle', 'text'=>'Your request was rejected'],
                    ];
                    $sinfo      = $status_map[$request['status']] ?? ['icon'=>'fa-info-circle','text'=>''];
                    $status_key = strtolower($request['status']);
                    ?>
                    <div class="status-panel <?php echo $status_key; ?>">
                        <i class="fas <?php echo $sinfo['icon']; ?> status-icon"></i>
                        <span class="status-badge <?php echo $status_key; ?>"><?php echo htmlspecialchars($request['status']); ?></span>
                        <p class="status-description"><?php echo $sinfo['text']; ?></p>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h6><i class="fas fa-history me-2" style="color:var(--muted);"></i>Timeline</h6>
                </div>
                <div class="modern-card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-marker default"></div>
                            <div class="timeline-content">
                                <div class="timeline-title">Request Submitted</div>
                                <div class="timeline-meta"><?php echo date('F d, Y h:i A', strtotime($request['request_date'])); ?></div>
                            </div>
                        </div>
                        <?php if ($request['status'] !== 'Pending'): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker <?php echo $status_key; ?>"></div>
                            <div class="timeline-content">
                                <div class="timeline-title">Status: <?php echo htmlspecialchars($request['status']); ?></div>
                                <div class="timeline-meta">
                                    <?php echo $request['processed_date'] ? date('F d, Y h:i A', strtotime($request['processed_date'])) : 'N/A'; ?>
                                    <?php if ($request['processed_by_name']): ?><br>By: <?php echo htmlspecialchars($request['processed_by_name']); ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($request['payment_status'] && $request['fee'] > 0): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker payment"></div>
                            <div class="timeline-content">
                                <div class="timeline-title">Payment Confirmed</div>
                                <div class="timeline-meta">₱<?php echo number_format($request['fee'],2); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($replies)): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker" style="background:#7c3aed;"></div>
                            <div class="timeline-content">
                                <div class="timeline-title">Reply Thread</div>
                                <div class="timeline-meta"><?php echo count($replies); ?> message<?php echo count($replies)!==1?'s':''; ?> exchanged</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($user_role !== 'Resident'): ?>
            <div class="modern-card">
                <div class="modern-card-header">
                    <h6><i class="fas fa-user me-2" style="color:var(--muted);"></i>Resident Information</h6>
                </div>
                <div class="modern-card-body">
                    <div class="info-row"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($request['first_name'].' '.$request['last_name']); ?></div></div>
                    <div class="info-row"><div class="info-label">Contact Number</div><div class="info-value"><?php echo htmlspecialchars($request['contact_number'] ?? 'N/A'); ?></div></div>
                    <div class="info-row"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($request['email'] ?? 'N/A'); ?></div></div>
                    <div class="info-row"><div class="info-label">Address</div><div class="info-value"><?php echo htmlspecialchars($request['address'] ?? 'N/A'); ?></div></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Image Preview Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:10px;overflow:hidden;border:1px solid var(--border);">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:0.875rem 1.25rem;">
                <h5 class="modal-title" id="imageModalTitle" style="font-size:0.95rem;font-weight:600;">Image Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-3" style="background:var(--bg);">
                <img id="imageModalImg" src="" class="img-fluid" style="border-radius:8px;" alt="Preview">
            </div>
        </div>
    </div>
</div>

<script>
function viewImage(src, title) {
    document.getElementById('imageModalImg').src = src;
    document.getElementById('imageModalTitle').textContent = title;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}
setTimeout(function () {
    document.querySelectorAll('.alert-dismissible').forEach(function (a) { new bootstrap.Alert(a).close(); });
}, 5000);
</script>

<?php include '../../includes/footer.php';?>