<?php
/**
 * Mark Notification as Read - AJAX Handler
 * Path: /modules/notifications/mark-as-read.php
 * 
 * This file handles AJAX requests to mark notifications as read
 * and returns the updated unread count
 */

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Get notification ID from POST
$notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

if ($notification_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit;
}

$current_user_id = getCurrentUserId();

// Verify notification belongs to current user before updating
$verify_stmt = $conn->prepare("SELECT user_id FROM tbl_notifications WHERE notification_id = ?");
$verify_stmt->bind_param("i", $notification_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Notification not found']);
    $verify_stmt->close();
    exit;
}

$notification_data = $verify_result->fetch_assoc();
if ($notification_data['user_id'] != $current_user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    $verify_stmt->close();
    exit;
}
$verify_stmt->close();

// Update notification as read
$stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
$stmt->bind_param("ii", $notification_id, $current_user_id);

if ($stmt->execute()) {
    // Get updated unread count using the function from your system
    $unread_count = getUnreadNotificationCount($conn, $current_user_id);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Notification marked as read',
        'unread_count' => $unread_count
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update notification']);
}

$stmt->close();
$conn->close();
?>