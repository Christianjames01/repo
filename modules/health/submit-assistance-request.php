<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Resident']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../request-assistance.php");
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
    header("Location: ../request-assistance.php");
    exit;
}

// Get form data
$request_type = trim($_POST['request_type']);
$priority = trim($_POST['priority']);
$diagnosis = trim($_POST['diagnosis']);
$requested_assistance = trim($_POST['requested_assistance']);
$estimated_amount = (float)$_POST['estimated_amount'];
$hospital_name = isset($_POST['hospital_name']) ? trim($_POST['hospital_name']) : null;
$additional_notes = isset($_POST['additional_notes']) ? trim($_POST['additional_notes']) : null;

// Validate required fields
if (empty($request_type) || empty($priority) || empty($diagnosis) || empty($requested_assistance) || $estimated_amount <= 0) {
    $_SESSION['error_message'] = "Please fill in all required fields.";
    header("Location: ../request-assistance.php");
    exit;
}

// Handle file uploads
$uploaded_files = [];
if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
    $upload_dir = '../../../uploads/medical_assistance/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $total_files = count($_FILES['documents']['name']);
    
    for ($i = 0; $i < $total_files; $i++) {
        if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['documents']['name'][$i];
            $file_tmp = $_FILES['documents']['tmp_name'][$i];
            $file_size = $_FILES['documents']['size'][$i];
            
            // Check file size (5MB max)
            if ($file_size > 5 * 1024 * 1024) {
                $_SESSION['error_message'] = "File {$file_name} is too large. Maximum size is 5MB.";
                header("Location: ../request-assistance.php");
                exit;
            }
            
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (!in_array($file_ext, $allowed_extensions)) {
                $_SESSION['error_message'] = "Invalid file type for {$file_name}. Only PDF, JPG, and PNG files are allowed.";
                header("Location: ../request-assistance.php");
                exit;
            }
            
            // Generate unique filename
            $new_filename = 'medical_' . $resident_id . '_' . time() . '_' . $i . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($file_tmp, $destination)) {
                $uploaded_files[] = $new_filename;
            }
        }
    }
}

$documents_json = !empty($uploaded_files) ? json_encode($uploaded_files) : null;

// Insert request into database
try {
    $stmt = $conn->prepare("
        INSERT INTO tbl_medical_assistance (
            resident_id,
            request_type,
            priority,
            diagnosis,
            requested_assistance,
            estimated_amount,
            hospital_name,
            additional_notes,
            documents,
            status,
            request_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', CURDATE())
    ");
    
    $stmt->bind_param(
        "issssdss",
        $resident_id,
        $request_type,
        $priority,
        $diagnosis,
        $requested_assistance,
        $estimated_amount,
        $hospital_name,
        $additional_notes,
        $documents_json
    );
    
    if ($stmt->execute()) {
        $assistance_id = $stmt->insert_id;
        
        // Log the activity
        $log_stmt = $conn->prepare("
            INSERT INTO tbl_activity_logs (user_id, action, description, created_at) 
            VALUES (?, 'medical_assistance_request', ?, NOW())
        ");
        $description = "Submitted medical assistance request #{$assistance_id} for {$request_type}";
        $log_stmt->bind_param("is", $user_id, $description);
        $log_stmt->execute();
        $log_stmt->close();
        
        $_SESSION['success_message'] = "Your medical assistance request has been submitted successfully! Reference #" . $assistance_id;
    } else {
        $_SESSION['error_message'] = "Error submitting request. Please try again.";
    }
    
    $stmt->close();
} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

header("Location: ../request-assistance.php");
exit;
?>