<?php
// modules/health/actions/cancel-appointment.php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Resident']);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method.";
    header("Location: ../book-appointment.php");
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';
$user_id = $_SESSION['user_id'];

if (!$appointment_id) {
    $_SESSION['error_message'] = "Invalid appointment ID.";
    header("Location: ../book-appointment.php");
    exit;
}

// Get resident ID
$stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$resident_id = $result['resident_id'];
$stmt->close();

if (!$resident_id) {
    $_SESSION['error_message'] = "Resident profile not found.";
    header("Location: ../book-appointment.php");
    exit;
}

// Verify appointment belongs to this resident and can be cancelled
$stmt = $conn->prepare("
    SELECT appointment_id, status, appointment_date, appointment_time, appointment_type 
    FROM tbl_health_appointments 
    WHERE appointment_id = ? AND resident_id = ?
");
$stmt->bind_param("ii", $appointment_id, $resident_id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    $_SESSION['error_message'] = "Appointment not found or you don't have permission to cancel it.";
    header("Location: ../book-appointment.php");
    exit;
}

// Check if appointment can be cancelled (only Scheduled or Confirmed)
if (!in_array($appointment['status'], ['Scheduled', 'Confirmed'])) {
    $_SESSION['error_message'] = "This appointment cannot be cancelled. Current status: {$appointment['status']}.";
    header("Location: ../book-appointment.php");
    exit;
}

// Check if cancelling too late (less than 2 hours before appointment)
$appointment_datetime = new DateTime($appointment['appointment_date'] . ' ' . $appointment['appointment_time']);
$now = new DateTime();
$hours_until = ($appointment_datetime->getTimestamp() - $now->getTimestamp()) / 3600;

if ($hours_until < 2 && $hours_until > 0) {
    $_SESSION['warning_message'] = "Note: Cancelling within 2 hours of your appointment time may affect future bookings.";
}

// Update appointment status to Cancelled
$stmt = $conn->prepare("
    UPDATE tbl_health_appointments 
    SET status = 'Cancelled',
        notes = CONCAT(IFNULL(notes, ''), ?)
    WHERE appointment_id = ?
");

$cancellation_note = "\n[" . date('Y-m-d H:i') . "] Cancelled by resident" . 
                     ($cancellation_reason ? ": " . $cancellation_reason : '');
$stmt->bind_param("si", $cancellation_note, $appointment_id);

if ($stmt->execute()) {
    // Log the activity
    if (tableExists($conn, 'tbl_activity_logs')) {
        $log_stmt = $conn->prepare("
            INSERT INTO tbl_activity_logs (user_id, action, description, created_at) 
            VALUES (?, 'appointment_cancelled', ?, NOW())
        ");
        $description = "Cancelled appointment #{$appointment_id} - {$appointment['appointment_type']} on " . 
                      date('M d, Y', strtotime($appointment['appointment_date']));
        $log_stmt->bind_param("is", $user_id, $description);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    $_SESSION['success_message'] = "Appointment cancelled successfully. Your appointment for " . 
                                  date('F j, Y', strtotime($appointment['appointment_date'])) . " at " . 
                                  date('g:i A', strtotime($appointment['appointment_time'])) . " has been cancelled.";
} else {
    $_SESSION['error_message'] = "Error cancelling appointment. Please try again or contact the health center.";
}

$stmt->close();
header("Location: ../book-appointment.php");
exit;

// Helper function
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}
?>