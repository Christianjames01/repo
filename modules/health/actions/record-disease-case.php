<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_id = !empty($_POST['resident_id']) ? (int)$_POST['resident_id'] : null;
    $disease_name = trim($_POST['disease_name']);
    $disease_category = trim($_POST['disease_category']);
    $symptoms = trim($_POST['symptoms']);
    $onset_date = $_POST['onset_date'];
    $diagnosis_date = !empty($_POST['diagnosis_date']) ? $_POST['diagnosis_date'] : null;
    $confirmed = isset($_POST['confirmed']) ? 1 : 0;
    $severity = $_POST['severity'];
    $hospitalized = isset($_POST['hospitalized']) ? 1 : 0;
    $hospital_name = trim($_POST['hospital_name']);
    $admission_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : null;
    $discharge_date = !empty($_POST['discharge_date']) ? $_POST['discharge_date'] : null;
    $outcome = !empty($_POST['outcome']) ? $_POST['outcome'] : null;
    $outcome_date = !empty($_POST['outcome_date']) ? $_POST['outcome_date'] : null;
    $contacts_traced = (int)$_POST['contacts_traced'];
    $quarantine_status = $_POST['quarantine_status'];
    $quarantine_start_date = !empty($_POST['quarantine_start_date']) ? $_POST['quarantine_start_date'] : null;
    $quarantine_end_date = !empty($_POST['quarantine_end_date']) ? $_POST['quarantine_end_date'] : null;
    $remarks = trim($_POST['remarks']);
    $reported_by = getCurrentUserId();
    $reported_date = date('Y-m-d');
    
    $stmt = $conn->prepare("INSERT INTO tbl_disease_surveillance 
        (resident_id, disease_name, disease_category, symptoms, onset_date, diagnosis_date, 
         confirmed, severity, hospitalized, hospital_name, admission_date, discharge_date, 
         outcome, outcome_date, contacts_traced, quarantine_status, quarantine_start_date, 
         quarantine_end_date, reported_by, reported_date, remarks) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("isssssissssssisssiss", 
        $resident_id, $disease_name, $disease_category, $symptoms, $onset_date, $diagnosis_date,
        $confirmed, $severity, $hospitalized, $hospital_name, $admission_date, $discharge_date,
        $outcome, $outcome_date, $contacts_traced, $quarantine_status, $quarantine_start_date,
        $quarantine_end_date, $reported_by, $reported_date, $remarks
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Disease case recorded successfully!";
    } else {
        $_SESSION['error_message'] = "Error recording disease case: " . $stmt->error;
    }
    $stmt->close();
}

header("Location: ../disease-surveillance.php");
exit;
?>