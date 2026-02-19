<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Force login and admin/staff access
requireLogin();

// Debug: Check what role the user has
error_log("Current user role: " . getCurrentUserRole());
error_log("Session role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'not set'));
error_log("Session role_name: " . (isset($_SESSION['role_name']) ? $_SESSION['role_name'] : 'not set'));

$user_role = getCurrentUserRole();

// Allow Super Admin, Admin, Staff - Block only Residents and others
$allowed_roles = ['Super Admin', 'Super Administrator', 'Admin', 'Staff'];

if (!in_array($user_role, $allowed_roles)) {
    error_log("Access denied for role: " . $user_role);
    $_SESSION['error_message'] = "You do not have permission to access this page. Your role: " . $user_role;
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'Manage Residents';