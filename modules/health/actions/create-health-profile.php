<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Resident']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    
    // Get resident ID
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $resident_id = $result->fetch_assoc()['resident_id'];
    $stmt->close();
    
    // Check if profile already exists
    $stmt = $conn->prepare("SELECT record_id FROM tbl_health_records WHERE resident_id = ?");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    if ($exists) {
        $_SESSION['error'] = "Health profile already exists. Please update instead.";
        header('Location: ../my-health.php');
        exit();
    }
    
    // Get form data
    $blood_type = trim($_POST['blood_type']);
    $height = !empty($_POST['height']) ? floatval($_POST['height']) : null;
    $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : null;
    $last_checkup_date = !empty($_POST['last_checkup_date']) ? $_POST['last_checkup_date'] : null;
    $medical_conditions = trim($_POST['medical_conditions']);
    $allergies = trim($_POST['allergies']);
    $current_medications = trim($_POST['current_medications']);
    $emergency_contact_name = trim($_POST['emergency_contact_name']);
    $emergency_contact_number = trim($_POST['emergency_contact_number']);
    $philhealth_number = trim($_POST['philhealth_number']);
    $pwd_id = trim($_POST['pwd_id']);
    $senior_citizen_id = trim($_POST['senior_citizen_id']);
    
    // Validation
    if (empty($blood_type)) {
        $_SESSION['error'] = "Blood type is required.";
        header('Location: ../my-health.php');
        exit();
    }
    
    // Insert health profile
    $sql = "INSERT INTO tbl_health_records (
                resident_id, 
                blood_type, 
                height, 
                weight, 
                last_checkup_date,
                allergies, 
                medical_conditions, 
                current_medications, 
                emergency_contact_name, 
                emergency_contact_number,
                philhealth_number,
                pwd_id,
                senior_citizen_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "isddsssssssss",
        $resident_id,
        $blood_type,
        $height,
        $weight,
        $last_checkup_date,
        $allergies,
        $medical_conditions,
        $current_medications,
        $emergency_contact_name,
        $emergency_contact_number,
        $philhealth_number,
        $pwd_id,
        $senior_citizen_id
    );
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Health profile created successfully!";
    } else {
        $_SESSION['error'] = "Failed to create health profile. Please try again.";
    }
    
    $stmt->close();
    header('Location: ../my-health.php');
    exit();
} else {
    header('Location: ../my-health.php');
    exit();
}
?>