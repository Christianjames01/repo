<?php
/**
 * notification_count.php - modules/notifications/notification_count.php
 *
 * Lightweight JSON endpoint polled by the bell icon (navbar) every ~30 s.
 * Returns the unread notification count and, for admins, triggers an IMAP
 * check so new resident emails surface in real-time.
 *
 * Usage (GET):
 *   fetch('modules/notifications/notification_count.php')
 *     .then(r => r.json())
 *     .then(d => updateBellBadge(d.unread_count));
 *
 * Optional GET param:
 *   ?fetch_email=1  — forces an IMAP check even for non-detail pages
 */

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Must be logged-in; return 0 silently otherwise.
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['unread_count' => 0]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

$user_id   = intval($_SESSION['user_id']);
$user_role = getCurrentUserRole();
$is_admin  = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');

// ── Optionally pull new emails from inbox (admins only) ───────────────────────
$imap_result = null;
if ($is_admin && ($_GET['fetch_email'] ?? '0') === '1') {
    // Only fetch if check_replies.php exists next to this file
    $checkRepliesPath = __DIR__ . '/check_replies.php';
    if (file_exists($checkRepliesPath)) {
        // We can't just include check_replies.php because it exits with JSON.
        // Instead, duplicate the minimal IMAP fetch inline OR call it via HTTP.
        // Best practice: extract fetchNewEmails() into a shared library.
        // For now we call it as a sub-request (works on localhost).
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url   = $proto . '://' . $host
               . str_replace('notification_count.php', 'check_replies.php', $_SERVER['REQUEST_URI']);
        // Fire-and-forget: we don't wait for the result here
        // (avoids doubling the response time for the badge poll)
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '') . "\r\n",
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($url, false, $ctx);
    }
}

// ── Count unread notifications for this user ──────────────────────────────────
$notification_filter = $is_admin
    ? "(
        type LIKE '%incident%' OR
        type LIKE '%blotter%'  OR
        type LIKE '%request%'  OR
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type IN ('general','announcement','alert','status_update','email_reply') OR
        reference_type IN ('incident','blotter','request','document','complaint',
                           'appointment','medical_assistance','announcement',
                           'notification','email_inbox')
      )"
    : "(
        type LIKE '%incident%' OR
        type LIKE '%request%'  OR
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type LIKE '%blotter%'  OR
        type IN ('general','announcement','alert','status_update','email_reply') OR
        reference_type IN ('incident','request','document','complaint',
                           'appointment','medical_assistance','blotter',
                           'announcement','notification')
      )";

$row = fetchOne($conn,
    "SELECT
         COUNT(*) AS total,
         SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
     FROM tbl_notifications
     WHERE user_id = ? AND $notification_filter",
    [$user_id], 'i'
);

$unread = intval($row['unread'] ?? 0);
$total  = intval($row['total']  ?? 0);

// ── Also count unread inbound email replies (admins only) ─────────────────────
$unread_replies = 0;
if ($is_admin) {
    $tblCheck = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
    if ($tblCheck && $tblCheck->num_rows > 0) {
        $rr = fetchOne($conn,
            "SELECT COUNT(*) AS cnt FROM tbl_email_replies
             WHERE direction = 'inbound' AND is_read = 0",
            [], ''
        );
        $unread_replies = intval($rr['cnt'] ?? 0);
    }
}

// Return combined count
echo json_encode([
    'success'        => true,
    'unread_count'   => $unread,            // notification table unread
    'unread_replies' => $unread_replies,    // raw email reply table unread
    'total_unread'   => $unread,            // notifications already include email_reply rows
    'total'          => $total,
]);
exit();