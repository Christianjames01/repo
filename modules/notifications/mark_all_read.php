<?php
/**
 * mark_all_read.php — modules/notifications/mark_all_read.php
 * Marks ALL notifications as read for the logged-in user.
 *
 * Works two ways:
 *   1. Direct browser visit (GET)  → marks all read, then redirects back
 *   2. AJAX / POST request         → marks all read, returns JSON
 */
@ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/database.php';

/* ── Auth check ─────────────────────────────────────────────────────── */
if (empty($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
    } else {
        header('Location: ../auth/login.php');
    }
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* ── Mark all as read ────────────────────────────────────────────────── */
$stmt = $conn->prepare(
    "UPDATE tbl_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

/* ── Respond ─────────────────────────────────────────────────────────── */
$is_ajax = ($_SERVER['REQUEST_METHOD'] === 'POST')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'marked' => $affected]);
} else {
    /* Browser visit — set flash message then redirect back */
    $_SESSION['success_message'] = $affected > 0
        ? "All notifications marked as read ({$affected} updated)."
        : 'All notifications were already read.';

    /* Go back to wherever the user came from, defaulting to index.php */
    $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';

    /* Safety: only redirect within the same host */
    $host = $_SERVER['HTTP_HOST'];
    if (strpos($referer, $host) !== false) {
        header('Location: ' . $referer);
    } else {
        header('Location: index.php');
    }
}
exit();