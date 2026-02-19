<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    
    // Get form data
    $assistance_id = (int)$_POST['assistance_id'];
    $amount_approved = floatval($_POST['amount_approved']);
    $remarks = trim($_POST['remarks']);
    $review_date = date('Y-m-d');
    
    // Validation
    if (!$assistance_id) {
        $_SESSION['error'] = "Invalid assistance request.";
        header('Location: ../approve-assistance.php');
        exit();
    }
    
    if ($amount_approved <= 0) {
        $_SESSION['error'] = "Amount approved must be greater than 0.";
        header('Location: ../approve-assistance.php');
        exit();
    }
    
    // Check if request exists and is pending
    $stmt = $conn->prepare("SELECT status, amount_requested FROM tbl_medical_assistance WHERE assistance_id = ?");
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
    
    // Check if approved amount exceeds requested amount
    if ($amount_approved > $request['amount_requested']) {
        $_SESSION['warning'] = "Warning: Approved amount exceeds requested amount.";
    }
    
    // Update assistance request
    $sql = "UPDATE tbl_medical_assistance SET
                status = 'Approved',
                amount_approved = ?,
                remarks = ?,
                reviewed_by = ?,
                review_date = ?
            WHERE assistance_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dsisi", $amount_approved, $remarks, $user_id, $review_date, $assistance_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Medical assistance request approved successfully!";
    } else {
        $_SESSION['error'] = "Failed to approve request. Please try again.";
    }
    
    $stmt->close();
    header('Location: ../approve-assistance.php');
    exit();
} else {
    header('Location: ../approve-assistance.php');
    exit();
}
?>