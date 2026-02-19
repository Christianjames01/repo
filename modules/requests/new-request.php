<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

$page_title = 'New Document Request';

// Fetch user's resident information
$sql = "SELECT r.* FROM tbl_residents r 
        INNER JOIN tbl_users u ON r.resident_id = u.resident_id 
        WHERE u.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$resident = $result->fetch_assoc();
$stmt->close();

if (!$resident) {
    $sql = "SELECT r.* FROM tbl_residents r 
            INNER JOIN tbl_users u ON r.email = u.email 
            WHERE u.user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $resident = $result->fetch_assoc();
    $stmt->close();
}

if (!$resident) {
    $_SESSION['error_message'] = 'Resident profile not found. Please complete your profile first.';
    header('Location: ../profile/index.php');
    exit();
}

// Fetch request types
$request_types = [];
$sql = "SELECT request_type_id, request_type_name as type_name, fee FROM tbl_request_types ORDER BY request_type_id";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $request_types[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    error_log("Form submitted - POST data: " . print_r($_POST, true));
    error_log("Files data: " . print_r($_FILES, true));
    
    try {
        $resident_id = $resident['resident_id'];
        $request_type_id = intval($_POST['request_type_id']);
        
        if ($request_type_id <= 0) {
            throw new Exception('Please select a valid document type.');
        }
        
        $purpose_text = sanitizeInput($_POST['purpose']);
        
        if (empty(trim($purpose_text))) {
            throw new Exception('Please enter the purpose of your request.');
        }
        
        $purpose = $purpose_text;
        
        if (!empty($_POST['additional_details'])) {
            $purpose .= "\n\nAdditional Details:\n" . sanitizeInput($_POST['additional_details']);
        }
        
        if (!empty($_POST['business_name'])) {
            $purpose .= "\n\nBusiness Information:";
            $purpose .= "\nBusiness Name: " . sanitizeInput($_POST['business_name']);
            
            if (!empty($_POST['business_address'])) {
                $purpose .= "\nBusiness Address: " . sanitizeInput($_POST['business_address']);
            }
            if (!empty($_POST['business_type'])) {
                $purpose .= "\nBusiness Type: " . sanitizeInput($_POST['business_type']);
            }
        }
        
        if (!empty($_POST['cedula_number']) || !empty($_POST['amount_paid'])) {
            $purpose .= "\n\nCedula Information:";
            if (!empty($_POST['cedula_number'])) {
                $purpose .= "\nCedula Number: " . sanitizeInput($_POST['cedula_number']);
            }
            if (!empty($_POST['amount_paid'])) {
                $purpose .= "\nAmount Paid: PHP " . number_format(floatval($_POST['amount_paid']), 2);
            }
        }
        
        $status = 'Pending';
        $payment_status = 0;
        
        $sql = "INSERT INTO tbl_requests 
                (resident_id, request_type_id, purpose, status, payment_status, request_date) 
                VALUES (?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("iissi", $resident_id, $request_type_id, $purpose, $status, $payment_status);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create request: ' . $stmt->error);
        }
        
        $request_id = $conn->insert_id;
        error_log("Request created with ID: " . $request_id);
        $stmt->close();

        $type_name_query = "SELECT request_type_name FROM tbl_request_types WHERE request_type_id = ?";
        $type_name_stmt = $conn->prepare($type_name_query);
        $type_name_stmt->bind_param("i", $request_type_id);
        $type_name_stmt->execute();
        $type_name_result = $type_name_stmt->get_result();
        $type_data = $type_name_result->fetch_assoc();
        $request_type_name = $type_data['request_type_name'] ?? 'Document';
        $type_name_stmt->close();

        // Get resident information for notification
        $resident_info_query = "SELECT first_name, last_name FROM tbl_residents WHERE resident_id = ?";
        $resident_info_stmt = $conn->prepare($resident_info_query);
        $resident_info_stmt->bind_param("i", $resident_id);
        $resident_info_stmt->execute();
        $resident_info_result = $resident_info_stmt->get_result();
        $resident_info = $resident_info_result->fetch_assoc();
        $resident_info_stmt->close();

        $resident_name = $resident_info['first_name'] . ' ' . $resident_info['last_name'];

        // Get all admins and staff to notify
        $admin_query = "SELECT user_id FROM tbl_users WHERE role IN ('Super Admin', 'Super Administrator', 'Staff', 'admin') AND is_active = 1";
        $admin_result = $conn->query($admin_query);

        $notification_title = "New Document Request";
        $notification_message = "$resident_name has submitted a request for $request_type_name";
        $notification_type = "document_request_submitted";
        $reference_type = "request";

        // Create notification for each admin/staff
        if ($admin_result && $admin_result->num_rows > 0) {
            while ($admin = $admin_result->fetch_assoc()) {
                $admin_id = $admin['user_id'];
                
                $notif_query = "INSERT INTO tbl_notifications 
                               (user_id, type, reference_type, reference_id, title, message, is_read, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
                
                $notif_stmt = $conn->prepare($notif_query);
                if ($notif_stmt) {
                    $notif_stmt->bind_param("ississ", 
                        $admin_id, 
                        $notification_type, 
                        $reference_type, 
                        $request_id, 
                        $notification_title, 
                        $notification_message
                    );
                    
                    if ($notif_stmt->execute()) {
                        error_log("✅ Notification created for admin user_id: $admin_id");
                    } else {
                        error_log("❌ Failed to create notification: " . $notif_stmt->error);
                    }
                    
                    $notif_stmt->close();
                }
            }
            
            error_log("✅ Document request notifications sent to " . $admin_result->num_rows . " admin(s)/staff");
        } else {
            error_log("⚠️ WARNING: No admins/staff found to notify!");
        }

        // ── Notify the RESIDENT that their request was submitted ──────────────
        $res_notif_query = "INSERT INTO tbl_notifications 
                           (user_id, type, reference_type, reference_id, title, message, is_read, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
        $res_notif_stmt = $conn->prepare($res_notif_query);
        if ($res_notif_stmt) {
            $res_notif_type    = "document_request_submitted";
            $res_ref_type      = "request";
            $res_notif_title   = "Request Submitted Successfully";
            $res_notif_message = "Your request for {$request_type_name} has been submitted and is now pending review.";
            $res_notif_stmt->bind_param("ississ",
                $user_id,
                $res_notif_type,
                $res_ref_type,
                $request_id,
                $res_notif_title,
                $res_notif_message
            );
            if ($res_notif_stmt->execute()) {
                error_log("✅ Resident notification created for user_id: $user_id");
            } else {
                error_log("❌ Failed to create resident notification: " . $res_notif_stmt->error);
            }
            $res_notif_stmt->close();
        }
        // ── End resident notification ─────────────────────────────────────────

        error_log("✅ Document request notifications sent to admins/staff and resident");
        
        // FILE UPLOAD SECTION
        $upload_success = true;
        $upload_errors = [];
        $upload_dir = '../../uploads/requests/';

        error_log("=== FILE UPLOAD START ===");
        error_log("Request ID: " . $request_id);

        // Ensure upload directory exists
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception('Failed to create upload directory.');
            }
            error_log("Created base upload directory: " . $upload_dir);
        }

        if (!is_writable($upload_dir)) {
            throw new Exception('Upload directory is not writable: ' . $upload_dir);
        }

        // Create request-specific directory
        $request_upload_dir = $upload_dir . $request_id . '/';
        if (!file_exists($request_upload_dir)) {
            if (!mkdir($request_upload_dir, 0755, true)) {
                throw new Exception('Failed to create request directory: ' . $request_upload_dir);
            }
            error_log("Created request directory: " . $request_upload_dir);
        }

        $files_uploaded_count = 0;
        $files_saved_to_db = 0;

        // Check if files were uploaded
        if (isset($_FILES['requirements']) && is_array($_FILES['requirements']['name'])) {
            $file_count = count($_FILES['requirements']['name']);
            error_log("Processing $file_count file upload slots");
            
            for ($i = 0; $i < $file_count; $i++) {
                // Skip if no file uploaded in this slot
                if ($_FILES['requirements']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    error_log("Slot $i: No file uploaded (skipping)");
                    continue;
                }
                
                // Check for upload errors
                if ($_FILES['requirements']['error'][$i] !== UPLOAD_ERR_OK) {
                    $error_msg = "Upload error in slot $i: Code " . $_FILES['requirements']['error'][$i];
                    error_log("❌ " . $error_msg);
                    $upload_errors[] = $error_msg;
                    continue;
                }
                
                // Get file details
                $filename = $_FILES['requirements']['name'][$i];
                $file_tmp = $_FILES['requirements']['tmp_name'][$i];
                $file_size = $_FILES['requirements']['size'][$i];
                $file_type = $_FILES['requirements']['type'][$i];
                $requirement_id = isset($_POST['requirement_ids'][$i]) ? intval($_POST['requirement_ids'][$i]) : null;
                
                error_log("📄 Slot $i: Processing '$filename' (Size: $file_size bytes, Type: $file_type)");
                
                // Validate file type
                $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'bmp', 'webp', 'svg', 'jfif'];
                
                if (!in_array($file_ext, $allowed_extensions)) {
                    $error_msg = "Invalid file type for: $filename (only JPG, PNG, GIF, PDF, BMP, WEBP, SVG, JFIF allowed)";
                    error_log("❌ " . $error_msg);
                    $upload_errors[] = $error_msg;
                    continue;
                }
                
                // Validate file size (5MB max)
                if ($file_size > 5 * 1024 * 1024) {
                    $error_msg = "File too large: $filename (max 5MB)";
                    error_log("❌ " . $error_msg);
                    $upload_errors[] = $error_msg;
                    continue;
                }
                
                // Validate temporary file exists
                if (!file_exists($file_tmp)) {
                    $error_msg = "Temporary file not found for: $filename";
                    error_log("❌ " . $error_msg);
                    $upload_errors[] = $error_msg;
                    continue;
                }
                
                // Generate unique filename
                $new_filename = 'req_' . $request_id . '_' . uniqid() . '.' . $file_ext;
                $server_file_path = $request_upload_dir . $new_filename;
                
                // Store relative path WITHOUT leading slash for database
                $db_file_path = 'uploads/requests/' . $request_id . '/' . $new_filename;
                
                error_log("Moving file: '$file_tmp' → '$server_file_path'");
                error_log("DB path will be: '$db_file_path'");
                
                // Move uploaded file
                if (move_uploaded_file($file_tmp, $server_file_path)) {
                    error_log("✅ File moved successfully");
                    
                    // Verify file was actually saved
                    if (!file_exists($server_file_path)) {
                        $error_msg = "File move reported success but file doesn't exist: $new_filename";
                        error_log("❌ " . $error_msg);
                        $upload_errors[] = $error_msg;
                        continue;
                    }
                    
                    $actual_size = filesize($server_file_path);
                    error_log("✅ File verified on disk: " . number_format($actual_size/1024, 2) . " KB");
                    
                    $files_uploaded_count++;
                    
                    // Insert into database
                    $insert_sql = "INSERT INTO tbl_request_attachments 
                                  (request_id, requirement_id, file_name, file_path, file_type, file_size, uploaded_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    
                    $insert_stmt = $conn->prepare($insert_sql);
                    
                    if (!$insert_stmt) {
                        $error_msg = "Failed to prepare DB insert: " . $conn->error;
                        error_log("❌ " . $error_msg);
                        $upload_errors[] = $error_msg;
                        @unlink($server_file_path);
                        error_log("🗑️ Deleted orphaned file: $server_file_path");
                        continue;
                    }
                    
                    // Handle requirement_id (can be NULL)
                    if ($requirement_id === 0 || $requirement_id === null) {
                        $requirement_id = null;
                    }
                    
                    // Bind parameters
                    $insert_stmt->bind_param("iisssi", 
                        $request_id, 
                        $requirement_id, 
                        $filename, 
                        $db_file_path, 
                        $file_type, 
                        $actual_size
                    );
                    
                    error_log("Executing DB insert: request_id=$request_id, req_id=" . ($requirement_id ?? 'NULL') . ", file=$filename");
                    
                    if ($insert_stmt->execute()) {
                        $attachment_id = $conn->insert_id;
                        error_log("✅ Database record created: attachment_id=$attachment_id");
                        $files_saved_to_db++;
                    } else {
                        $error_msg = "Failed to save to database for: $filename - " . $insert_stmt->error;
                        error_log("❌ " . $error_msg);
                        $upload_errors[] = $error_msg;
                        @unlink($server_file_path);
                        error_log("🗑️ Deleted orphaned file: $server_file_path");
                    }
                    
                    $insert_stmt->close();
                    
                } else {
                    $error_msg = "Failed to move uploaded file: $filename";
                    error_log("❌ " . $error_msg);
                    error_log("Source: $file_tmp (exists: " . (file_exists($file_tmp) ? 'YES' : 'NO') . ")");
                    error_log("Destination: $server_file_path");
                    error_log("Destination dir writable: " . (is_writable($request_upload_dir) ? 'YES' : 'NO'));
                    $upload_errors[] = $error_msg;
                }
            }
        } else {
            error_log("⚠️ No files array found in \$_FILES['requirements']");
        }

        error_log("=== FILE UPLOAD END ===");
        error_log("Files uploaded to server: $files_uploaded_count");
        error_log("Files saved to database: $files_saved_to_db");
        error_log("Total errors: " . count($upload_errors));

        // CRITICAL CHECK
        if ($files_uploaded_count > 0 && $files_saved_to_db === 0) {
            error_log("⚠️ WARNING: Files uploaded but NONE saved to database!");
        }

        // Set success message
        if ($files_saved_to_db > 0) {
            if (!empty($upload_errors)) {
                $_SESSION['success_message'] = "Request submitted! $files_saved_to_db file(s) uploaded successfully. " . count($upload_errors) . " file(s) failed.";
            } else {
                $_SESSION['success_message'] = "Request submitted successfully with $files_saved_to_db attachment(s)!";
            }
        } else {
            if (!empty($upload_errors)) {
                $_SESSION['success_message'] = "Request submitted but file uploads failed: " . implode(', ', $upload_errors);
            } else {
                $_SESSION['success_message'] = 'Request submitted successfully! (No files were uploaded)';
            }
        }

        error_log("Request submission completed - Redirecting to my-requests.php");
        
        if (ob_get_length()) ob_end_clean();
        
        header('Location: my-requests.php', true, 303);
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Request submission error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles - EXACT MATCH from view-incidents.php */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements - EXACT match */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
}

.card-body {
    padding: 1.75rem;
}

/* Alert Enhancements - EXACT match */
.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-success {
    background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
    border-left-color: #198754;
}

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

.alert-info {
    background: linear-gradient(135deg, #cfe2ff 0%, #e7f1ff 100%);
    border-left-color: #0d6efd;
}

.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
    border-left-color: #ffc107;
}

.alert i {
    font-size: 1.1rem;
}

/* Enhanced Badges - EXACT match */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Enhanced Buttons - EXACT match */
.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn:active {
    transform: translateY(0);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.requirement-item {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    background: #fff;
    transition: all 0.3s ease;
}

.requirement-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.requirement-item.mandatory {
    border-left: 4px solid #dc3545;
}

.requirement-item.optional {
    border-left: 4px solid #6c757d;
}

.file-upload-wrapper {
    position: relative;
    overflow: hidden;
    display: inline-block;
    width: 100%;
}

.file-upload-wrapper input[type=file] {
    position: absolute;
    left: -9999px;
}

.file-upload-label {
    display: block;
    padding: 20px 15px;
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    cursor: pointer;
    text-align: center;
    transition: all 0.3s;
}

.file-upload-label:hover {
    border-color: #0d6efd;
    background: #e7f1ff;
}

.file-upload-label.has-file {
    border-color: #198754;
    background: #d1e7dd;
    border-style: solid;
}

.file-upload-label i.fa-cloud-upload-alt {
    font-size: 2rem;
    color: #6c757d;
    margin-bottom: 8px;
    display: block;
}

.file-upload-label:hover i.fa-cloud-upload-alt {
    color: #0d6efd;
}

.file-upload-label.has-file i.fa-cloud-upload-alt {
    color: #198754;
}

.preview-container {
    margin-top: 15px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.preview-image {
    max-width: 100%;
    max-height: 200px;
    border-radius: 5px;
    border: 1px solid #dee2e6;
    display: block;
    margin: 0 auto;
}

.file-info {
    margin-top: 10px;
    padding: 8px 12px;
    background: #fff;
    border-radius: 5px;
    font-size: 0.875rem;
}

.remove-file-btn {
    margin-top: 10px;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}

.loading-overlay.active {
    display: flex;
}

.loading-content {
    background: white;
    padding: 30px 40px;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.loading-content .spinner-border {
    width: 3rem;
    height: 3rem;
    margin-bottom: 15px;
}

.success-checkmark {
    width: 80px;
    height: 80px;
    margin: 0 auto;
}

.success-checkmark .check-icon {
    width: 80px;
    height: 80px;
    position: relative;
    border-radius: 50%;
    box-sizing: content-box;
    border: 4px solid #198754;
}

.success-checkmark .check-icon::before {
    top: 3px;
    left: -2px;
    width: 30px;
    transform-origin: 100% 50%;
    border-radius: 100px 0 0 100px;
}

.success-checkmark .check-icon::after {
    top: 0;
    left: 30px;
    width: 60px;
    transform-origin: 0 50%;
    border-radius: 0 100px 100px 0;
    animation: rotate-circle 4.25s ease-in;
}

.success-checkmark .check-icon::before, .success-checkmark .check-icon::after {
    content: '';
    height: 100px;
    position: absolute;
    background: #fff;
    transform: rotate(-45deg);
}

.success-checkmark .icon-line {
    height: 5px;
    background-color: #198754;
    display: block;
    border-radius: 2px;
    position: absolute;
    z-index: 10;
}

.success-checkmark .icon-line.line-tip {
    top: 46px;
    left: 14px;
    width: 25px;
    transform: rotate(45deg);
    animation: icon-line-tip 0.75s;
}

.success-checkmark .icon-line.line-long {
    top: 38px;
    right: 8px;
    width: 47px;
    transform: rotate(-45deg);
    animation: icon-line-long 0.75s;
}

.success-checkmark .icon-circle {
    top: -4px;
    left: -4px;
    z-index: 10;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    position: absolute;
    box-sizing: content-box;
    border: 4px solid rgba(25, 135, 84, .5);
}

.success-checkmark .icon-fix {
    top: 8px;
    width: 5px;
    left: 26px;
    z-index: 1;
    height: 85px;
    position: absolute;
    transform: rotate(-45deg);
    background-color: #fff;
}

@keyframes rotate-circle {
    0% { transform: rotate(-45deg); }
    5% { transform: rotate(-45deg); }
    12% { transform: rotate(-405deg); }
    100% { transform: rotate(-405deg); }
}

@keyframes icon-line-tip {
    0%  { width: 0; left: 1px; top: 19px; }
    54% { width: 0; left: 1px; top: 19px; }
    70% { width: 50px; left: -8px; top: 37px; }
    84% { width: 17px; left: 21px; top: 48px; }
    100%{ width: 25px; left: 14px; top: 45px; }
}

@keyframes icon-line-long {
    0%  { width: 0; right: 46px; top: 54px; }
    65% { width: 0; right: 46px; top: 54px; }
    84% { width: 55px; right: 0px; top: 35px; }
    100%{ width: 47px; right: 8px; top: 38px; }
}

.info-box {
    border: 1px solid #dee2e6;
}

/* Responsive Adjustments - EXACT match */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .card-body {
        padding: 1.25rem;
    }
}

/* Smooth Scrolling - EXACT match */
html {
    scroll-behavior: smooth;
}
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5 class="mt-3 mb-2">Submitting Your Request</h5>
        <p class="text-muted mb-0">Please wait while we process your documents...</p>
        <p class="text-muted mt-2"><strong id="uploadProgress">Preparing upload...</strong></p>
    </div>
</div>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0 fw-bold">
                        <i class="fas fa-file-alt me-2"></i>
                        New Document Request
                    </h2>
                    <p class="text-muted mb-0 mt-1">Submit a request for barangay documents</p>
                </div>
                <a href="my-requests.php" class="btn btn-danger">
                    <i class="fas fa-arrow-left me-2"></i>Back to Requests
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="" id="requestForm" enctype="multipart/form-data">
                        <div class="mb-4">
                            <h5 class="mb-3">
                                <i class="fas fa-user me-2"></i>Personal Information
                            </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ?? '') . ' ' . $resident['last_name']); ?>" 
                                           readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?>" 
                                           readonly>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" rows="2" readonly><?php echo htmlspecialchars($resident['address'] ?? 'N/A'); ?></textarea>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h5 class="mb-3">
                                <i class="fas fa-file-invoice me-2"></i>Request Details
                            </h5>
                            <div class="mb-3">
                                <label class="form-label">Document Type <span class="text-danger">*</span></label>
                                <select name="request_type_id" id="request_type_id" class="form-select" required>
                                    <option value="">Select Document Type</option>
                                    <?php foreach ($request_types as $type): ?>
                                        <option value="<?php echo $type['request_type_id']; ?>" 
                                                data-typename="<?php echo htmlspecialchars($type['type_name']); ?>"
                                                data-fee="<?php echo $type['fee']; ?>">
                                            <?php echo htmlspecialchars($type['type_name']); ?>
                                            <?php if ($type['fee'] > 0): ?>
                                                - PHP <?php echo number_format($type['fee'], 2); ?>
                                            <?php else: ?>
                                                - Free
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="feeDisplay" class="mt-2" style="display: none;">
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-money-bill-wave me-2"></i>
                                        <strong>Fee:</strong> <span id="feeAmount">PHP 0.00</span>
                                        <br><small class="text-muted">Payment will be confirmed by admin after processing</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Purpose <span class="text-danger">*</span></label>
                                <input type="text" name="purpose" class="form-control" 
                                       placeholder="e.g., Employment, School Requirements, Bank Requirements" required>
                            </div>

                            <!-- Business Permit Fields -->
                            <div id="businessFields" style="display: none;">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please provide business details
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Business Name</label>
                                    <input type="text" name="business_name" class="form-control" 
                                           placeholder="Enter business name">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Business Address</label>
                                    <input type="text" name="business_address" class="form-control" 
                                           placeholder="Enter business address">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Business Type</label>
                                    <select name="business_type" class="form-select">
                                        <option value="">Select Business Type</option>
                                        <option value="Retail">Retail</option>
                                        <option value="Service">Service</option>
                                        <option value="Food">Food</option>
                                        <option value="Manufacturing">Manufacturing</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Cedula Fields -->
                            <div id="cedulaFields" style="display: none;">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please provide cedula details (if applicable)
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Cedula Number (if renewal)</label>
                                        <input type="text" name="cedula_number" class="form-control" 
                                               placeholder="Enter previous cedula number (optional)">
                                        <small class="text-muted">Leave blank if this is your first cedula</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Additional Details</label>
                                <textarea name="additional_details" class="form-control" rows="4" 
                                          placeholder="Provide any additional information or special requests..."></textarea>
                            </div>
                        </div>

                        <!-- Requirements Upload Section -->
                        <div id="requirementsSection" style="display: none;">
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-paperclip me-2"></i>Attachments & Requirements
                                </h5>
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Important:</strong> Please upload clear, readable images or PDF files.
                                    <ul class="mb-0 mt-2">
                                        <li>Maximum file size: <strong>5MB per file</strong></li>
                                        <li>Accepted formats: <strong>JPG, PNG, GIF, PDF</strong></li>
                                        <li>Required documents must be uploaded before submission</li>
                                    </ul>
                                </div>
                                <div id="requirementsList"></div>
                            </div>
                        </div>

                        <div class="alert alert-info border-0 shadow-sm">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Processing Information:</strong> Processing time may vary depending on the document type. 
                            You will be notified once your request is processed. Payment (if applicable) will be confirmed by the barangay admin.
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-paper-plane me-1"></i>Submit Request
                            </button>
                            <a href="my-requests.php" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-times me-1"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="card-title mb-3">
                        <i class="fas fa-info-circle me-2"></i>General Requirements
                    </h6>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="fas fa-check text-success me-2"></i>
                            Valid ID
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success me-2"></i>
                            Proof of residency
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success me-2"></i>
                            Complete application form
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-check text-success me-2"></i>
                            Payment confirmation (if applicable)
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title mb-3">
                        <i class="fas fa-clock me-2"></i>Processing Time
                    </h6>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr>
                                    <td>Barangay Clearance</td>
                                    <td class="text-end">1-3 days</td>
                                </tr>
                                <tr>
                                    <td>Certificates</td>
                                    <td class="text-end">1-2 days</td>
                                </tr>
                                <tr>
                                    <td>Business Permit</td>
                                    <td class="text-end">3-5 days</td>
                                </tr>
                                <tr>
                                    <td>Barangay ID</td>
                                    <td class="text-end">5-7 days</td>
                                </tr>
                                <tr>
                                    <td>Cedula</td>
                                    <td class="text-end">1 day</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submit Confirmation Modal -->
<div class="modal fade" id="submitConfirmModal" tabindex="-1" aria-labelledby="submitConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="submitConfirmModalLabel">
                    <i class="fas fa-check-circle me-2"></i>Confirm Request Submission
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Please review your request details before submitting.</strong>
                </div>
                
                <h6 class="fw-bold mb-3">Request Summary:</h6>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Full Name:</label>
                        <div class="fw-bold" id="modal_fullname">-</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Contact Number:</label>
                        <div class="fw-bold" id="modal_contact">-</div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small">Document Type:</label>
                    <div class="fw-bold" id="modal_document_type">-</div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small">Purpose:</label>
                    <div class="fw-bold" id="modal_purpose">-</div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small">Fee:</label>
                    <div class="fw-bold text-success" id="modal_fee">-</div>
                </div>
                
                <div id="modal_business_info" style="display: none;">
                    <div class="mb-3">
                        <label class="text-muted small">Business Name:</label>
                        <div class="fw-bold" id="modal_business_name">-</div>
                    </div>
                </div>
                
                <div id="modal_requirements_info" style="display: none;">
                    <div class="mb-3">
                        <label class="text-muted small">Uploaded Documents:</label>
                        <div id="modal_requirements_list"></div>
                    </div>
                </div>
                
                <div class="alert alert-warning mb-0">
                    <small>
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        By submitting this request, you confirm that all information provided is accurate and complete. 
                        Processing time may vary depending on the document type.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmSubmitBtn">
                    <i class="fas fa-paper-plane me-2"></i>Confirm & Submit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="success-checkmark mb-4">
                    <div class="check-icon">
                        <span class="icon-line line-tip"></span>
                        <span class="icon-line line-long"></span>
                        <div class="icon-circle"></div>
                        <div class="icon-fix"></div>
                    </div>
                </div>
                
                <h3 class="text-success mb-3">
                    <i class="fas fa-check-circle me-2"></i>Request Submitted Successfully!
                </h3>
                
                <div class="alert alert-success mb-4">
                    <p class="mb-2"><strong>Your request has been received and is now being processed.</strong></p>
                    <p class="mb-0 small">You will be notified once your request status changes.</p>
                </div>
                
                <div class="info-box bg-light p-3 rounded mb-4">
                    <div class="row text-start">
                        <div class="col-6 mb-2">
                            <small class="text-muted d-block">Request Date:</small>
                            <strong id="success_date"></strong>
                        </div>
                        <div class="col-6 mb-2">
                            <small class="text-muted d-block">Status:</small>
                            <span class="badge bg-warning">Pending</span>
                        </div>
                        <div class="col-12">
                            <small class="text-muted d-block">Document Type:</small>
                            <strong id="success_document"></strong>
                        </div>
                    </div>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary btn-lg" id="viewRequestsBtn">
                        <i class="fas fa-list me-2"></i>View My Requests
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="newRequestBtn">
                        <i class="fas fa-plus me-2"></i>Submit Another Request
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const requestTypeSelect = document.getElementById('request_type_id');
    const businessFields = document.getElementById('businessFields');
    const cedulaFields = document.getElementById('cedulaFields');
    const feeDisplay = document.getElementById('feeDisplay');
    const feeAmount = document.getElementById('feeAmount');
    const requirementsSection = document.getElementById('requirementsSection');
    const requirementsList = document.getElementById('requirementsList');
    
    requestTypeSelect.addEventListener('change', function() {
        const requestTypeId = this.value;
        
        businessFields.style.display = 'none';
        cedulaFields.style.display = 'none';
        feeDisplay.style.display = 'none';
        requirementsSection.style.display = 'none';
        requirementsList.innerHTML = '';
        
        if (!requestTypeId) return;
        
        const selectedOption = this.options[this.selectedIndex];
        const typeName = selectedOption.getAttribute('data-typename') || selectedOption.text;
        const fee = parseFloat(selectedOption.getAttribute('data-fee')) || 0;
        
        feeAmount.textContent = fee > 0 ? 'PHP ' + fee.toFixed(2) : 'Free';
        feeDisplay.style.display = 'block';
        
        if (typeName.toLowerCase().includes('business')) {
            businessFields.style.display = 'block';
        } else if (typeName.toLowerCase().includes('cedula')) {
            cedulaFields.style.display = 'block';
        }
        
        fetch('get_requirements.php?request_type_id=' + requestTypeId)
            .then(response => response.json())
            .then(data => {
                requirementsList.innerHTML = '';
                if (data.success && data.requirements.length > 0) {
                    requirementsSection.style.display = 'block';
                    data.requirements.forEach((req, index) => {
                        requirementsList.appendChild(createRequirementItem(req, index));
                    });
                } else {
                    requirementsSection.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error fetching requirements:', error);
                requirementsList.innerHTML = '';
                requirementsSection.style.display = 'none';
            });
    });
});

function createRequirementItem(requirement, index) {
    const div = document.createElement('div');
    div.className = 'requirement-item ' + (requirement.is_mandatory ? 'mandatory' : 'optional');
    
    const uniqueId = 'req_' + requirement.requirement_id;
    const labelId  = 'label_' + requirement.requirement_id;
    const previewId= 'preview_' + requirement.requirement_id;
    
    div.innerHTML = `
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <strong><i class="fas fa-file-alt me-2"></i>${requirement.requirement_name}</strong>
                ${requirement.is_mandatory ? '<span class="badge bg-danger ms-2">Required</span>' : '<span class="badge bg-secondary ms-2">Optional</span>'}
            </div>
        </div>
        ${requirement.description ? `<p class="text-muted small mb-3"><i class="fas fa-info-circle me-1"></i>${requirement.description}</p>` : ''}
        <div class="file-upload-wrapper">
            <input type="file" name="requirements[]" id="${uniqueId}" accept="image/*,.pdf"
                   ${requirement.is_mandatory ? 'required' : ''}
                   onchange="handleFileSelect(this, ${requirement.requirement_id})">
            <input type="hidden" name="requirement_ids[]" value="${requirement.requirement_id}">
            <label for="${uniqueId}" class="file-upload-label" id="${labelId}">
                <i class="fas fa-cloud-upload-alt"></i>
                <div class="file-name mt-2">Click to upload or drag file here</div>
                <small class="text-muted d-block mt-1">Supported: JPG, PNG, GIF, PDF (Max: 5MB)</small>
            </label>
        </div>
        <div id="${previewId}"></div>
    `;
    return div;
}

function handleFileSelect(input, requirementId) {
    const label = document.getElementById('label_' + requirementId);
    const preview = document.getElementById('preview_' + requirementId);
    const fileNameDiv = label.querySelector('.file-name');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileSize = file.size / 1024 / 1024;
        
        if (fileSize > 5) {
            alert('File size must be less than 5MB');
            input.value = '';
            return;
        }
        
        label.classList.add('has-file');
        fileNameDiv.innerHTML = `<i class="fas fa-check-circle text-success me-2"></i>${file.name}`;
        
        const previewContainer = document.createElement('div');
        previewContainer.className = 'preview-container';
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewContainer.innerHTML = `
                    <div class="text-center">
                        <img src="${e.target.result}" class="preview-image" alt="Preview">
                        <div class="file-info mt-2">
                            <i class="fas fa-image me-2"></i><strong>File:</strong> ${file.name}<br>
                            <i class="fas fa-weight me-2"></i><strong>Size:</strong> ${fileSize.toFixed(2)} MB
                        </div>
                        <button type="button" class="btn btn-sm btn-danger remove-file-btn" onclick="removeFile(${requirementId})">
                            <i class="fas fa-trash me-1"></i>Remove File
                        </button>
                    </div>
                `;
                preview.innerHTML = '';
                preview.appendChild(previewContainer);
            };
            reader.readAsDataURL(file);
        } else {
            previewContainer.innerHTML = `
                <div class="text-center">
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-file-pdf fa-2x mb-2"></i><br>
                        <strong>PDF Document</strong><br>
                        <small>${file.name}</small><br>
                        <small class="text-muted">Size: ${fileSize.toFixed(2)} MB</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger remove-file-btn" onclick="removeFile(${requirementId})">
                        <i class="fas fa-trash me-1"></i>Remove File
                    </button>
                </div>
            `;
            preview.innerHTML = '';
            preview.appendChild(previewContainer);
        }
    } else {
        resetFileInput(requirementId);
    }
}

function removeFile(requirementId) {
    document.getElementById('req_' + requirementId).value = '';
    resetFileInput(requirementId);
}

function resetFileInput(requirementId) {
    const label = document.getElementById('label_' + requirementId);
    const preview = document.getElementById('preview_' + requirementId);
    label.classList.remove('has-file');
    label.querySelector('.file-name').innerHTML = 'Click to upload or drag file here';
    preview.innerHTML = '';
}

// Show confirmation modal
document.getElementById('submitBtn').addEventListener('click', function(e) {
    e.preventDefault();
    
    const form = document.getElementById('requestForm');
    const requestType = document.getElementById('request_type_id');
    const purpose = document.querySelector('input[name="purpose"]');
    
    if (!form.checkValidity()) { form.reportValidity(); return false; }
    if (!requestType.value)    { alert('Please select a document type'); return false; }
    if (!purpose.value.trim()) { alert('Please enter the purpose of your request'); return false; }
    
    const requiredInputs = document.querySelectorAll('input[type="file"][required]');
    let missingFiles = false;
    requiredInputs.forEach(input => { if (!input.files || input.files.length === 0) missingFiles = true; });
    if (missingFiles) { alert('Please upload all required documents before submitting'); return false; }
    
    const selectedOption = requestType.options[requestType.selectedIndex];
    const fee = selectedOption.getAttribute('data-fee');
    const fullName = document.querySelector('input[type="text"][readonly]').value;
    const contact  = document.querySelectorAll('input[type="text"][readonly]')[1].value;
    
    document.getElementById('modal_fullname').textContent      = fullName;
    document.getElementById('modal_contact').textContent       = contact;
    document.getElementById('modal_document_type').textContent = selectedOption.text;
    document.getElementById('modal_purpose').textContent       = purpose.value;
    document.getElementById('modal_fee').textContent           = fee > 0 ? 'PHP ' + parseFloat(fee).toFixed(2) : 'Free';
    
    const businessName = document.querySelector('input[name="business_name"]');
    if (businessName && businessName.value.trim()) {
        document.getElementById('modal_business_info').style.display = 'block';
        document.getElementById('modal_business_name').textContent = businessName.value;
    } else {
        document.getElementById('modal_business_info').style.display = 'none';
    }
    
    const uploadedFiles = document.querySelectorAll('input[type="file"]');
    let hasFiles = false;
    let filesList = '<ul class="list-unstyled mb-0">';
    uploadedFiles.forEach(input => {
        if (input.files && input.files.length > 0) {
            hasFiles = true;
            filesList += `<li><i class="fas fa-check-circle text-success me-2"></i>${input.files[0].name}</li>`;
        }
    });
    filesList += '</ul>';
    
    document.getElementById('modal_requirements_info').style.display = hasFiles ? 'block' : 'none';
    if (hasFiles) document.getElementById('modal_requirements_list').innerHTML = filesList;
    
    new bootstrap.Modal(document.getElementById('submitConfirmModal')).show();
});

document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
    bootstrap.Modal.getInstance(document.getElementById('submitConfirmModal')).hide();
    
    const form          = document.getElementById('requestForm');
    const submitBtn     = document.getElementById('submitBtn');
    const cancelBtn     = document.getElementById('cancelBtn');
    const loadingOverlay= document.getElementById('loadingOverlay');
    const uploadProgress= document.getElementById('uploadProgress');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting...';
    cancelBtn.style.pointerEvents = 'none';
    cancelBtn.style.opacity = '0.5';
    loadingOverlay.classList.add('active');
    
    const formData = new FormData(form);
    formData.append('submit_request', '1');
    
    const requestType  = document.getElementById('request_type_id');
    const documentType = requestType.options[requestType.selectedIndex].text;
    
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            uploadProgress.textContent = `Uploading... ${Math.round((e.loaded / e.total) * 100)}%`;
        }
    });
    
    xhr.addEventListener('load', function() {
        if (xhr.status === 200) {
            uploadProgress.textContent = 'Upload complete!';
            submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>Success!';
            setTimeout(function() {
                loadingOverlay.classList.remove('active');
                const now = new Date();
                document.getElementById('success_date').textContent = now.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
                document.getElementById('success_document').textContent = documentType;
                new bootstrap.Modal(document.getElementById('successModal')).show();
            }, 500);
        } else {
            loadingOverlay.classList.remove('active');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Submit Request';
            cancelBtn.style.pointerEvents = '';
            cancelBtn.style.opacity = '';
            alert('An error occurred during submission. Please try again.');
        }
    });
    
    xhr.addEventListener('error', function() {
        loadingOverlay.classList.remove('active');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Submit Request';
        cancelBtn.style.pointerEvents = '';
        cancelBtn.style.opacity = '';
        alert('Upload failed. Please check your connection and try again.');
    });
    
    xhr.addEventListener('timeout', function() {
        loadingOverlay.classList.remove('active');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Submit Request';
        cancelBtn.style.pointerEvents = '';
        cancelBtn.style.opacity = '';
        alert('Upload timed out. Please try again.');
    });
    
    xhr.open('POST', window.location.href, true);
    xhr.timeout = 300000;
    xhr.send(formData);
});

document.getElementById('viewRequestsBtn').addEventListener('click', function() {
    window.location.href = 'my-requests.php';
});

document.getElementById('newRequestBtn').addEventListener('click', function() {
    window.location.reload();
});
</script>
<?php include '../../includes/footer.php'; ?>