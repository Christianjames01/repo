<?php
// Enable ALL error reporting and logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Start output buffering to catch any errors
ob_start();

echo "<!DOCTYPE html><html><head><title>Debug</title></head><body><pre>";
echo "=== DEBUGGING MY APPLICATIONS PAGE ===\n\n";

try {
    echo "Step 1: Loading database config...\n";
    require_once '../../../config/database.php';
    echo "✅ Database config loaded\n";
    echo "Connection status: " . ($conn ? "Connected" : "Not connected") . "\n\n";
} catch (Exception $e) {
    echo "❌ ERROR loading database: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
    ob_end_flush();
    exit;
}

try {
    echo "Step 2: Loading auth config...\n";
    require_once '../../../config/auth.php';
    echo "✅ Auth config loaded\n\n";
} catch (Exception $e) {
    echo "❌ ERROR loading auth: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
    ob_end_flush();
    exit;
}

try {
    echo "Step 3: Checking if requireLogin() function exists...\n";
    if (function_exists('requireLogin')) {
        echo "✅ requireLogin() function exists\n";
        echo "Step 4: Calling requireLogin()...\n";
        requireLogin();
        echo "✅ requireLogin() completed\n\n";
    } else {
        echo "❌ requireLogin() function does NOT exist\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR in requireLogin(): " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
    ob_end_flush();
    exit;
}

try {
    echo "Step 5: Checking if hasRole() function exists...\n";
    if (function_exists('hasRole')) {
        echo "✅ hasRole() function exists\n";
        echo "Step 6: Checking user role...\n";
        
        if (!hasRole(['Resident'])) {
            echo "❌ User is NOT a Resident - would redirect\n";
        } else {
            echo "✅ User has Resident role\n\n";
        }
    } else {
        echo "❌ hasRole() function does NOT exist\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR in hasRole(): " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
    ob_end_flush();
    exit;
}

try {
    echo "Step 7: Checking if getCurrentUserId() function exists...\n";
    if (function_exists('getCurrentUserId')) {
        echo "✅ getCurrentUserId() function exists\n";
        $current_user_id = getCurrentUserId();
        echo "Current User ID: $current_user_id\n\n";
    } else {
        echo "❌ getCurrentUserId() function does NOT exist\n";
        echo "Checking session directly...\n";
        session_start();
        if (isset($_SESSION['user_id'])) {
            $current_user_id = $_SESSION['user_id'];
            echo "User ID from session: $current_user_id\n\n";
        } else {
            echo "❌ No user_id in session\n";
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR getting user ID: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
    ob_end_flush();
    exit;
}

try {
    echo "Step 8: Preparing database query...\n";
    $stmt = $conn->prepare("
        SELECT ja.*, j.job_title, j.job_type, j.location, c.company_name, c.company_logo
        FROM tbl_job_applications ja
        INNER JOIN tbl_jobs j ON ja.job_id = j.job_id
        LEFT JOIN tbl_companies c ON j.company_id = c.company_id
        WHERE ja.applicant_id = ?
        ORDER BY ja.application_date DESC
    ");
    
    if (!$stmt) {
        echo "❌ Prepare failed: " . $conn->error . "\n";
    } else {
        echo "✅ Statement prepared\n";
    }
    
    echo "Step 9: Binding parameters...\n";
    $stmt->bind_param("i", $current_user_id);
    echo "✅ Parameters bound\n";
    
    echo "Step 10: Executing query...\n";
    if (!$stmt->execute()) {
        echo "❌ Execute failed: " . $stmt->error . "\n";
    } else {
        echo "✅ Query executed\n";
    }
    
    echo "Step 11: Getting results...\n";
    $applications = $stmt->get_result();
    echo "✅ Results retrieved\n";
    echo "Number of applications: " . $applications->num_rows . "\n\n";
    
} catch (Exception $e) {
    echo "❌ ERROR in database query: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
    ob_end_flush();
    exit;
}

try {
    echo "Step 12: Checking header file...\n";
    $header_path = '../../../includes/header.php';
    if (file_exists($header_path)) {
        echo "✅ Header file exists at: $header_path\n";
        echo "File size: " . filesize($header_path) . " bytes\n";
    } else {
        echo "❌ Header file NOT FOUND at: $header_path\n";
        echo "Looking for it...\n";
        
        // Try to find it
        $possible_paths = [
            '../../../includes/header.php',
            '../../includes/header.php',
            '../includes/header.php',
            'includes/header.php',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                echo "FOUND at: $path\n";
            }
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR checking header: " . $e->getMessage() . "\n";
}

echo "\n=== ALL CHECKS COMPLETED ===\n";
echo "\nIf all checks passed, the issue might be:\n";
echo "1. The header.php file has an error\n";
echo "2. The header.php file has output buffering issues\n";
echo "3. There's a redirect happening in header.php\n";

echo "</pre></body></html>";
ob_end_flush();
?>