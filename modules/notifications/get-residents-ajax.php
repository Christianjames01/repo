<?php
/**
 * Get Residents AJAX - modules/notifications/get-residents-ajax.php
 * Production version
 */

@ini_set('display_errors', 0);
@error_reporting(0);

while (ob_get_level()) {
    ob_end_clean();
}

ob_start();

$response = ['error' => false, 'data' => []];

try {
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection failed');
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }

    $user_id = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Database prepare failed');
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception('User not found');
    }

    // Allow all staff roles (same list used across the system)
    $staff_roles = ['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod'];
    if (!in_array($user['role'], $staff_roles)) {
        throw new Exception('Access denied');
    }

    $result = $conn->query("
        SELECT 
            resident_id, 
            CONCAT(first_name, ' ', last_name) AS name, 
            email 
        FROM tbl_residents 
        WHERE email IS NOT NULL 
          AND TRIM(email) != '' 
        ORDER BY first_name, last_name
    ");

    if (!$result) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $residents = [];
    while ($row = $result->fetch_assoc()) {
        $residents[] = [
            'id'    => (int)$row['resident_id'],
            'name'  => $row['name'],
            'email' => $row['email']
        ];
    }

    $response['data']    = $residents;
    $response['count']   = count($residents);
    $response['success'] = true;

} catch (Exception $e) {
    $response['error']   = true;
    $response['message'] = $e->getMessage();
    $response['success'] = false;
}

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

echo json_encode($response);
exit;