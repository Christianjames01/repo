<?php
/**
 * modules/notifications/assign-notification.php
 * - Manual assign (uid passed)  → always overwrites, even if already assigned
 * - Auto-assign (auto_assign=1) → only touches unassigned rows (assigned_to = 0 / NULL)
 * - Self-pickup (no uid)        → always overwrites
 */
ob_start();
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
requireLogin();
ob_end_clean();
header('Content-Type: application/json');

$uid = intval($_SESSION['user_id'] ?? 0);
if (!$uid) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$nids           = array_map('intval', $_POST['nids'] ?? []);
$is_auto_assign = !empty($_POST['auto_assign']);

if (empty($nids)) {
    echo json_encode(['success' => false, 'error' => 'No notifications selected']);
    exit;
}

/* ── Resolve target user ─────────────────────────────────────────────── */

if (isset($_POST['uid']) && intval($_POST['uid']) > 0) {
    // Manual assign to a specific staff member chosen from the dropdown
    $target_uid = intval($_POST['uid']);
    $target = fetchOne($conn,
        "SELECT u.user_id, u.username, u.role,
                COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username) AS full_name
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.user_id = ? AND u.status = 'Active' AND u.is_active = 1",
        [$target_uid], 'i');

} elseif ($is_auto_assign) {
    // Background auto-assign on page load: pick a random active staff member
    $target = fetchOne($conn,
        "SELECT u.user_id, u.username, u.role,
                COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username) AS full_name
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.role IN ('Admin', 'Staff', 'Finance')
           AND u.status = 'Active'
           AND u.is_active = 1
           AND u.user_id != ?
         ORDER BY RAND()
         LIMIT 1",
        [$uid], 'i');

} else {
    // Self-pickup: current user takes ownership
    $target = fetchOne($conn,
        "SELECT u.user_id, u.username, u.role,
                COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username) AS full_name
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.user_id = ? AND u.status = 'Active' AND u.is_active = 1",
        [$uid], 'i');
}

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'No available staff found']);
    exit;
}

/* ── Run the UPDATE ──────────────────────────────────────────────────────
   Auto-assign  → only update rows that are still unassigned
   Everything else → always overwrite (so manual reassignments persist)
─────────────────────────────────────────────────────────────────────── */

$success_count = 0;

if ($is_auto_assign) {
    $stmt = $conn->prepare(
        "UPDATE tbl_notifications
         SET assigned_to = ?
         WHERE notification_id = ?
           AND (assigned_to IS NULL OR assigned_to = 0)"
    );
} else {
    // Manual assign / pickup — always save regardless of current value
    $stmt = $conn->prepare(
        "UPDATE tbl_notifications
         SET assigned_to = ?
         WHERE notification_id = ?"
    );
}

foreach ($nids as $nid) {
    $stmt->bind_param('ii', $target['user_id'], $nid);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        $success_count++;
    }
}
$stmt->close();

$display_name = trim($target['full_name']) ?: ($target['role'] ?: $target['username']);

echo json_encode([
    'success'  => true,
    'uid'      => $target['user_id'],
    'name'     => $display_name,
    'assigned' => $success_count,
    'total'    => count($nids),
    'message'  => "Assigned {$success_count} notification(s) to {$display_name}",
]);