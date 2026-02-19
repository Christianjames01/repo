<?php
/**
 * Session Management
 * BarangayLink System
 * Enhanced with Account Status Checking
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings for security
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    
    session_start();
}

// Session timeout check
if (!function_exists('checkSessionTimeout')) {
    function checkSessionTimeout() {
        if (isset($_SESSION['LAST_ACTIVITY']) && 
            (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
            session_unset();
            session_destroy();
            return false;
        }
        $_SESSION['LAST_ACTIVITY'] = time();
        return true;
    }
}

// Check if user is logged in
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
}



if (!function_exists('isAccountActive')) {
    function isAccountActive() {
        if (!isLoggedIn()) {
            return false;
        }
        
        global $conn;
        $user_id = $_SESSION['user_id'];
        
        // Check what columns exist in the database
        $check_status = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'status'");
        $has_status = $check_status && $check_status->num_rows > 0;
        
        $check_account_status = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'account_status'");
        $has_account_status = $check_account_status && $check_account_status->num_rows > 0;
        
        $check_is_active = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'is_active'");
        $has_is_active = $check_is_active && $check_is_active->num_rows > 0;
        
        // Build query based on available columns
        $query = "SELECT user_id";
        
        if ($has_status) {
            $query .= ", status";
        }
        if ($has_account_status) {
            $query .= ", account_status";
        }
        if ($has_is_active) {
            $query .= ", is_active";
        }
        
        $query .= " FROM tbl_users WHERE user_id = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // User doesn't exist anymore
            $stmt->close();
            return false;
        }
        
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Check status column (values: 'active', 'inactive')
        if ($has_status && isset($user['status'])) {
            if (strtolower($user['status']) === 'inactive') {
                return false;
            }
        }
        
        // Check account_status column (values: 'Active', 'Deactivated')
        if ($has_account_status && isset($user['account_status'])) {
            if ($user['account_status'] === 'Deactivated') {
                return false;
            }
        }
        
        // Check is_active column (values: 1, 0)
        if ($has_is_active && isset($user['is_active'])) {
            if ($user['is_active'] == 0) {
                return false;
            }
        }
        
        return true;
    }
}

// Check user role
if (!function_exists('hasRole')) {
    function hasRole($role) {
        if (!isLoggedIn()) {
            return false;
        }
        
        // Admin and Super Admin have access to everything
        if (isset($_SESSION['role_name']) && 
            ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Super Admin')) {
            return true;
        }
        
        return isset($_SESSION['role_name']) && $_SESSION['role_name'] === $role;
    }
}

// Check multiple roles
if (!function_exists('hasAnyRole')) {
    function hasAnyRole($roles) {
        if (!isLoggedIn()) {
            return false;
        }
        
        // Admin and Super Admin have access to everything
        if (isset($_SESSION['role_name']) && 
            ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Super Admin')) {
            return true;
        }
        
        return isset($_SESSION['role_name']) && in_array($_SESSION['role_name'], $roles);
    }
}

// Get current user ID
if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return isLoggedIn() ? $_SESSION['user_id'] : null;
    }
}

// Get current user role
if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        return isLoggedIn() ? $_SESSION['role_name'] : null;
    }
}

// Get current resident ID (if user is a resident)
if (!function_exists('getCurrentResidentId')) {
    function getCurrentResidentId() {
        return (isLoggedIn() && isset($_SESSION['resident_id'])) ? $_SESSION['resident_id'] : null;
    }
}

// UPDATED: Require login with account status check
if (!function_exists('requireLogin')) {
    function requireLogin() {
        // Check if logged in and session hasn't timed out
        if (!isLoggedIn() || !checkSessionTimeout()) {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
            header('Location: /barangaylink1/modules/auth/login.php');
            exit();
        }
        
        // IMPORTANT: Check if account is still active
        if (!isAccountActive()) {
            // Log out the user
            session_unset();
            session_destroy();
            
            // Redirect with deactivation message
            header('Location: /barangaylink1/modules/auth/login.php?deactivated=1');
            exit();
        }
    }
}

// Require specific role (ADMIN AND SUPER ADMIN CAN ACCESS EVERYTHING)
if (!function_exists('requireRole')) {
    function requireRole($role) {
        requireLogin(); // This will now also check account status
        
        // CRITICAL FIX: Admin and Super Admin can access ANY page regardless of role requirement
        if (isset($_SESSION['role_name']) && 
            ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Super Admin')) {
            return; // Allow access and return immediately
        }
        
        // Convert to array if single role provided
        $roles = is_array($role) ? $role : [$role];
        
        // Check if user has one of the required roles
        $hasAccess = false;
        foreach ($roles as $requiredRole) {
            if (hasRole($requiredRole)) {
                $hasAccess = true;
                break;
            }
        }
        
        if (!$hasAccess) {
            header('Location: /barangaylink1/modules/dashboard/index.php?error=unauthorized');
            exit();
        }
    }
}

// Require any of multiple roles (accepts array or single role)
if (!function_exists('requireAnyRole')) {
    function requireAnyRole($roles) {
        requireLogin(); // This will now also check account status
        
        // Admin and Super Admin can access everything - bypass role check
        if (isset($_SESSION['role_name']) && 
            ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Super Admin')) {
            return;
        }
        
        // Convert single role to array for consistency
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        
        if (!hasAnyRole($roles)) {
            $_SESSION['error_message'] = 'You do not have permission to access this page.';
            header('Location: /barangaylink1/modules/dashboard/index.php');
            exit();
        }
    }
}

// Set success message
if (!function_exists('setSuccessMessage')) {
    function setSuccessMessage($message) {
        $_SESSION['success_message'] = $message;
    }
}

// Set error message
if (!function_exists('setErrorMessage')) {
    function setErrorMessage($message) {
        $_SESSION['error_message'] = $message;
    }
}

// Get and clear success message
if (!function_exists('getSuccessMessage')) {
    function getSuccessMessage() {
        if (isset($_SESSION['success_message'])) {
            $message = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
            return $message;
        }
        return null;
    }
}

// Get and clear error message
if (!function_exists('getErrorMessage')) {
    function getErrorMessage() {
        if (isset($_SESSION['error_message'])) {
            $message = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
            return $message;
        }
        return null;
    }
}

// Regenerate session ID for security
if (!function_exists('regenerateSession')) {
    function regenerateSession() {
        session_regenerate_id(true);
    }
}

// Initialize user session after successful login
if (!function_exists('initUserSession')) {
    function initUserSession($user) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
        
        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['role'] = $user['role_name']; // For compatibility
        $_SESSION['logged_in'] = true;
        $_SESSION['LAST_ACTIVITY'] = time();
        
        // Set resident_id if user is a resident
        if (!empty($user['resident_id'])) {
            $_SESSION['resident_id'] = $user['resident_id'];
        }
    }
}
?>