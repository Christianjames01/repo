<?php
// modules/community/admin/get-event-details.php
session_start();
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit();
}

$event_id = intval($_GET['id']);

$stmt = $conn->prepare("
    SELECT e.*,
           CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, '')) as organizer_name,
           COUNT(DISTINCT a.attendee_id) as attendee_count
    FROM tbl_events e
    LEFT JOIN tbl_resident r ON e.organizer_id = r.resident_id
    LEFT JOIN tbl_event_attendees a ON e.event_id = a.event_id
    WHERE e.event_id = ?
    GROUP BY e.event_id
");

$stmt->bind_param("i", $event_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    $stmt->close();
    exit();
}

$event = $result->fetch_assoc();
$stmt->close();

// Clean up organizer name if empty
if (empty(trim($event['organizer_name']))) {
    $event['organizer_name'] = 'Unknown';
}

echo json_encode([
    'success' => true,
    'event' => $event
]);
?>