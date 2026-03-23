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

// Verify table is accessible
$col_check = $conn->query("SHOW COLUMNS FROM tbl_incidents LIKE 'incident_id'");
if (!$col_check || $col_check->num_rows === 0) {
    die('Database error: tbl_incidents table is missing or inaccessible.');
}

// Clean up broken rows where incident_id = 0
$broken_check = $conn->query("SELECT COUNT(*) as cnt FROM tbl_incidents WHERE incident_id = 0");
if ($broken_check) {
    $broken_row = $broken_check->fetch_assoc();
    if ($broken_row['cnt'] > 0) {
        error_log("WARNING: Found " . $broken_row['cnt'] . " broken incident(s) with incident_id=0.");
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
    
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        
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
                if (!function_exists('generateReferenceNumber')) {
                    throw new Exception('generateReferenceNumber function not found');
                }
                
                $reference_no = generateReferenceNumber('INC');
                if (empty($reference_no)) {
                    throw new Exception('Failed to generate reference number');
                }
                
                error_log("Generated reference: $reference_no");
                
                $columns_check  = $conn->query("SHOW COLUMNS FROM tbl_incidents LIKE 'latitude'");
                $has_coordinates = ($columns_check && $columns_check->num_rows > 0);
                
                if ($has_coordinates && $latitude !== null && $longitude !== null) {
                    $sql = "INSERT INTO tbl_incidents (
                                reference_no, resident_id, incident_type, description,
                                location, severity, status, date_reported, latitude, longitude
                            ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?)";
                } else {
                    $sql = "INSERT INTO tbl_incidents (
                                reference_no, resident_id, incident_type, description,
                                location, severity, status, date_reported
                            ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())";
                }
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Database prepare error: ' . $conn->error);
                
                if ($has_coordinates && $latitude !== null && $longitude !== null) {
                    $stmt->bind_param("ssssssdd", $reference_no, $resident_id, $incident_type, $description, $location, $severity, $latitude, $longitude);
                } else {
                    $stmt->bind_param("sissss", $reference_no, $resident_id, $incident_type, $description, $location, $severity);
                }
                
                if (!$stmt->execute()) throw new Exception('Database execute error: ' . $stmt->error);
                if ($stmt->affected_rows <= 0) throw new Exception('Insert operation did not affect any rows');
                
                $incident_id = (int)$conn->insert_id;
                $stmt->close();
                
                error_log("insert_id returned: $incident_id");
                
                if ($incident_id <= 0) {
                    error_log("WARNING: insert_id returned 0. Falling back to reference_no lookup.");
                    $retrieve_stmt = $conn->prepare("SELECT incident_id FROM tbl_incidents WHERE reference_no = ? LIMIT 1");
                    if ($retrieve_stmt) {
                        $retrieve_stmt->bind_param("s", $reference_no);
                        $retrieve_stmt->execute();
                        $retrieve_result = $retrieve_stmt->get_result();
                        if ($retrieve_result && $retrieve_result->num_rows > 0) {
                            $row = $retrieve_result->fetch_assoc();
                            $incident_id = (int)$row['incident_id'];
                        }
                        $retrieve_stmt->close();
                    }
                }
                
                if ($incident_id <= 0) {
                    $success = 'Incident reported successfully! Reference No: <strong>' . htmlspecialchars($reference_no) . '</strong>';
                    $success .= '<br><small>Barangay officials will be notified.</small>';
                    $_SESSION['incident_success'] = $success;
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                
                // Handle image uploads
                $uploaded_images = [];
                if (isset($_FILES['incident_images']) && !empty($_FILES['incident_images']['name'][0])) {
                    $upload_dir = '../../uploads/incidents/';
                    if (!file_exists($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) $warnings[] = "Failed to create upload directory";
                    }
                    if (file_exists($upload_dir) && is_writable($upload_dir)) {
                        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                        $max_size = 10485760;
                        foreach ($_FILES['incident_images']['tmp_name'] as $key => $tmp_name) {
                            if (empty($tmp_name)) continue;
                            $file_name = $_FILES['incident_images']['name'][$key];
                            $file_size = $_FILES['incident_images']['size'][$key];
                            $file_tmp  = $_FILES['incident_images']['tmp_name'][$key];
                            $file_type = $_FILES['incident_images']['type'][$key];
                            if (!in_array($file_type, $allowed_types)) { $warnings[] = "File $file_name has invalid type and was skipped"; continue; }
                            if ($file_size > $max_size) { $warnings[] = "File $file_name exceeds 10MB and was skipped"; continue; }
                            $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            if (in_array($extension, ['jfif', 'jpe'])) $extension = 'jpg';
                            $new_filename = 'incident_' . $incident_id . '_' . time() . '_' . uniqid() . '.' . $extension;
                            $upload_path  = $upload_dir . $new_filename;
                            if (move_uploaded_file($file_tmp, $upload_path)) {
                                $uploaded_images[] = $new_filename;
                                $db_image_path = 'incidents/' . $new_filename;
                                $check_table = $conn->query("SHOW TABLES LIKE 'tbl_incident_images'");
                                if ($check_table && $check_table->num_rows > 0) {
                                    $img_stmt = $conn->prepare("INSERT INTO tbl_incident_images (incident_id, image_path) VALUES (?, ?)");
                                    $img_stmt->bind_param("is", $incident_id, $db_image_path);
                                    $img_stmt->execute();
                                    $img_stmt->close();
                                }
                            } else {
                                $warnings[] = "Failed to upload $file_name";
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
                    } catch (Exception $notify_error) {
                        error_log("Notification error: " . $notify_error->getMessage());
                        $warnings[] = "Incident created but notifications may have failed";
                    }
                }

                /* Notify the resident who filed the report */
                if (!empty($current_user_id) && $incident_id > 0) {
                    $notif_title = 'Incident Report Submitted';
                    $notif_msg   = "Your incident report \"{$incident_title}\" (Ref: {$reference_no}) has been submitted successfully. Barangay officials have been notified and will review it shortly.";

                    if (function_exists('createNotification')) {
                        try {
                            createNotification($conn, $current_user_id, $notif_title, $notif_msg, 'incident_reported', $incident_id, 'incident');
                        } catch (Exception $ne) {
                            error_log("Resident notification error: " . $ne->getMessage());
                        }
                    } else {
                        $rn = $conn->prepare("INSERT INTO tbl_notifications (user_id, title, message, type, reference_id, reference_type, is_read, created_at) VALUES (?, ?, ?, 'incident_reported', ?, 'incident', 0, NOW())");
                        if ($rn) {
                            $rn->bind_param('issi', $current_user_id, $notif_title, $notif_msg, $incident_id);
                            $rn->execute();
                            $rn->close();
                        }
                    }
                }
                
                $success = 'Incident reported successfully! Reference No: <strong>' . htmlspecialchars($reference_no) . '</strong>';
                if (!empty($uploaded_images)) $success .= '<br><small>' . count($uploaded_images) . ' image(s) uploaded.</small>';
                $success .= '<br><small>Barangay officials have been notified.</small>';
                
                $_SESSION['incident_success'] = $success;
                if (!empty($warnings)) $_SESSION['incident_warnings'] = $warnings;
                
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
                
            } catch (Exception $e) {
                error_log("INCIDENT CREATION ERROR: " . $e->getMessage());
                $error = 'Failed to submit incident report: ' . htmlspecialchars($e->getMessage());
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'Database error: Duplicate entry detected. Please try again.';
                }
            }
        }
    }
}

if (isset($_SESSION['incident_warnings'])) {
    $warnings = $_SESSION['incident_warnings'];
    unset($_SESSION['incident_warnings']);
}

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');

:root {
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-info:#3b82f6;--db-info-light:#dbeafe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}

*,*::before,*::after { box-sizing: border-box; }
body { font-family: 'Sora', sans-serif; background: var(--db-bg); color: var(--db-text); font-size: 13.5px; }

/* ── Hero ── */
.rm-hero { background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #1a4a2e 100%); padding: 28px 36px; margin-bottom: 24px; border-radius: 0 0 var(--db-radius-lg) var(--db-radius-lg); position: relative; overflow: hidden; }
.rm-hero__ring { position: absolute; border-radius: 50%; border: 1px solid rgba(255,255,255,.06); pointer-events: none; }
.rm-hero__ring--1 { width: 300px; height: 300px; top: -130px; right: -60px; }
.rm-hero__ring--2 { width: 180px; height: 180px; top: -50px; right: 70px; border-color: rgba(245,158,11,.12); }
.rm-hero__ring--3 { width: 100px; height: 100px; bottom: -40px; left: 40%; border-color: rgba(13,148,136,.14); }
.rm-hero__inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.rm-hero__left { display: flex; align-items: center; gap: 16px; }
.rm-hero__icon { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #f59e0b, #d97706); display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; box-shadow: 0 4px 16px rgba(245,158,11,.4); flex-shrink: 0; }
.rm-hero__title { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.4px; margin-bottom: 3px; }
.rm-hero__sub { font-size: 13px; color: rgba(255,255,255,.55); }

/* ── Alerts ── */
.db-alert { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-radius: var(--db-radius); margin-bottom: 16px; font-weight: 500; font-size: 13.5px; border-left: 4px solid; line-height: 1.6; }
.db-alert--success { background: var(--db-success-light); color: #065f46; border-color: var(--db-success); }
.db-alert--error   { background: var(--db-danger-light);  color: #7f1d1d; border-color: var(--db-danger); }
.db-alert--warning { background: var(--db-warning-light); color: #78350f; border-color: var(--db-warning); }
.db-alert--info    { background: var(--db-info-light);    color: #1e3a5f; border-color: var(--db-info); }
.db-alert i { margin-top: 2px; flex-shrink: 0; font-size: 15px; }
.db-alert__close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .5; padding: 0; line-height: 1; flex-shrink: 0; }
.db-alert__close:hover { opacity: 1; }
.db-alert__actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }

/* ── Panel ── */
.db-panel { background: var(--db-surf); border-radius: var(--db-radius-lg); border: 1px solid var(--db-border); box-shadow: var(--db-shadow); margin-bottom: 18px; overflow: hidden; animation: dbFadeUp .35s ease both; }
@keyframes dbFadeUp { from { opacity: 0; transform: translateY(10px) } to { opacity: 1; transform: translateY(0) } }
.db-panel__header { display: flex; align-items: center; gap: 10px; padding: 18px 22px; border-bottom: 1px solid var(--db-border); }
.db-panel__icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.db-panel__icon--amber  { background: var(--db-amber-light);   color: var(--db-amber-dark); }
.db-panel__icon--rose   { background: var(--db-rose-light);    color: var(--db-rose); }
.db-panel__icon--info   { background: var(--db-info-light);    color: var(--db-info); }
.db-panel__icon--navy   { background: var(--db-indigo-light);  color: var(--db-navy); }
.db-panel__title { font-size: 15px; font-weight: 700; margin: 0; }
.db-panel__body { padding: 22px; }

/* ── Buttons ── */
.db-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--db-radius-sm); font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .18s; white-space: nowrap; }
.db-btn--sm { padding: 6px 12px; font-size: 12px; }
.db-btn--lg { padding: 12px 24px; font-size: 14px; }
.db-btn--full { width: 100%; justify-content: center; }
.db-btn--primary { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; }
.db-btn--primary:hover { background: linear-gradient(135deg, var(--db-navy-light), #2748a0); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.25); color: #fff; }
.db-btn--ghost { background: var(--db-surf2); color: var(--db-text); border-color: var(--db-border); }
.db-btn--ghost:hover { background: var(--db-border); }
.db-btn--success { background: linear-gradient(135deg, var(--db-success), #059669); color: #fff; }
.db-btn--success:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(16,185,129,.3); color: #fff; }
.db-btn--danger { background: linear-gradient(135deg, var(--db-rose), #be123c); color: #fff; }
.db-btn:disabled { opacity: .6; pointer-events: none; }

/* ── Badges ── */
.db-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 20px; font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: .3px; white-space: nowrap; }
.db-badge--rose    { background: var(--db-rose-light);    color: #9f1239; }
.db-badge--amber   { background: var(--db-amber-light);   color: #92400e; }
.db-badge--sky     { background: var(--db-sky-light);     color: #0369a1; }
.db-badge--success { background: var(--db-success-light); color: #065f46; }
.db-badge--muted   { background: var(--db-surf2); color: var(--db-muted); border: 1px solid var(--db-border); }

/* ── Form Elements ── */
.db-form-group { margin-bottom: 20px; }
.db-form-label { display: block; font-weight: 700; font-size: 12px; color: var(--db-text); margin-bottom: 8px; text-transform: uppercase; letter-spacing: .4px; }
.db-form-label .req { color: var(--db-rose); margin-left: 2px; }
.db-form-control,
.db-form-select,
.db-form-textarea {
    width: 100%;
    border: 2px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    padding: 10px 14px;
    font-family: 'Sora', sans-serif;
    font-size: 13.5px;
    color: var(--db-text);
    background: var(--db-surf2);
    transition: border-color .18s, box-shadow .18s, background .18s;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
}
.db-form-control:focus,
.db-form-select:focus,
.db-form-textarea:focus {
    border-color: var(--db-navy-light);
    box-shadow: 0 0 0 4px rgba(28,52,97,.1);
    background: var(--db-surf);
}
.db-form-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px; cursor: pointer; }
.db-form-textarea { resize: vertical; min-height: 120px; line-height: 1.6; }
.db-form-hint { font-size: 11px; color: var(--db-muted); margin-top: 5px; display: flex; align-items: center; gap: 4px; }
.db-char-count { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--db-muted); float: right; margin-top: 5px; }
.db-char-count.warn { color: var(--db-warning); }
.db-char-count.danger { color: var(--db-danger); }

/* ── Severity Pills Selector ── */
.db-severity-group { display: flex; gap: 8px; flex-wrap: wrap; }
.db-severity-opt { position: relative; }
.db-severity-opt input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
.db-severity-opt label {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 20px; cursor: pointer;
    font-size: 12px; font-weight: 600; border: 2px solid transparent;
    transition: all .18s;
}
.db-severity-opt label.low    { background: var(--db-success-light); color: #065f46; border-color: #a7f3d0; }
.db-severity-opt label.medium { background: var(--db-amber-light);   color: #92400e; border-color: #fde68a; }
.db-severity-opt label.high   { background: #fff7ed; color: #9a3412;  border-color: #fed7aa; }
.db-severity-opt label.critical { background: var(--db-rose-light);  color: #9f1239; border-color: #fecdd3; }
.db-severity-opt input:checked + label { box-shadow: 0 0 0 3px rgba(28,52,97,.15); transform: translateY(-1px); border-width: 2px; }
.db-severity-opt input:checked + label.low      { border-color: var(--db-success); box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.db-severity-opt input:checked + label.medium   { border-color: var(--db-amber);   box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
.db-severity-opt input:checked + label.high     { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,.2); }
.db-severity-opt input:checked + label.critical { border-color: var(--db-rose);    box-shadow: 0 0 0 3px rgba(225,29,72,.2); }
.db-severity-opt label:hover { transform: translateY(-1px); }

/* ── Type Grid ── */
.db-type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
.db-type-opt { position: relative; }
.db-type-opt input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
.db-type-opt label {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 6px; padding: 12px 8px; border-radius: var(--db-radius-sm); cursor: pointer;
    font-size: 11.5px; font-weight: 600; border: 2px solid var(--db-border);
    background: var(--db-surf2); color: var(--db-muted); transition: all .18s; text-align: center;
    min-height: 72px;
}
.db-type-opt label i { font-size: 18px; transition: all .18s; }
.db-type-opt label:hover { border-color: var(--db-amber); background: var(--db-amber-light); color: var(--db-amber-dark); }
.db-type-opt label:hover i { transform: scale(1.15); }
.db-type-opt input:checked + label { border-color: var(--db-navy-light); background: #e8edf7; color: var(--db-navy); box-shadow: 0 0 0 3px rgba(28,52,97,.1); }
.db-type-opt input:checked + label i { color: var(--db-amber-dark); transform: scale(1.1); }

/* ── File Upload ── */
.db-file-zone { border: 2px dashed var(--db-border); border-radius: var(--db-radius); padding: 24px; text-align: center; cursor: pointer; transition: all .2s; background: var(--db-surf2); position: relative; }
.db-file-zone:hover, .db-file-zone.drag-over { border-color: var(--db-navy-light); background: #e8edf7; }
.db-file-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.db-file-zone__icon { font-size: 28px; color: var(--db-border); margin-bottom: 8px; }
.db-file-zone__label { font-size: 13px; font-weight: 600; color: var(--db-muted); }
.db-file-zone__hint  { font-size: 11px; color: var(--db-muted); opacity: .7; margin-top: 4px; }
.db-image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; margin-top: 14px; }
.db-img-thumb { position: relative; border-radius: var(--db-radius-sm); overflow: hidden; border: 2px solid var(--db-border); aspect-ratio: 1; background: var(--db-surf2); }
.db-img-thumb img { width: 100%; height: 100%; object-fit: cover; }
.db-img-thumb__name { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(13,27,54,.7); color: #fff; font-size: 9px; padding: 4px 6px; font-family: 'DM Mono', monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── Sidebar info cards ── */
.db-info-card { background: var(--db-surf); border-radius: var(--db-radius); border: 1px solid var(--db-border); box-shadow: var(--db-shadow); margin-bottom: 14px; overflow: hidden; }
.db-info-card__header { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--db-border); }
.db-info-card__icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
.db-info-card__title { font-size: 13px; font-weight: 700; margin: 0; }
.db-info-card__body { padding: 14px 18px; }
.db-guideline-list { list-style: none; padding: 0; margin: 0; }
.db-guideline-list li { display: flex; align-items: flex-start; gap: 8px; padding: 7px 0; border-bottom: 1px solid var(--db-border); font-size: 12.5px; color: var(--db-text); line-height: 1.5; }
.db-guideline-list li:last-child { border-bottom: none; }
.db-guideline-list li i { color: var(--db-amber-dark); margin-top: 2px; flex-shrink: 0; font-size: 11px; }
.db-hotline-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--db-border); }
.db-hotline-row:last-child { border-bottom: none; }
.db-hotline-label { font-size: 12px; color: var(--db-muted); }
.db-hotline-num { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 700; color: var(--db-text); }
.db-hotline-num.emergency { color: var(--db-rose); }

/* ── Layout ── */
.ri-layout { display: grid; grid-template-columns: 1fr 300px; gap: 18px; padding: 0 24px 32px; }
@media (max-width: 992px) { .ri-layout { grid-template-columns: 1fr; } }
@media (max-width: 768px) { .rm-hero { padding: 20px; border-radius: 0; } .ri-layout { padding: 0 16px 24px; } }

/* ── Divider ── */
.db-divider { height: 1px; background: var(--db-border); margin: 4px 0 20px; }
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="rm-hero__title">Report Incident</div>
                <div class="rm-hero__sub">Submit an incident report to barangay authorities</div>
            </div>
        </div>
        <a href="view-incidents.php" class="db-btn db-btn--ghost" style="background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff;">
            <i class="fas fa-list"></i> View Incidents
        </a>
    </div>
</div>

<div class="ri-layout">
    <!-- Main Form -->
    <div>

        <?php if ($success): ?>
        <div class="db-alert db-alert--success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <div style="flex:1">
                <?php echo $success; ?>
                <div class="db-alert__actions">
                    <a href="view-incidents.php" class="db-btn db-btn--sm db-btn--success"><i class="fas fa-eye"></i> View My Incidents</a>
                    <button onclick="location.reload()" class="db-btn db-btn--sm db-btn--ghost"><i class="fas fa-plus"></i> Report Another</button>
                </div>
            </div>
            <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="db-alert db-alert--error">
            <i class="fas fa-exclamation-circle"></i>
            <div style="flex:1"><?php echo $error; ?></div>
            <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
        </div>
        <?php endif; ?>

        <?php foreach ($warnings as $warning): ?>
        <div class="db-alert db-alert--warning">
            <i class="fas fa-exclamation-triangle"></i>
            <div style="flex:1"><?php echo htmlspecialchars($warning); ?></div>
            <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
        </div>
        <?php endforeach; ?>

        <form method="POST" action="" enctype="multipart/form-data" id="incidentForm">
            <?php echo getCSRFField(); ?>

            <!-- Incident Type Panel -->
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-tag"></i></div>
                    <h2 class="db-panel__title">Incident Type <span style="color:var(--db-rose);font-size:13px;">*</span></h2>
                </div>
                <div class="db-panel__body">
                    <div class="db-type-grid" id="typeGrid">
                        <?php
                        $types = [
                            'Crime'            => 'fa-user-secret',
                            'Fire'             => 'fa-fire',
                            'Accident'         => 'fa-car-crash',
                            'Health Emergency' => 'fa-ambulance',
                            'Violation'        => 'fa-gavel',
                            'Natural Disaster' => 'fa-cloud-showers-heavy',
                            'Others'           => 'fa-question-circle',
                        ];
                        $selected_type = isset($_POST['incident_type']) ? $_POST['incident_type'] : '';
                        foreach ($types as $t => $icon):
                            $checked = ($selected_type === $t) ? 'checked' : '';
                            $id = 'type_' . strtolower(str_replace(' ', '_', $t));
                        ?>
                        <div class="db-type-opt">
                            <input type="radio" name="incident_type" value="<?php echo $t; ?>" id="<?php echo $id; ?>" <?php echo $checked; ?> required>
                            <label for="<?php echo $id; ?>">
                                <i class="fas <?php echo $icon; ?>"></i>
                                <?php echo htmlspecialchars($t); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="typeError" style="display:none;" class="db-form-hint" style="color:var(--db-danger);"><i class="fas fa-exclamation-circle" style="color:var(--db-danger)"></i> Please select an incident type</div>
                </div>
            </div>

            <!-- Details Panel -->
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-file-alt"></i></div>
                    <h2 class="db-panel__title">Incident Details</h2>
                </div>
                <div class="db-panel__body">

                    <div class="db-form-group">
                        <label class="db-form-label" for="location">
                            <i class="fas fa-map-marker-alt" style="color:var(--db-rose)"></i>
                            Location <span class="req">*</span>
                        </label>
                        <input type="text" name="location" id="location" class="db-form-control"
                            placeholder="e.g. Purok 1, Near the Church, Barangay Hall"
                            required maxlength="200"
                            value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                        <div class="db-form-hint"><i class="fas fa-info-circle"></i> Be as specific as possible to help responders locate the incident</div>
                    </div>

                    <div class="db-form-group">
                        <label class="db-form-label" for="description">
                            <i class="fas fa-align-left" style="color:var(--db-sky)"></i>
                            Description <span class="req">*</span>
                        </label>
                        <textarea name="description" id="description" class="db-form-textarea"
                            placeholder="Provide a detailed description of the incident — what happened, who was involved, any injuries or damages..."
                            required minlength="10" maxlength="2000"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        <span class="db-char-count" id="charCount">0 / 2000</span>
                        <div class="db-form-hint"><i class="fas fa-info-circle"></i> Minimum 10 characters required</div>
                    </div>

                    <div class="db-form-group" style="margin-bottom:0">
                        <label class="db-form-label">
                            <i class="fas fa-tachometer-alt" style="color:var(--db-amber-dark)"></i>
                            Severity Level <span class="req">*</span>
                        </label>
                        <?php $sel_sev = isset($_POST['severity']) ? $_POST['severity'] : 'Medium'; ?>
                        <div class="db-severity-group">
                            <div class="db-severity-opt">
                                <input type="radio" name="severity" value="Low" id="sev_low" <?php echo $sel_sev==='Low'?'checked':''; ?>>
                                <label for="sev_low" class="low"><i class="fas fa-circle"></i> Low</label>
                            </div>
                            <div class="db-severity-opt">
                                <input type="radio" name="severity" value="Medium" id="sev_medium" <?php echo $sel_sev==='Medium'?'checked':''; ?>>
                                <label for="sev_medium" class="medium"><i class="fas fa-exclamation-circle"></i> Medium</label>
                            </div>
                            <div class="db-severity-opt">
                                <input type="radio" name="severity" value="High" id="sev_high" <?php echo $sel_sev==='High'?'checked':''; ?>>
                                <label for="sev_high" class="high"><i class="fas fa-exclamation-triangle"></i> High</label>
                            </div>
                            <div class="db-severity-opt">
                                <input type="radio" name="severity" value="Critical" id="sev_critical" <?php echo $sel_sev==='Critical'?'checked':''; ?>>
                                <label for="sev_critical" class="critical"><i class="fas fa-fire"></i> Critical</label>
                            </div>
                        </div>
                        <div class="db-form-hint" style="margin-top:8px"><i class="fas fa-info-circle"></i> <strong>Critical</strong> requires immediate response. Choose honestly to help prioritize resources.</div>
                    </div>

                </div>
            </div>

            <!-- Evidence Panel -->
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__icon db-panel__icon--info"><i class="fas fa-camera"></i></div>
                    <h2 class="db-panel__title">Evidence Photos <span style="font-size:11px;color:var(--db-muted);font-weight:400">(Optional)</span></h2>
                    <span class="db-badge db-badge--muted" style="margin-left:auto">Up to 5 images</span>
                </div>
                <div class="db-panel__body">
                    <div class="db-file-zone" id="fileZone">
                        <input type="file" name="incident_images[]" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" multiple id="imageInput">
                        <div class="db-file-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="db-file-zone__label">Click to upload or drag & drop images</div>
                        <div class="db-file-zone__hint">JPG, PNG, GIF, WEBP — max 10MB per file, up to 5 files</div>
                    </div>
                    <div id="imagePreview" class="db-image-grid"></div>
                </div>
            </div>

            <!-- Submit -->
            <div class="db-panel">
                <div class="db-panel__body">
                    <div class="db-alert db-alert--info" style="margin-bottom:16px">
                        <i class="fas fa-shield-alt"></i>
                        <div>Your report will be reviewed by barangay officials and you will receive notifications on updates. For life-threatening emergencies, <strong>call 911 immediately</strong>.</div>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="db-btn db-btn--primary db-btn--lg" id="submitBtn" style="flex:1">
                            <i class="fas fa-paper-plane"></i> Submit Incident Report
                        </button>
                        <a href="view-incidents.php" class="db-btn db-btn--ghost db-btn--lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </div>

        </form>
    </div>

    <!-- Sidebar -->
    <div>
        <!-- Guidelines -->
        <div class="db-info-card">
            <div class="db-info-card__header">
                <div class="db-info-card__icon" style="background:var(--db-info-light);color:var(--db-info)"><i class="fas fa-info-circle"></i></div>
                <h5 class="db-info-card__title">Reporting Guidelines</h5>
            </div>
            <div class="db-info-card__body">
                <ul class="db-guideline-list">
                    <li><i class="fas fa-check-circle"></i> Provide accurate and complete information</li>
                    <li><i class="fas fa-check-circle"></i> Upload clear photos as evidence if available</li>
                    <li><i class="fas fa-check-circle"></i> Select the appropriate severity level</li>
                    <li><i class="fas fa-check-circle"></i> For life-threatening emergencies, call 911 first</li>
                    <li><i class="fas fa-check-circle"></i> You'll receive a reference number for tracking</li>
                    <li><i class="fas fa-check-circle"></i> Response time depends on severity and availability</li>
                </ul>
            </div>
        </div>

        <!-- Emergency Hotlines -->
        <div class="db-info-card">
            <div class="db-info-card__header">
                <div class="db-info-card__icon" style="background:var(--db-rose-light);color:var(--db-rose)"><i class="fas fa-phone"></i></div>
                <h5 class="db-info-card__title" style="color:var(--db-rose)">Emergency Hotlines</h5>
            </div>
            <div class="db-info-card__body">
                <div class="db-hotline-row">
                    <span class="db-hotline-label"><i class="fas fa-phone-alt" style="color:var(--db-rose);margin-right:6px"></i>Emergency</span>
                    <span class="db-hotline-num emergency">911</span>
                </div>
                <div class="db-hotline-row">
                    <span class="db-hotline-label"><i class="fas fa-fire" style="color:#f97316;margin-right:6px"></i>Fire Bureau</span>
                    <span class="db-hotline-num">(02) 8426-0219</span>
                </div>
                <div class="db-hotline-row">
                    <span class="db-hotline-label"><i class="fas fa-shield-alt" style="color:var(--db-navy-light);margin-right:6px"></i>Police</span>
                    <span class="db-hotline-num">117</span>
                </div>
                <div class="db-hotline-row">
                    <span class="db-hotline-label"><i class="fas fa-home" style="color:var(--db-teal);margin-right:6px"></i>Barangay</span>
                    <span class="db-hotline-num"><?php echo htmlspecialchars(BARANGAY_CONTACT ?? '(123) 456-7890'); ?></span>
                </div>
            </div>
        </div>

        <!-- Severity Reference -->
        <div class="db-info-card">
            <div class="db-info-card__header">
                <div class="db-info-card__icon" style="background:var(--db-amber-light);color:var(--db-amber-dark)"><i class="fas fa-tachometer-alt"></i></div>
                <h5 class="db-info-card__title">Severity Reference</h5>
            </div>
            <div class="db-info-card__body" style="display:flex;flex-direction:column;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="db-badge db-badge--success"><i class="fas fa-circle"></i> Low</span>
                    <span style="font-size:12px;color:var(--db-muted)">Minor, no immediate danger</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="db-badge db-badge--amber"><i class="fas fa-exclamation-circle"></i> Medium</span>
                    <span style="font-size:12px;color:var(--db-muted)">Moderate, needs attention</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="db-badge db-badge--rose" style="background:#fff7ed;color:#9a3412"><i class="fas fa-exclamation-triangle"></i> High</span>
                    <span style="font-size:12px;color:var(--db-muted)">Urgent, prompt response needed</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="db-badge db-badge--rose"><i class="fas fa-fire"></i> Critical</span>
                    <span style="font-size:12px;color:var(--db-muted)">Immediate response required</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Character counter
const descEl = document.getElementById('description');
const charCountEl = document.getElementById('charCount');
function updateCount() {
    const len = descEl.value.length;
    charCountEl.textContent = len + ' / 2000';
    charCountEl.className = 'db-char-count' + (len > 1800 ? ' danger' : len > 1500 ? ' warn' : '');
}
descEl.addEventListener('input', updateCount);
updateCount();

// Image preview
const imageInput = document.getElementById('imageInput');
const previewGrid = document.getElementById('imagePreview');
const fileZone    = document.getElementById('fileZone');

imageInput.addEventListener('change', function(e) {
    previewGrid.innerHTML = '';
    const files = e.target.files;
    if (files.length > 5) {
        alert('Maximum 5 images allowed. Please select up to 5 files.');
        e.target.value = '';
        return;
    }
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        if (file.size > 10485760) {
            alert(file.name + ' exceeds the 10MB limit and was removed.');
            e.target.value = '';
            previewGrid.innerHTML = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(ev) {
            const thumb = document.createElement('div');
            thumb.className = 'db-img-thumb';
            thumb.innerHTML = `<img src="${ev.target.result}" alt="${file.name}"><div class="db-img-thumb__name">${file.name}</div>`;
            previewGrid.appendChild(thumb);
        };
        reader.readAsDataURL(file);
    }
});

// Drag & drop
fileZone.addEventListener('dragover', e => { e.preventDefault(); fileZone.classList.add('drag-over'); });
fileZone.addEventListener('dragleave', () => fileZone.classList.remove('drag-over'));
fileZone.addEventListener('drop', e => {
    e.preventDefault();
    fileZone.classList.remove('drag-over');
    imageInput.files = e.dataTransfer.files;
    imageInput.dispatchEvent(new Event('change'));
});

// Prevent double-submit
document.getElementById('incidentForm').addEventListener('submit', function(e) {
    // Validate type selection
    const typeSelected = document.querySelector('input[name="incident_type"]:checked');
    if (!typeSelected) {
        e.preventDefault();
        document.getElementById('typeError').style.display = 'flex';
        document.getElementById('typeGrid').scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
});

// Hide type error on selection
document.querySelectorAll('input[name="incident_type"]').forEach(r => {
    r.addEventListener('change', () => document.getElementById('typeError').style.display = 'none');
});

// Auto-dismiss alerts after 6s
setTimeout(function() {
    document.querySelectorAll('.db-alert--success, .db-alert--warning').forEach(a => {
        a.style.transition = 'opacity .4s';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 400);
    });
}, 6000);
</script>

<?php include '../../includes/footer.php'; ?>