<?php
/**
 * Get Residents AJAX - modules/notifications/get-residents-ajax.php
 * Production version
 */

// Suppress all errors from displaying
@ini_set('display_errors', 0);
@error_reporting(0);

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start fresh buffer
ob_start();

// Response container
$response = ['error' => false, 'data' => []];

try {
    // Include database connection
    require_once __DIR__ . '/../../config/database.php';
    
    // Check if database connection exists
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }
    
    $user_id = (int)$_SESSION['user_id'];
    
    // Get user role from database
    $stmt = $conn->prepare("SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1");
    
    if (!$stmt) {
        throw new Exception('Database prepare failed');
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Check if user exists
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Check if user has Super Administrator role
    if ($user['role'] !== 'Super Administrator') {
        throw new Exception('Access denied - role: ' . $user['role']);
    }
    
    // Query to get all residents with valid email addresses
    $query = "SELECT 
                resident_id, 
                CONCAT(first_name, ' ', last_name) as name, 
                email 
              FROM tbl_residents 
              WHERE email IS NOT NULL 
                AND email != '' 
              ORDER BY first_name, last_name";
    
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception('Query failed: ' . $conn->error);
    }
    
    $residents = [];
    
    while ($row = $result->fetch_assoc()) {
        $residents[] = [
            'id' => (int)$row['resident_id'],
            'name' => $row['name'],
            'email' => $row['email']
        ];
    }
    
    $response['data'] = $residents;
    $response['count'] = count($residents);
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = true;
    $response['message'] = $e->getMessage();
    $response['success'] = false;
}

// Clear the output buffer
ob_end_clean();

// Set proper JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Output JSON and terminate
echo json_encode($response);
exit;