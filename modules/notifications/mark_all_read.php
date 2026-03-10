<?php
/**
 * mark-as-read.php - modules/notifications/mark-as-read.php
 * Lightweight AJAX endpoint to mark a single notification as read.
 */
@ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

$user_id = (int)$_SESSION['user_id'];
$nid     = intval($_POST['notification_id'] ?? 0);

if (!$nid) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit();
}

$stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
$stmt->bind_param("ii", $nid, $user_id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
exit();