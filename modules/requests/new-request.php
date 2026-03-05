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
        if (!$stmt) throw new Exception('Database prepare failed: ' . $conn->error);
        
        $stmt->bind_param("iissi", $resident_id, $request_type_id, $purpose, $status, $payment_status);
        if (!$stmt->execute()) throw new Exception('Failed to create request: ' . $stmt->error);
        
        $request_id = $conn->insert_id;
        $stmt->close();

        $type_name_query = "SELECT request_type_name FROM tbl_request_types WHERE request_type_id = ?";
        $type_name_stmt = $conn->prepare($type_name_query);
        $type_name_stmt->bind_param("i", $request_type_id);
        $type_name_stmt->execute();
        $type_data = $type_name_stmt->get_result()->fetch_assoc();
        $request_type_name = $type_data['request_type_name'] ?? 'Document';
        $type_name_stmt->close();

        $resident_info_query = "SELECT first_name, last_name FROM tbl_residents WHERE resident_id = ?";
        $resident_info_stmt = $conn->prepare($resident_info_query);
        $resident_info_stmt->bind_param("i", $resident_id);
        $resident_info_stmt->execute();
        $resident_info = $resident_info_stmt->get_result()->fetch_assoc();
        $resident_info_stmt->close();
        $resident_name = $resident_info['first_name'] . ' ' . $resident_info['last_name'];

        $admin_query = "SELECT user_id FROM tbl_users WHERE role IN ('Super Admin', 'Super Administrator', 'Staff', 'admin') AND is_active = 1";
        $admin_result = $conn->query($admin_query);

        $notification_title   = "New Document Request";
        $notification_message = "$resident_name has submitted a request for $request_type_name";
        $notification_type    = "document_request_submitted";
        $reference_type       = "request";

        if ($admin_result && $admin_result->num_rows > 0) {
            while ($admin = $admin_result->fetch_assoc()) {
                $admin_id = $admin['user_id'];
                $notif_query = "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
                $notif_stmt = $conn->prepare($notif_query);
                if ($notif_stmt) {
                    $notif_stmt->bind_param("ississ", $admin_id, $notification_type, $reference_type, $request_id, $notification_title, $notification_message);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }
            }
        }

        $res_notif_query = "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
        $res_notif_stmt = $conn->prepare($res_notif_query);
        if ($res_notif_stmt) {
            $res_notif_type    = "document_request_submitted";
            $res_ref_type      = "request";
            $res_notif_title   = "Request Submitted Successfully";
            $res_notif_message = "Your request for {$request_type_name} has been submitted and is now pending review.";
            $res_notif_stmt->bind_param("ississ", $user_id, $res_notif_type, $res_ref_type, $request_id, $res_notif_title, $res_notif_message);
            $res_notif_stmt->execute();
            $res_notif_stmt->close();
        }

        // FILE UPLOAD
        $upload_dir = '../../uploads/requests/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
        if (!is_writable($upload_dir)) throw new Exception('Upload directory is not writable.');
        
        $request_upload_dir = $upload_dir . $request_id . '/';
        if (!file_exists($request_upload_dir)) mkdir($request_upload_dir, 0755, true);

        $files_saved_to_db = 0;
        $upload_errors     = [];

        if (isset($_FILES['requirements']) && is_array($_FILES['requirements']['name'])) {
            $file_count = count($_FILES['requirements']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['requirements']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($_FILES['requirements']['error'][$i] !== UPLOAD_ERR_OK) { $upload_errors[] = "Upload error slot $i"; continue; }
                
                $filename       = $_FILES['requirements']['name'][$i];
                $file_tmp       = $_FILES['requirements']['tmp_name'][$i];
                $file_size      = $_FILES['requirements']['size'][$i];
                $file_type      = $_FILES['requirements']['type'][$i];
                $requirement_id = isset($_POST['requirement_ids'][$i]) ? intval($_POST['requirement_ids'][$i]) : null;
                
                $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($file_ext, ['jpg','jpeg','png','gif','pdf','bmp','webp','svg','jfif'])) { $upload_errors[] = "Invalid type: $filename"; continue; }
                if ($file_size > 5 * 1024 * 1024) { $upload_errors[] = "Too large: $filename"; continue; }
                
                $new_filename    = 'req_' . $request_id . '_' . uniqid() . '.' . $file_ext;
                $server_file_path = $request_upload_dir . $new_filename;
                $db_file_path    = 'uploads/requests/' . $request_id . '/' . $new_filename;
                
                if (move_uploaded_file($file_tmp, $server_file_path)) {
                    $actual_size = filesize($server_file_path);
                    if ($requirement_id === 0) $requirement_id = null;
                    $insert_stmt = $conn->prepare("INSERT INTO tbl_request_attachments (request_id, requirement_id, file_name, file_path, file_type, file_size, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("iisssi", $request_id, $requirement_id, $filename, $db_file_path, $file_type, $actual_size);
                        if ($insert_stmt->execute()) $files_saved_to_db++;
                        else { $upload_errors[] = "DB error: $filename"; @unlink($server_file_path); }
                        $insert_stmt->close();
                    }
                } else {
                    $upload_errors[] = "Move failed: $filename";
                }
            }
        }

        if ($files_saved_to_db > 0 && empty($upload_errors)) {
            $_SESSION['success_message'] = "Request submitted successfully with $files_saved_to_db attachment(s)!";
        } elseif ($files_saved_to_db > 0) {
            $_SESSION['success_message'] = "Request submitted! $files_saved_to_db file(s) uploaded. " . count($upload_errors) . " failed.";
        } else {
            $_SESSION['success_message'] = 'Request submitted successfully!';
        }

        if (ob_get_length()) ob_end_clean();
        header('Location: my-requests.php', true, 303);
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Request submission error: " . $e->getMessage());
    }
}

include '../../includes/header.php';
?>

<link rel="stylesheet" href="/barangaylink1/assets/css/_db_shared.css">
<style>
/* page-specific overrides only */

:root {
    --db-navy:#0d1b36;
    --db-navy-mid:#152849;
    --db-navy-light:#1c3461;
    --db-amber:#f59e0b;
    --db-amber-light:#fef3c7;
    --db-amber-dark:#b45309;
    --db-teal:#0d9488;
    --db-teal-light:#ccfbf1;
    --db-rose:#e11d48;
    --db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;
    --db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;
    --db-indigo-light:#e0e7ff;
    --db-success:#10b981;
    --db-success-light:#d1fae5;
    --db-warning:#f59e0b;
    --db-warning-light:#fef3c7;
    --db-danger:#ef4444;
    --db-danger-light:#fee2e2;
    --db-info:#3b82f6;
    --db-info-light:#dbeafe;
    --db-bg:#eef2f7;
    --db-surf:#ffffff;
    --db-surf2:#f8fafc;
    --db-border:#e2e8f0;
    --db-text:#0f172a;
    --db-muted:#64748b;
    --db-radius:14px;
    --db-radius-sm:8px;
    --db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}

*, *::before, *::after { box-sizing: border-box; }
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
.db-alert { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: var(--db-radius); margin-bottom: 16px; font-weight: 500; font-size: 13.5px; border-left: 4px solid; }
.db-alert--success { background: var(--db-success-light); color: #065f46; border-color: var(--db-success); }
.db-alert--error { background: var(--db-danger-light); color: #7f1d1d; border-color: var(--db-danger); }
.db-alert--info { background: var(--db-info-light); color: #1e40af; border-color: var(--db-info); }
.db-alert--warning { background: var(--db-warning-light); color: #92400e; border-color: var(--db-warning); }
.db-alert__close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .6; }

/* ── Panels ── */
.db-panel { background: var(--db-surf); border-radius: var(--db-radius-lg); border: 1px solid var(--db-border); box-shadow: var(--db-shadow); margin-bottom: 18px; overflow: hidden; animation: dbFadeUp .35s ease both; }
@keyframes dbFadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.db-panel__header { display: flex; align-items: center; justify-content: space-between; padding: 16px 22px; border-bottom: 1px solid var(--db-border); gap: 10px; flex-wrap: wrap; background: linear-gradient(135deg, #f8fafc 0%, #fff 100%); }
.db-panel__title { display: flex; align-items: center; gap: 10px; }
.db-panel__title h2 { font-size: 14px; font-weight: 700; margin: 0; }
.db-panel__icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.db-panel__icon--amber { background: var(--db-amber-light); color: var(--db-amber-dark); }
.db-panel__icon--sky   { background: var(--db-sky-light);   color: var(--db-sky); }
.db-panel__icon--navy  { background: var(--db-indigo-light); color: var(--db-navy); }
.db-panel__icon--success { background: var(--db-success-light); color: var(--db-success); }
.db-panel__icon--rose  { background: var(--db-rose-light); color: var(--db-rose); }
.db-panel__body { padding: 22px; }

/* ── Buttons ── */
.db-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--db-radius-sm); font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .18s; white-space: nowrap; }
.db-btn--sm { padding: 6px 12px; font-size: 12px; }
.db-btn--primary { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; }
.db-btn--primary:hover { background: linear-gradient(135deg, var(--db-navy-light), #2748a0); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.25); color: #fff; }
.db-btn--ghost { background: var(--db-surf2); color: var(--db-text); border-color: var(--db-border); }
.db-btn--ghost:hover { background: var(--db-border); color: var(--db-text); }
.db-btn--danger { background: linear-gradient(135deg, #dc2626, var(--db-rose)); color: #fff; }
.db-btn--danger:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(225,29,72,.25); color: #fff; }
.db-btn--success { background: linear-gradient(135deg, #059669, var(--db-success)); color: #fff; }
.db-btn--success:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(16,185,129,.25); color: #fff; }

/* ── Badges ── */
.db-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 20px; font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: .3px; white-space: nowrap; }
.db-badge--rose    { background: var(--db-rose-light);    color: #9f1239; }
.db-badge--amber   { background: var(--db-amber-light);   color: #92400e; }
.db-badge--sky     { background: var(--db-sky-light);     color: #0369a1; }
.db-badge--success { background: var(--db-success-light); color: #065f46; }
.db-badge--muted   { background: var(--db-surf2); color: var(--db-muted); border: 1px solid var(--db-border); }
.db-badge--navy    { background: #e8edf7; color: var(--db-navy); }

/* ── Form Controls ── */
.db-form-label { display: block; font-size: 12px; font-weight: 600; color: var(--db-muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.db-form-label .req { color: var(--db-rose); }
.db-form-control, .db-form-select, .db-form-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    background: var(--db-surf2);
    color: var(--db-text);
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    transition: border-color .2s, box-shadow .2s, background .2s;
    outline: none;
}
.db-form-control:focus, .db-form-select:focus, .db-form-textarea:focus {
    border-color: var(--db-navy-light);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(28,52,97,.1);
}
.db-form-control[readonly], .db-form-textarea[readonly] {
    background: #f1f5f9;
    color: var(--db-muted);
    cursor: default;
}
.db-form-textarea { resize: vertical; min-height: 90px; }
.db-form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
.db-form-group { margin-bottom: 16px; }
.db-form-row { display: grid; gap: 16px; }
.db-form-row--2 { grid-template-columns: 1fr 1fr; }
.db-form-row--3 { grid-template-columns: 1fr 1fr 1fr; }

/* ── Info box ── */
.db-info-box { background: var(--db-info-light); border-left: 4px solid var(--db-info); border-radius: var(--db-radius-sm); padding: 12px 14px; font-size: 12.5px; color: #1e40af; display: flex; gap: 10px; align-items: flex-start; }
.db-info-box--warning { background: var(--db-warning-light); border-left-color: var(--db-warning); color: #92400e; }
.db-info-box--success { background: var(--db-success-light); border-left-color: var(--db-success); color: #065f46; }

/* ── Sidebar Cards ── */
.db-side-card { background: var(--db-surf); border-radius: var(--db-radius); border: 1px solid var(--db-border); box-shadow: var(--db-shadow); margin-bottom: 14px; overflow: hidden; }
.db-side-card__header { padding: 13px 16px; border-bottom: 1px solid var(--db-border); display: flex; align-items: center; gap: 8px; background: linear-gradient(135deg, #f8fafc, #fff); }
.db-side-card__header span { font-size: 13px; font-weight: 700; }
.db-side-card__body { padding: 14px 16px; }
.db-checklist { list-style: none; margin: 0; padding: 0; }
.db-checklist li { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 12.5px; border-bottom: 1px solid var(--db-border); }
.db-checklist li:last-child { border-bottom: none; }
.db-checklist li i { color: var(--db-success); font-size: 11px; flex-shrink: 0; }

/* ── Processing Table ── */
.db-proc-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.db-proc-table tr { border-bottom: 1px solid var(--db-border); }
.db-proc-table tr:last-child { border-bottom: none; }
.db-proc-table td { padding: 7px 4px; }
.db-proc-table td:last-child { text-align: right; }
.db-proc-days { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--db-navy); font-weight: 600; background: var(--db-amber-light); padding: 2px 7px; border-radius: 20px; }

/* ── File Upload ── */
.db-upload-zone { border: 2px dashed var(--db-border); border-radius: var(--db-radius-sm); padding: 20px 14px; text-align: center; cursor: pointer; transition: all .2s; background: var(--db-surf2); }
.db-upload-zone:hover { border-color: var(--db-navy-light); background: #f0f4ff; }
.db-upload-zone.has-file { border-style: solid; border-color: var(--db-success); background: var(--db-success-light); }
.db-upload-zone__icon { font-size: 28px; color: var(--db-muted); margin-bottom: 8px; }
.db-upload-zone.has-file .db-upload-zone__icon { color: var(--db-success); }
.db-upload-zone__text { font-size: 12.5px; color: var(--db-muted); font-weight: 500; }
.db-upload-zone__hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
.db-req-item { border: 1px solid var(--db-border); border-radius: var(--db-radius-sm); padding: 14px; margin-bottom: 12px; background: var(--db-surf); }
.db-req-item--mandatory { border-left: 3px solid var(--db-rose); }
.db-req-item--optional  { border-left: 3px solid #94a3b8; }
.db-req-item__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.db-req-item__name { font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px; }
.db-preview-container { margin-top: 12px; background: var(--db-surf2); border: 1px solid var(--db-border); border-radius: var(--db-radius-sm); padding: 10px; text-align: center; }
.db-preview-image { max-width: 100%; max-height: 180px; border-radius: 6px; border: 1px solid var(--db-border); }

/* ── Fee badge ── */
.db-fee-banner { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, var(--db-amber-light), #fffbeb); border: 1px solid #fde68a; border-radius: var(--db-radius-sm); font-size: 13px; }
.db-fee-banner__amount { font-family: 'DM Mono', monospace; font-size: 15px; font-weight: 700; color: var(--db-amber-dark); }
.db-fee-banner__free   { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 700; color: var(--db-success); }

/* ── Loading Overlay ── */
.db-loading-overlay { position: fixed; inset: 0; background: rgba(13,27,54,.55); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.db-loading-overlay.active { display: flex; }
.db-loading-box { background: var(--db-surf); padding: 32px 40px; border-radius: var(--db-radius-lg); text-align: center; box-shadow: var(--db-shadow-lg); min-width: 280px; }
.db-loading-box .spinner { width: 44px; height: 44px; border: 4px solid var(--db-border); border-top-color: var(--db-navy); border-radius: 50%; animation: dbSpin .7s linear infinite; margin: 0 auto 16px; }
@keyframes dbSpin { to { transform: rotate(360deg); } }
.db-loading-box h5 { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.db-loading-box p  { font-size: 12.5px; color: var(--db-muted); }

/* ── Success modal visuals ── */
.db-success-icon { width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 3px solid var(--db-success); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 28px; color: var(--db-success); }
.db-summary-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.db-summary-item label { font-size: 11px; color: var(--db-muted); text-transform: uppercase; letter-spacing: .4px; display: block; margin-bottom: 3px; }
.db-summary-item strong { font-size: 13px; }

/* ── Modal ── */
.db-modal-header { background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)); color: #fff; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; }
.db-modal-header h5 { font-size: 15px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
.db-modal-header .btn-close { filter: invert(1); }

@media (max-width: 768px) {
    .rm-hero { padding: 20px; border-radius: 0; }
    .db-form-row--2, .db-form-row--3 { grid-template-columns: 1fr; }
    .db-summary-row { grid-template-columns: 1fr; }
}
</style>

<!-- Loading Overlay -->
<div class="db-loading-overlay" id="loadingOverlay">
    <div class="db-loading-box">
        <div class="spinner"></div>
        <h5>Submitting Your Request</h5>
        <p>Please wait while we process your documents...</p>
        <p><strong id="uploadProgress">Preparing upload...</strong></p>
    </div>
</div>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">New Document Request</div>
                <div class="rm-hero__sub">Submit a request for barangay documents</div>
            </div>
        </div>
        <a href="my-requests.php" class="db-btn db-btn--danger"><i class="fas fa-arrow-left"></i> Back to Requests</a>
    </div>
</div>

<div style="padding: 0 24px 32px;">

<?php if (isset($error)): ?>
<div class="db-alert db-alert--error">
    <i class="fas fa-exclamation-circle"></i>
    <?php echo htmlspecialchars($error); ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<form method="POST" action="" id="requestForm" enctype="multipart/form-data">

<div style="display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start;">

    <!-- ── Left Column ── -->
    <div>

        <!-- Personal Information -->
        <div class="db-panel" style="animation-delay:.05s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-user"></i></div>
                    <h2>Personal Information</h2>
                </div>
                <span class="db-badge db-badge--muted"><i class="fas fa-lock"></i> Auto-filled</span>
            </div>
            <div class="db-panel__body">
                <div class="db-form-row db-form-row--2">
                    <div class="db-form-group">
                        <label class="db-form-label">Full Name</label>
                        <input type="text" class="db-form-control"
                               value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ?? '') . ' ' . $resident['last_name']); ?>"
                               readonly>
                    </div>
                    <div class="db-form-group">
                        <label class="db-form-label">Contact Number</label>
                        <input type="text" class="db-form-control"
                               value="<?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?>"
                               readonly>
                    </div>
                </div>
                <div class="db-form-group" style="margin-bottom:0">
                    <label class="db-form-label">Address</label>
                    <textarea class="db-form-textarea" rows="2" readonly><?php echo htmlspecialchars($resident['address'] ?? 'N/A'); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Request Details -->
        <div class="db-panel" style="animation-delay:.1s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-file-invoice"></i></div>
                    <h2>Request Details</h2>
                </div>
            </div>
            <div class="db-panel__body">

                <div class="db-form-group">
                    <label class="db-form-label">Document Type <span class="req">*</span></label>
                    <select name="request_type_id" id="request_type_id" class="db-form-select" required>
                        <option value="">Select Document Type</option>
                        <?php foreach ($request_types as $type): ?>
                            <option value="<?php echo $type['request_type_id']; ?>"
                                    data-typename="<?php echo htmlspecialchars($type['type_name']); ?>"
                                    data-fee="<?php echo $type['fee']; ?>">
                                <?php echo htmlspecialchars($type['type_name']); ?>
                                <?php if ($type['fee'] > 0): ?>
                                    — PHP <?php echo number_format($type['fee'], 2); ?>
                                <?php else: ?>
                                    — Free
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Fee Banner -->
                <div id="feeBanner" style="display:none; margin-bottom:16px;">
                    <div class="db-fee-banner">
                        <i class="fas fa-coins" style="color:var(--db-amber-dark); font-size:16px;"></i>
                        <div>
                            <div style="font-size:11px; color:var(--db-amber-dark); font-weight:600; text-transform:uppercase; letter-spacing:.4px;">Processing Fee</div>
                            <span id="feeAmountDisplay" class="db-fee-banner__amount"></span>
                        </div>
                        <div style="margin-left:auto; font-size:11px; color:var(--db-muted);">Confirmed by admin after processing</div>
                    </div>
                </div>

                <div class="db-form-group">
                    <label class="db-form-label">Purpose <span class="req">*</span></label>
                    <input type="text" name="purpose" class="db-form-control"
                           placeholder="e.g., Employment, School Requirements, Bank Requirements" required>
                </div>

                <!-- Business Fields -->
                <div id="businessFields" style="display:none;">
                    <div class="db-alert db-alert--info" style="margin-bottom:14px;">
                        <i class="fas fa-store"></i>
                        <span>Please provide your business details below.</span>
                    </div>
                    <div class="db-form-group">
                        <label class="db-form-label">Business Name</label>
                        <input type="text" name="business_name" class="db-form-control" placeholder="Enter business name">
                    </div>
                    <div class="db-form-row db-form-row--2">
                        <div class="db-form-group">
                            <label class="db-form-label">Business Address</label>
                            <input type="text" name="business_address" class="db-form-control" placeholder="Enter business address">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Business Type</label>
                            <select name="business_type" class="db-form-select">
                                <option value="">Select Type</option>
                                <option value="Retail">Retail</option>
                                <option value="Service">Service</option>
                                <option value="Food">Food</option>
                                <option value="Manufacturing">Manufacturing</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Cedula Fields -->
                <div id="cedulaFields" style="display:none;">
                    <div class="db-alert db-alert--info" style="margin-bottom:14px;">
                        <i class="fas fa-id-card"></i>
                        <span>Please provide your cedula details (if applicable).</span>
                    </div>
                    <div class="db-form-group">
                        <label class="db-form-label">Cedula Number <span style="font-weight:400; text-transform:none; letter-spacing:0;">(if renewal)</span></label>
                        <input type="text" name="cedula_number" class="db-form-control"
                               placeholder="Enter previous cedula number (optional)">
                        <div style="font-size:11px; color:var(--db-muted); margin-top:5px;"><i class="fas fa-info-circle me-1"></i>Leave blank if this is your first cedula</div>
                    </div>
                </div>

                <div class="db-form-group" style="margin-bottom:0;">
                    <label class="db-form-label">Additional Details</label>
                    <textarea name="additional_details" class="db-form-textarea" rows="4"
                              placeholder="Provide any additional information or special requests..."></textarea>
                </div>
            </div>
        </div>

        <!-- Requirements Upload Section -->
        <div id="requirementsSection" style="display:none;">
            <div class="db-panel" style="animation-delay:.15s">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-paperclip"></i></div>
                        <h2>Attachments &amp; Requirements</h2>
                    </div>
                </div>
                <div class="db-panel__body">
                    <div class="db-alert db-alert--warning" style="margin-bottom:16px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Important:</strong> Upload clear, readable images or PDF files.
                            Max <strong>5MB per file</strong>. Accepted: <strong>JPG, PNG, GIF, PDF</strong>.
                        </div>
                    </div>
                    <div id="requirementsList"></div>
                </div>
            </div>
        </div>

        <!-- Processing Notice -->
        <div class="db-info-box" style="margin-bottom:18px;">
            <i class="fas fa-info-circle" style="flex-shrink:0; margin-top:1px;"></i>
            <div>
                <strong>Processing Notice:</strong> Processing time varies by document type.
                You will be notified when your request status changes.
                Payment (if applicable) will be confirmed by the barangay admin.
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="db-btn db-btn--primary" id="submitBtn">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
            <a href="my-requests.php" class="db-btn db-btn--ghost" id="cancelBtn">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>

    </div><!-- /left -->

    <!-- ── Right Column ── -->
    <div>

        <!-- General Requirements -->
        <div class="db-side-card">
            <div class="db-side-card__header">
                <div class="db-panel__icon db-panel__icon--success" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-clipboard-list"></i></div>
                <span>General Requirements</span>
            </div>
            <div class="db-side-card__body">
                <ul class="db-checklist">
                    <li><i class="fas fa-check-circle"></i> Valid ID</li>
                    <li><i class="fas fa-check-circle"></i> Proof of residency</li>
                    <li><i class="fas fa-check-circle"></i> Complete application form</li>
                    <li><i class="fas fa-check-circle"></i> Payment confirmation (if applicable)</li>
                </ul>
            </div>
        </div>

        <!-- Processing Time -->
        <div class="db-side-card">
            <div class="db-side-card__header">
                <div class="db-panel__icon db-panel__icon--amber" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-clock"></i></div>
                <span>Processing Time</span>
            </div>
            <div class="db-side-card__body">
                <table class="db-proc-table">
                    <tr><td>Barangay Clearance</td><td><span class="db-proc-days">1–3 days</span></td></tr>
                    <tr><td>Certificates</td><td><span class="db-proc-days">1–2 days</span></td></tr>
                    <tr><td>Business Permit</td><td><span class="db-proc-days">3–5 days</span></td></tr>
                    <tr><td>Barangay ID</td><td><span class="db-proc-days">5–7 days</span></td></tr>
                    <tr><td>Cedula</td><td><span class="db-proc-days">1 day</span></td></tr>
                </table>
            </div>
        </div>

        <!-- Tips -->
        <div class="db-side-card">
            <div class="db-side-card__header">
                <div class="db-panel__icon db-panel__icon--sky" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-lightbulb"></i></div>
                <span>Tips for Faster Processing</span>
            </div>
            <div class="db-side-card__body" style="font-size:12px; color:var(--db-muted); line-height:1.6;">
                <p style="margin:0 0 8px;">• Upload high-quality, legible document scans</p>
                <p style="margin:0 0 8px;">• Ensure your purpose is clearly stated</p>
                <p style="margin:0 0 8px;">• Double-check all required attachments are uploaded</p>
                <p style="margin:0;">• Monitor your notifications for status updates</p>
            </div>
        </div>

    </div><!-- /right -->

</div><!-- /grid -->
</form>

</div><!-- /padding wrapper -->


<!-- ── Confirm Modal ── -->
<div class="modal fade" id="submitConfirmModal" tabindex="-1" aria-labelledby="submitConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border:none; border-radius:var(--db-radius-lg); overflow:hidden;">
            <div class="db-modal-header">
                <h5><i class="fas fa-check-circle"></i> Confirm Request Submission</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:22px;">
                <div class="db-info-box" style="margin-bottom:18px;">
                    <i class="fas fa-info-circle" style="flex-shrink:0;"></i>
                    <span>Please review your request details before submitting.</span>
                </div>

                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--db-muted); margin-bottom:12px;">Request Summary</div>

                <div class="db-summary-row">
                    <div class="db-summary-item"><label>Full Name</label><strong id="modal_fullname">—</strong></div>
                    <div class="db-summary-item"><label>Contact Number</label><strong id="modal_contact">—</strong></div>
                </div>
                <div class="db-summary-row">
                    <div class="db-summary-item"><label>Document Type</label><strong id="modal_document_type">—</strong></div>
                    <div class="db-summary-item"><label>Fee</label><strong id="modal_fee" style="color:var(--db-success)">—</strong></div>
                </div>
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.4px; display:block; margin-bottom:3px;">Purpose</label>
                    <strong id="modal_purpose">—</strong>
                </div>

                <div id="modal_business_info" style="display:none; margin-bottom:14px;">
                    <label style="font-size:11px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.4px; display:block; margin-bottom:3px;">Business Name</label>
                    <strong id="modal_business_name">—</strong>
                </div>

                <div id="modal_requirements_info" style="display:none; margin-bottom:14px;">
                    <label style="font-size:11px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.4px; display:block; margin-bottom:6px;">Uploaded Documents</label>
                    <div id="modal_requirements_list"></div>
                </div>

                <div class="db-alert db-alert--warning" style="margin-bottom:0;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <small>By submitting, you confirm all information provided is accurate and complete. Processing time varies by document type.</small>
                </div>
            </div>
            <div class="modal-footer" style="padding:14px 22px; border-top:1px solid var(--db-border);">
                <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                <button type="button" class="db-btn db-btn--success" id="confirmSubmitBtn"><i class="fas fa-paper-plane"></i> Confirm &amp; Submit</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Success Modal ── -->
<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:none; border-radius:var(--db-radius-lg); overflow:hidden;">
            <div class="modal-body" style="padding:36px 28px; text-align:center;">
                <div class="db-success-icon"><i class="fas fa-check"></i></div>

                <h3 style="font-size:18px; font-weight:800; color:var(--db-success); margin-bottom:8px;">Request Submitted Successfully!</h3>
                <p style="color:var(--db-muted); font-size:13px; margin-bottom:20px;">Your request has been received and is now pending review.</p>

                <div style="background:var(--db-surf2); border:1px solid var(--db-border); border-radius:var(--db-radius-sm); padding:16px; margin-bottom:22px; text-align:left;">
                    <div class="db-summary-row">
                        <div class="db-summary-item"><label>Request Date</label><strong id="success_date"></strong></div>
                        <div class="db-summary-item"><label>Status</label><span class="db-badge db-badge--amber"><i class="fas fa-clock"></i> Pending</span></div>
                    </div>
                    <div class="db-summary-item"><label>Document Type</label><strong id="success_document"></strong></div>
                </div>

                <div style="display:flex; flex-direction:column; gap:8px;">
                    <button type="button" class="db-btn db-btn--primary" style="justify-content:center;" id="viewRequestsBtn">
                        <i class="fas fa-list"></i> View My Requests
                    </button>
                    <button type="button" class="db-btn db-btn--ghost" style="justify-content:center;" id="newRequestBtn">
                        <i class="fas fa-plus"></i> Submit Another Request
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const requestTypeSelect   = document.getElementById('request_type_id');
    const businessFields      = document.getElementById('businessFields');
    const cedulaFields        = document.getElementById('cedulaFields');
    const feeBanner           = document.getElementById('feeBanner');
    const feeAmountDisplay    = document.getElementById('feeAmountDisplay');
    const requirementsSection = document.getElementById('requirementsSection');
    const requirementsList    = document.getElementById('requirementsList');

    requestTypeSelect.addEventListener('change', function () {
        const requestTypeId = this.value;
        businessFields.style.display      = 'none';
        cedulaFields.style.display        = 'none';
        feeBanner.style.display           = 'none';
        requirementsSection.style.display = 'none';
        requirementsList.innerHTML        = '';

        if (!requestTypeId) return;

        const selectedOption = this.options[this.selectedIndex];
        const typeName = selectedOption.getAttribute('data-typename') || selectedOption.text;
        const fee      = parseFloat(selectedOption.getAttribute('data-fee')) || 0;

        if (fee > 0) {
            feeAmountDisplay.textContent     = 'PHP ' + fee.toFixed(2);
            feeAmountDisplay.className       = 'db-fee-banner__amount';
        } else {
            feeAmountDisplay.textContent     = 'Free';
            feeAmountDisplay.className       = 'db-fee-banner__free';
        }
        feeBanner.style.display = 'block';

        if (typeName.toLowerCase().includes('business')) businessFields.style.display = 'block';
        else if (typeName.toLowerCase().includes('cedula')) cedulaFields.style.display = 'block';

        fetch('get_requirements.php?request_type_id=' + requestTypeId)
            .then(r => r.json())
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
            .catch(() => { requirementsSection.style.display = 'none'; });
    });
});

function createRequirementItem(requirement, index) {
    const div = document.createElement('div');
    div.className = 'db-req-item ' + (requirement.is_mandatory ? 'db-req-item--mandatory' : 'db-req-item--optional');

    const uid     = 'req_' + requirement.requirement_id;
    const labelId = 'label_' + requirement.requirement_id;
    const prevId  = 'preview_' + requirement.requirement_id;

    div.innerHTML = `
        <div class="db-req-item__header">
            <div class="db-req-item__name">
                <i class="fas fa-file-alt" style="color:var(--db-muted);"></i>
                ${requirement.requirement_name}
                ${requirement.is_mandatory
                    ? '<span class="db-badge db-badge--rose"><i class="fas fa-asterisk"></i> Required</span>'
                    : '<span class="db-badge db-badge--muted">Optional</span>'}
            </div>
        </div>
        ${requirement.description ? `<p style="font-size:12px; color:var(--db-muted); margin-bottom:10px;"><i class="fas fa-info-circle me-1"></i>${requirement.description}</p>` : ''}
        <input type="file" name="requirements[]" id="${uid}" accept="image/*,.pdf" style="display:none;"
               ${requirement.is_mandatory ? 'required' : ''}
               onchange="handleFileSelect(this, ${requirement.requirement_id})">
        <input type="hidden" name="requirement_ids[]" value="${requirement.requirement_id}">
        <label for="${uid}" class="db-upload-zone" id="${labelId}">
            <div class="db-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
            <div class="db-upload-zone__text file-name">Click to upload or drag file here</div>
            <div class="db-upload-zone__hint">JPG, PNG, GIF, PDF — Max 5MB</div>
        </label>
        <div id="${prevId}"></div>
    `;
    return div;
}

function handleFileSelect(input, requirementId) {
    const label       = document.getElementById('label_' + requirementId);
    const preview     = document.getElementById('preview_' + requirementId);
    const fileNameDiv = label.querySelector('.file-name');

    if (input.files && input.files[0]) {
        const file     = input.files[0];
        const fileSize = file.size / 1024 / 1024;

        if (fileSize > 5) {
            alert('File size must be less than 5MB');
            input.value = '';
            return;
        }

        label.classList.add('has-file');
        fileNameDiv.innerHTML = `<i class="fas fa-check-circle" style="color:var(--db-success);"></i> ${file.name}`;

        const container = document.createElement('div');
        container.className = 'db-preview-container';

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function (e) {
                container.innerHTML = `
                    <img src="${e.target.result}" class="db-preview-image" alt="Preview">
                    <div style="font-size:11.5px; color:var(--db-muted); margin-top:8px;">
                        <i class="fas fa-image me-1"></i>${file.name} &nbsp;·&nbsp; ${fileSize.toFixed(2)} MB
                    </div>
                    <button type="button" class="db-btn db-btn--danger db-btn--sm" style="margin-top:8px;" onclick="removeFile(${requirementId})">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                `;
                preview.innerHTML = '';
                preview.appendChild(container);
            };
            reader.readAsDataURL(file);
        } else {
            container.innerHTML = `
                <div style="padding:12px; display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-file-pdf" style="font-size:28px; color:var(--db-rose);"></i>
                    <div>
                        <div style="font-weight:600; font-size:13px;">${file.name}</div>
                        <div style="font-size:11.5px; color:var(--db-muted);">${fileSize.toFixed(2)} MB · PDF Document</div>
                    </div>
                    <button type="button" class="db-btn db-btn--danger db-btn--sm" style="margin-left:auto;" onclick="removeFile(${requirementId})">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            `;
            preview.innerHTML = '';
            preview.appendChild(container);
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

// Submit button → show confirm modal
document.getElementById('submitBtn').addEventListener('click', function () {
    const form        = document.getElementById('requestForm');
    const requestType = document.getElementById('request_type_id');
    const purpose     = document.querySelector('input[name="purpose"]');

    if (!form.checkValidity()) { form.reportValidity(); return; }
    if (!requestType.value)    { alert('Please select a document type.'); return; }
    if (!purpose.value.trim()) { alert('Please enter the purpose of your request.'); return; }

    const requiredInputs = document.querySelectorAll('input[type="file"][required]');
    let missingFiles = false;
    requiredInputs.forEach(i => { if (!i.files || i.files.length === 0) missingFiles = true; });
    if (missingFiles) { alert('Please upload all required documents before submitting.'); return; }

    const selectedOption = requestType.options[requestType.selectedIndex];
    const fee     = parseFloat(selectedOption.getAttribute('data-fee')) || 0;
    const fullName = document.querySelector('.db-form-control[readonly]').value;
    const contact  = document.querySelectorAll('.db-form-control[readonly]')[1].value;

    document.getElementById('modal_fullname').textContent      = fullName;
    document.getElementById('modal_contact').textContent       = contact;
    document.getElementById('modal_document_type').textContent = selectedOption.text;
    document.getElementById('modal_purpose').textContent       = purpose.value;
    document.getElementById('modal_fee').textContent           = fee > 0 ? 'PHP ' + fee.toFixed(2) : 'Free';

    const businessName = document.querySelector('input[name="business_name"]');
    if (businessName && businessName.value.trim()) {
        document.getElementById('modal_business_info').style.display = 'block';
        document.getElementById('modal_business_name').textContent = businessName.value;
    } else {
        document.getElementById('modal_business_info').style.display = 'none';
    }

    const uploadedFiles = document.querySelectorAll('input[type="file"]');
    let hasFiles = false;
    let filesList = '<ul style="list-style:none;margin:0;padding:0;">';
    uploadedFiles.forEach(input => {
        if (input.files && input.files.length > 0) {
            hasFiles = true;
            filesList += `<li style="padding:4px 0; font-size:12.5px;"><i class="fas fa-check-circle" style="color:var(--db-success);margin-right:6px;"></i>${input.files[0].name}</li>`;
        }
    });
    filesList += '</ul>';

    document.getElementById('modal_requirements_info').style.display = hasFiles ? 'block' : 'none';
    if (hasFiles) document.getElementById('modal_requirements_list').innerHTML = filesList;

    new bootstrap.Modal(document.getElementById('submitConfirmModal')).show();
});

// Confirm submit
document.getElementById('confirmSubmitBtn').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('submitConfirmModal')).hide();

    const form           = document.getElementById('requestForm');
    const submitBtn      = document.getElementById('submitBtn');
    const cancelBtn      = document.getElementById('cancelBtn');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const uploadProgress = document.getElementById('uploadProgress');

    submitBtn.disabled  = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    cancelBtn.style.pointerEvents = 'none';
    cancelBtn.style.opacity       = '0.5';
    loadingOverlay.classList.add('active');

    const formData = new FormData(form);
    formData.append('submit_request', '1');

    const requestType  = document.getElementById('request_type_id');
    const documentType = requestType.options[requestType.selectedIndex].text;

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) {
            uploadProgress.textContent = `Uploading... ${Math.round((e.loaded / e.total) * 100)}%`;
        }
    });
    xhr.addEventListener('load', function () {
        if (xhr.status === 200) {
            uploadProgress.textContent = 'Upload complete!';
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Success!';
            setTimeout(function () {
                loadingOverlay.classList.remove('active');
                const now = new Date();
                document.getElementById('success_date').textContent     = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                document.getElementById('success_document').textContent = documentType;
                new bootstrap.Modal(document.getElementById('successModal')).show();
            }, 500);
        } else {
            loadingOverlay.classList.remove('active');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
            cancelBtn.style.pointerEvents = '';
            cancelBtn.style.opacity = '';
            alert('An error occurred during submission. Please try again.');
        }
    });
    xhr.addEventListener('error', function () {
        loadingOverlay.classList.remove('active');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        cancelBtn.style.pointerEvents = '';
        cancelBtn.style.opacity = '';
        alert('Upload failed. Please check your connection and try again.');
    });
    xhr.open('POST', window.location.href, true);
    xhr.timeout = 300000;
    xhr.send(formData);
});

document.getElementById('viewRequestsBtn').addEventListener('click', () => { window.location.href = 'my-requests.php'; });
document.getElementById('newRequestBtn').addEventListener('click', () => { window.location.reload(); });
</script>

<?php include '../../includes/footer.php'; ?>