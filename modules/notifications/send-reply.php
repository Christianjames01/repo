<?php
/**
 * modules/notifications/send-reply.php
 * Sends email reply, creates notification for RECIPIENT only,
 * and logs the reply as a conversation record on the original ticket.
 * Never creates a notification for the sender (admin).
 */

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/email_helper.php';

requireLogin();

ob_end_clean();
ob_start();
header('Content-Type: application/json; charset=UTF-8');

function jsonOut(array $data): void {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

// Gate
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'send_reply') {
    jsonOut(['success' => false, 'error' => 'Invalid request']);
}

// Inputs
$to      = trim($_POST['to']      ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = trim($_POST['body']    ?? '');
$nid     = intval($_POST['nid']   ?? 0);
$user_id = intval($_SESSION['user_id'] ?? 0);

// Validate
if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    jsonOut(['success' => false, 'error' => 'Invalid or missing recipient email.']);
}
if (empty($subject)) {
    jsonOut(['success' => false, 'error' => 'Subject is required.']);
}
if (empty(strip_tags($body))) {
    jsonOut(['success' => false, 'error' => 'Message body is empty.']);
}

// ── Sender (logged-in admin) ───────────────────────────────────────────
$from_name  = defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME  : 'Barangay System';
$from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@localhost';

try {
    $sender = fetchOne($conn,
        "SELECT u.username, CONCAT_WS(' ', r.first_name, r.last_name) AS full_name
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.user_id = ?",
        [$user_id], 'i');
    if (!empty($sender['full_name'])) $from_name = trim($sender['full_name']);
    elseif (!empty($sender['username'])) $from_name = $sender['username'];
} catch (Throwable $e) {}

// ── Recipient user lookup ─────────────────────────────────────────────
$recipient_user_id = null;
$recipient_name    = $to;

try {
    $recipient = fetchOne($conn,
        "SELECT u.user_id, u.username, CONCAT_WS(' ', r.first_name, r.last_name) AS full_name
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.email = ?",
        [$to], 's');
    if ($recipient) {
        $recipient_user_id = intval($recipient['user_id']);
        $recipient_name    = !empty($recipient['full_name'])
            ? trim($recipient['full_name'])
            : ($recipient['username'] ?? $to);
    }
} catch (Throwable $e) {}

// ── HTML email ────────────────────────────────────────────────────────
$html = getEmailTemplate([
    'title'       => $subject,
    'greeting'    => 'Hello ' . htmlspecialchars($recipient_name) . ',',
    'message'     => $body,
    'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Staff Reply',
]);

$sent  = false;
$error = '';

// ── Send email ────────────────────────────────────────────────────────
try {
    $result = sendEmail($to, $subject, $html, $recipient_name);
    $sent   = (bool)$result;
    if (!$sent) {
        $error = 'sendEmail() returned false — check Apache error log.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// ── On success: notify RECIPIENT only + log as conversation ───────────
if ($sent) {
    $plain   = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $body));
    $preview = mb_strimwidth($plain, 0, 120, '…');

    // 1. Create notification for the RECIPIENT (resident/user who sent the original)
    //    so they see "Email Reply" with NEW EMAIL badge in their panel
    if ($recipient_user_id && $recipient_user_id !== $user_id) {
        try {
            createNotification(
                $conn,
                $recipient_user_id,
                'Reply from Barangay: ' . $subject,
                "The Barangay has replied to your request: {$preview}",
                'email_reply',
                $nid ?: null,
                'email_inbox'
            );
        } catch (Throwable $e) {
            error_log('send-reply recipient notification failed: ' . $e->getMessage());
        }
    }

    // 2. Log the reply as a conversation record in tbl_notification_replies
    //    The auto-notification tab reads from this table to show "Reply Sent" entries
    //    WITHOUT creating a standalone notification card for the admin.
    try {
        executeQuery($conn,
            "INSERT INTO tbl_notification_replies
                 (notification_id, sender_user_id, recipient_email, subject, body, sent, error_msg, created_at)
             VALUES (?, ?, ?, ?, ?, 1, '', NOW())",
            [$nid, $user_id, $to, $subject, $plain],
            'iisss');
    } catch (Throwable $e) {
        // Table may not exist yet — harmless, reply already sent
        error_log('send-reply log insert failed: ' . $e->getMessage());
    }

} else {
    // Log failure
    try {
        executeQuery($conn,
            "INSERT INTO tbl_notification_replies
                 (notification_id, sender_user_id, recipient_email, subject, body, sent, error_msg, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, NOW())",
            [$nid, $user_id, $to, $subject, strip_tags($body), $error],
            'iissss');
    } catch (Throwable $e) {}
}

// ── Respond ───────────────────────────────────────────────────────────
if ($sent) {
    jsonOut(['success' => true]);
} else {
    $msg = 'Could not send email.';
    if (stripos($error, 'connect')  !== false) $msg = 'Cannot connect to Gmail SMTP. Enable openssl in php.ini.';
    elseif (stripos($error, 'auth') !== false) $msg = 'Gmail auth failed. Check App Password in config/email.php.';
    elseif (!empty($error))                    $msg = $error;
    jsonOut(['success' => false, 'error' => $msg]);
}