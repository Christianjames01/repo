<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Only residents can file complaints
requireRole('Resident');

$current_user_id = getCurrentUserId();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'file_complaint') {
    
    // Get form data
    $resident_id = isset($_POST['resident_id']) ? intval($_POST['resident_id']) : 0;
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $priority = isset($_POST['priority']) ? trim($_POST['priority']) : 'Medium';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    
    // Validate required fields
    $errors = [];
    
    if (empty($subject)) {
        $errors[] = 'Subject is required';
    }
    
    if (empty($category)) {
        $errors[] = 'Category is required';
    }
    
    if (empty($description)) {
        $errors[] = 'Description is required';
    }
    
    if ($resident_id <= 0) {
        $errors[] = 'Invalid resident ID';
    }
    
    // Verify resident belongs to current user
    $verify_stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ? AND resident_id = ?");
    $verify_stmt->bind_param("ii", $current_user_id, $resident_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        $errors[] = 'Unauthorized access';
    }
    $verify_stmt->close();
    
    // If there are errors, redirect back with error message
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode(', ', $errors);
        header('Location: file-complaint.php');
        exit();
    }
    
    // Check which columns exist in tbl_complaints
    $columns_result = $conn->query("SHOW COLUMNS FROM tbl_complaints");
    $available_columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $available_columns[] = $col['Field'];
    }
    
    // Generate unique complaint number
    $complaint_number = generateComplaintNumber($conn);
    
    // Set date to current datetime
    $current_datetime = date('Y-m-d H:i:s');
    
    // Build dynamic INSERT query based on available columns
    $fields = [];
    $values = [];
    $types = '';
    $params = [];
    
    // Add resident_id
    if (in_array('resident_id', $available_columns)) {
        $fields[] = 'resident_id';
        $values[] = '?';
        $types .= 'i';
        $params[] = $resident_id;
    }
    
    // Add complaint_number
    if (in_array('complaint_number', $available_columns)) {
        $fields[] = 'complaint_number';
        $values[] = '?';
        $types .= 's';
        $params[] = $complaint_number;
    }
    
    // Add subject
    if (in_array('subject', $available_columns)) {
        $fields[] = 'subject';
        $values[] = '?';
        $types .= 's';
        $params[] = $subject;
    }
    
    // Add description
    if (in_array('description', $available_columns)) {
        $fields[] = 'description';
        $values[] = '?';
        $types .= 's';
        $params[] = $description;
    }
    
    // Add category
    if (in_array('category', $available_columns)) {
        $fields[] = 'category';
        $values[] = '?';
        $types .= 's';
        $params[] = $category;
    }
    
    // Add priority
    if (in_array('priority', $available_columns)) {
        $fields[] = 'priority';
        $values[] = '?';
        $types .= 's';
        $params[] = $priority;
    }
    
    // Add status - ALWAYS set to 'Pending' (exact case)
    if (in_array('status', $available_columns)) {
        $fields[] = 'status';
        $values[] = '?';
        $types .= 's';
        $params[] = 'Pending';
    }
    
    // Add location if column exists and value is provided
    if (in_array('location', $available_columns) && !empty($location)) {
        $fields[] = 'location';
        $values[] = '?';
        $types .= 's';
        $params[] = $location;
    }
    
    // Add date field based on what exists in the table
    if (in_array('date_filed', $available_columns)) {
        $fields[] = 'date_filed';
        $values[] = '?';
        $types .= 's';
        $params[] = $current_datetime;
    } elseif (in_array('created_at', $available_columns)) {
        $fields[] = 'created_at';
        $values[] = '?';
        $types .= 's';
        $params[] = $current_datetime;
    } elseif (in_array('date_created', $available_columns)) {
        $fields[] = 'date_created';
        $values[] = '?';
        $types .= 's';
        $params[] = $current_datetime;
    }
    
    // Check if we have required fields
    if (empty($fields)) {
        $_SESSION['error_message'] = 'Unable to create complaint - database structure issue';
        header('Location: file-complaint.php');
        exit();
    }
    
    // Build the SQL query
    $insert_sql = "INSERT INTO tbl_complaints (" . implode(', ', $fields) . ") 
                   VALUES (" . implode(', ', $values) . ")";
    
    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        $_SESSION['error_message'] = 'Database error: ' . $conn->error;
        header('Location: file-complaint.php');
        exit();
    }
    
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $complaint_id = $stmt->insert_id;
        $stmt->close();
        
        // Handle file uploads if any
        if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
            // Use absolute path from document root
           $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/complaints/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_count = count($_FILES['attachments']['name']);
            $uploaded_files = [];
            
            for ($i = 0; $i < $file_count && $i < 5; $i++) {
                if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['attachments']['tmp_name'][$i];
                    $file_name = $_FILES['attachments']['name'][$i];
                    $file_size = $_FILES['attachments']['size'][$i];
                    
                    // Check file size (5MB max)
                    if ($file_size <= 5242880) {
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                        
                        if (in_array($file_ext, $allowed_ext)) {
                            // Generate unique filename
                            $new_filename = 'complaint_' . $complaint_id . '_' . time() . '_' . $i . '.' . $file_ext;
                            $destination = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($file_tmp, $destination)) {
                                $uploaded_files[] = $new_filename;
                                
                                // If you have a separate attachments table, insert here
                                // Check if tbl_complaint_attachments exists
                                $table_check = $conn->query("SHOW TABLES LIKE 'tbl_complaint_attachments'");
                                if ($table_check && $table_check->num_rows > 0) {
                                    $attach_sql = "INSERT INTO tbl_complaint_attachments (complaint_id, file_name, file_path, uploaded_at) 
                                                   VALUES (?, ?, ?, NOW())";
                                    $attach_stmt = $conn->prepare($attach_sql);
                                    if ($attach_stmt) {
                                        $file_path = 'uploads/complaints/' . $new_filename;
                                        $attach_stmt->bind_param("iss", $complaint_id, $file_name, $file_path);
                                        $attach_stmt->execute();
                                        $attach_stmt->close();
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Optionally update the complaint record with attachment info if column exists
            if (!empty($uploaded_files) && in_array('attachments', $available_columns)) {
                $attachments_json = json_encode($uploaded_files);
                $update_sql = "UPDATE tbl_complaints SET attachments = ? WHERE complaint_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if ($update_stmt) {
                    $update_stmt->bind_param("si", $attachments_json, $complaint_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
        }
        
        // SEND NOTIFICATIONS USING YOUR EXISTING createNotification FUNCTION
        
        // 1. Notify the resident/complainant (confirmation)
        createNotification(
    $conn,
    $current_user_id,
    'Complaint Received',
    "Your complaint \"$subject\" (#{$complaint_number}) has been received and is being reviewed.",
    'complaint_filed',
    $complaint_id,  // This is the reference_id
    'complaint'     // This is the reference_type
);
        
        // 2. Notify all Super Administrators about the new complaint
        $super_admins = fetchAll($conn, 
            "SELECT user_id FROM tbl_users WHERE role IN ('Super Admin', 'Super Administrator', 'Admin') AND status = 'active'", 
            [], ''
        );
        
        foreach ($super_admins as $admin) {
            createNotification(
                $conn,
                $admin['user_id'],
                'New Complaint Filed',
                "A new complaint has been filed: \"$subject\" (#{$complaint_number}). Please review and assign to a staff member.",
                'complaint_filed',
                $complaint_id,
                'complaint'
            );
        }
        
        // Log activity if function exists
        if (function_exists('logActivity')) {
            logActivity($conn, $current_user_id, 'Filed complaint', 'tbl_complaints', $complaint_id);
        }
        
        $_SESSION['success_message'] = "Complaint filed successfully! Your complaint number is: <strong>$complaint_number</strong>. Please keep this for your reference. You will be notified of any updates.";
        header('Location: view-complaints.php');
        exit();
        
    } else {
        $_SESSION['error_message'] = 'Failed to file complaint. Please try again. Error: ' . $stmt->error;
        $stmt->close();
        header('Location: file-complaint.php');
        exit();
    }
    
} else {
    // If accessed directly without POST, redirect to file complaint page
    header('Location: file-complaint.php');
    exit();
}

// Function to generate unique complaint number
function generateComplaintNumber($conn) {
    $prefix = 'CMPL';
    $year = date('Y');
    $month = date('m');
    
    // Get the last complaint number for this month
    $sql = "SELECT complaint_number FROM tbl_complaints 
            WHERE complaint_number LIKE ? 
            ORDER BY complaint_id DESC LIMIT 1";
    
    $pattern = $prefix . '-' . $year . $month . '%';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_number = $row['complaint_number'];
        
        // Extract the sequence number
        $parts = explode('-', $last_number);
        $sequence = isset($parts[2]) ? intval($parts[2]) + 1 : 1;
    } else {
        $sequence = 1;
    }
    
    $stmt->close();
    
    // Format: CMPL-YYYYMM-XXXX (e.g., CMPL-202501-0001)
    $complaint_number = $prefix . '-' . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    
    return $complaint_number;
}

$conn->close();
?>