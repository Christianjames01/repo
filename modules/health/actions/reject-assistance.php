<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    
    // Get form data
    $assistance_id = (int)$_POST['assistance_id'];
    $rejection_reason = trim($_POST['rejection_reason']);
    $review_date = date('Y-m-d');
    
    // Validation
    if (!$assistance_id) {
        $_SESSION['error'] = "Invalid assistance request.";
        header('Location: ../approve-assistance.php');
        exit();
    }
    
    if (empty($rejection_reason)) {
        $_SESSION['error'] = "Rejection reason is required.";
        header('Location: ../approve-assistance.php');
        exit();
    }
    
    // Check if request exists and is pending
    $stmt = $conn->prepare("SELECT status FROM tbl_medical_assistance WHERE assistance_id = ?");
    $stmt->bind_param("i", $assistance_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error'] = "Assistance request not found.";
        $stmt->close();
        header('Location: ../approve-assistance.php');
        exit();
    }
    
    $request = $result->fetch_assoc();
    $stmt->close();
    
    if ($request['status'] !== 'Pending') {
        $_SESSION['error'] = "This request has already been processed.";
        header('Location: ../approve-assistance.php');
        exit();
    }
    
    // Update assistance request
    $sql = "UPDATE tbl_medical_assistance SET
                status = 'Rejected',
                remarks = ?,
                reviewed_by = ?,
                review_date = ?
            WHERE assistance_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisi", $rejection_reason, $user_id, $review_date, $assistance_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Medical assistance request rejected.";
    } else {
        $_SESSION['error'] = "Failed to reject request. Please try again.";
    }
    
    $stmt->close();
    header('Location: ../approve-assistance.php');
    exit();
} else {
    header('Location: ../approve-assistance.php');
    exit();
}
?>