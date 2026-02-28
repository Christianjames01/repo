<?php
/**
 * Chat Database API
 * Path: modules/typhoon_tracker/chat_db_api.php
 *
 * FIXED: require_once uses __DIR__ so the path resolves correctly
 * regardless of where PHP is called from.
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verify connection is alive
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database connection failed: ' . ($conn->connect_error ?? 'unknown error'),
    ]);
    exit();
}

// Auto-create tables if they don't exist yet
$conn->query("CREATE TABLE IF NOT EXISTS tbl_chat_sessions (
    session_id  VARCHAR(64)  NOT NULL PRIMARY KEY,
    user_id     INT          DEFAULT 0,
    title       VARCHAR(255) DEFAULT 'New Chat',
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS tbl_chat_messages (
    message_id  INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id  VARCHAR(64) NOT NULL,
    user_id     INT         DEFAULT 0,
    sender      VARCHAR(20) NOT NULL COMMENT 'user or assistant',
    message     TEXT        NOT NULL,
    created_at  DATETIME    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Route ─────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── Create a new chat session ─────────────────────────────────────────────
    case 'create_session':
        $user_id = intval($_POST['user_id'] ?? 0);
        $title   = trim($_POST['title']     ?? 'New Chat');
        $sid     = bin2hex(random_bytes(16));

        $stmt = $conn->prepare(
            "INSERT INTO tbl_chat_sessions (session_id, user_id, title, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->bind_param('sis', $sid, $user_id, $title);

        echo $stmt->execute()
            ? json_encode(['success' => true,  'session_id' => $sid])
            : json_encode(['success' => false, 'error' => $stmt->error]);
        $stmt->close();
        break;

    // ── List sessions for a user ──────────────────────────────────────────────
    case 'get_sessions':
        $user_id = intval($_GET['user_id'] ?? 0);

        $stmt = $conn->prepare(
            "SELECT session_id, title, created_at, updated_at
             FROM tbl_chat_sessions
             WHERE user_id = ?
             ORDER BY updated_at DESC
             LIMIT 50"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = [];
        while ($r = $stmt->get_result()->fetch_assoc()) $rows[] = $r;
        $stmt->close();

        echo json_encode(['success' => true, 'sessions' => $rows]);
        break;

    // ── Save a message ────────────────────────────────────────────────────────
    case 'save_message':
        $sid     = trim($_POST['session_id'] ?? '');
        $sender  = trim($_POST['sender']     ?? '');
        $message = trim($_POST['message']    ?? '');
        $uid     = intval($_POST['user_id']  ?? 0);

        if (!$sid || !$sender || !$message) {
            echo json_encode(['success' => false, 'error' => 'Missing session_id, sender, or message.']);
            break;
        }
        if (!in_array($sender, ['user', 'assistant'])) {
            echo json_encode(['success' => false, 'error' => 'sender must be "user" or "assistant".']);
            break;
        }

        $stmt = $conn->prepare(
            "INSERT INTO tbl_chat_messages (session_id, user_id, sender, message, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('siss', $sid, $uid, $sender, $message);

        if ($stmt->execute()) {
            $mid = $stmt->insert_id;
            $stmt->close();
            // Bump session timestamp
            $upd = $conn->prepare("UPDATE tbl_chat_sessions SET updated_at = NOW() WHERE session_id = ?");
            $upd->bind_param('s', $sid);
            $upd->execute();
            $upd->close();
            echo json_encode(['success' => true, 'message_id' => $mid]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            $stmt->close();
        }
        break;

    // ── Get messages for a session ────────────────────────────────────────────
    case 'get_messages':
        $sid = trim($_GET['session_id'] ?? '');

        if (!$sid) {
            echo json_encode(['success' => false, 'error' => 'Missing session_id.']);
            break;
        }

        $stmt = $conn->prepare(
            "SELECT message_id, sender, message, created_at
             FROM tbl_chat_messages
             WHERE session_id = ?
             ORDER BY created_at ASC"
        );
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();

        echo json_encode(['success' => true, 'messages' => $rows]);
        break;

    // ── Delete session + its messages ─────────────────────────────────────────
    case 'delete_session':
        $sid = trim($_POST['session_id'] ?? '');
        $uid = intval($_POST['user_id']  ?? 0);

        if (!$sid) {
            echo json_encode(['success' => false, 'error' => 'Missing session_id.']);
            break;
        }

        $d = $conn->prepare("DELETE FROM tbl_chat_messages WHERE session_id = ?");
        $d->bind_param('s', $sid); $d->execute(); $d->close();

        if ($uid > 0) {
            $d = $conn->prepare("DELETE FROM tbl_chat_sessions WHERE session_id = ? AND user_id = ?");
            $d->bind_param('si', $sid, $uid);
        } else {
            $d = $conn->prepare("DELETE FROM tbl_chat_sessions WHERE session_id = ?");
            $d->bind_param('s', $sid);
        }
        echo $d->execute()
            ? json_encode(['success' => true])
            : json_encode(['success' => false, 'error' => $d->error]);
        $d->close();
        break;

    // ── Rename a session ──────────────────────────────────────────────────────
    case 'rename_session':
        $sid   = trim($_POST['session_id'] ?? '');
        $title = trim($_POST['title']      ?? '');

        if (!$sid || !$title) {
            echo json_encode(['success' => false, 'error' => 'Missing session_id or title.']);
            break;
        }

        $stmt = $conn->prepare("UPDATE tbl_chat_sessions SET title = ? WHERE session_id = ?");
        $stmt->bind_param('ss', $title, $sid);
        echo $stmt->execute()
            ? json_encode(['success' => true])
            : json_encode(['success' => false, 'error' => $stmt->error]);
        $stmt->close();
        break;

    // ── Unknown action ────────────────────────────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
        break;
}