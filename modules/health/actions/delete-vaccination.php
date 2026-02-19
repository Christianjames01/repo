// ========================================
// FILE: modules/health/actions/delete-vaccination.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if (isset($_GET['id'])) {
    $vaccination_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM tbl_vaccination_records WHERE vaccination_id = ?");
    $stmt->bind_param("i", $vaccination_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Vaccination record deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Error deleting vaccination record.";
    }
    $stmt->close();
}

header("Location: ../vaccinations.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/update-vaccination.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vaccination_id = (int)$_POST['vaccination_id'];
    $vaccine_name = trim($_POST['vaccine_name']);
    $vaccine_type = trim($_POST['vaccine_type']);
    $vaccination_date = $_POST['vaccination_date'];
    $dose_number = (int)$_POST['dose_number'];
    $total_doses = (int)$_POST['total_doses'];
    $next_dose_date = !empty($_POST['next_dose_date']) ? $_POST['next_dose_date'] : null;
    $vaccine_brand = trim($_POST['vaccine_brand']);
    $batch_number = trim($_POST['batch_number']);
    $administered_by = trim($_POST['administered_by']);
    $vaccination_site = trim($_POST['vaccination_site']);
    $side_effects = trim($_POST['side_effects']);
    $remarks = trim($_POST['remarks']);
    
    $stmt = $conn->prepare("UPDATE tbl_vaccination_records SET 
        vaccine_name = ?, vaccine_type = ?, vaccination_date = ?, dose_number = ?, 
        total_doses = ?, next_dose_date = ?, vaccine_brand = ?, batch_number = ?, 
        administered_by = ?, vaccination_site = ?, side_effects = ?, remarks = ?
        WHERE vaccination_id = ?");
    
    $stmt->bind_param("sssiiisssssi", 
        $vaccine_name, $vaccine_type, $vaccination_date, $dose_number, $total_doses,
        $next_dose_date, $vaccine_brand, $batch_number, $administered_by, 
        $vaccination_site, $side_effects, $remarks, $vaccination_id
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Vaccination record updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating vaccination record.";
    }
    $stmt->close();
}

header("Location: ../vaccinations.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/update-appointment-status.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_id = (int)$_POST['appointment_id'];
    $status = $_POST['status'];
    $attended_by = isset($_POST['attended_by']) ? trim($_POST['attended_by']) : null;
    $diagnosis = isset($_POST['diagnosis']) ? trim($_POST['diagnosis']) : null;
    $prescription = isset($_POST['prescription']) ? trim($_POST['prescription']) : null;
    $follow_up_date = isset($_POST['follow_up_date']) && !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $cancelled_reason = isset($_POST['cancelled_reason']) ? trim($_POST['cancelled_reason']) : null;
    
    if ($status === 'Completed') {
        $stmt = $conn->prepare("UPDATE tbl_health_appointments SET 
            status = ?, attended_by = ?, diagnosis = ?, prescription = ?, 
            follow_up_date = ?, notes = ?
            WHERE appointment_id = ?");
        $stmt->bind_param("ssssssi", $status, $attended_by, $diagnosis, $prescription, 
            $follow_up_date, $notes, $appointment_id);
    } elseif ($status === 'Cancelled') {
        $stmt = $conn->prepare("UPDATE tbl_health_appointments SET 
            status = ?, cancelled_reason = ?, notes = ?
            WHERE appointment_id = ?");
        $stmt->bind_param("sssi", $status, $cancelled_reason, $notes, $appointment_id);
    } else {
        $stmt = $conn->prepare("UPDATE tbl_health_appointments SET 
            status = ?, notes = CONCAT(IFNULL(notes, ''), ?)
            WHERE appointment_id = ?");
        $note_text = $notes ? "\n[" . date('Y-m-d H:i') . "] " . $notes : '';
        $stmt->bind_param("ssi", $status, $note_text, $appointment_id);
    }
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Appointment status updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating appointment status.";
    }
    $stmt->close();
}

header("Location: ../appointments.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/delete-appointment.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if (isset($_GET['id'])) {
    $appointment_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM tbl_health_appointments WHERE appointment_id = ?");
    $stmt->bind_param("i", $appointment_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Appointment deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Error deleting appointment.";
    }
    $stmt->close();
}

header("Location: ../appointments.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/update-disease-case.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $surveillance_id = (int)$_POST['surveillance_id'];
    $outcome = $_POST['outcome'];
    $outcome_date = !empty($_POST['outcome_date']) ? $_POST['outcome_date'] : null;
    $quarantine_status = $_POST['quarantine_status'];
    $quarantine_end_date = !empty($_POST['quarantine_end_date']) ? $_POST['quarantine_end_date'] : null;
    $contacts_traced = (int)$_POST['contacts_traced'];
    $remarks = trim($_POST['remarks']);
    $verified_by = getCurrentUserId();
    
    $stmt = $conn->prepare("UPDATE tbl_disease_surveillance SET 
        outcome = ?, outcome_date = ?, quarantine_status = ?, quarantine_end_date = ?,
        contacts_traced = ?, remarks = ?, verified_by = ?
        WHERE surveillance_id = ?");
    
    $stmt->bind_param("ssssissi", 
        $outcome, $outcome_date, $quarantine_status, $quarantine_end_date,
        $contacts_traced, $remarks, $verified_by, $surveillance_id
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease case updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating disease case.";
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/verify-disease-case.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if (isset($_GET['id'])) {
    $surveillance_id = (int)$_GET['id'];
    $verified_by = getCurrentUserId();
    
    $stmt = $conn->prepare("UPDATE tbl_disease_surveillance SET 
        confirmed = 1, verified_by = ?
        WHERE surveillance_id = ?");
    $stmt->bind_param("ii", $verified_by, $surveillance_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease case verified successfully!";
    } else {
        $_SESSION['error_message'] = "Error verifying disease case.";
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/delete-disease-case.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if (isset($_GET['id'])) {
    $surveillance_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM tbl_disease_surveillance WHERE surveillance_id = ?");
    $stmt->bind_param("i", $surveillance_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease case deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Error deleting disease case.";
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>