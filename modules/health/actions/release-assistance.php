<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$assistance_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$assistance_id) {
    $_SESSION['error'] = "Invalid assistance request.";
    header('Location: ../approve-assistance.php');
    exit();
}

// Check if request exists and is approved
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

if ($request['status'] !== 'Approved') {
    $_SESSION['error'] = "Only approved requests can be marked as released.";
    header('Location: ../approve-assistance.php');
    exit();
}

// Update status to Released
$stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status = 'Released' WHERE assistance_id = ?");
$stmt->bind_param("i", $assistance_id);

if ($stmt->execute()) {
    $_SESSION['success'] = "Medical assistance marked as released.";
} else {
    $_SESSION['error'] = "Failed to update status. Please try again.";
}

$stmt->close();
header('Location: ../approve-assistance.php');
exit();
?>