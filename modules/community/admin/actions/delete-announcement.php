<?php
require_once '../../../../config/config.php';
require_once '../../../../includes/auth_functions.php';

header('Content-Type: application/json');

try {
    requireLogin();
    requireRole(['Admin', 'Staff', 'Super Admin']);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = isset($data['id']) ? intval($data['id']) : 0;
    
    if ($id <= 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid ID'
        ]);
        exit();
    }
    
    // Delete announcement - adjust column name if needed
    $stmt = $conn->prepare("DELETE FROM tbl_announcements WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Announcement deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Announcement not found'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to delete announcement'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>