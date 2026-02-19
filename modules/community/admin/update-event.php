<?php
require_once '../../../../config/config.php';
require_once '../../../../includes/auth_functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check role
if (!hasRole(['Admin', 'Staff', 'Super Admin'])) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['event_id', 'title', 'description', 'event_date', 'start_time', 'end_time', 'location', 'event_type', 'status'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (!isset($input[$field]) || trim($input[$field]) === '') {
        $missing_fields[] = $field;
    }
}

if (!empty($missing_fields)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
    ]);
    exit;
}

// Sanitize inputs
$event_id = intval($input['event_id']);
$title = trim($input['title']);
$description = trim($input['description']);
$event_date = trim($input['event_date']);
$start_time = trim($input['start_time']);
$end_time = trim($input['end_time']);
$location = trim($input['location']);
$event_type = trim($input['event_type']);
$status = trim($input['status']);

// Validate event exists
$check_query = "SELECT event_id FROM tbl_events WHERE event_id = ?";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param('i', $event_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

// Validate date format
$date_obj = DateTime::createFromFormat('Y-m-d', $event_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $event_date) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Validate time format
$start_time_obj = DateTime::createFromFormat('H:i', $start_time);
$end_time_obj = DateTime::createFromFormat('H:i', $end_time);

if (!$start_time_obj || !$end_time_obj) {
    echo json_encode(['success' => false, 'message' => 'Invalid time format']);
    exit;
}

// Validate end time is after start time
if ($end_time_obj <= $start_time_obj) {
    echo json_encode(['success' => false, 'message' => 'End time must be after start time']);
    exit;
}

// Validate event type
$valid_types = ['meeting', 'social', 'cleanup', 'sports', 'other'];
if (!in_array($event_type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid event type']);
    exit;
}

// Validate status
$valid_statuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

// Update event
$update_query = "
    UPDATE tbl_events 
    SET title = ?, 
        description = ?, 
        event_date = ?, 
        start_time = ?, 
        end_time = ?, 
        location = ?, 
        event_type = ?, 
        status = ?,
        updated_at = NOW()
    WHERE event_id = ?
";

$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param(
    'ssssssssi',
    $title,
    $description,
    $event_date,
    $start_time,
    $end_time,
    $location,
    $event_type,
    $status,
    $event_id
);

if ($update_stmt->execute()) {
    // Log the action
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $log_query = "INSERT INTO tbl_activity_logs (user_id, action, description, created_at) VALUES (?, 'update_event', ?, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $log_description = "Updated event: {$title}";
        $log_stmt->bind_param('is', $user_id, $log_description);
        $log_stmt->execute();
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Event updated successfully',
        'event_id' => $event_id
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update event: ' . $conn->error
    ]);
}

$conn->close();
?>