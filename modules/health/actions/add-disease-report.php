<?php
require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../config/session.php';
require_once '../../../includes/security.php';
require_once '../../../includes/functions.php';

requireLogin();
requireAnyRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $created_by = getCurrentUserId();
    
    $stmt = $conn->prepare("INSERT INTO tbl_disease_surveillance 
        (disease_name, report_date, location, affected_count, age_group, severity, status, reported_by, symptoms, actions_taken, remarks, created_by, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stmt->bind_param("sssisssssssi", 
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
        $created_by
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease surveillance report added successfully!";
        
        // Log activity
        if (function_exists('logActivity')) {
            $report_id = $stmt->insert_id;
            logActivity($conn, $created_by, "Added disease surveillance report: $disease_name", 'tbl_disease_surveillance', $report_id);
        }
    } else {
        $_SESSION['error_message'] = "Error adding disease report: " . $conn->error;
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>