<?php
ob_start();
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();
ob_end_clean();
header('Content-Type: application/json');

// ── GET: fetch current status ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['nid']) && ($_GET['action'] ?? '') === 'get') {
    $nid = intval($_GET['nid']);
    $uid = intval($_SESSION['user_id'] ?? 0);
    $row = fetchOne($conn, "SELECT status FROM tbl_notifications WHERE notification_id=? AND user_id=?", [$nid, $uid], 'ii');
    echo json_encode(['success' => true, 'status' => $row['status'] ?? 'Open']);
    exit;
}

// ── POST: update status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$nid    = intval($_POST['nid'] ?? 0);
$status = trim($_POST['status'] ?? '');
$uid    = intval($_SESSION['user_id'] ?? 0);

$allowed = ['Open', 'On Hold', 'Work In Progress', 'Resolved', 'Closed', 'Read'];
if (!$nid || !in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$s = $conn->prepare("UPDATE tbl_notifications SET status=? WHERE notification_id=? AND user_id=?");
$s->bind_param('sii', $status, $nid, $uid);
$s->execute();
$s->close();

// ── Notify resident of status change ─────────────────────────────────
try {
    // Get notification details + resident email
    $notif = fetchOne($conn,
        "SELECT n.*, u.email, CONCAT_WS(' ', r.first_name, r.last_name) AS full_name
         FROM tbl_notifications n
         LEFT JOIN tbl_users u ON u.user_id = n.user_id
         LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
         LEFT JOIN tbl_users r ON r.user_id = n.user_id
         WHERE n.notification_id = ?",
        [$nid], 'i');

    if ($notif && !empty($notif['email']) && $notif['user_id'] !== $uid) {
        require_once '../../includes/email_helper.php';
        $recipientName  = !empty($notif['full_name']) ? $notif['full_name'] : $notif['email'];
        $subject        = 'Status Update: ' . ($notif['title'] ?? 'Your Request');
        $html = getEmailTemplate([
            'title'       => $subject,
            'greeting'    => 'Hello ' . htmlspecialchars($recipientName) . ',',
            'message'     => 'Your request <strong>' . htmlspecialchars($notif['title'] ?? '') . '</strong> has been updated to: <strong>' . htmlspecialchars($status) . '</strong>.',
            'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Status Update',
        ]);
        sendEmail($notif['email'], $subject, $html, $recipientName);

        // Log to tbl_notification_replies so it shows in Auto Notifications tab
        executeQuery($conn,
            "INSERT INTO tbl_notification_replies
                 (notification_id, sender_user_id, recipient_email, subject, body, sent, error_msg, created_at)
             VALUES (?, ?, ?, ?, ?, 1, '', NOW())",
            [$nid, $uid, $notif['email'], $subject, 'Status changed to: ' . $status],
            'iisss');
    }
} catch (Throwable $e) {
    error_log('update-status email failed: ' . $e->getMessage());
}

echo json_encode(['success' => true]);