<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireLogin();

$current_user_id = getCurrentUserId();
$current_role = getCurrentUserRole();
$can_manage = in_array($current_role, ['Admin', 'Super Admin', 'Super Administrator', 'Staff']);

if (!$can_manage) {
    setMessage('error', 'You do not have permission to manage events.');
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? '';

// Map event types to colors
$color_map = [
    'Meeting' => '#0d6efd',
    'Activity' => '#198754',
    'Holiday' => '#dc3545',
    'Emergency' => '#ffc107',
    'Other' => '#6c757d'
];

if ($action === 'add') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    $location = trim($_POST['location'] ?? '');
    $event_type = $_POST['event_type'] ?? 'Other';
    
    // Convert empty strings to NULL for time fields
    if (empty($start_time)) $start_time = null;
    if (empty($end_time)) $end_time = null;
    
    // Get color based on event type
    $color = $color_map[$event_type] ?? '#6c757d';

    if (empty($title) || empty($event_date)) {
        setMessage('error', 'Title and date are required.');
        header('Location: index.php');
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_calendar_events 
        (title, description, event_date, start_time, end_time, location, event_type, color, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "ssssssssi",
        $title,
        $description,
        $event_date,
        $start_time,
        $end_time,
        $location,
        $event_type,
        $color,
        $current_user_id
    );

    if ($stmt->execute()) {
        setMessage('success', 'Event added successfully.');
    } else {
        setMessage('error', 'Failed to add event: ' . $stmt->error);
    }
    $stmt->close();

} elseif ($action === 'edit') {
    $event_id = (int)($_POST['event_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    $location = trim($_POST['location'] ?? '');
    $event_type = $_POST['event_type'] ?? 'Other';
    
    // Convert empty strings to NULL for time fields
    if (empty($start_time)) $start_time = null;
    if (empty($end_time)) $end_time = null;
    
    // Get color based on event type
    $color = $color_map[$event_type] ?? '#6c757d';

    if (empty($title) || empty($event_date)) {
        setMessage('error', 'Invalid event data.');
        header('Location: index.php');
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE tbl_calendar_events 
        SET title = ?, description = ?, event_date = ?, start_time = ?, 
            end_time = ?, location = ?, event_type = ?, color = ?
        WHERE id = ? AND is_active = 1
    ");
    
    $stmt->bind_param(
        "ssssssssi",
        $title,
        $description,
        $event_date,
        $start_time,
        $end_time,
        $location,
        $event_type,
        $color,
        $event_id
    );

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            setMessage('success', 'Event updated successfully.');
        } else {
            setMessage('error', 'Event not found or no changes made.');
        }
    } else {
        setMessage('error', 'Failed to update event: ' . $stmt->error);
    }
    $stmt->close();

} elseif ($action === 'delete') {
    $event_id = (int)($_POST['event_id'] ?? 0);

    if (!$event_id && $event_id !== 0) {
        setMessage('error', 'Invalid event ID.');
        header('Location: index.php');
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE tbl_calendar_events 
        SET is_active = 0 
        WHERE id = ? AND is_active = 1
    ");
    
    $stmt->bind_param("i", $event_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            setMessage('success', 'Event deleted successfully.');
        } else {
            setMessage('error', 'Event not found.');
        }
    } else {
        setMessage('error', 'Failed to delete event: ' . $stmt->error);
    }
    $stmt->close();

} else {
    setMessage('error', 'Invalid action.');
}

// Redirect back to calendar with month/year parameters if they exist
$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');
header('Location: index.php?month=' . $month . '&year=' . $year);
exit;
?>