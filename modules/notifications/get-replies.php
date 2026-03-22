<?php
/**
 * modules/notifications/get-replies.php
 * Returns reply conversation history for a notification.
 * Called by the Auto Notifications tab in the My Summary panel.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');

$nid = intval($_GET['nid'] ?? 0);

if (!$nid) {
    echo json_encode(['replies' => []]);
    exit;
}

try {
    $replies = fetchAll($conn,
        "SELECT
             nr.reply_id,
             nr.recipient_email,
             nr.subject,
             SUBSTRING(nr.body, 1, 200) AS body,
             DATE_FORMAT(nr.created_at, '%M %d, %Y %h:%i %p') AS created_at,
             COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username, 'Admin') AS sender_name
         FROM tbl_notification_replies nr
         LEFT JOIN tbl_users u ON nr.sender_user_id = u.user_id
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE nr.notification_id = ? AND nr.sent = 1
         ORDER BY nr.created_at ASC",
        [$nid], 'i');

    echo json_encode(['replies' => $replies]);
} catch (Throwable $e) {
    // Table doesn't exist yet — return empty
    echo json_encode(['replies' => []]);
}