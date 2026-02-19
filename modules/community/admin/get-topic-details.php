<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
require_once 'check-poll-expiry.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

header('Content-Type: application/json');

$poll_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($poll_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid poll ID']);
    exit();
}

// Get poll details
$query = "
    SELECT p.*, 
           CONCAT(r.first_name, ' ', r.last_name) as created_by_name,
           COUNT(DISTINCT v.vote_id) as total_votes
    FROM tbl_polls p
    LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
    LEFT JOIN tbl_poll_votes v ON p.poll_id = v.poll_id
    WHERE p.poll_id = ?
    GROUP BY p.poll_id
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $poll_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Poll not found']);
    exit();
}

$poll = $result->fetch_assoc();

// Get poll options with vote counts
$options_query = "
    SELECT po.*, COUNT(pv.vote_id) as vote_count
    FROM tbl_poll_options po
    LEFT JOIN tbl_poll_votes pv ON po.option_id = pv.option_id
    WHERE po.poll_id = ?
    GROUP BY po.option_id
    ORDER BY po.option_order
";

$options_stmt = $conn->prepare($options_query);
$options_stmt->bind_param("i", $poll_id);
$options_stmt->execute();
$options_result = $options_stmt->get_result();

$options = [];
while ($option = $options_result->fetch_assoc()) {
    $options[] = $option;
}

$poll['options'] = $options;

// Check if poll is closed and get winners
$is_closed = $poll['status'] === 'closed' || ($poll['end_date'] && strtotime($poll['end_date']) <= time());
if ($is_closed) {
    $poll['winners'] = getPollWinner($conn, $poll_id);
} else {
    $poll['winners'] = [];
}

echo json_encode([
    'success' => true,
    'poll' => $poll
]);
?>