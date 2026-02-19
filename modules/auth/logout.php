<?php
/**
 * Logout Handler
 * BarangayLink System
 */

require_once '../../config/config.php';
require_once '../../config/session.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = array();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: ../../modules/auth/login.php?logout=success');
exit();
?>