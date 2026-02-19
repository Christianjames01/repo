<?php
/**
 * DIAGNOSTIC SCRIPT - Place this at /modules/incidents/check-access.php
 * Access it to see what's blocking you
 */

session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';

echo "<h1>Access Diagnostic Check</h1>";
echo "<hr>";

echo "<h2>Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
echo "<hr>";

echo "<h2>Security Function Check:</h2>";

// Check if security functions exist
if (file_exists('../../includes/security.php')) {
    echo "✅ security.php exists<br>";
    require_once '../../includes/security.php';
    
    // Check what requireAnyRole function does
    if (function_exists('requireAnyRole')) {
        echo "✅ requireAnyRole() function exists<br>";
        echo "<br><strong>Testing requireAnyRole with your role...</strong><br>";
        
        try {
            requireAnyRole(['Super Administrator', 'Barangay Captain', 'Barangay Tanod']);
            echo "✅ requireAnyRole PASSED - You should have access!<br>";
        } catch (Exception $e) {
            echo "❌ requireAnyRole FAILED: " . $e->getMessage() . "<br>";
        }
        
        echo "<br><strong>Testing with 'Super Admin' (your actual role)...</strong><br>";
        try {
            requireAnyRole(['Super Admin', 'Admin', 'Staff']);
            echo "✅ requireAnyRole with 'Super Admin' PASSED!<br>";
        } catch (Exception $e) {
            echo "❌ requireAnyRole with 'Super Admin' FAILED: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "❌ requireAnyRole() function NOT found<br>";
    }
    
    // Check getCurrentUserRole
    if (function_exists('getCurrentUserRole')) {
        echo "<br>✅ getCurrentUserRole() function exists<br>";
        $role = getCurrentUserRole();
        echo "Your current role according to function: <strong>" . htmlspecialchars($role) . "</strong><br>";
    } else {
        echo "<br>❌ getCurrentUserRole() function NOT found<br>";
    }
    
} else {
    echo "❌ security.php NOT found at: " . realpath('../../includes/security.php') . "<br>";
}

echo "<hr>";
echo "<h2>Recommended Fix:</h2>";

$current_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Unknown';

if ($current_role == 'Super Admin') {
    echo "<p>✅ Your role is '<strong>Super Admin</strong>'</p>";
    echo "<p>The security check is probably looking for '<strong>Super Administrator</strong>' (with 'istrator')</p>";
    echo "<p><strong>Solution:</strong> Update the requireAnyRole call to include 'Super Admin':</p>";
    echo "<pre>";
    echo "requireAnyRole(['Super Admin', 'Super Administrator', 'Admin', 'Staff']);\n";
    echo "</pre>";
} else {
    echo "<p>Your current role: <strong>" . htmlspecialchars($current_role) . "</strong></p>";
    echo "<p>Make sure this role is included in the allowed roles list.</p>";
}

echo "<hr>";
echo "<h2>Database Role Check:</h2>";

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT u.user_id, u.username, u.role, r.role_name 
            FROM tbl_users u 
            LEFT JOIN tbl_roles r ON u.role = r.role_id 
            WHERE u.user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    if ($user) {
        echo "<p>Database role: <strong>" . htmlspecialchars($user['role']) . "</strong></p>";
        if ($user['role_name']) {
            echo "<p>Role name from tbl_roles: <strong>" . htmlspecialchars($user['role_name']) . "</strong></p>";
        } else {
            echo "<p>⚠️ role_name is NULL - tbl_roles might not have this role</p>";
        }
    }
}
?>