<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
session_start();

if (!isLoggedIn()) die('Please login');

echo "<h2>Leave Requests Debug</h2>";

// Check total leaves
$total = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_leave_requests");
echo "<p><strong>Total leave requests:</strong> " . ($total['count'] ?? 0) . "</p>";

// Check all leaves with user info
$all = fetchAll($conn, "SELECT lr.*, u.username, u.role FROM tbl_leave_requests lr LEFT JOIN tbl_users u ON lr.user_id = u.user_id");
echo "<pre>";
print_r($all);
echo "</pre>";
?>