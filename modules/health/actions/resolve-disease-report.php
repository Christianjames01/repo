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
    
    // Update the status to Resolved
    $stmt = $conn->prepare("UPDATE tbl_disease_surveillance SET status = 'Resolved', updated_at = NOW() WHERE surveillance_id = ?");
    $stmt->bind_param("i", $surveillance_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease report has been marked as resolved successfully!";
        
        // Log activity
        if (function_exists('logActivity')) {
            $current_user_id = getCurrentUserId();
            logActivity($conn, $current_user_id, "Marked disease report #$surveillance_id as resolved", 'tbl_disease_surveillance', $surveillance_id);
        }
    } else {
        $_SESSION['error_message'] = "Error resolving disease report: " . $conn->error;
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>