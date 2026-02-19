<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

header('Content-Type: application/json');

// Check if poll_id is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid poll ID'
    ]);
    exit();
}

$poll_id = intval($_GET['id']);

try {
    // Get poll details
    $poll_query = "
        SELECT p.*,
               CONCAT(r.first_name, ' ', r.last_name) as created_by_name,
               COUNT(DISTINCT v.vote_id) as total_votes
        FROM tbl_polls p
        LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
        LEFT JOIN tbl_poll_votes v ON p.poll_id = v.poll_id
        WHERE p.poll_id = ?
        GROUP BY p.poll_id
    ";
    
    $stmt = $conn->prepare($poll_query);
    $stmt->bind_param("i", $poll_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Poll not found'
        ]);
        exit();
    }
    
    $poll = $result->fetch_assoc();
    
    // Get poll options with vote counts
    $options_query = "
        SELECT po.option_id, po.option_text, po.option_order,
               COUNT(pv.vote_id) as vote_count
        FROM tbl_poll_options po
        LEFT JOIN tbl_poll_votes pv ON po.option_id = pv.option_id
        WHERE po.poll_id = ?
        GROUP BY po.option_id, po.option_text, po.option_order
        ORDER BY po.option_order ASC
    ";
    
    $options_stmt = $conn->prepare($options_query);
    $options_stmt->bind_param("i", $poll_id);
    $options_stmt->execute();
    $options_result = $options_stmt->get_result();
    
    $options = [];
    while ($option = $options_result->fetch_assoc()) {
        $options[] = [
            'option_id' => $option['option_id'],
            'option_text' => $option['option_text'],
            'option_order' => $option['option_order'],
            'vote_count' => intval($option['vote_count'])
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'poll' => [
            'poll_id' => $poll['poll_id'],
            'question' => $poll['question'],
            'description' => $poll['description'],
            'status' => $poll['status'],
            'allow_multiple' => intval($poll['allow_multiple']),
            'show_results' => $poll['show_results'],
            'end_date' => $poll['end_date'],
            'created_at' => $poll['created_at'],
            'created_by_name' => $poll['created_by_name'],
            'total_votes' => intval($poll['total_votes']),
            'options' => $options
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>