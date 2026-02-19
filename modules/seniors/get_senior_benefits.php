<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

header('Content-Type: application/json');

$senior_id = intval($_GET['senior_id'] ?? 0);

if ($senior_id <= 0) {
    echo json_encode(['error' => 'Invalid senior ID']);
    exit;
}

// Fetch recent benefits (last 10)
$sql = "SELECT sb.*, u.username as released_by_name
        FROM tbl_senior_benefits sb
        LEFT JOIN tbl_users u ON sb.released_by = u.user_id
        WHERE sb.senior_id = ?
        ORDER BY sb.benefit_date DESC, sb.created_at DESC
        LIMIT 10";

$benefits = fetchAll($conn, $sql, [$senior_id], 'i');

echo json_encode([
    'success' => true,
    'benefits' => $benefits
]);
?>