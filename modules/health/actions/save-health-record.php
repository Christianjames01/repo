<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$resident_id = isset($_POST['resident_id']) ? (int)$_POST['resident_id'] : 0;

if (!$resident_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid resident ID']);
    exit;
}

// Verify resident exists
$stmt = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Resident not found']);
    exit;
}
$stmt->close();

// Prepare data
$blood_type = !empty($_POST['blood_type']) ? trim($_POST['blood_type']) : null;
$height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
$weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
$last_checkup_date = !empty($_POST['last_checkup_date']) ? $_POST['last_checkup_date'] : null;
$philhealth_number = !empty($_POST['philhealth_number']) ? trim($_POST['philhealth_number']) : null;
$pwd_id = !empty($_POST['pwd_id']) ? trim($_POST['pwd_id']) : null;
$senior_citizen_id = !empty($_POST['senior_citizen_id']) ? trim($_POST['senior_citizen_id']) : null;
$allergies = !empty($_POST['allergies']) ? trim($_POST['allergies']) : null;
$medical_conditions = !empty($_POST['medical_conditions']) ? trim($_POST['medical_conditions']) : null;
$current_medications = !empty($_POST['current_medications']) ? trim($_POST['current_medications']) : null;
$notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

// Check if health record exists
$stmt = $conn->prepare("SELECT record_id FROM tbl_health_records WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$existing_record = $stmt->get_result()->fetch_assoc();
$stmt->close();

try {
    if ($existing_record) {
        // Update existing record
        $sql = "UPDATE tbl_health_records SET 
                blood_type = ?,
                height = ?,
                weight = ?,
                last_checkup_date = ?,
                philhealth_number = ?,
                pwd_id = ?,
                senior_citizen_id = ?,
                allergies = ?,
                medical_conditions = ?,
                current_medications = ?,
                notes = ?,
                updated_at = NOW()
                WHERE resident_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sddssssssssi", 
            $blood_type, $height, $weight, $last_checkup_date,
            $philhealth_number, $pwd_id, $senior_citizen_id,
            $allergies, $medical_conditions, $current_medications, $notes,
            $resident_id
        );
    } else {
        // Insert new record
        $sql = "INSERT INTO tbl_health_records (
                    resident_id, blood_type, height, weight, last_checkup_date,
                    philhealth_number, pwd_id, senior_citizen_id,
                    allergies, medical_conditions, current_medications, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isddssssssss",
            $resident_id, $blood_type, $height, $weight, $last_checkup_date,
            $philhealth_number, $pwd_id, $senior_citizen_id,
            $allergies, $medical_conditions, $current_medications, $notes
        );
    }
    
    if ($stmt->execute()) {
        // Log the action
        $action = $existing_record ? 'updated' : 'created';
        $log_sql = "INSERT INTO tbl_activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $description = "Health record {$action} for resident ID: {$resident_id}";
        $log_stmt->bind_param("iss", $_SESSION['user_id'], $action, $description);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Health record saved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving health record']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>