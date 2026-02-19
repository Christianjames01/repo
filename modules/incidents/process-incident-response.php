<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Only allow staff roles (not residents)
requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod']);

$current_user_id = getCurrentUserId();
$current_role = getCurrentUserRole();

// Prevent Tanod from modifying
$is_tanod = ($current_role === 'Tanod' || $current_role === 'Barangay Tanod');
if ($is_tanod) {
    $_SESSION['error_message'] = 'You do not have permission to perform this action.';
    header('Location: view-incidents.php');
    exit;
}

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header('Location: view-incidents.php');
    exit;
}

// Get the action type
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$incident_id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;

if (!$incident_id) {
    $_SESSION['error_message'] = 'Invalid incident ID';
    header('Location: view-incidents.php');
    exit;
}

// Verify incident exists
$stmt = $conn->prepare("SELECT incident_id, status, resident_id FROM tbl_incidents WHERE incident_id = ?");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = 'Incident not found';
    header('Location: view-incidents.php');
    exit;
}

$incident = $result->fetch_assoc();
$stmt->close();

// Process based on action type
switch ($action) {
    case 'add_response':
        addIncidentResponse($conn, $incident_id, $current_user_id);
        break;
        
    case 'update_status':
        updateIncidentStatus($conn, $incident_id, $current_user_id, $incident);
        break;
        
    case 'assign_responder':
        assignResponder($conn, $incident_id, $current_user_id, $incident);
        break;
        
    default:
        $_SESSION['error_message'] = 'Invalid action';
        header("Location: incident-details.php?id=$incident_id");
        exit;
}

// Helper function to get user's display name
function getUserDisplayName($conn, $user_id) {
    $stmt = $conn->prepare("SELECT u.username, u.resident_id, 
                                   r.first_name, r.middle_name, r.last_name 
                           FROM tbl_users u
                           LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                           WHERE u.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return "User #$user_id";
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Build full name if available
    if (!empty($row['first_name']) && !empty($row['last_name'])) {
        $name_parts = [$row['first_name']];
        if (!empty($row['middle_name'])) {
            $name_parts[] = substr($row['middle_name'], 0, 1) . '.';
        }
        $name_parts[] = $row['last_name'];
        return implode(' ', $name_parts);
    }
    
    return $row['username'];
}

// Function to add incident response
function addIncidentResponse($conn, $incident_id, $current_user_id) {
    // Check if responses table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_responses'");
    if (!$table_check || $table_check->num_rows === 0) {
        $_SESSION['error_message'] = 'Response tracking is not available';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    $response_message = isset($_POST['response_message']) ? trim($_POST['response_message']) : '';
    $action_taken = isset($_POST['action_taken']) ? trim($_POST['action_taken']) : null;
    
    if (empty($response_message)) {
        $_SESSION['error_message'] = 'Response message is required';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    // Insert response
    $stmt = $conn->prepare("INSERT INTO tbl_incident_responses 
                           (incident_id, responder_id, response_message, action_taken, response_date) 
                           VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("iiss", $incident_id, $current_user_id, $response_message, $action_taken);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Response added successfully';
        
        // Log activity if function exists
        if (function_exists('logActivity')) {
            logActivity($conn, $current_user_id, "Added response to incident #$incident_id", 'tbl_incident_responses', $conn->insert_id);
        }
    } else {
        $_SESSION['error_message'] = 'Failed to add response: ' . $conn->error;
    }
    
    $stmt->close();
    header("Location: incident-details.php?id=$incident_id");
    exit;
}

// Function to update incident status
function updateIncidentStatus($conn, $incident_id, $current_user_id, $incident) {
    $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
    $severity = isset($_POST['severity']) ? trim($_POST['severity']) : '';
    $status_notes = isset($_POST['status_notes']) ? trim($_POST['status_notes']) : '';
    
    if (empty($new_status)) {
        $_SESSION['error_message'] = 'New status is required';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    // FIXED: Updated valid statuses to match the form options
    $valid_statuses = ['Pending', 'Under Investigation', 'In Progress', 'Resolved', 'Closed'];
    if (!in_array($new_status, $valid_statuses)) {
        $_SESSION['error_message'] = 'Invalid status: ' . htmlspecialchars($new_status);
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    // Validate severity
    $valid_severities = ['Low', 'Medium', 'High', 'Critical'];
    if (!empty($severity) && !in_array($severity, $valid_severities)) {
        $_SESSION['error_message'] = 'Invalid severity level';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    // Check which columns exist in the table
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM tbl_incidents");
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Build dynamic UPDATE query based on existing columns
    $update_fields = ["status = ?"];
    $params = [$new_status];
    $types = "s";
    
    if (!empty($severity)) {
        $update_fields[] = "severity = ?";
        $params[] = $severity;
        $types .= "s";
    }
    
    // Add timestamp fields if they exist
    if (in_array('date_updated', $columns)) {
        $update_fields[] = "date_updated = NOW()";
    }
    if (in_array('updated_at', $columns)) {
        $update_fields[] = "updated_at = NOW()";
    }
    
    // Add incident_id to params
    $params[] = $incident_id;
    $types .= "i";
    
    // Execute update
    $sql = "UPDATE tbl_incidents SET " . implode(", ", $update_fields) . " WHERE incident_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Incident status updated successfully';
        
        // Mark as resolved if status is Closed or Resolved
        if (in_array($new_status, ['Closed', 'Resolved']) && in_array('resolved_at', $columns)) {
            $stmt_resolve = $conn->prepare("UPDATE tbl_incidents 
                                           SET resolved_at = NOW()" . 
                                           (in_array('resolved_by', $columns) ? ", resolved_by = ?" : "") . 
                                           " WHERE incident_id = ?");
            if (in_array('resolved_by', $columns)) {
                $stmt_resolve->bind_param("ii", $current_user_id, $incident_id);
            } else {
                $stmt_resolve->bind_param("i", $incident_id);
            }
            $stmt_resolve->execute();
            $stmt_resolve->close();
        }
        
        // Add response if notes provided
        if (!empty($status_notes)) {
            $table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_responses'");
            if ($table_check && $table_check->num_rows > 0) {
                $response_msg = "Status changed to: $new_status";
                if (!empty($status_notes)) {
                    $response_msg .= "\nNotes: $status_notes";
                }
                
                $stmt2 = $conn->prepare("INSERT INTO tbl_incident_responses 
                                       (incident_id, responder_id, response_message, action_taken, response_date) 
                                       VALUES (?, ?, ?, 'Updated', NOW())");
                $stmt2->bind_param("iis", $incident_id, $current_user_id, $response_msg);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        
        // Send notifications
        sendStatusUpdateNotifications($conn, $incident_id, $new_status, $columns);
        
        // Log activity if function exists
        if (function_exists('logActivity')) {
            logActivity($conn, $current_user_id, "Updated incident #$incident_id status to $new_status", 'tbl_incidents', $incident_id);
        }
    } else {
        $_SESSION['error_message'] = 'Failed to update status: ' . $conn->error;
    }
    
    $stmt->close();
    header("Location: incident-details.php?id=$incident_id");
    exit;
}

// Function to send status update notifications
function sendStatusUpdateNotifications($conn, $incident_id, $new_status, $columns) {
    // Get incident details - check which columns exist
    $select_cols = ['incident_type', 'location', 'resident_id'];
    if (in_array('subject', $columns)) {
        $select_cols[] = 'subject';
    }
    if (in_array('responder_id', $columns)) {
        $select_cols[] = 'responder_id';
    }
    if (in_array('assigned_to', $columns)) {
        $select_cols[] = 'assigned_to';
    }
    
    $sql = "SELECT " . implode(', ', $select_cols) . " FROM tbl_incidents WHERE incident_id = ?";
    $stmt_inc = $conn->prepare($sql);
    $stmt_inc->bind_param("i", $incident_id);
    $stmt_inc->execute();
    $inc_details = $stmt_inc->get_result()->fetch_assoc();
    $stmt_inc->close();
    
    if (!$inc_details) return;
    
    // Build incident title
    $incident_title = isset($inc_details['subject']) && !empty($inc_details['subject'])
        ? $inc_details['subject'] 
        : $inc_details['incident_type'] . ' - ' . substr($inc_details['location'], 0, 30);
    
    // Get reporter user_id from resident_id
    $reporter_id = null;
    if (!empty($inc_details['resident_id'])) {
        $stmt_user = $conn->prepare("SELECT user_id FROM tbl_users WHERE resident_id = ? AND status = 'active' LIMIT 1");
        $stmt_user->bind_param("i", $inc_details['resident_id']);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_result->num_rows > 0) {
            $reporter_id = $user_result->fetch_assoc()['user_id'];
        }
        $stmt_user->close();
    }
    
    // Get assigned staff
    $assigned_staff = null;
    if (isset($inc_details['responder_id']) && !empty($inc_details['responder_id'])) {
        $assigned_staff = $inc_details['responder_id'];
    } elseif (isset($inc_details['assigned_to']) && !empty($inc_details['assigned_to'])) {
        $assigned_staff = $inc_details['assigned_to'];
    }
    
    // Send notifications
    if ($reporter_id && function_exists('notifyIncidentStatusUpdate')) {
        notifyIncidentStatusUpdate(
            $conn,
            $incident_id,
            $incident_title,
            $new_status,
            $reporter_id,
            $assigned_staff
        );
    }
}

// Function to assign responder
function assignResponder($conn, $incident_id, $current_user_id, $incident) {
    $responder_id = isset($_POST['responder_id']) ? (int)$_POST['responder_id'] : 0;
    $assignment_notes = isset($_POST['assignment_notes']) ? trim($_POST['assignment_notes']) : '';
    $notify_responder = isset($_POST['notify_responder']) ? true : false;
    
    if (!$responder_id) {
        $_SESSION['error_message'] = 'Please select a responder';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    // Verify responder exists and is active
    $stmt = $conn->prepare("SELECT user_id, username FROM tbl_users WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $responder_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error_message'] = 'Invalid responder selected';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    $responder = $result->fetch_assoc();
    $stmt->close();
    
    // Get display name
    $responder_display_name = getUserDisplayName($conn, $responder_id);
    
    // Check which columns exist
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM tbl_incidents");
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Build dynamic UPDATE query
    $update_fields = [];
    $params = [];
    $types = "";
    
    if (in_array('responder_id', $columns)) {
        $update_fields[] = "responder_id = ?";
        $params[] = $responder_id;
        $types .= "i";
    }
    if (in_array('assigned_to', $columns)) {
        $update_fields[] = "assigned_to = ?";
        $params[] = $responder_id;
        $types .= "i";
    }
    if (in_array('assigned_staff_id', $columns)) {
        $update_fields[] = "assigned_staff_id = ?";
        $params[] = $responder_id;
        $types .= "i";
    }
    if (in_array('date_updated', $columns)) {
        $update_fields[] = "date_updated = NOW()";
    }
    if (in_array('updated_at', $columns)) {
        $update_fields[] = "updated_at = NOW()";
    }
    
    if (empty($update_fields)) {
        $_SESSION['error_message'] = 'Cannot assign responder - no compatible columns found';
        header("Location: incident-details.php?id=$incident_id");
        exit;
    }
    
    $params[] = $incident_id;
    $types .= "i";
    
    $sql = "UPDATE tbl_incidents SET " . implode(", ", $update_fields) . " WHERE incident_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Responder assigned successfully';
        
        // Add response with assignment info
        $table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_responses'");
        if ($table_check && $table_check->num_rows > 0) {
            $response_msg = "Assigned to: " . $responder_display_name;
            if (!empty($assignment_notes)) {
                $response_msg .= "\nNotes: $assignment_notes";
            }
            
            $stmt2 = $conn->prepare("INSERT INTO tbl_incident_responses 
                                   (incident_id, responder_id, response_message, action_taken, response_date) 
                                   VALUES (?, ?, ?, 'Assigned', NOW())");
            $stmt2->bind_param("iis", $incident_id, $current_user_id, $response_msg);
            $stmt2->execute();
            $stmt2->close();
        }
        
        // Send notifications
        sendAssignmentNotifications($conn, $incident_id, $responder_id, $current_user_id, $columns);
        
        // Log activity
        if (function_exists('logActivity')) {
            logActivity($conn, $current_user_id, "Assigned incident #$incident_id to user #$responder_id", 'tbl_incidents', $incident_id);
        }
    } else {
        $_SESSION['error_message'] = 'Failed to assign responder: ' . $conn->error;
    }
    
    $stmt->close();
    header("Location: incident-details.php?id=$incident_id");
    exit;
}

// Function to send assignment notifications
function sendAssignmentNotifications($conn, $incident_id, $responder_id, $current_user_id, $columns) {
    // Get incident details
    $select_cols = ['incident_type', 'location', 'resident_id'];
    if (in_array('subject', $columns)) {
        $select_cols[] = 'subject';
    }
    
    $sql = "SELECT " . implode(', ', $select_cols) . " FROM tbl_incidents WHERE incident_id = ?";
    $stmt_inc = $conn->prepare($sql);
    $stmt_inc->bind_param("i", $incident_id);
    $stmt_inc->execute();
    $inc_details = $stmt_inc->get_result()->fetch_assoc();
    $stmt_inc->close();
    
    if (!$inc_details) return;
    
    // Build incident title
    $incident_title = isset($inc_details['subject']) && !empty($inc_details['subject'])
        ? $inc_details['subject'] 
        : $inc_details['incident_type'] . ' - ' . substr($inc_details['location'], 0, 30);
    
    // Get current user's display name
    $current_user_name = getUserDisplayName($conn, $current_user_id);
    
    // Get reporter user_id
    $reporter_id = null;
    if (!empty($inc_details['resident_id'])) {
        $stmt_user = $conn->prepare("SELECT user_id FROM tbl_users WHERE resident_id = ? AND status = 'active' LIMIT 1");
        $stmt_user->bind_param("i", $inc_details['resident_id']);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_result->num_rows > 0) {
            $reporter_id = $user_result->fetch_assoc()['user_id'];
        }
        $stmt_user->close();
    }
    
    // Send notifications - FIXED: Changed parameter names to match database schema
    if (function_exists('notifyIncidentAssignment')) {
        notifyIncidentAssignment(
            $conn,
            $incident_id,
            $incident_title,
            $responder_id,
            $current_user_name,
            $reporter_id
        );
    }
}
?>