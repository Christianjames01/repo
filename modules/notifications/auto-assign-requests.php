<?php
/**
 * Auto-assign NEW REQUESTS to random available technician
 * ONLY processes unassigned notifications (assigned_to = 0)
 */
ob_start();
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
requireLogin();
ob_end_clean();
header('Content-Type: application/json');

if (!in_array(getCurrentUserRole(), ['Super Admin','Super Administrator','Admin','Staff','Finance'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$nids = array_map('intval', $_POST['nids'] ?? []);
if (empty($nids)) {
    echo json_encode(['success' => false, 'error' => 'No notifications']); exit;
}

// 🔥 STEP 1: Verify these are actually unassigned REQUESTS
$unassigned_nids = [];
foreach ($nids as $nid) {
    $notif = fetchOne($conn, 
        "SELECT notification_id, type, title, assigned_to 
         FROM tbl_notifications 
         WHERE notification_id = ? AND assigned_to = 0", [$nid], 'i');
    
    if ($notif && (
        stripos($notif['title'], 'request') !== false ||
        stripos($notif['title'], 'document') !== false ||
        stripos($notif['title'], 'certificate') !== false ||
        stripos($notif['title'], 'clearance') !== false ||
        stripos($notif['type'], 'request') !== false ||
        stripos($notif['type'], 'document') !== false
    )) {
        $unassigned_nids[] = $nid;
    }
}

if (empty($unassigned_nids)) {
    echo json_encode(['success' => true, 'assigned' => 0, 'message' => 'No new requests to assign']); exit;
}

// 🔥 STEP 2: Get available technicians (online/active staff, exclude current user)
$current_uid = $_SESSION['user_id'];
$staff = fetchAll($conn, "
    SELECT u.user_id, u.username, u.role,
           COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username) AS full_name,
           CASE WHEN u.last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END as is_online
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.role IN ('Admin','Staff','Finance')
      AND u.status = 'Active' AND u.is_active = 1
      AND u.user_id != ?
    ORDER BY is_online DESC, RAND()
    LIMIT 1", [$current_uid], 'i');

// Pick first available (prioritizes online)
$target = $staff[0] ?? null;
if (!$target) {
    echo json_encode(['success' => false, 'error' => 'No available staff']); exit;
}

// 🔥 STEP 3: Assign ONLY the verified unassigned requests
$stmt = $conn->prepare("UPDATE tbl_notifications SET assigned_to = ?, assigned_at = NOW() WHERE notification_id = ? AND assigned_to = 0");
$stmt->bind_param('ii', $target['user_id'], $nid);
$success_count = 0;

foreach ($unassigned_nids as $nid) {
    $stmt->execute();
    if ($stmt->affected_rows > 0) $success_count++;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'uid' => $target['user_id'],
    'name' => $target['full_name'] ?: $target['role'] ?: $target['username'],
    'assigned' => $success_count,
    'total' => count($unassigned_nids),
    'message' => "Auto-assigned $success_count new requests to {$target['full_name']}"
]);
?>