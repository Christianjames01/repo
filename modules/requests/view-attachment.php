<?php
/**
 * Save this as: modules/requests/view_attachment.php
 * 
 * This script serves files securely while checking permissions
 * Use: <img src="view_attachment.php?file=uploads/requests/16/image.jpg">
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';

// Must be logged in
requireLogin();

$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

// Get requested file from query parameter
$requested_file = isset($_GET['file']) ? $_GET['file'] : '';

if (empty($requested_file)) {
    header("HTTP/1.0 400 Bad Request");
    die("No file specified");
}

// Security: Remove any path traversal attempts
$requested_file = str_replace(['../', '..\\', '../', '..\\'], '', $requested_file);

// Build absolute server path
$file_path = __DIR__ . '/../../' . $requested_file;

// Check if file exists
if (!file_exists($file_path)) {
    header("HTTP/1.0 404 Not Found");
    die("File not found");
}

// Check if it's in the allowed uploads directory
if (strpos(realpath($file_path), realpath(__DIR__ . '/../../uploads/')) !== 0) {
    header("HTTP/1.0 403 Forbidden");
    die("Access denied");
}

// Extract request_id from path (e.g., uploads/requests/16/file.jpg)
preg_match('/requests\/(\d+)\//', $requested_file, $matches);
$request_id = isset($matches[1]) ? intval($matches[1]) : 0;

// Verify user has access to this request
if ($user_role === 'Resident' && $request_id > 0) {
    // Check if this resident owns this request
    $check_sql = "SELECT r.request_id 
                  FROM tbl_requests r
                  INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
                  INNER JOIN tbl_users u ON res.resident_id = u.resident_id
                  WHERE r.request_id = ? AND u.user_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("ii", $request_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("HTTP/1.0 403 Forbidden");
        die("Access denied");
    }
    $stmt->close();
}

// Get MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file_path);
finfo_close($finfo);

// Set appropriate headers
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($file_path));
header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
header('Cache-Control: private, max-age=3600');

// Output file
readfile($file_path);
exit();