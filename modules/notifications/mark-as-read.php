<?php
/**
 * Mark Notification as Read - AJAX Handler
 * Path: /modules/notifications/mark-as-read.php
 *
 * Handles AJAX POST requests from index.php row clicks.
 * Marks a single notification as read and returns the new unread count.
 */

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Always respond with JSON
header('Content-Type: application/json');

// ── Auth check ────────────────────────────────────────────────────────────────
requireLogin();   // redirects if not logged in — safe to call before JSON header above
                  // because requireLogin() only redirects on non-AJAX; if it does
                  // redirect you'll get a 302 which the fetch().catch() will swallow gracefully.

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// ── Validate input ────────────────────────────────────────────────────────────
$notification_id = intval($_POST['notification_id'] ?? 0);
$action          = trim($_POST['action'] ?? 'mark_read');

if ($notification_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit;
}

// Only handle the mark_read action (matches what index.php sends)
if ($action !== 'mark_read') {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ── Verify ownership (security: never trust client-supplied IDs alone) ────────
$check = $conn->prepare(
    "SELECT notification_id FROM tbl_notifications
     WHERE notification_id = ? AND user_id = ?"
);
$check->bind_param('ii', $notification_id, $user_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $check->close();
    echo json_encode(['success' => false, 'message' => 'Notification not found or access denied']);
    exit;
}
$check->close();

// ── Mark as read ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "UPDATE tbl_notifications
     SET is_read = 1
     WHERE notification_id = ? AND user_id = ? AND is_read = 0"
);
$stmt->bind_param('ii', $notification_id, $user_id);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// ── Return updated unread count so the navbar badge can refresh ───────────────
// Build the same notification_filter used in index.php so the count is consistent.
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    $notification_filter = "(
        type LIKE '%incident%' OR
        type LIKE '%request%'  OR
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type LIKE '%blotter%' OR
        type IN ('general','announcement','alert','status_update','email_reply') OR
        reference_type IN ('incident','request','document','complaint',
                           'appointment','medical_assistance','blotter',
                           'announcement','notification')
    )";
} else {
    $notification_filter = "(
        type LIKE '%incident%' OR
        type LIKE '%blotter%'  OR
        type LIKE '%request%'  OR
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type IN ('general','announcement','alert','status_update','email_reply') OR
        reference_type IN ('incident','blotter','request','document','complaint',
                           'appointment','medical_assistance','announcement','notification')
    )";
}

$count_stmt = $conn->prepare(
    "SELECT COUNT(*) as unread_count
     FROM tbl_notifications
     WHERE user_id = ? AND is_read = 0 AND $notification_filter"
);
$count_stmt->bind_param('i', $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$count_row    = $count_result->fetch_assoc();
$count_stmt->close();

$unread_count = intval($count_row['unread_count'] ?? 0);

echo json_encode([
    'success'      => true,
    'message'      => 'Notification marked as read',
    'unread_count' => $unread_count,   // use this to update your navbar badge via JS if needed
]);
exit;