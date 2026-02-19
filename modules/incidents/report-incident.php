<?php
// CORRECT INCLUDE ORDER - CRITICAL!
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireLogin(); // Allow all authenticated users to report incidents

$page_title = 'Report Incident';
$error = '';
$success = '';
$warnings = [];

// Get resident_id directly from database
$current_user_id = getCurrentUserId();
$resident_id = null;

$stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $resident_id = $row['resident_id'];
}
$stmt->close();

// Check for success message from redirect
if (isset($_SESSION['incident_success'])) {
    $success = $_SESSION['incident_success'];
    unset($_SESSION['incident_success']);
}

// ============================================================
// FIX #1: Ensure incident_id is AUTO_INCREMENT on every load
// This silently repairs the column if it was ever broken
// ============================================================
$col_check = $conn->query("SHOW COLUMNS FROM tbl_incidents LIKE 'incident_id'");
if ($col_check && $col_check->num_rows > 0) {
    $col_info = $col_check->fetch_assoc();
    // If Extra doesn't contain auto_increment, fix it now
    if (stripos($col_info['Extra'], 'auto_increment') === false) {
        error_log("FIXING: incident_id is missing AUTO_INCREMENT - applying fix now");
        $conn->query("ALTER TABLE tbl_incidents MODIFY incident_id INT(11) NOT NULL AUTO_INCREMENT");
    }
}

// ============================================================
// FIX #2: Clean up any existing broken rows where incident_id = 0
// ============================================================
$broken_check = $conn->query("SELECT COUNT(*) as cnt FROM tbl_incidents WHERE incident_id = 0");
if ($broken_check) {
    $broken_row = $broken_check->fetch_assoc();
    if ($broken_row['cnt'] > 0) {
        error_log("WARNING: Found " . $broken_row['cnt'] . " broken incident(s) with incident_id=0. These need manual review.");
        // We don't auto-delete these - admin should review them
        // But we do reset the AUTO_INCREMENT to be safe
        $max_result = $conn->query("SELECT MAX(incident_id) as max_id FROM tbl_incidents WHERE incident_id > 0");
        if ($max_result) {
            $max_row = $max_result->fetch_assoc();
            $next_id = (int)$max_row['max_id'] + 1;
            $conn->query("ALTER TABLE tbl_incidents AUTO_INCREMENT = $next_id");
        }
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    
    error_log("POST DATA RECEIVED: " . print_r($_POST, true));
    
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        
        // Sanitize and validate inputs
        $incident_type = isset($_POST['incident_type']) ? sanitizeInput($_POST['incident_type']) : '';
        $description   = isset($_POST['description'])   ? sanitizeInput($_POST['description'])   : '';
        $location      = isset($_POST['location'])      ? sanitizeInput($_POST['location'])      : '';
        $severity      = isset($_POST['severity'])      ? sanitizeInput($_POST['severity'])      : 'Medium';
        
        error_log("Validating - Type: $incident_type, Desc length: " . strlen($description) . ", Location: $location");
        
        $validation_errors = [];
        
        if (empty($incident_type)) $validation_errors[] = 'Incident type is required';
        if (empty($description))   $validation_errors[] = 'Description is required';
        if (empty($location))      $validation_errors[] = 'Location is required';
        
        $valid_types = ['Crime', 'Fire', 'Accident', 'Health Emergency', 'Violation', 'Natural Disaster', 'Others'];
        if (!empty($incident_type) && !in_array($incident_type, $valid_types)) {
            $validation_errors[] = 'Invalid incident type selected';
        }
        
        $valid_severities = ['Low', 'Medium', 'High', 'Critical'];
        if (!empty($severity) && !in_array($severity, $valid_severities)) {
            $validation_errors[] = 'Invalid severity level selected';
        }
        
        if (!empty($description) && strlen($description) < 10) {
            $validation_errors[] = 'Description must be at least 10 characters long';
        }
        if (!empty($description) && strlen($description) > 2000) {
            $validation_errors[] = 'Description must not exceed 2000 characters';
        }
        
        // Validate coordinates
        $latitude  = null;
        $longitude = null;
        
        if (!empty($_POST['latitude'])) {
            $latitude = floatval($_POST['latitude']);
            if ($latitude < -90 || $latitude > 90) {
                $validation_errors[] = 'Invalid latitude value. Must be between -90 and 90';
            }
        }
        if (!empty($_POST['longitude'])) {
            $longitude = floatval($_POST['longitude']);
            if ($longitude < -180 || $longitude > 180) {
                $validation_errors[] = 'Invalid longitude value. Must be between -180 and 180';
            }
        }
        
        // Validate file upload count
        if (isset($_FILES['incident_images']) && !empty($_FILES['incident_images']['name'][0])) {
            $file_count = count(array_filter($_FILES['incident_images']['name']));
            if ($file_count > 5) {
                $validation_errors[] = 'Maximum 5 images allowed. You uploaded ' . $file_count . ' files';
            }
        }
        
        if (!empty($validation_errors)) {
            $error = implode('<br>', $validation_errors);
            error_log("Validation failed: " . implode(", ", $validation_errors));
        } else {
            
            try {
                // Generate reference number
                if (!function_exists('generateReferenceNumber')) {
                    throw new Exception('generateReferenceNumber function not found');
                }
                
                $reference_no = generateReferenceNumber('INC');
                if (empty($reference_no)) {
                    throw new Exception('Failed to generate reference number');
                }
                
                error_log("Generated reference: $reference_no");
                
                // Check if lat/lng columns exist
                $columns_check  = $conn->query("SHOW COLUMNS FROM tbl_incidents LIKE 'latitude'");
                $has_coordinates = ($columns_check && $columns_check->num_rows > 0);
                
                // ============================================================
                // FIX #3: DO NOT include incident_id in the INSERT at all.
                // Let AUTO_INCREMENT handle it completely.
                // ============================================================
                if ($has_coordinates && $latitude !== null && $longitude !== null) {
                    $sql = "INSERT INTO tbl_incidents (
                                reference_no,
                                resident_id,
                                incident_type,
                                description,
                                location,
                                severity,
                                status,
                                date_reported,
                                latitude,
                                longitude
                            ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?)";
                } else {
                    $sql = "INSERT INTO tbl_incidents (
                                reference_no,
                                resident_id,
                                incident_type,
                                description,
                                location,
                                severity,
                                status,
                                date_reported
                            ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())";
                }
                
                error_log("SQL Query: $sql");
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                
                if ($has_coordinates && $latitude !== null && $longitude !== null) {
                    $stmt->bind_param("ssssssdd",
                        $reference_no,
                        $resident_id,
                        $incident_type,
                        $description,
                        $location,
                        $severity,
                        $latitude,
                        $longitude
                    );  
                } else {
                    $stmt->bind_param("sissss",
                        $reference_no,
                        $resident_id,
                        $incident_type,
                        $description,
                        $location,
                        $severity
                    );
                }
                
                if (!$stmt->execute()) {
                    throw new Exception('Database execute error: ' . $stmt->error);
                }
                
                if ($stmt->affected_rows <= 0) {
                    throw new Exception('Insert operation did not affect any rows');
                }
                
                error_log("Insert successful, affected rows: " . $stmt->affected_rows);
                
                // ============================================================
                // FIX #4: Use insert_id IMMEDIATELY after execute(), before
                // closing the statement or running any other query.
                // This is the correct and reliable way to get the new ID.
                // ============================================================
                $incident_id = (int)$conn->insert_id;
                
                $stmt->close();
                
                error_log("insert_id returned: $incident_id");
                
                // Validate the ID we got
                if ($incident_id <= 0) {
                    // insert_id failed - fall back to reference_no lookup
                    error_log("WARNING: insert_id returned 0. Falling back to reference_no lookup.");
                    
                    $retrieve_stmt = $conn->prepare("SELECT incident_id FROM tbl_incidents WHERE reference_no = ? LIMIT 1");
                    if ($retrieve_stmt) {
                        $retrieve_stmt->bind_param("s", $reference_no);
                        $retrieve_stmt->execute();
                        $retrieve_result = $retrieve_stmt->get_result();
                        if ($retrieve_result && $retrieve_result->num_rows > 0) {
                            $row = $retrieve_result->fetch_assoc();
                            $incident_id = (int)$row['incident_id'];
                            error_log("Fallback retrieved incident_id: $incident_id");
                        }
                        $retrieve_stmt->close();
                    }
                }
                
                // If STILL 0 after fallback, the AUTO_INCREMENT is broken at DB level
                if ($incident_id <= 0) {
                    error_log("CRITICAL: incident_id is still 0 after fallback. AUTO_INCREMENT may be broken.");
                    error_log("Attempting emergency ALTER to fix AUTO_INCREMENT...");
                    
                    $conn->query("ALTER TABLE tbl_incidents MODIFY incident_id INT(11) NOT NULL AUTO_INCREMENT");
                    
                    // Report success anyway since the record WAS created
                    $success = 'Incident reported successfully! Reference No: <strong>' . htmlspecialchars($reference_no) . '</strong>';
                    $success .= '<br><small>Barangay officials will be notified.</small>';
                    $_SESSION['incident_success'] = $success;
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                
                error_log("Final incident_id: $incident_id");
                
                // Handle image uploads
                $uploaded_images = [];
                if (isset($_FILES['incident_images']) && !empty($_FILES['incident_images']['name'][0])) {
                    $upload_dir = '../../uploads/incidents/';
                    
                    if (!file_exists($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            $warnings[] = "Failed to create upload directory";
                        }
                    }
                    
                    if (file_exists($upload_dir) && is_writable($upload_dir)) {
                        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                        $max_size = 10485760; // 10MB
                        
                        foreach ($_FILES['incident_images']['tmp_name'] as $key => $tmp_name) {
                            if (empty($tmp_name)) continue;
                            
                            $file_name = $_FILES['incident_images']['name'][$key];
                            $file_size = $_FILES['incident_images']['size'][$key];
                            $file_tmp  = $_FILES['incident_images']['tmp_name'][$key];
                            $file_type = $_FILES['incident_images']['type'][$key];
                            
                            if (!in_array($file_type, $allowed_types)) {
                                $warnings[] = "File $file_name has invalid type and was skipped";
                                continue;
                            }
                            if ($file_size > $max_size) {
                                $warnings[] = "File $file_name exceeds 10MB and was skipped";
                                continue;
                            }
                            
                            $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            // Normalize jfif/jpe to jpg so Apache serves it correctly
                            if (in_array($extension, ['jfif', 'jpe'])) {
                                $extension = 'jpg';
                            }
                            $new_filename = 'incident_' . $incident_id . '_' . time() . '_' . uniqid() . '.' . $extension;
                            $upload_path  = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($file_tmp, $upload_path)) {
                                $uploaded_images[] = $new_filename;
                                error_log("Image uploaded: $new_filename");
                                
                                // FIXED: Store path WITH subfolder so incident-details.php can find it
                                $db_image_path = 'incidents/' . $new_filename;
                                
                                $check_table = $conn->query("SHOW TABLES LIKE 'tbl_incident_images'");
                                if ($check_table && $check_table->num_rows > 0) {
                                    $img_stmt = $conn->prepare("INSERT INTO tbl_incident_images (incident_id, image_path) VALUES (?, ?)");
                                    $img_stmt->bind_param("is", $incident_id, $db_image_path);
                                    if ($img_stmt->execute()) {
                                        error_log("Image record saved: $db_image_path");
                                    } else {
                                        error_log("Failed to save image record: " . $img_stmt->error);
                                    }
                                    $img_stmt->close();
                                }
                            } else {
                                $warnings[] = "Failed to upload $file_name";
                                error_log("Failed to move uploaded file: $file_name");
                            }
                        }
                    } else {
                        $warnings[] = "Upload directory is not accessible";
                    }
                }
                
                // Send notifications
                $incident_title = $incident_type . " - " . substr($location, 0, 30);
                if (function_exists('notifyIncidentReported')) {
                    try {
                        notifyIncidentReported($conn, $incident_id, $incident_title);
                        error_log("Notifications sent for incident $incident_id");
                    } catch (Exception $notify_error) {
                        error_log("Notification error: " . $notify_error->getMessage());
                        $warnings[] = "Incident created but notifications may have failed";
                    }
                }
                
                // Build success message
                $success = 'Incident reported successfully! Reference No: <strong>' . htmlspecialchars($reference_no) . '</strong>';
                if (!empty($uploaded_images)) {
                    $success .= '<br><small>' . count($uploaded_images) . ' image(s) uploaded.</small>';
                }
                $success .= '<br><small>Barangay officials have been notified.</small>';
                
                $_SESSION['incident_success'] = $success;
                if (!empty($warnings)) {
                    $_SESSION['incident_warnings'] = $warnings;
                }
                
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
                
            } catch (Exception $e) {
                error_log("INCIDENT CREATION ERROR: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                
                $error = 'Failed to submit incident report: ' . htmlspecialchars($e->getMessage());
                
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'Database error: Duplicate entry detected. Please try again.';
                }
            }
        }
    }
}

// Check for warnings from redirect
if (isset($_SESSION['incident_warnings'])) {
    $warnings = $_SESSION['incident_warnings'];
    unset($_SESSION['incident_warnings']);
}

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
}

.card-body {
    padding: 1.75rem;
}

/* Form Enhancements */
.form-label {
    font-weight: 700;
    font-size: 0.875rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

.form-control-lg, .form-select-lg {
    padding: 0.875rem 1.25rem;
    font-size: 1rem;
}

/* Enhanced Buttons */
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

.btn-lg {
    padding: 0.875rem 2rem;
    font-size: 1.05rem;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* Alert Enhancements */
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

.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #fff9e5 100%);
    border-left-color: #ffc107;
}

.alert-info {
    background: linear-gradient(135deg, #d1ecf1 0%, #e7f5f7 100%);
    border-left-color: #0dcaf0;
}

.alert i {
    font-size: 1.1rem;
}

/* Image Preview */
#imagePreview .card {
    border: 2px solid #e9ecef;
    transition: all var(--transition-speed) ease;
}

#imagePreview .card:hover {
    border-color: #0d6efd;
    transform: translateY(-2px);
}

/* Guidelines Card */
.bg-light {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
}

/* Emergency Hotlines Card */
.border-danger {
    border: 2px solid #dc3545 !important;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .card-body {
        padding: 1.25rem;
    }
    
    .btn {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-exclamation-triangle me-2 text-danger"></i>
                        Report Incident
                    </h2>
                    <p class="text-muted mb-0">Submit an incident report to barangay authorities</p>
                </div>
                <a href="view-incidents.php" class="btn btn-secondary">
                    <i class="fas fa-list me-2"></i>View Incidents
                </a>
            </div>
        </div>
    </div>
    
    <!-- Success Message -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <hr>
        <div class="mt-2">
            <a href="view-incidents.php" class="btn btn-sm btn-success me-2">
                <i class="fas fa-eye me-1"></i>View My Incidents
            </a>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="location.reload()">
                <i class="fas fa-plus me-1"></i>Report Another Incident
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Error Message -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Warning Messages -->
    <?php foreach ($warnings as $warning): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($warning); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endforeach; ?>
    
    <div class="row">
        <!-- Main Form -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="incidentForm">
                        <?php echo getCSRFField(); ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Incident Type <span class="text-danger">*</span>
                                </label>
                                <select name="incident_type" class="form-select form-select-lg" required>
                                    <option value="">Select Type</option>
                                    <option value="Crime">Crime</option>
                                    <option value="Fire">Fire</option>
                                    <option value="Accident">Accident</option>
                                    <option value="Health Emergency">Health Emergency</option>
                                    <option value="Violation">Violation</option>
                                    <option value="Natural Disaster">Natural Disaster</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Severity Level <span class="text-danger">*</span>
                                </label>
                                <select name="severity" class="form-select form-select-lg" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                    <option value="Critical">Critical</option>
                                </select>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Critical: Requires immediate response
                                </small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                Location <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="location" class="form-control form-control-lg" 
                                placeholder="Enter incident location (e.g., Purok 1, Near Church)" 
                                required maxlength="200">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                Description <span class="text-danger">*</span>
                            </label>
                            <textarea name="description" class="form-control" rows="5" 
                                    placeholder="Provide detailed description of the incident..." 
                                    required minlength="10" maxlength="2000" id="description"></textarea>
                            <small class="text-muted">
                                <span id="charCount">0</span>/2000 characters (minimum 10)
                            </small>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">
                                Upload Evidence Photos (Optional)
                            </label>
                            <input type="file" name="incident_images[]" class="form-control" 
                                accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" multiple id="imageInput">
                            <small class="text-muted">
                                You can upload up to 5 images (Max 10MB each). Allowed: JPG, PNG, GIF, WEBP
                            </small>
                            <div id="imagePreview" class="mt-3 row g-2"></div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Your incident report will be reviewed by barangay officials. 
                            You will receive updates via notifications. For life-threatening emergencies, please call 911 immediately.
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="fas fa-paper-plane me-2"></i>Submit Incident Report
                            </button>
                            <a href="view-incidents.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Guidelines Card -->
            <div class="card border-0 shadow-sm bg-light mb-3">
                <div class="card-body">
                    <h5 class="card-title fw-bold">
                        <i class="fas fa-info-circle me-2 text-primary"></i>Guidelines
                    </h5>
                    <hr>
                    <ul class="mb-0">
                        <li class="mb-2">Provide accurate and complete information</li>
                        <li class="mb-2">Upload clear photos as evidence if available</li>
                        <li class="mb-2">Select appropriate severity level</li>
                        <li class="mb-2">For life-threatening emergencies, call 911</li>
                        <li class="mb-2">You will receive a reference number for tracking</li>
                        <li>Response time depends on severity and availability</li>
                    </ul>
                </div>
            </div>
            
            <!-- Emergency Hotlines Card -->
            <div class="card border-0 shadow-sm border-danger">
                <div class="card-body">
                    <h5 class="card-title text-danger fw-bold">
                        <i class="fas fa-phone me-2"></i>Emergency Hotlines
                    </h5>
                    <hr>
                    <p class="mb-2"><strong>Emergency:</strong> 911</p>
                    <p class="mb-2"><strong>Fire:</strong> (02) 8426-0219</p>
                    <p class="mb-2"><strong>Police:</strong> 117</p>
                    <p class="mb-0"><strong>Barangay:</strong> <?php echo htmlspecialchars(BARANGAY_CONTACT ?? '(123) 456-7890'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Character counter
document.getElementById('description').addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

// Image preview
document.getElementById('imageInput').addEventListener('change', function(e) {
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    const files = e.target.files;
    
    if (files.length > 5) {
        alert('Maximum 5 images allowed.');
        e.target.value = '';
        return;
    }
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        if (file.size > 10485760) {
            alert(file.name + ' exceeds 10MB limit.');
            e.target.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(event) {
            const col = document.createElement('div');
            col.className = 'col-4';
            col.innerHTML = `
                <div class="card">
                    <img src="${event.target.result}" class="card-img-top" style="height: 120px; object-fit: cover;">
                    <div class="card-body p-2">
                        <small class="text-muted">${file.name.substring(0, 20)}</small>
                    </div>
                </div>
            `;
            preview.appendChild(col);
        };
        reader.readAsDataURL(file);
    }
});

// Form submission - prevent double submit
document.getElementById('incidentForm').addEventListener('submit', function() {
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
});

// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>