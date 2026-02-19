<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id   = getCurrentUserId();
$user_role = getCurrentUserRole();

if ($user_role !== 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

$resident_sql  = "SELECT r.resident_id, r.is_verified 
                  FROM tbl_residents r 
                  INNER JOIN tbl_users u ON r.resident_id = u.resident_id 
                  WHERE u.user_id = ?";
$resident_stmt = $conn->prepare($resident_sql);
$resident_stmt->bind_param("i", $user_id);
$resident_stmt->execute();
$resident_data = $resident_stmt->get_result()->fetch_assoc();
$resident_stmt->close();

// Fallback: try matching by email if resident_id join fails
if (!$resident_data) {
    $resident_sql2 = "SELECT r.resident_id, r.is_verified 
                      FROM tbl_residents r 
                      INNER JOIN tbl_users u ON r.email = u.email 
                      WHERE u.user_id = ?";
    $resident_stmt2 = $conn->prepare($resident_sql2);
    $resident_stmt2->bind_param("i", $user_id);
    $resident_stmt2->execute();
    $resident_data = $resident_stmt2->get_result()->fetch_assoc();
    $resident_stmt2->close();
}

if (!$resident_data) {
    $_SESSION['error_message'] = 'Resident profile not found.';
    header('Location: ../dashboard/index.php');
    exit();
}

if ($resident_data['is_verified'] != 1) {
    header('Location: not-verified.php');
    exit();
}

$resident_id = (int) $resident_data['resident_id'];
$page_title  = 'My Document Requests';

// ── Auto-create replies table if missing ──────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS tbl_request_replies (
    reply_id     INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT NOT NULL,
    sender_type  ENUM('admin','resident') NOT NULL,
    sender_id    INT NOT NULL,
    message      TEXT NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request_id (request_id)
)");

// ── Handle resident reply ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resident_reply') {
    $reply_request_id = intval($_POST['request_id'] ?? 0);
    $reply_message    = trim($_POST['reply_message'] ?? '');
    $redirect_status  = isset($_POST['current_status']) ? '?status=' . urlencode($_POST['current_status']) : '';

    if ($reply_request_id > 0 && $reply_message !== '') {
        // Check if request is rejected
        $check_stmt = $conn->prepare("SELECT status FROM tbl_requests WHERE request_id = ? AND resident_id = ?");
        $check_stmt->bind_param("ii", $reply_request_id, $resident_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($check_result && $check_result['status'] === 'Rejected') {
            $_SESSION['error_message'] = 'Cannot send reply to a rejected request';
            header('Location: my-requests.php' . $redirect_status);
            exit();
        }
        
        $verify_stmt = $conn->prepare("SELECT request_id FROM tbl_requests WHERE request_id = ? AND resident_id = ?");
        $verify_stmt->bind_param("ii", $reply_request_id, $resident_id);
        $verify_stmt->execute();
        $owns = $verify_stmt->get_result()->num_rows > 0;
        $verify_stmt->close();

        if ($owns) {
            $ins_stmt = $conn->prepare("INSERT INTO tbl_request_replies (request_id, sender_type, sender_id, message) VALUES (?, 'resident', ?, ?)");
            $ins_stmt->bind_param("iis", $reply_request_id, $user_id, $reply_message);
            if ($ins_stmt->execute()) {
                $_SESSION['success_message'] = 'Your reply has been sent.';

                // ── Notify admins about the resident reply ──────────────
                $info_stmt = $conn->prepare(
                    "SELECT req.processed_by, req.request_id,
                            rt.request_type_name,
                            res.first_name, res.last_name
                     FROM tbl_requests req
                     LEFT JOIN tbl_request_types rt ON req.request_type_id = rt.request_type_id
                     LEFT JOIN tbl_residents res    ON req.resident_id = res.resident_id
                     WHERE req.request_id = ?"
                );
                $info_stmt->bind_param("i", $reply_request_id);
                $info_stmt->execute();
                $req_info = $info_stmt->get_result()->fetch_assoc();
                $info_stmt->close();

                if ($req_info) {
                    $res_name    = trim(($req_info['first_name'] ?? '') . ' ' . ($req_info['last_name'] ?? ''));
                    $doc_type    = $req_info['request_type_name'] ?? 'Document Request';
                    $notif_title = "Resident Replied to Remarks";
                    $notif_msg   = "{$res_name} replied to your note on their {$doc_type} request.";
                    $notif_type  = "request_status_update";
                    $ref_type    = "request";

                    $admin_ids = [];
                    if (!empty($req_info['processed_by'])) {
                        $admin_ids[] = (int)$req_info['processed_by'];
                    }
                    $sa_result = $conn->query("SELECT user_id FROM tbl_users WHERE role IN ('Super Admin','Super Administrator','Admin')");
                    if ($sa_result) {
                        while ($sa_row = $sa_result->fetch_assoc()) {
                            $admin_ids[] = (int)$sa_row['user_id'];
                        }
                    }
                    $admin_ids = array_unique($admin_ids);

                    $notif_stmt = $conn->prepare(
                        "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
                    );
                    foreach ($admin_ids as $aid) {
                        $notif_stmt->bind_param("ississ", $aid, $notif_type, $ref_type, $reply_request_id, $notif_title, $notif_msg);
                        $notif_stmt->execute();
                    }
                    $notif_stmt->close();
                }
                // ── End notification ────────────────────────────────────

            } else {
                $_SESSION['error_message'] = 'Failed to send reply. Please try again.';
            }
            $ins_stmt->close();
        } else {
            $_SESSION['error_message'] = 'Request not found.';
        }
    } else {
        $_SESSION['error_message'] = 'Reply cannot be empty.';
    }

    header('Location: my-requests.php' . $redirect_status);
    exit();
}

// ── Filters ───────────────────────────────────────────────────
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// ── Statistics ────────────────────────────────────────────────
$stats_stmt = $conn->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Pending'  THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN payment_status = 1  THEN 1 ELSE 0 END) as paid
    FROM tbl_requests WHERE resident_id = ?");
$stats_stmt->bind_param("i", $resident_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// ── Requests query ────────────────────────────────────────────
$requests_sql = "SELECT r.request_id, r.resident_id, r.request_type_id,
                 r.purpose, r.status, r.payment_status,
                 r.request_date, r.processed_date, r.remarks, r.processed_by,
                 rt.request_type_name, rt.fee,
                 u.username AS processed_by_name
                 FROM tbl_requests r
                 LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
                 LEFT JOIN tbl_users u ON r.processed_by = u.user_id
                 WHERE r.resident_id = ?";
$params = [$resident_id];
$types  = "i";

if ($status_filter && $status_filter !== 'Paid') {
    $requests_sql .= " AND r.status = ?"; $params[] = $status_filter; $types .= "s";
}
if ($status_filter === 'Paid') {
    $requests_sql .= " AND r.payment_status = 1";
}
$requests_sql .= " ORDER BY r.request_date DESC";

$requests_stmt = $conn->prepare($requests_sql);
$requests_stmt->bind_param($types, ...$params);
$requests_stmt->execute();
$requests = $requests_stmt->get_result();

// ── Fetch all replies for this resident's requests ────────────
$replies_by_request = [];
$all_replies_stmt = $conn->prepare(
    "SELECT rr.reply_id, rr.request_id, rr.sender_type, rr.sender_id, rr.message, rr.created_at,
            u.username,
            res.first_name, res.last_name
     FROM tbl_request_replies rr
     LEFT JOIN tbl_users u ON rr.sender_id = u.user_id AND rr.sender_type = 'admin'
     LEFT JOIN tbl_users ru ON rr.sender_id = ru.user_id AND rr.sender_type = 'resident'
     LEFT JOIN tbl_residents res ON ru.resident_id = res.resident_id
     WHERE rr.request_id IN (SELECT request_id FROM tbl_requests WHERE resident_id = ?)
     ORDER BY rr.created_at ASC"
);
$all_replies_stmt->bind_param("i", $resident_id);
$all_replies_stmt->execute();
$all_replies_result = $all_replies_stmt->get_result();
while ($reply = $all_replies_result->fetch_assoc()) {
    $replies_by_request[$reply['request_id']][] = $reply;
}
$all_replies_stmt->close();

include '../../includes/header.php';
?>

<style>
:root {
    --bg: #f5f5f4; --surface: #ffffff; --border: #e5e5e5;
    --text: #1a1a1a; --muted: #6b6b6b; --accent: #2563eb;
    --accent-light: #eff6ff; --radius: 10px; --shadow: 0 1px 4px rgba(0,0,0,0.06);
}
body { background: var(--bg); color: var(--text); }

.page-header { padding-bottom: 1.25rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); }
.page-header h2 { font-size: 1.4rem; font-weight: 700; color: var(--text); margin: 0 0 0.2rem 0; }
.page-header p  { font-size: 0.85rem; color: var(--muted); margin: 0; }

/* Stat Cards */
.stat-card-link { text-decoration: none; display: block; height: 100%; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); transition: transform 0.18s, box-shadow 0.18s; cursor: pointer; height: 100%; }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
.stat-card.active-total    { border: 2px solid #9ca3af; background: #f9fafb; }
.stat-card.active-pending  { border: 2px solid #f0d98a; background: #fef9ec; }
.stat-card.active-approved { border: 2px solid #bbd8f7; background: #edf6ff; }
.stat-card.active-released { border: 2px solid #9fe0be; background: #edfaf1; }
.stat-card.active-rejected { border: 2px solid #f2b4b4; background: #fff1f1; }
.stat-card.active-paid     { border: 2px solid #9fe0be; background: #edfaf1; }
.stat-card .card-body { padding: 1.1rem 0.75rem; text-align: center; }
.stat-icon { font-size: 1.5rem; margin-bottom: 0.5rem; }
.stat-icon.total    { color: #9ca3af; } .stat-icon.pending  { color: #d4a017; }
.stat-icon.approved { color: var(--accent); } .stat-icon.released { color: #16a34a; }
.stat-icon.rejected { color: #dc2626; } .stat-icon.paid     { color: #16a34a; }
.stat-value { font-size: 1.6rem; font-weight: 700; color: var(--text); line-height: 1; margin-bottom: 0.2rem; }
.stat-label { font-size: 0.75rem; color: var(--muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

/* Request Cards */
.request-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); transition: border-color 0.18s, box-shadow 0.18s, transform 0.18s; display: flex; flex-direction: column; height: 100%; overflow: hidden; }
.request-card:hover { border-color: #c5c5c5; box-shadow: 0 4px 16px rgba(0,0,0,0.09); transform: translateY(-2px); }
.request-card .card-body-click { padding: 1.1rem 1.25rem; flex: 1; cursor: pointer; }
.request-card .card-footer { background: var(--bg); border-top: 1px solid var(--border); padding: 0.7rem 1.25rem; }
.request-title { font-size: 0.975rem; font-weight: 600; color: var(--text); margin: 0 0 0.2rem 0; line-height: 1.3; }
.request-date  { font-size: 0.78rem; color: var(--muted); }

.status-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
.status-badge.pending  { background: #fef9ec; color: #92610a; border: 1px solid #f0d98a; }
.status-badge.approved { background: #edf6ff; color: #1a5faa; border: 1px solid #bbd8f7; }
.status-badge.released { background: #edfaf1; color: #16653a; border: 1px solid #9fe0be; }
.status-badge.rejected { background: #fff1f1; color: #a01b1b; border: 1px solid #f2b4b4; }

.badge-paid   { background:#edfaf1;color:#16653a;border:1px solid #9fe0be;padding:0.2rem 0.55rem;border-radius:5px;font-size:0.75rem;font-weight:600; }
.badge-unpaid { background:#fef9ec;color:#92610a;border:1px solid #f0d98a;padding:0.2rem 0.55rem;border-radius:5px;font-size:0.75rem;font-weight:600; }
.badge-free   { background:var(--bg);color:var(--muted);border:1px solid var(--border);padding:0.2rem 0.55rem;border-radius:5px;font-size:0.75rem;font-weight:600; }
.badge-na     { background:var(--bg);color:var(--muted);border:1px solid var(--border);padding:0.2rem 0.55rem;border-radius:5px;font-size:0.75rem; }
.fee-value   { font-weight: 700; color: var(--text); font-size: 0.9rem; }
.field-label { font-size: 0.72rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; }
.purpose-text { font-size: 0.875rem; color: var(--text); line-height: 1.5; }

/* Remarks & Reply Thread */
.remarks-section { padding: 0 1.25rem 1rem; border-top: 1px solid var(--border); margin-top: 0; }

.admin-remark {
    background: var(--accent-light); border: 1px solid #bbd8f7;
    border-left: 3px solid var(--accent); border-radius: 0 8px 8px 0;
    padding: 0.75rem 1rem; font-size: 0.82rem; color: #1e40af; margin-top: 0.875rem;
}
.admin-remark-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.65; margin-bottom: 0.3rem; display: block; }

.reply-thread { margin-top: 0.6rem; display: flex; flex-direction: column; gap: 0.45rem; }

.reply-bubble { padding: 0.55rem 0.875rem; border-radius: 10px; font-size: 0.8rem; line-height: 1.5; max-width: 88%; }
.reply-bubble.admin    { background: var(--accent-light); border: 1px solid #bbd8f7; color: #1e40af; align-self: flex-start; border-bottom-left-radius: 3px; }
.reply-bubble.resident { background: #f0fdf4; border: 1px solid #9fe0be; color: #166534; align-self: flex-end; border-bottom-right-radius: 3px; text-align: right; }
.reply-meta { font-size: 0.67rem; opacity: 0.6; margin-top: 0.2rem; display: block; }

.reply-toggle-btn {
    background: none; border: none; padding: 0;
    color: var(--accent); font-size: 0.75rem; font-weight: 600;
    cursor: pointer; text-decoration: underline; text-underline-offset: 2px;
    margin-top: 0.6rem; display: inline-block;
}
.reply-toggle-btn:hover { color: #1d4ed8; }

.reply-input-area { display: none; margin-top: 0.6rem; }
.reply-input-area.open { display: block; }

.reply-textarea {
    width: 100%; border: 1px solid var(--border); border-radius: 7px;
    padding: 0.5rem 0.75rem; font-size: 0.82rem; resize: none;
    background: var(--surface); color: var(--text); outline: none;
    transition: border-color 0.18s;
}
.reply-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }

.btn-send-reply {
    background: var(--accent); color: #fff; border: none;
    border-radius: 6px; font-size: 0.78rem; font-weight: 600;
    padding: 0.4rem 0.875rem; cursor: pointer; transition: background 0.18s; margin-top: 0.4rem;
}
.btn-send-reply:hover { background: #1d4ed8; }

.btn-cancel-reply {
    background: none; color: var(--muted); border: 1px solid var(--border);
    border-radius: 6px; font-size: 0.78rem; font-weight: 500;
    padding: 0.4rem 0.875rem; cursor: pointer; margin-top: 0.4rem; margin-left: 0.3rem;
    transition: border-color 0.18s;
}
.btn-cancel-reply:hover { border-color: var(--text); color: var(--text); }

.chat-disabled-notice {
    background: #fff1f1; border: 1px solid #f2b4b4; color: #a01b1b;
    padding: 0.6rem 0.875rem; border-radius: 7px; font-size: 0.75rem;
    display: flex; align-items: center; gap: 0.4rem; margin-top: 0.6rem;
}
.chat-disabled-notice i { font-size: 0.875rem; }

/* Buttons */
.btn-primary-minimal { background: var(--text); color: #fff; border: none; border-radius: 7px; font-weight: 600; font-size: 0.875rem; padding: 0.5rem 1.1rem; transition: background 0.18s, transform 0.15s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; }
.btn-primary-minimal:hover { background: #333; color: #fff; transform: translateY(-1px); }
.btn-outline-minimal { background: transparent; color: var(--text); border: 1px solid var(--border); border-radius: 7px; font-weight: 500; font-size: 0.8rem; padding: 0.35rem 0.85rem; transition: border-color 0.18s, background 0.18s; cursor: pointer; }
.btn-outline-minimal:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }

.request-id-chip { font-size: 0.78rem; color: var(--muted); font-family: monospace; }

.empty-state { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 4rem 2rem; text-align: center; }
.empty-state i  { font-size: 3rem; color: #d1d5db; margin-bottom: 1rem; display: block; }
.empty-state h4 { font-size: 1.1rem; font-weight: 600; color: var(--muted); margin-bottom: 0.5rem; }
.empty-state p  { font-size: 0.875rem; color: #9ca3af; margin-bottom: 1.5rem; }

.alert-success { background:#edfaf1;border:1px solid #9fe0be;color:#16653a;border-radius:8px;font-size:0.9rem; }
.alert-danger  { background:#fff1f1;border:1px solid #f2b4b4;color:#a01b1b;border-radius:8px;font-size:0.9rem; }
.modal-content { border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 8px 32px rgba(0,0,0,0.12); }
.modal-header  { border-bottom:1px solid var(--border);padding:1rem 1.25rem; }
.modal-header .modal-title { font-size:0.95rem;font-weight:600;color:var(--text); }
.modal-footer  { border-top:1px solid var(--border); }

@media (max-width: 767px) {
    .stat-card .card-body { padding: 0.875rem 0.5rem; }
    .stat-value { font-size: 1.3rem; }
}
</style>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2><i class="fas fa-file-alt me-2" style="color:var(--muted); font-size:1.2rem;"></i>My Document Requests</h2>
            <p>View and track your document request history</p>
        </div>
        <a href="new-request.php" class="btn-primary-minimal"><i class="fas fa-plus"></i>New Request</a>
    </div>

    <!-- Flash Messages -->
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

    <!-- Stat Cards -->
    <div class="row mb-4 g-3">
        <?php
        $stat_cards = [
            ['label'=>'Total',    'key'=>'total',    'icon_class'=>'total',    'icon'=>'file-alt',        'filter'=>''],
            ['label'=>'Pending',  'key'=>'pending',  'icon_class'=>'pending',  'icon'=>'clock',           'filter'=>'Pending'],
            ['label'=>'Approved', 'key'=>'approved', 'icon_class'=>'approved', 'icon'=>'check-circle',    'filter'=>'Approved'],
            ['label'=>'Released', 'key'=>'released', 'icon_class'=>'released', 'icon'=>'check-double',    'filter'=>'Released'],
            ['label'=>'Rejected', 'key'=>'rejected', 'icon_class'=>'rejected', 'icon'=>'times-circle',    'filter'=>'Rejected'],
            ['label'=>'Paid',     'key'=>'paid',     'icon_class'=>'paid',     'icon'=>'money-bill-wave', 'filter'=>'Paid'],
        ];
        foreach ($stat_cards as $card):
            $is_active    = ($status_filter === $card['filter']) || ($card['filter'] === '' && $status_filter === '');
            $active_class = $is_active ? 'active-' . $card['icon_class'] : '';
            $href         = $card['filter'] === '' ? '?' : '?status=' . urlencode($card['filter']);
        ?>
        <div class="col-6 col-md-2">
            <a href="<?= $href ?>" class="stat-card-link">
                <div class="stat-card <?= $active_class ?>">
                    <div class="card-body">
                        <div class="stat-icon <?= $card['icon_class'] ?>"><i class="fas fa-<?= $card['icon'] ?>"></i></div>
                        <div class="stat-value"><?= (int)($stats[$card['key']] ?? 0) ?></div>
                        <div class="stat-label"><?= $card['label'] ?></div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Requests Grid -->
    <div class="row g-3">
        <?php if ($requests && $requests->num_rows > 0): ?>
            <?php while ($request = $requests->fetch_assoc()):
                $rid         = intval($request['request_id']);
                $status_key  = strtolower($request['status']);
                $is_rejected = ($request['status'] === 'Rejected');
                $status_icons = ['pending'=>'clock','approved'=>'check-circle','released'=>'check-double','rejected'=>'times-circle'];
                $s_icon      = $status_icons[$status_key] ?? 'info-circle';
                $req_replies = $replies_by_request[$rid] ?? [];
                $has_remarks = !empty($request['remarks']);
                $has_thread  = $has_remarks || !empty($req_replies);
            ?>
            <div class="col-lg-6">
                <div class="request-card">

                    <!-- Clickable top area -->
                    <div class="card-body-click" onclick="viewRequestDetails(<?= $rid ?>)">
                        <div class="d-flex justify-content-between align-items-start mb-3 gap-2">
                            <div>
                                <div class="request-title"><?= htmlspecialchars($request['request_type_name'] ?? 'N/A') ?></div>
                                <div class="request-date"><i class="fas fa-calendar-alt me-1"></i><?= date('F d, Y', strtotime($request['request_date'])) ?></div>
                            </div>
                            <span class="status-badge <?= $status_key ?>" style="flex-shrink:0;">
                                <i class="fas fa-<?= $s_icon ?>"></i><?= htmlspecialchars($request['status']) ?>
                            </span>
                        </div>

                        <div class="mb-3">
                            <div class="field-label">Purpose</div>
                            <div class="purpose-text">
                                <?php $p = $request['purpose'] ?? ''; $fl = strtok($p, "\n");
                                echo htmlspecialchars(strlen($fl) > 90 ? substr($fl,0,90).'…' : $fl); ?>
                            </div>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <div class="field-label">Fee</div>
                                <?php if ($request['fee'] > 0): ?><span class="fee-value">₱<?= number_format($request['fee'],2) ?></span>
                                <?php else: ?><span class="badge-free">Free</span><?php endif; ?>
                            </div>
                            <div class="col-6">
                                <div class="field-label">Payment</div>
                                <?php if ($request['payment_status']==1): ?><span class="badge-paid"><i class="fas fa-check me-1"></i>Paid</span>
                                <?php elseif ($request['fee']>0): ?><span class="badge-unpaid">Unpaid</span>
                                <?php else: ?><span class="badge-na">N/A</span><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Remarks & Reply Thread -->
                    <?php if ($has_thread): ?>
                    <div class="remarks-section">
                        <?php if ($has_remarks): ?>
                        <div class="admin-remark">
                            <span class="admin-remark-label"><i class="fas fa-comment-dots me-1"></i>Admin Note</span>
                            <?= htmlspecialchars(strlen($request['remarks']) > 120 ? substr($request['remarks'],0,120).'…' : $request['remarks']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($req_replies)): ?>
                        <div class="reply-thread">
                            <?php foreach ($req_replies as $reply): ?>
                            <div class="reply-bubble <?= $reply['sender_type'] ?>">
                                <?= htmlspecialchars($reply['message']) ?>
                                <span class="reply-meta">
                                    <?= $reply['sender_type'] === 'admin' ? '<i class="fas fa-user-shield me-1"></i>Admin' : '<i class="fas fa-user me-1"></i>You' ?>
                                    · <?= date('M j, g:i A', strtotime($reply['created_at'])) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($is_rejected): ?>
                        <div class="chat-disabled-notice">
                            <i class="fas fa-ban"></i>
                            <span>Chat is disabled because this request has been rejected.</span>
                        </div>
                        <?php else: ?>
                        <button class="reply-toggle-btn" onclick="toggleReplyForm(<?= $rid ?>)">
                            <i class="fas fa-reply me-1"></i><?= empty($req_replies) ? 'Reply to this note' : 'Add a reply' ?>
                        </button>
                        <div class="reply-input-area" id="reply-area-<?= $rid ?>">
                            <form method="POST" action="my-requests.php<?= $status_filter ? '?status='.urlencode($status_filter) : '' ?>">
                                <input type="hidden" name="action"         value="resident_reply">
                                <input type="hidden" name="request_id"     value="<?= $rid ?>">
                                <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                                <textarea name="reply_message" class="reply-textarea" rows="3"
                                          placeholder="Type your reply here…" required></textarea>
                                <div>
                                    <button type="submit" class="btn-send-reply">
                                        <i class="fas fa-paper-plane me-1"></i>Send Reply
                                    </button>
                                    <button type="button" class="btn-cancel-reply" onclick="toggleReplyForm(<?= $rid ?>)">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Card Footer -->
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <span class="request-id-chip">#<?= str_pad($rid,5,'0',STR_PAD_LEFT) ?></span>
                        <button class="btn-outline-minimal" onclick="viewRequestDetails(<?= $rid ?>)">
                            <i class="fas fa-eye me-1"></i>View Details
                        </button>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>

        <?php else: ?>
            <div class="col-12">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4><?= $status_filter ? 'No '.htmlspecialchars($status_filter).' Requests' : 'No Requests Found' ?></h4>
                    <p><?= $status_filter ? 'You have no requests with this status.' : "You haven't submitted any document requests yet." ?></p>
                    <?php if ($status_filter): ?>
                        <a href="?" class="btn-primary-minimal"><i class="fas fa-list"></i>View All Requests</a>
                    <?php else: ?>
                        <a href="new-request.php" class="btn-primary-minimal"><i class="fas fa-plus"></i>Submit Your First Request</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2" style="color:var(--muted);"></i>Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody" style="background: var(--bg);">
                <div class="text-center py-5">
                    <div class="spinner-border" style="color:var(--accent);width:1.75rem;height:1.75rem;border-width:2px;" role="status"></div>
                    <p style="color:var(--muted);font-size:0.875rem;margin-top:0.75rem;">Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-minimal" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleReplyForm(rid) {
    const area = document.getElementById('reply-area-' + rid);
    if (!area) return;
    area.classList.toggle('open');
    if (area.classList.contains('open')) area.querySelector('textarea').focus();
}

function viewRequestDetails(requestId) {
    if (!requestId || requestId <= 0 || isNaN(requestId)) return;
    const modal     = new bootstrap.Modal(document.getElementById('detailsModal'));
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = `<div class="text-center py-5"><div class="spinner-border" style="color:var(--accent);width:1.75rem;height:1.75rem;border-width:2px;" role="status"></div><p style="color:var(--muted);font-size:0.875rem;margin-top:0.75rem;">Loading...</p></div>`;
    modal.show();
    fetch('get_request_details.php?id=' + requestId)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(text => {
            try {
                const d = JSON.parse(text);
                modalBody.innerHTML = d.success ? d.html
                    : `<div style="background:#fff1f1;border:1px solid #f2b4b4;border-radius:8px;padding:1rem;color:#a01b1b;font-size:0.9rem;"><i class="fas fa-exclamation-triangle me-2"></i>${d.message||'Failed to load'}</div>`;
            } catch(e) {
                modalBody.innerHTML = `<div style="background:#fff1f1;border:1px solid #f2b4b4;border-radius:8px;padding:1rem;color:#a01b1b;font-size:0.9rem;"><i class="fas fa-exclamation-triangle me-2"></i>Invalid server response.</div>`;
            }
        })
        .catch(err => {
            modalBody.innerHTML = `<div style="background:#fff1f1;border:1px solid #f2b4b4;border-radius:8px;padding:1rem;color:#a01b1b;font-size:0.9rem;"><i class="fas fa-exclamation-triangle me-2"></i>Network error: ${err.message}</div>`;
        });
}

setTimeout(() => document.querySelectorAll('.alert-dismissible').forEach(el => new bootstrap.Alert(el).close()), 5000);
</script>

<?php $requests_stmt->close(); include '../../includes/footer.php'; ?>