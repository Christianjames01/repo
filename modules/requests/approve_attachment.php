<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';

requireLogin();
$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

header('Content-Type: application/json');

// Only allow admin and staff
if ($user_role === 'Resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

if (!$attachment_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

// Validate rejection requires remarks
if ($action === 'reject' && empty($remarks)) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for rejection']);
    exit();
}

$approval_status = ($action === 'approve') ? 'Approved' : 'Rejected';

$sql = "UPDATE tbl_request_attachments 
        SET approval_status = ?, 
            approval_remarks = ?, 
            approved_by = ?, 
            approved_at = NOW() 
        WHERE attachment_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssii", $approval_status, $remarks, $user_id, $attachment_id);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Attachment ' . strtolower($approval_status) . ' successfully',
        'status' => $approval_status
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update attachment status']);
}

$stmt->close();
$conn->close();
?>