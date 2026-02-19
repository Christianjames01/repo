<?php
require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../config/session.php';
require_once '../../../includes/security.php';
require_once '../../../includes/functions.php';

requireLogin();
requireAnyRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vaccination_id = (int)$_POST['vaccination_id'];
    $vaccine_name = trim($_POST['vaccine_name']);
    $vaccine_type = trim($_POST['vaccine_type']);
    $vaccination_date = $_POST['vaccination_date'];
    $dose_number = (int)$_POST['dose_number'];
    $total_doses = (int)$_POST['total_doses'];
    $next_dose_date = !empty($_POST['next_dose_date']) ? $_POST['next_dose_date'] : null;
    $vaccine_brand = trim($_POST['vaccine_brand']);
    $batch_number = trim($_POST['batch_number']);
    $administered_by = trim($_POST['administered_by']);
    $vaccination_site = trim($_POST['vaccination_site']);
    $side_effects = !empty($_POST['side_effects']) ? trim($_POST['side_effects']) : null;
    $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    $stmt = $conn->prepare("UPDATE tbl_vaccination_records SET 
        vaccine_name = ?, 
        vaccine_type = ?, 
        vaccination_date = ?, 
        dose_number = ?, 
        total_doses = ?, 
        next_dose_date = ?, 
        vaccine_brand = ?, 
        batch_number = ?, 
        administered_by = ?, 
        vaccination_site = ?, 
        side_effects = ?, 
        remarks = ?
        WHERE vaccination_id = ?");
    
    $stmt->bind_param("sssiiissssssi", 
        $vaccine_name, 
        $vaccine_type, 
        $vaccination_date, 
        $dose_number, 
        $total_doses,
        $next_dose_date, 
        $vaccine_brand, 
        $batch_number, 
        $administered_by, 
        $vaccination_site, 
        $side_effects, 
        $remarks, 
        $vaccination_id
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Vaccination record updated successfully!";
        
        // Log activity
        if (function_exists('logActivity')) {
            $current_user_id = getCurrentUserId();
            logActivity($conn, $current_user_id, "Updated vaccination record #$vaccination_id", 'tbl_vaccination_records', $vaccination_id);
        }
    } else {
        $_SESSION['error_message'] = "Error updating vaccination record: " . $conn->error;
    }
    $stmt->close();
}

header("Location: ../vaccinations.php");
exit;
?>