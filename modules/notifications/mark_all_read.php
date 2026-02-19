<?php
/**
 * Mark All Notifications as Read - modules/notifications/mark_all_read.php
 * Marks all unread notifications as read for current user
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$user_id = $_SESSION['user_id'];

try {
    // Mark all unread notifications as read for current user
    $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    // Set success message
    $_SESSION['success_message'] = "Marked {$affected_rows} notification(s) as read successfully!";
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Failed to mark notifications as read: ' . $e->getMessage();
}

// Redirect back to referring page or notifications page
$redirect = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $redirect);
exit();
?>