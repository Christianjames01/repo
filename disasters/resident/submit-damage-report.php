<?php
/**
 * Submit Damage Report Handler
 * Path: barangaylink/disasters/resident/submit-damage-report.php
 */

require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and is a resident
if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if ($user_role !== 'Resident') {
    setMessage('Access Denied: This page is for residents only.', 'error');
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_id = $_SESSION['resident_id'] ?? null;
    
    if (!$resident_id) {
        setMessage('Error: Resident ID not found in session.', 'error');
        header('Location: index.php');
        exit();
    }
    
    $disaster_type = sanitizeInput($_POST['disaster_type']);
    $assessment_date = sanitizeInput($_POST['assessment_date']);
    $location = sanitizeInput($_POST['location']);
    $damage_type = sanitizeInput($_POST['damage_type']);
    $severity = sanitizeInput($_POST['severity']);
    $estimated_cost = !empty($_POST['estimated_cost']) ? sanitizeInput($_POST['estimated_cost']) : 0;
    $description = sanitizeInput($_POST['description']);
    
    // Insert damage report with Pending status
    $sql = "INSERT INTO tbl_damage_assessments 
            (resident_id, disaster_type, assessment_date, location, damage_type, 
             severity, estimated_cost, description, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
    
    if (executeQuery($conn, $sql, 
        [$resident_id, $disaster_type, $assessment_date, $location, $damage_type, 
         $severity, $estimated_cost, $description],
        'isssssds')) {
        
        logActivity($conn, getCurrentUserId(), "Submitted damage report for disaster type: $disaster_type");
        
        // Notify admin/staff about new damage report
        $admin_users = fetchAll($conn, "
            SELECT u.user_id 
            FROM tbl_users u 
            INNER JOIN tbl_roles r ON u.role_id = r.role_id 
            WHERE r.role_name IN ('Super Admin', 'Admin', 'Staff', 'Secretary')
        ");
        
        foreach ($admin_users as $admin) {
            createNotification(
                $conn, 
                $admin['user_id'], 
                'New Damage Report', 
                "A resident has submitted a damage report for $disaster_type", 
                'warning',
                '/barangaylink/disasters/damage-assessment.php'
            );
        }
        
        setMessage('Damage report submitted successfully. Our team will assess your report shortly.', 'success');
    } else {
        setMessage('Failed to submit damage report. Please try again.', 'error');
    }
    
    header('Location: index.php');
    exit();
} else {
    header('Location: index.php');
    exit();
}
?>