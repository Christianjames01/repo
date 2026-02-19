<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Resident']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../book-appointment.php");
    exit;
}

$user_id = $_SESSION['user_id'];

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

// Get form data
$appointment_type = trim($_POST['appointment_type']);
$appointment_date = $_POST['appointment_date'];
$appointment_time = $_POST['appointment_time'];
$purpose = trim($_POST['purpose']);
$symptoms = isset($_POST['symptoms']) ? trim($_POST['symptoms']) : null;
$contact_number = trim($_POST['contact_number']);
$special_instructions = isset($_POST['special_instructions']) ? trim($_POST['special_instructions']) : null;

// Validate date (must be at least 1 day in advance)
$selected_date = new DateTime($appointment_date);
$tomorrow = new DateTime('tomorrow');
$tomorrow->setTime(0, 0, 0);

if ($selected_date < $tomorrow) {
    $_SESSION['error_message'] = "Appointments must be booked at least 1 day in advance.";
    header("Location: ../book-appointment.php");
    exit;
}

// Check if time slot is already taken
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM tbl_health_appointments 
    WHERE appointment_date = ? 
    AND appointment_time = ? 
    AND status IN ('Scheduled', 'Confirmed')
");
$stmt->bind_param("ss", $appointment_date, $appointment_time);
$stmt->execute();
$slot_check = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Allow up to 3 appointments per time slot
if ($slot_check['count'] >= 3) {
    $_SESSION['error_message'] = "This time slot is fully booked. Please select another time.";
    header("Location: ../book-appointment.php");
    exit;
}

// Insert appointment into tbl_health_appointments
try {
    $stmt = $conn->prepare("
        INSERT INTO tbl_health_appointments (
            resident_id,
            appointment_type,
            appointment_date,
            appointment_time,
            purpose,
            symptoms,
            contact_number,
            special_instructions,
            status,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled', ?, NOW())
    ");
    
    $stmt->bind_param(
        "isssssssi",
        $resident_id,
        $appointment_type,
        $appointment_date,
        $appointment_time,
        $purpose,
        $symptoms,
        $contact_number,
        $special_instructions,
        $user_id
    );
    
    if ($stmt->execute()) {
        $appointment_id = $stmt->insert_id;
        
        // Log the activity
        $log_stmt = $conn->prepare("
            INSERT INTO tbl_activity_logs (user_id, action, description, created_at) 
            VALUES (?, 'appointment_booked', ?, NOW())
        ");
        $description = "Appointment booked for {$appointment_date} at {$appointment_time}";
        $log_stmt->bind_param("is", $user_id, $description);
        $log_stmt->execute();
        $log_stmt->close();
        
        $_SESSION['success_message'] = "Appointment booked successfully! Your appointment is scheduled for " . 
                                      date('F j, Y', strtotime($appointment_date)) . " at " . 
                                      date('g:i A', strtotime($appointment_time)) . 
                                      ". Please wait for confirmation.";
    } else {
        $_SESSION['error_message'] = "Error booking appointment. Please try again.";
    }
    
    $stmt->close();
} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

header("Location: ../book-appointment.php");
exit;
?>