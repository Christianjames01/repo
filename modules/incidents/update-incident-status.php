<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Allow staff roles to update status
requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod']);

$current_user_id = getCurrentUserId();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    if (isset($_SERVER['HTTP_REFERER'])) {
        header('Location: ' . $_SERVER['HTTP_REFERER']);
    } else {
        header('Location: view-incidents.php');
    }
    exit;
}

// Validate required fields
$incident_id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';
$severity = isset($_POST['severity']) ? trim($_POST['severity']) : '';
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

if (!$incident_id || !$status || !$severity) {
    $_SESSION['error_message'] = 'Please fill in all required fields';
    header('Location: incident-details.php?id=' . $incident_id);
    exit;
}

// Validate status values
$valid_statuses = ['Pending', 'In Progress', 'Resolved', 'Closed'];
$valid_severities = ['Low', 'Medium', 'High', 'Critical'];

if (!in_array($status, $valid_statuses) || !in_array($severity, $valid_severities)) {
    $_SESSION['error_message'] = 'Invalid status or severity value';
    header('Location: incident-details.php?id=' . $incident_id);
    exit;
}

// Check if incident exists
$stmt = $conn->prepare("SELECT incident_id, status FROM tbl_incidents WHERE incident_id = ?");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = 'Incident not found';
    $stmt->close();
    header('Location: view-incidents.php');
    exit;
}

$incident = $result->fetch_assoc();
$old_status = $incident['status'];
$stmt->close();

// Update incident status and severity
$stmt = $conn->prepare("UPDATE tbl_incidents SET status = ?, severity = ? WHERE incident_id = ?");
$stmt->bind_param("ssi", $status, $severity, $incident_id);

if ($stmt->execute()) {
    // Check if incident_responses table exists to log the status change
    $table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_responses'");
    $has_responses_table = ($table_check && $table_check->num_rows > 0);
    
    if ($has_responses_table && !empty($notes)) {
        // Log the status update as a response
        $response_message = "Status updated from '{$old_status}' to '{$status}'. Severity set to '{$severity}'.";
        
        if (!empty($notes)) {
            $response_message .= "\n\nNotes: " . $notes;
        }
        
        $action_taken = "Status Update";
        $stmt_response = $conn->prepare("INSERT INTO tbl_incident_responses (incident_id, responder_id, response_message, action_taken) VALUES (?, ?, ?, ?)");
        $stmt_response->bind_param("iiss", $incident_id, $current_user_id, $response_message, $action_taken);
        $stmt_response->execute();
        $stmt_response->close();
    }
    
    $_SESSION['success_message'] = 'Incident status updated successfully';
} else {
    $_SESSION['error_message'] = 'Failed to update incident status: ' . $conn->error;
}

$stmt->close();

// Redirect back to incident details
header('Location: incident-details.php?id=' . $incident_id);
exit;
?>