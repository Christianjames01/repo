<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Resident']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method.";
    header("Location: ../book-appointment.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get resident_id and name
$stmt = $conn->prepare("
    SELECT u.resident_id, r.first_name, r.last_name 
    FROM tbl_users u
    JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
    $_SESSION['error_message'] = "User not found.";
    header("Location: ../book-appointment.php");
    exit();
}

$resident_id = $user_data['resident_id'];
$resident_name = $user_data['first_name'] . ' ' . $user_data['last_name'];

// Check if we have action and appointment_id
if (!isset($_POST['action']) || $_POST['action'] !== 'cancel_appointment') {
    $_SESSION['error_message'] = "Invalid action.";
    header("Location: ../book-appointment.php");
    exit();
}

if (!isset($_POST['appointment_id'])) {
    $_SESSION['error_message'] = "Appointment ID not provided.";
    header("Location: ../book-appointment.php");
    exit();
}

// Get and validate appointment_id
$appointment_id = intval($_POST['appointment_id']);

if ($appointment_id <= 0) {
    $_SESSION['error_message'] = "Invalid appointment ID.";
    header("Location: ../book-appointment.php");
    exit();
}

$cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';

// Verify appointment belongs to this resident and can be cancelled
$stmt = $conn->prepare("
    SELECT appointment_id, status, appointment_date, appointment_time, appointment_type
    FROM tbl_health_appointments 
    WHERE appointment_id = ? AND resident_id = ?
");
$stmt->bind_param("ii", $appointment_id, $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$appointment = $result->fetch_assoc();
$stmt->close();

if (!$appointment) {
    $_SESSION['error_message'] = "Appointment not found or you don't have permission to cancel it.";
    header("Location: ../book-appointment.php");
    exit();
}

// Check if appointment can be cancelled
if (!in_array($appointment['status'], ['Scheduled', 'Confirmed'])) {
    $_SESSION['error_message'] = "This appointment cannot be cancelled. Current status: " . $appointment['status'];
    header("Location: ../book-appointment.php");
    exit();
}

// Check if appointment is not in the past
$appointment_datetime = strtotime($appointment['appointment_date'] . ' ' . $appointment['appointment_time']);
if ($appointment_datetime < time()) {
    $_SESSION['error_message'] = "Cannot cancel past appointments.";
    header("Location: ../book-appointment.php");
    exit();
}

// Update appointment status to Cancelled
$update_notes = "\n[" . date('Y-m-d H:i') . "] Cancelled by resident";
if (!empty($cancellation_reason)) {
    $update_notes .= ": " . $cancellation_reason;
}

$stmt = $conn->prepare("
    UPDATE tbl_health_appointments 
    SET status = 'Cancelled',
        notes = CONCAT(IFNULL(notes, ''), ?)
    WHERE appointment_id = ? AND resident_id = ?
");
$stmt->bind_param("sii", $update_notes, $appointment_id, $resident_id);

if ($stmt->execute()) {
    // Create notifications for Admin and Staff
    $notification_title = "Appointment Cancelled";
    $notification_message = "$resident_name has cancelled their " . $appointment['appointment_type'] . 
                           " appointment scheduled for " . date('F j, Y', strtotime($appointment['appointment_date'])) .
                           " at " . date('g:i A', strtotime($appointment['appointment_time'])) .
                           ($cancellation_reason ? ". Reason: $cancellation_reason" : ".");
    
    // Get all Admin and Staff users
    $admin_users = $conn->query("
        SELECT user_id FROM tbl_users 
        WHERE role IN ('Admin', 'Staff', 'Super Admin', 'Super Administrator')
    ");
    
    if ($admin_users) {
        while ($admin = $admin_users->fetch_assoc()) {
            createNotification(
                $conn,
                $admin['user_id'],
                $notification_title,
                $notification_message,
                'appointment_cancelled',
                $appointment_id,
                'appointment'
            );
        }
    }
    
    $_SESSION['success_message'] = "Appointment cancelled successfully.";
} else {
    $_SESSION['error_message'] = "Failed to cancel appointment. Please try again.";
}

$stmt->close();
$conn->close();

header("Location: ../book-appointment.php");
exit();
