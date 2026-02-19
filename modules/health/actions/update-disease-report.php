<?php
require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../config/session.php';
require_once '../../../includes/security.php';
require_once '../../../includes/functions.php';

requireLogin();
requireAnyRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $surveillance_id = (int)$_POST['surveillance_id'];
    $disease_name = trim($_POST['disease_name']);
    $report_date = $_POST['report_date'];
    $location = trim($_POST['location']);
    $affected_count = (int)$_POST['affected_count'];
    $age_group = !empty($_POST['age_group']) ? trim($_POST['age_group']) : null;
    $severity = $_POST['severity'];
    $status = $_POST['status'];
    $reported_by = trim($_POST['reported_by']);
    $symptoms = !empty($_POST['symptoms']) ? trim($_POST['symptoms']) : null;
    $actions_taken = !empty($_POST['actions_taken']) ? trim($_POST['actions_taken']) : null;
    $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    $stmt = $conn->prepare("UPDATE tbl_disease_surveillance SET 
        disease_name = ?,
        report_date = ?,
        location = ?,
        affected_count = ?,
        age_group = ?,
        severity = ?,
        status = ?,
        reported_by = ?,
        symptoms = ?,
        actions_taken = ?,
        remarks = ?,
        updated_at = NOW()
        WHERE surveillance_id = ?");
    
    $stmt->bind_param("ssissssssssi",
        $disease_name,
        $report_date,
        $location,
        $affected_count,
        $age_group,
        $severity,
        $status,
        $reported_by,
        $symptoms,
        $actions_taken,
        $remarks,
        $surveillance_id
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease surveillance report updated successfully!";
        
        // Log activity
        if (function_exists('logActivity')) {
            $current_user_id = getCurrentUserId();
            logActivity($conn, $current_user_id, "Updated disease surveillance report #$surveillance_id", 'tbl_disease_surveillance', $surveillance_id);
        }
    } else {
        $_SESSION['error_message'] = "Error updating disease report: " . $conn->error;
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>