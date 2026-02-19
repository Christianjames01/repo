<?php
require_once '../../../../config/config.php';
require_once '../../../../includes/auth_functions.php';

header('Content-Type: application/json');

try {
    requireLogin();
    requireRole(['Admin', 'Staff', 'Super Admin']);
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit();
    }
    
    // Get announcement
    $stmt = $conn->prepare("SELECT * FROM tbl_announcements WHERE id = ?");
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $announcement = $result->fetch_assoc()) {
        // Make sure we have the required fields
        if (!isset($announcement['title']) || !isset($announcement['content'])) {
            echo json_encode(['success' => false, 'message' => 'Announcement data incomplete']);
            exit();
        }
        
        echo json_encode([
            'success' => true, 
            'announcement' => [
                'id' => $announcement['id'],
                'title' => $announcement['title'],
                'content' => $announcement['content'],
                'created_at' => $announcement['created_at'],
                'created_by' => isset($announcement['created_by']) ? $announcement['created_by'] : null,
                'is_active' => isset($announcement['is_active']) ? $announcement['is_active'] : 1
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Announcement not found (ID: ' . $id . ')'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>