<?php
/**
 * Authentication Functions
 * Path: barangaylink1/includes/auth_functions.php
 * 
 * IMPORTANT: These functions MUST be loaded before functions.php
 * to avoid circular dependency issues.
 */
function requireAnyRole($roles, $redirect_url = '/barangaylink1/modules/dashboard/index.php') {
    // First check if logged in
    requireLogin();
    
    // Then check if has required role
    if (!hasRole($roles)) {
        redirect($redirect_url, 'Access denied. Insufficient permissions.', 'error');
    }
}
/**
 * Check if user is logged in
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID from session
 * @return int|null User ID or null if not logged in
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role from session
 * @return string|null User role or null if not logged in
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? $_SESSION['role_name'] ?? null;
}

/**
 * Get current username from session
 * @return string|null Username or null if not logged in
 */
function getCurrentUsername() {
    return $_SESSION['username'] ?? null;
}

/**
 * Check if user has specific role(s)
 * @param string|array $roles Single role string or array of roles
 * @return bool True if user has one of the specified roles
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Convert single role to array for consistent handling
    $roles = is_array($roles) ? $roles : [$roles];
    
    $currentRole = getCurrentUserRole();
    
    // Check if current user role matches any of the specified roles
    return in_array($currentRole, $roles);
}

/**
 * Redirect to another page with optional message
 * @param string $url URL to redirect to
 * @param string $message Optional message to display after redirect
 * @param string $type Message type: success, error, warning, info
 */
function redirect($url, $message = '', $type = 'info') {
    // Set message in session if provided
    if (!empty($message)) {
        $_SESSION[$type . '_message'] = $message;
    }
    
    // Perform redirect
    header("Location: $url");
    exit();
}

/**
 * Check if current user is admin or super admin
 * @return bool True if user is admin or super admin
 */
function isAdmin() {
    return hasRole(['Admin', 'Super Admin', 'Super Administrator']);
}

/**
 * Require user to be logged in, redirect if not
 * @param string $redirect_url URL to redirect to if not logged in
 */
function requireLogin($redirect_url = '/barangaylink1/modules/auth/login.php') {
    if (!isLoggedIn()) {
        redirect($redirect_url, 'Please login to continue', 'error');
    }
}

/**
 * Require user to have specific role(s), redirect if not
 * @param string|array $roles Required role(s)
 * @param string $redirect_url URL to redirect to if access denied
 */
function requireRole($roles, $redirect_url = '/barangaylink1/modules/dashboard/index.php') {
    // First check if logged in
    requireLogin();
    
    // Then check if has required role
    if (!hasRole($roles)) {
        redirect($redirect_url, 'Access denied. Insufficient permissions.', 'error');
    }
}

/**
 * Get all current user data from session
 * @return array Associative array of user data
 */
function getCurrentUserData() {
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'role' => $_SESSION['role'] ?? $_SESSION['role_name'] ?? null,
        'resident_id' => $_SESSION['resident_id'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'profile_photo' => $_SESSION['profile_photo'] ?? null
    ];
}

/**
 * Logout current user - destroy session and redirect to login
 */
function logoutUser() {
    // Unset all session variables
    $_SESSION = array();
    
    // Destroy the session cookie if it exists
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page
    header("Location: /barangaylink1/modules/auth/login.php?logout=1");
    exit();
}

/**
 * Check if session has timed out based on last activity
 * Logs out user if session has expired
 * @return bool True if session is still valid, false if expired (will logout)
 */
function checkSessionTimeout() {
    if (isLoggedIn()) {
        // Default timeout: 30 minutes (1800 seconds)
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800;
        
        // Get last activity time from session
        $last_activity = $_SESSION['last_activity'] ?? $_SESSION['LAST_ACTIVITY'] ?? time();
        
        // Check if time since last activity exceeds timeout limit
        if ((time() - $last_activity) > $timeout) {
            // Session has expired - logout user
            session_unset();
            session_destroy();
            header("Location: /barangaylink1/modules/auth/login.php?timeout=1");
            exit();
        }
        
        // Update last activity time to current time
        $_SESSION['last_activity'] = time();
        $_SESSION['LAST_ACTIVITY'] = time();
    }
    
    return true;
}

function getCurrentResidentId() {
    // First check session
    if (isset($_SESSION['resident_id'])) {
        return $_SESSION['resident_id'];
    }
    
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    global $conn;
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        $_SESSION['resident_id'] = $row['resident_id']; // Cache in session
        return $row['resident_id'];
    }
    
    $stmt->close();
    return null;
}

/**
 * Set user session data after successful login
 * @param array $user_data User data from database
 */
function setUserSession($user_data) {
    $_SESSION['user_id'] = $user_data['user_id'];
    $_SESSION['username'] = $user_data['username'];
    $_SESSION['role'] = $user_data['role'] ?? $user_data['role_name'] ?? 'Resident';
    $_SESSION['role_name'] = $user_data['role_name'] ?? $user_data['role'] ?? 'Resident';
    $_SESSION['role_id'] = $user_data['role_id'] ?? null;
    $_SESSION['resident_id'] = $user_data['resident_id'] ?? null;
    $_SESSION['full_name'] = $user_data['full_name'] ?? null;
    $_SESSION['profile_photo'] = $user_data['profile_photo'] ?? null;
    $_SESSION['last_activity'] = time();
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SESSION['logged_in'] = true;
    
    // Regenerate session ID to prevent session fixation attacks
    session_regenerate_id(true);
}

/**
 * Check if user is super admin
 * @return bool True if user is super admin
 */
function isSuperAdmin() {
    return hasRole(['Super Admin', 'Super Administrator']);
}

/**
 * Check if user is staff
 * @return bool True if user is staff
 */
function isStaff() {
    return hasRole('Staff');
}

/**
 * Check if user is resident
 * @return bool True if user is resident
 */
function isResident() {
    return hasRole('Resident');
}

/**
 * Get user's full name from session
 * @return string|null Full name or null
 */
function getCurrentUserFullName() {
    return $_SESSION['full_name'] ?? $_SESSION['username'] ?? null;
}

/**
 * Get user's profile photo from session
 * @return string|null Profile photo filename or null
 */
function getCurrentUserPhoto() {
    return $_SESSION['profile_photo'] ?? null;
}
?>