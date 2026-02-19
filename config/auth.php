<?php
/**
 * Authentication Configuration
 * Path: config/auth.php
 * 
 * This file ensures session is started and loads all auth-related functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define base path if not already defined
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Load authentication functions if they exist
$auth_functions_path = BASE_PATH . '/includes/auth_functions.php';
if (file_exists($auth_functions_path)) {
    require_once($auth_functions_path);
}

// Load general helper functions if they exist
$functions_path = BASE_PATH . '/includes/functions.php';
if (file_exists($functions_path)) {
    require_once($functions_path);
}

// Define missing functions if they don't exist yet (fallback)
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        return $_SESSION['role'] ?? $_SESSION['role_name'] ?? null;
    }
}

if (!function_exists('hasRole')) {
    function hasRole($roles) {
        if (!isLoggedIn()) {
            return false;
        }
        
        $roles = is_array($roles) ? $roles : [$roles];
        $currentRole = getCurrentUserRole();
        
        return in_array($currentRole, $roles);
    }
}

if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount($conn, $user_id) {
        if (!$conn || !$user_id) {
            return 0;
        }
        
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_notifications WHERE user_id = ? AND is_read = 0");
            if (!$stmt) {
                return 0;
            }
            
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return $row['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin($redirect_url = '/barangaylink1/modules/auth/login.php') {
        if (!isLoggedIn()) {
            header('Location: ' . $redirect_url);
            exit();
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole($roles, $redirect_url = '/barangaylink1/index.php') {
        requireLogin();
        
        if (!hasRole($roles)) {
            header('Location: ' . $redirect_url);
            exit();
        }
    }
}

// Define barangay name constant if not already defined
if (!defined('BARANGAY_NAME')) {
    define('BARANGAY_NAME', 'Brgy Centro');
}

// Session timeout check
if (isLoggedIn()) {
    $timeout = 1800; // 30 minutes
    $last_activity = $_SESSION['last_activity'] ?? $_SESSION['LAST_ACTIVITY'] ?? time();
    
    if ((time() - $last_activity) > $timeout) {
        session_unset();
        session_destroy();
        header("Location: /barangaylink1/modules/auth/login.php?timeout=1");
        exit();
    }
    
    $_SESSION['last_activity'] = time();
    $_SESSION['LAST_ACTIVITY'] = time();
}
?>