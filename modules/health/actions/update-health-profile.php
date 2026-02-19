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
    
    // Update health profile
    $sql = "UPDATE tbl_health_records SET
                blood_type = ?,
                height = ?,
                weight = ?,
                last_checkup_date = ?,
                allergies = ?,
                medical_conditions = ?,
                current_medications = ?,
                emergency_contact_name = ?,
                emergency_contact_number = ?,
                philhealth_number = ?,
                pwd_id = ?,
                senior_citizen_id = ?
            WHERE resident_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sddssssssssi",
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
        $senior_citizen_id,
        $resident_id
    );
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['success'] = "Health profile updated successfully!";
        } else {
            $_SESSION['info'] = "No changes were made to your profile.";
        }
    } else {
        $_SESSION['error'] = "Failed to update health profile. Please try again.";
    }
    
    $stmt->close();
    header('Location: ../my-health.php');
    exit();
} else {
    header('Location: ../my-health.php');
    exit();
}
?>