<?php
/**
 * Poll Vote Submission Handler
 * This file handles vote submissions with proper error handling
 * and AUTO_INCREMENT validation
 */

require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get POST data
$poll_id = isset($_POST['poll_id']) ? intval($_POST['poll_id']) : 0;
$option_ids = isset($_POST['option_ids']) ? $_POST['option_ids'] : [];
$resident_id = isset($_SESSION['resident_id']) ? intval($_SESSION['resident_id']) : 0;

// Validation
if (!$poll_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid poll ID']);
    exit();
}

if (empty($option_ids)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one option']);
    exit();
}

if (!$resident_id) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit();
}

// Ensure option_ids is an array
if (!is_array($option_ids)) {
    $option_ids = [$option_ids];
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Check if tbl_poll_votes has proper AUTO_INCREMENT
    $check_table = $conn->query("SHOW CREATE TABLE tbl_poll_votes");
    if ($check_table) {
        $table_def = $check_table->fetch_row()[1];
        if (!stripos($table_def, 'AUTO_INCREMENT')) {
            // Try to fix the table structure
            $fix_result = $conn->query("ALTER TABLE tbl_poll_votes MODIFY vote_id INT NOT NULL AUTO_INCREMENT");
            if (!$fix_result) {
                throw new Exception("Database table structure issue. Please contact administrator.");
            }
        }
    }
    
    // Verify poll exists and is active
    $poll_query = $conn->prepare("SELECT poll_id, status, allow_multiple, end_date FROM tbl_polls WHERE poll_id = ?");
    $poll_query->bind_param("i", $poll_id);
    $poll_query->execute();
    $poll_result = $poll_query->get_result();
    
    if ($poll_result->num_rows === 0) {
        throw new Exception("Poll not found");
    }
    
    $poll = $poll_result->fetch_assoc();
    
    // Check if poll is active
    if ($poll['status'] !== 'active') {
        throw new Exception("This poll is not currently active");
    }
    
    // Check if poll has expired
    if ($poll['end_date'] && strtotime($poll['end_date']) <= time()) {
        // Auto-close the poll
        $conn->query("UPDATE tbl_polls SET status = 'closed' WHERE poll_id = $poll_id");
        throw new Exception("This poll has ended");
    }
    
    // Check if multiple selections are allowed
    if (!$poll['allow_multiple'] && count($option_ids) > 1) {
        throw new Exception("Only one option can be selected for this poll");
    }
    
    // Check if user has already voted
    $vote_check = $conn->prepare("SELECT COUNT(*) as count FROM tbl_poll_votes WHERE poll_id = ? AND resident_id = ?");
    $vote_check->bind_param("ii", $poll_id, $resident_id);
    $vote_check->execute();
    $vote_count = $vote_check->get_result()->fetch_assoc()['count'];
    
    if ($vote_count > 0) {
        throw new Exception("You have already voted in this poll");
    }
    
    // Verify all option IDs belong to this poll
    $option_placeholders = str_repeat('?,', count($option_ids) - 1) . '?';
    $option_verify_query = $conn->prepare("SELECT COUNT(*) as count FROM tbl_poll_options WHERE poll_id = ? AND option_id IN ($option_placeholders)");
    
    $bind_params = array_merge([$poll_id], $option_ids);
    $types = str_repeat('i', count($bind_params));
    $option_verify_query->bind_param($types, ...$bind_params);
    $option_verify_query->execute();
    $valid_options = $option_verify_query->get_result()->fetch_assoc()['count'];
    
    if ($valid_options !== count($option_ids)) {
        throw new Exception("Invalid option selection");
    }
    
    // Insert votes
    $vote_stmt = $conn->prepare("INSERT INTO tbl_poll_votes (poll_id, option_id, resident_id) VALUES (?, ?, ?)");
    
    foreach ($option_ids as $option_id) {
        $option_id = intval($option_id);
        $vote_stmt->bind_param("iii", $poll_id, $option_id, $resident_id);
        
        if (!$vote_stmt->execute()) {
            throw new Exception("Error recording vote: " . $vote_stmt->error);
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    // Get updated vote counts
    $results_query = $conn->prepare("
        SELECT o.option_id, o.option_text, COUNT(v.vote_id) as vote_count
        FROM tbl_poll_options o
        LEFT JOIN tbl_poll_votes v ON o.option_id = v.option_id
        WHERE o.poll_id = ?
        GROUP BY o.option_id, o.option_text
        ORDER BY o.option_order
    ");
    $results_query->bind_param("i", $poll_id);
    $results_query->execute();
    $results = $results_query->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Vote recorded successfully!',
        'results' => $results
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit();
?>