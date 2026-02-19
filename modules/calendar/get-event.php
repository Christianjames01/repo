<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once '../../config/config.php';
    require_once '../../config/database.php';
    require_once '../../includes/security.php';
    require_once '../../config/session.php';

    requireLogin();

    // Check if ID parameter exists
    if (!isset($_GET['id'])) {
        echo json_encode(['error' => 'No ID parameter provided']);
        exit;
    }

    // Convert to integer (this handles 0 correctly)
    $event_id = (int)$_GET['id'];

    // Check if it's a valid number (allows 0)
    if (!is_numeric($_GET['id']) && $_GET['id'] !== '0') {
        echo json_encode(['error' => 'Invalid event ID format']);
        exit;
    }

    if (!isset($conn) || !$conn) {
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT e.id as event_id, e.title, e.description, e.event_date, e.start_time, 
               e.end_time, e.location, e.event_type, e.color, e.created_by, e.is_active,
               u.username as created_by_name
        FROM tbl_calendar_events e
        LEFT JOIN tbl_users u ON e.created_by = u.user_id
        WHERE e.id = ? AND e.is_active = 1
    ");
    
    if (!$stmt) {
        echo json_encode(['error' => 'Query preparation failed: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'Event not found (ID: ' . $event_id . ')']);
    }

    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>