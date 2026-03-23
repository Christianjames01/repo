<?php
/**
 * modules/notifications/pickup-notification.php - ALL-IN-ONE API (6 endpoints)
 * FIXED: Removed last_activity column dependency
 */
ob_start();
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
requireLogin();
ob_end_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$uid = intval($_SESSION['user_id'] ?? 0);

if (!$uid) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']); 
    exit;
}

// ── 1. GET STAFF LIST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'staff') {
    $staff = fetchAll($conn,
        "SELECT u.user_id,
                COALESCE(CONCAT_WS(' ', r.first_name, r.last_name), u.username) AS full_name,
                u.role,
                0 AS is_online
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.role IN ('Super Admin','Super Administrator','Admin','Staff','Finance')
           AND u.status = 'Active' AND u.is_active = 1
         ORDER BY u.role, full_name",
        [], '');
    echo json_encode(['success' => true, 'staff' => $staff]); 
    exit;
}

// ── 2. GET NOTIFICATION STATUS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $nid = intval($_GET['nid'] ?? 0);
    if (!$nid) {
        echo json_encode(['success' => false, 'error' => 'Missing notification ID']); 
        exit;
    }
    $status = fetchOne($conn, "SELECT status FROM tbl_notification_status WHERE notification_id = ?", [$nid], 'i');
    echo json_encode(['success' => true, 'status' => $status['status'] ?? null]); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'status') {
    $nid    = intval($_POST['nid'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    if (!$nid || !in_array($status, ['Open', 'On Hold', 'Work In Progress', 'Read'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    // Ensure table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS tbl_notification_status (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            status          VARCHAR(50) NOT NULL DEFAULT 'Open',
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ← PUT IT HERE — cleans up any duplicate rows from before the fix
    $conn->query("
        DELETE t1 FROM tbl_notification_status t1
        INNER JOIN tbl_notification_status t2
        WHERE t1.notification_id = t2.notification_id AND t1.id < t2.id
    ");

    // Check if a row already exists for this notification
    $existing = $conn->query("SELECT id FROM tbl_notification_status WHERE notification_id = $nid LIMIT 1");
    $row = $existing ? $existing->fetch_assoc() : null;

    if ($row) {
        $s = $conn->prepare("UPDATE tbl_notification_status SET status = ?, updated_at = NOW() WHERE notification_id = ?");
        $s->bind_param('si', $status, $nid);
    } else {
        $s = $conn->prepare("INSERT INTO tbl_notification_status (notification_id, status, updated_at) VALUES (?, ?, NOW())");
        $s->bind_param('is', $nid, $status);
    }

    $success = $s->execute();
    $s->close();

    echo json_encode(['success' => $success]);
    exit;
}

// ── 4. SEND REPLY ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reply') {
    $to = trim($_POST['to'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $nid = intval($_POST['nid'] ?? 0);
    
    if (empty($to) || empty($subject) || !$nid) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']); 
        exit;
    }
    
    $s = $conn->prepare("INSERT INTO tbl_notification_replies (notification_id, recipient_email, subject, body, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $s->bind_param('isssi', $nid, $to, $subject, $body, $uid);
    $success = $s->execute();
    $s->close();
    
    echo json_encode(['success' => $success]); 
    exit;
}

// ── 5. GET REPLIES ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'replies') {
    $nid = intval($_GET['nid'] ?? 0);
    if (!$nid) {
        echo json_encode(['success' => false, 'error' => 'Missing notification ID']); 
        exit;
    }
    $replies = fetchAll($conn,
        "SELECT recipient_email, subject, body, created_at 
         FROM tbl_notification_replies 
         WHERE notification_id = ? 
         ORDER BY created_at DESC 
         LIMIT 10",
        [$nid], 'i');
    echo json_encode(['success' => true, 'replies' => $replies]); 
    exit;
}

// ── 6. PICKUP / ASSIGN (MAIN ENDPOINT) ────────────────────────────────────
$nids = array_map('intval', $_POST['nids'] ?? []);
if (empty($nids)) {
    echo json_encode(['success' => false, 'error' => 'No notifications selected']); 
    exit;
}

$me = fetchOne($conn,
    "SELECT u.role, u.username,
            CONCAT_WS(' ', r.first_name, r.last_name) AS resident_name
     FROM tbl_users u
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
     WHERE u.user_id = ?",
    [$uid], 'i');

$admin_roles  = ['Super Admin', 'Super Administrator', 'Admin', 'Staff', 'Finance'];
$is_staff     = in_array($me['role'] ?? '', $admin_roles);
$display_name = $is_staff
    ? ($me['role'] ?? $me['username'] ?? 'Staff')
    : ($me['resident_name'] ?? $me['username'] ?? 'Staff');

foreach ($nids as $nid) {
    $s = $conn->prepare("UPDATE tbl_notifications SET assigned_to = ? WHERE notification_id = ?");
    $s->bind_param('ii', $uid, $nid);
    $s->execute();
    $s->close();
}

echo json_encode(['success' => true, 'uid' => $uid, 'name' => $display_name]);
?>