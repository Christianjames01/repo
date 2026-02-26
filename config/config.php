<?php
// config/config.php

// ============================================
// CRITICAL: Set timezone FIRST before anything else
// ============================================
date_default_timezone_set('Asia/Manila');

// Session Configuration - MUST be set BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    session_start();
}

// Database Configuration
$servername = "localhost";
$username   = "root";
$password   = "";
$database   = "barangaylink";

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

// ============================================
// CRITICAL: Set MySQL timezone to Philippine Time
// ============================================
$conn->query("SET time_zone = '+08:00'");

// ============================================
// AUTO-DETECT IP ADDRESS FOR MOBILE ACCESS
// ============================================
function getServerUrl() {
    // Get the server's IP address
    $serverIP = $_SERVER['SERVER_ADDR'] ?? 'localhost';
    
    // If accessed via IP address (from phone), use that IP
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost') {
        $host = $_SERVER['HTTP_HOST'];
    } else {
        $host = 'localhost';
    }
    
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    
    return $protocol . '://' . $host;
}

// Auto-detect base URL
$base_server_url = getServerUrl();

// Barangay Information
define('BARANGAY_NAME', 'Brgy Centro');
define('MUNICIPALITY', 'Davao City');
define('PROVINCE', 'Davao del Sur');
define('BARANGAY_ADDRESS', 'San Juan, Brgy Centro, Agdao, Davao City, Philippines');
define('BARANGAY_CONTACT', '+63 9487970726');
define('BARANGAY_EMAIL', 'barangay.centro@gmail.com');
define('BARANGAY_LOGO', '/barangaylink1/assets/images/brgy.png');
define('BASE_URL', '/barangaylink1');
$base_url = '/barangaylink1';

// Application Information - Auto-detect URL
define('APP_NAME', 'BarangayLink');
define('APP_VERSION', '1.0.0');
define('APP_URL', $base_server_url . '/barangaylink1');
define('SITE_URL', $base_server_url . '/barangaylink1/');

// Session Configuration
define('SESSION_TIMEOUT', 1800); // 30 minutes (in seconds)

// Security Settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour in seconds

// File Upload Settings
define('MAX_FILE_SIZE', 5242880); // 5MB in bytes
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// Upload Configuration (Enhanced)
define('UPLOAD_MAX_SIZE', 10485760); // 10MB in bytes
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', '/barangaylink1/uploads/');

// Allowed file types for uploads
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_DOCUMENT_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
]);

// Upload directories structure
define('INCIDENT_UPLOAD_DIR', UPLOAD_DIR . 'incidents/');
define('DOCUMENT_UPLOAD_DIR', UPLOAD_DIR . 'documents/');
define('PROFILE_UPLOAD_DIR', UPLOAD_DIR . 'profiles/');
define('ANNOUNCEMENT_UPLOAD_DIR', UPLOAD_DIR . 'announcements/');
define('MEDIA_UPLOAD_DIR', UPLOAD_DIR . 'media/');
define('MEDIA_PHOTO_DIR', MEDIA_UPLOAD_DIR . 'photos/');
define('MEDIA_VIDEO_DIR', MEDIA_UPLOAD_DIR . 'videos/');
define('MEDIA_PHOTO_URL', UPLOAD_URL . 'media/photos/');
define('MEDIA_VIDEO_URL', UPLOAD_URL . 'media/videos/');

// Create upload directories if they don't exist
$upload_dirs = [
    UPLOAD_DIR,
    INCIDENT_UPLOAD_DIR,
    DOCUMENT_UPLOAD_DIR,
    PROFILE_UPLOAD_DIR,
    ANNOUNCEMENT_UPLOAD_DIR,
    MEDIA_UPLOAD_DIR,
    MEDIA_PHOTO_DIR,
    MEDIA_VIDEO_DIR
];

foreach ($upload_dirs as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Pagination
define('RECORDS_PER_PAGE', 10);

// Date and time format
define('DATE_FORMAT', 'F d, Y');
define('TIME_FORMAT', 'h:i A');
define('DATETIME_FORMAT', 'F d, Y h:i A');

// System settings
define('SYSTEM_NAME', 'BarangayLink');
define('SYSTEM_DESCRIPTION', 'Barangay Management Information System');
define('SYSTEM_VERSION', '1.0.0');

// Debug mode (set to false in production)
define('DEBUG_MODE', true);

// ============================================
// EMAIL / SMTP CONFIGURATION
// ============================================
define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_USERNAME',   'sanjuanbrgycentro@gmail.com');
define('MAIL_PASSWORD',   'kdbtphdxvmvgcpub'); // Gmail App Password
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_FROM_EMAIL', 'sanjuanbrgycentro@gmail.com');
define('MAIL_FROM_NAME',  'Barangay System');
define('MAIL_IS_HTML',    true);
define('MAIL_CHARSET',    'UTF-8');
define('MAIL_DEBUG',      0);
define('MAIL_TIMEOUT',    10);
define('MAIL_SMTP_KEEPALIVE', true);

// ============================================
// IMAP — for reading incoming resident emails
// ============================================
define('IMAP_HOST',     '{imap.gmail.com:993/imap/ssl}INBOX');
define('IMAP_USER',     'sanjuanbrgycentro@gmail.com');
define('IMAP_PASSWORD', 'kdbtphdxvmvgcpub'); // same Gmail App Password

// ============================================
// LOAD FUNCTIONS IN CORRECT ORDER
// ============================================

// 1. Load authentication functions FIRST (before functions.php)
require_once __DIR__ . '/../includes/auth_functions.php';

// 2. Load other utility functions (they can now use auth functions)
require_once __DIR__ . '/../includes/functions.php';

// Check session timeout on every request (after auth functions are loaded)
if (isLoggedIn()) {
    checkSessionTimeout();
}
?>