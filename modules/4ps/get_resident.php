<?php
// Include config which handles session, database, and functions
require_once __DIR__ . '/../../config/config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and is Super Admin
if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if ID parameter is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Resident ID is required']);
    exit();
}

$resident_id = intval($_GET['id']);

// Fetch resident data - using correct column names from tbl_residents
$query = "SELECT 
    r.resident_id,
    r.first_name,
    r.last_name,
    r.middle_name,
    r.ext_name,
    r.email,
    COALESCE(r.contact_number, r.contact_no, r.phone, '') as contact_no,
    COALESCE(r.birthdate, r.date_of_birth) as birthdate,
    COALESCE(r.gender, '') as gender,
    COALESCE(r.civil_status, '') as civil_status,
    COALESCE(r.permanent_address, r.address, '') as permanent_address,
    COALESCE(r.street, '') as street,
    COALESCE(r.barangay, '') as barangay,
    COALESCE(r.town, r.city, '') as town,
    COALESCE(r.city, r.town, '') as city,
    COALESCE(r.province, '') as province,
    COALESCE(r.birthplace, '') as birthplace,
    COALESCE(r.address, r.permanent_address, '') as address
FROM tbl_residents r
WHERE r.resident_id = ? AND r.is_verified = 1";

$stmt = $conn->prepare($query);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $resident_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Resident not found or not verified']);
    $stmt->close();
    exit();
}

$resident = $result->fetch_assoc();
$stmt->close();

// Log the retrieved data for debugging
error_log("Retrieved resident data for ID $resident_id: " . json_encode($resident));

// Return success response with resident data
echo json_encode([
    'success' => true,
    'message' => 'Resident data retrieved successfully',
    'data' => $resident
]);
?>