<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Allow all roles to view complaints
requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod', 'Resident']);

$current_user_id = getCurrentUserId();
$current_role = getCurrentUserRole();

// Define staff roles
$staff_roles = ['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod'];
$is_resident = !in_array($current_role, $staff_roles);
$is_tanod = ($current_role === 'Tanod' || $current_role === 'Barangay Tanod');

// Tanod can only view, not modify
$can_modify = !$is_resident && !$is_tanod;

// Get resident_id if user is a resident
$resident_id = null;
if ($is_resident) {
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $resident_id = $row['resident_id'];
    }
    $stmt->close();

    if (!$resident_id) {
        $_SESSION['error_message'] = 'Invalid resident account';
        header('Location: view-complaints.php');
        exit();
    }
}

// Get complaint ID
$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$complaint_id) {
    $_SESSION['error_message'] = 'Invalid complaint ID';
    header('Location: view-complaints.php');
    exit();
}

// Fetch complaint details
$sql = "SELECT c.*, 
               r.first_name, r.last_name, r.address, r.contact_number, r.email, r.resident_id,
               u.username as assigned_to_name
        FROM tbl_complaints c
        LEFT JOIN tbl_residents r ON c.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON c.assigned_to = u.user_id
        WHERE c.complaint_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $_SESSION['error_message'] = 'Complaint not found';
    header('Location: view-complaints.php');
    exit();
}

$complaint = $result->fetch_assoc();
$stmt->close();

// Ensure resident can only view their own complaints
if ($is_resident && $complaint['resident_id'] != $resident_id) {
    $_SESSION['error_message'] = 'You are not authorized to view this complaint.';
    header('Location: view-complaints.php');
    exit();
}

// ── Attachment paths ──────────────────────────────────────────────────────────
// Filesystem path  → used for file_exists / scandir
$upload_fs  = $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/complaints/';
// Web URL          → used in <img src=""> and <a href="">
$upload_url = '/barangaylink1/uploads/complaints/';

// Collect attachments for this complaint
$attachments = [];

// 1. Scan the uploads folder for files named complaint_{id}_*
if (is_dir($upload_fs)) {
    foreach (scandir($upload_fs) as $file) {
        if ($file === '.' || $file === '..') continue;
        if (strpos($file, 'complaint_' . $complaint_id . '_') === 0) {
            $attachments[] = $file;
        }
    }
}

// 2. Also check tbl_complaint_attachments table if it exists
$db_attachments = [];
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_complaint_attachments'");
if ($table_check && $table_check->num_rows > 0) {
    $att_stmt = $conn->prepare("SELECT file_name, file_path FROM tbl_complaint_attachments WHERE complaint_id = ?");
    $att_stmt->bind_param("i", $complaint_id);
    $att_stmt->execute();
    $att_result = $att_stmt->get_result();
    while ($att_row = $att_result->fetch_assoc()) {
        $db_attachments[] = $att_row;
        // Add the filename to our main list if not already there from the scan
        $basename = basename($att_row['file_path']);
        if (!in_array($basename, $attachments)) {
            $attachments[] = $basename;
        }
    }
    $att_stmt->close();
}

// Fetch list of staff users to assign (only for staff who can modify)
$staff_users = [];
if ($can_modify) {
    $staff_query = "SELECT user_id, username, role 
                    FROM tbl_users 
                    WHERE role IN ('Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod')
                    ORDER BY username ASC";
    $staff_result = $conn->query($staff_query);
    if ($staff_result && $staff_result->num_rows > 0) {
        while ($row = $staff_result->fetch_assoc()) {
            $staff_users[] = $row;
        }
    }
}

// Handle form submissions (only for staff who can modify)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_modify) {
    if (isset($_POST['update_complaint'])) {
        $new_status   = trim($_POST['status']);
        $new_priority = trim($_POST['priority']);
        $responder_id = intval($_POST['responder_id']);

        $valid_statuses   = ['Pending', 'In Progress', 'Resolved', 'Closed'];
        $valid_priorities = ['Low', 'Medium', 'High', 'Urgent'];
        $errors = [];

        if (!in_array($new_status,   $valid_statuses))   $errors[] = 'Invalid status selected.';
        if (!in_array($new_priority, $valid_priorities)) $errors[] = 'Invalid priority selected.';
        if ($responder_id <= 0)                          $errors[] = 'Please select a valid responder.';

        if (empty($errors)) {
            $update_stmt = $conn->prepare("UPDATE tbl_complaints SET status = ?, priority = ?, assigned_to = ? WHERE complaint_id = ?");
            $update_stmt->bind_param("ssii", $new_status, $new_priority, $responder_id, $complaint_id);

            if ($update_stmt->execute()) {
                $_SESSION['success_message'] = 'Complaint updated successfully!';
                $update_stmt->close();

                $user_stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE resident_id = ?");
                $user_stmt->bind_param("i", $complaint['resident_id']);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();

                if ($user_row = $user_result->fetch_assoc()) {
                    $complainant_user_id = $user_row['user_id'];
                    if ($new_status === 'Resolved') {
                        createNotification($conn, $complainant_user_id, 'Complaint Resolved',
                            "Your complaint \"{$complaint['subject']}\" has been resolved.", 'complaint_resolved', $complaint_id, 'complaint');
                    } elseif ($new_status === 'Closed') {
                        createNotification($conn, $complainant_user_id, 'Complaint Closed',
                            "Your complaint \"{$complaint['subject']}\" has been closed.", 'complaint_closed', $complaint_id, 'complaint');
                    } else {
                        createNotification($conn, $complainant_user_id, 'Complaint Status Updated',
                            "Your complaint \"{$complaint['subject']}\" status has been updated to: $new_status", 'complaint_status_update', $complaint_id, 'complaint');
                    }
                    if ($responder_id && $responder_id != $complainant_user_id && $responder_id != $complaint['assigned_to']) {
                        $staff_name = getUserFullName($conn, $responder_id);
                        createNotification($conn, $responder_id, 'Complaint Assigned to You',
                            "You have been assigned to handle complaint: \"{$complaint['subject']}\"", 'complaint_assignment', $complaint_id, 'complaint');
                        createNotification($conn, $complainant_user_id, 'Complaint Assigned',
                            "Your complaint has been assigned to $staff_name for handling.", 'complaint_assignment', $complaint_id, 'complaint');
                    }
                }
                $user_stmt->close();

                if (function_exists('logActivity')) {
                    logActivity($conn, $current_user_id, "Updated complaint - Status: $new_status, Priority: $new_priority", 'tbl_complaints', $complaint_id);
                }

                header("Location: complaint-details.php?id=$complaint_id");
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to update complaint: ' . $update_stmt->error;
                $update_stmt->close();
            }
        } else {
            $_SESSION['error_message'] = implode('<br>', $errors);
        }
    }
}

$page_title = 'Complaint Details - ' . htmlspecialchars($complaint['complaint_number']);

if (!function_exists('getComplaintStatusBadge')) {
    function getComplaintStatusBadge($status) {
        $status = trim($status);
        $badges = [
            'Pending'     => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>',
            'In Progress' => '<span class="badge bg-primary"><i class="fas fa-spinner me-1"></i>In Progress</span>',
            'Resolved'    => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Resolved</span>',
            'Closed'      => '<span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>Closed</span>',
        ];
        return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

if (!function_exists('getComplaintPriorityBadge')) {
    function getComplaintPriorityBadge($priority) {
        $priority = trim($priority);
        $badges = [
            'Low'    => '<span class="badge bg-success bg-opacity-25 text-success"><i class="fas fa-circle me-1"></i>Low</span>',
            'Medium' => '<span class="badge bg-warning bg-opacity-25 text-warning"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
            'High'   => '<span class="badge bg-danger bg-opacity-25 text-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
            'Urgent' => '<span class="badge bg-danger text-white"><i class="fas fa-fire me-1"></i>Urgent</span>',
        ];
        return $badges[$priority] ?? '<span class="badge bg-secondary">' . htmlspecialchars($priority) . '</span>';
    }
}

include '../../includes/header.php';
?>

<style>
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

.card { border: none; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); transition: all var(--transition-speed) ease; overflow: hidden; }
.card:hover { box-shadow: var(--shadow-md); transform: translateY(-4px); }
.card-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-bottom: 2px solid #e9ecef; padding: 1.25rem 1.5rem; border-radius: var(--border-radius) var(--border-radius) 0 0 !important; }
.card-header h5 { font-weight: 700; font-size: 1.1rem; margin: 0; display: flex; align-items: center; }
.card-body { padding: 1.75rem; }

.page-header { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-radius: var(--border-radius-lg); padding: 2rem; margin-bottom: 2rem; box-shadow: var(--shadow-sm); }
.page-header h2 { font-size: 1.75rem; font-weight: 800; margin-bottom: 0.5rem; color: #1a1a1a; }
.page-header .reference-badge { display: inline-flex; align-items: center; background: linear-gradient(135deg, #e9ecef 0%, #f8f9fa 100%); padding: 0.5rem 1rem; border-radius: 50px; font-size: 0.9rem; font-weight: 600; margin-top: 0.5rem; }

.badge { font-weight: 600; padding: 0.5rem 1rem; border-radius: 50px; font-size: 0.85rem; letter-spacing: 0.3px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.badge-group { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }

.info-item { padding: 1rem; background: #f8f9fa; border-radius: 8px; margin-bottom: 1rem; transition: all var(--transition-speed) ease; }
.info-item:hover { background: #e9ecef; transform: translateX(4px); }
.info-item-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: #6c757d; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
.info-item-value { font-size: 1rem; font-weight: 600; color: #1a1a1a; display: flex; align-items: center; gap: 0.5rem; }

/* ── Attachment Gallery ── */
.attachment-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1.25rem;
    margin-top: 1rem;
}

.attachment-card {
    border-radius: var(--border-radius);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    background: white;
    border: 1px solid #e9ecef;
}

.attachment-card:hover {
    box-shadow: var(--shadow-lg);
    transform: translateY(-6px);
}

/* Image wrapper */
.attachment-img-wrap {
    position: relative;
    display: block;
    width: 100%;
    height: 160px;
    overflow: hidden;
    background: #f0f0f0;
    cursor: pointer;
}

.attachment-img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform var(--transition-speed) ease;
}

.attachment-card:hover .attachment-img-wrap img {
    transform: scale(1.06);
}

/* Dark overlay on hover */
.attachment-img-wrap .overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity var(--transition-speed);
}

.attachment-card:hover .attachment-img-wrap .overlay {
    opacity: 1;
}

.attachment-info {
    padding: 0.65rem 0.85rem;
    background: white;
}

.attachment-info small {
    font-size: 0.75rem;
    color: #6c757d;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Non-image file card */
.attachment-file-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 160px;
    background: #f8f9fa;
    text-decoration: none;
    transition: background var(--transition-speed);
}

.attachment-file-wrap:hover { background: #e9ecef; }

.btn { border-radius: 8px; padding: 0.625rem 1.5rem; font-weight: 600; transition: all var(--transition-speed) ease; border: none; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn:active { transform: translateY(0); }

.form-label { font-weight: 700; font-size: 0.9rem; color: #1a1a1a; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
.form-control, .form-select { border: 2px solid #e9ecef; border-radius: 8px; padding: 0.75rem 1rem; transition: all var(--transition-speed) ease; font-size: 0.95rem; }
.form-control:focus, .form-select:focus { border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,0.1); }

.alert { border: none; border-radius: var(--border-radius); padding: 1.25rem 1.5rem; box-shadow: var(--shadow-sm); border-left: 4px solid; }
.alert-success { background: linear-gradient(135deg,#d1f4e0,#e7f9ee); border-left-color: #198754; }
.alert-danger  { background: linear-gradient(135deg,#ffd6d6,#ffe5e5); border-left-color: #dc3545; }
.alert-info    { background: linear-gradient(135deg,#e7f3ff,#f0f8ff); border-left-color: #0dcaf0; }
.alert-warning { background: linear-gradient(135deg,#fff3cd,#fffae6); border-left-color: #ffc107; }

.modal-content { border: none; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-lg); }
.modal-header { background: linear-gradient(135deg,#f8f9fa,#fff); border-bottom: 2px solid #e9ecef; padding: 1.5rem; }
.modal-title { font-weight: 700; font-size: 1.25rem; }
.modal-body { padding: 2rem; }
.modal-footer { background: #f8f9fa; border-top: 2px solid #e9ecef; padding: 1.25rem 2rem; }

/* Lightbox overlay */
#lightboxOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.88);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    cursor: zoom-out;
}
#lightboxOverlay.active { display: flex; }
#lightboxOverlay img {
    max-width: 90vw;
    max-height: 90vh;
    border-radius: 8px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.6);
    cursor: default;
    animation: lbIn .2s ease;
}
@keyframes lbIn { from{opacity:0;transform:scale(.92)} to{opacity:1;transform:scale(1)} }
#lightboxClose {
    position: absolute;
    top: 1rem; right: 1.25rem;
    color: #fff;
    font-size: 2rem;
    cursor: pointer;
    line-height: 1;
    opacity: .8;
    transition: opacity .2s;
}
#lightboxClose:hover { opacity: 1; }

@media (max-width: 768px) {
    .page-header { padding: 1.5rem; }
    .page-header h2 { font-size: 1.5rem; }
    .card-body { padding: 1.25rem; }
    .attachment-gallery { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; }
    .badge-group { flex-direction: column; align-items: flex-start; }
}

html { scroll-behavior: smooth; }
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div class="flex-grow-1">
                <a href="view-complaints.php" class="btn btn-outline-secondary btn-sm mb-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Complaints
                </a>
                <h2><i class="fas fa-file-alt me-2 text-primary"></i>Complaint Details</h2>
                <div class="reference-badge">
                    <i class="fas fa-hashtag me-2"></i>
                    <span><?php echo htmlspecialchars($complaint['complaint_number']); ?></span>
                </div>
            </div>
            <div class="badge-group">
                <?php echo getComplaintStatusBadge($complaint['status'] ?? 'Pending'); ?>
                <?php echo getComplaintPriorityBadge($complaint['priority'] ?? 'Medium'); ?>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <?php if ($is_tanod): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <i class="fas fa-info-circle me-2"></i>
        <strong>View Only Mode:</strong> As a Tanod, you can view complaint details but cannot make modifications.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- ── Left Column ── -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle me-2 text-primary"></i>Complaint Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-item-label"><i class="fas fa-calendar-alt"></i>Date Filed</div>
                                <div class="info-item-value">
                                    <i class="fas fa-clock text-primary"></i>
                                    <?php echo date('F d, Y g:i A', strtotime($complaint['date_filed'] ?? $complaint['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-item-label"><i class="fas fa-tag"></i>Category</div>
                                <div class="info-item-value">
                                    <?php
                                    $icon_class = 'fa-comment';
                                    switch($complaint['category']) {
                                        case 'Noise':          $icon_class = 'fa-volume-up';      break;
                                        case 'Garbage':        $icon_class = 'fa-trash';          break;
                                        case 'Property':       $icon_class = 'fa-home';           break;
                                        case 'Infrastructure': $icon_class = 'fa-road';           break;
                                        case 'Public Safety':  $icon_class = 'fa-shield-alt';     break;
                                        case 'Services':       $icon_class = 'fa-concierge-bell'; break;
                                        case 'Animals':        $icon_class = 'fa-paw';            break;
                                        case 'Utilities':      $icon_class = 'fa-bolt';           break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon_class; ?>"></i>
                                    <?php echo htmlspecialchars($complaint['category'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-heading"></i>Subject</div>
                        <div class="info-item-value" style="display:block;font-size:1.25rem;">
                            <?php echo htmlspecialchars($complaint['subject']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-align-left"></i>Description</div>
                        <div class="info-item-value" style="display:block;line-height:1.7;font-weight:400;">
                            <?php echo nl2br(htmlspecialchars($complaint['description'])); ?>
                        </div>
                    </div>

                    <?php if (!empty($attachments)): ?>
                    <!-- ── Attachments ── -->
                    <div class="mt-4">
                        <div class="info-item-label mb-3">
                            <i class="fas fa-paperclip"></i>
                            Attachments
                            <span class="badge bg-primary ms-2"><?php echo count($attachments); ?></span>
                        </div>

                        <div class="attachment-gallery">
                            <?php foreach ($attachments as $file):
                                $file_ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                $is_image  = in_array($file_ext, ['jpg','jpeg','png','gif','webp']);
                                $file_web  = $upload_url . rawurlencode($file);   // web URL for browser
                                $file_disk = $upload_fs  . $file;                 // disk path for file_exists
                            ?>
                            <div class="attachment-card">
                                <?php if ($is_image): ?>
                                    <!-- Clickable image → opens lightbox -->
                                    <div class="attachment-img-wrap"
                                         onclick="openLightbox('<?php echo htmlspecialchars($file_web, ENT_QUOTES); ?>')"
                                         title="Click to enlarge">
                                        <img src="<?php echo htmlspecialchars($file_web); ?>"
                                             alt="<?php echo htmlspecialchars($file); ?>"
                                             loading="lazy"
                                             onerror="this.parentElement.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;color:#aaa;font-size:.8rem;\'>Image not found</div>'">
                                        <div class="overlay">
                                            <i class="fas fa-search-plus text-white fs-3"></i>
                                        </div>
                                    </div>
                                    <div class="attachment-info">
                                        <small>
                                            <i class="fas fa-camera me-1 text-primary"></i>
                                            <?php echo htmlspecialchars($file); ?>
                                        </small>
                                        <a href="<?php echo htmlspecialchars($file_web); ?>"
                                           download
                                           class="btn btn-sm btn-outline-primary w-100 mt-2"
                                           onclick="event.stopPropagation()">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                    </div>

                                <?php else: ?>
                                    <!-- Non-image file download -->
                                    <a href="<?php echo htmlspecialchars($file_web); ?>"
                                       target="_blank"
                                       class="text-decoration-none"
                                       download>
                                        <div class="attachment-file-wrap">
                                            <?php
                                            $file_icon = 'fa-file';
                                            if ($file_ext === 'pdf')                         $file_icon = 'fa-file-pdf';
                                            elseif (in_array($file_ext, ['doc','docx']))     $file_icon = 'fa-file-word';
                                            elseif (in_array($file_ext, ['xls','xlsx']))     $file_icon = 'fa-file-excel';
                                            ?>
                                            <i class="fas <?php echo $file_icon; ?> text-primary mb-2" style="font-size:2.5rem;"></i>
                                            <span class="text-uppercase fw-bold text-muted small"><?php echo $file_ext; ?></span>
                                        </div>
                                        <div class="attachment-info">
                                            <small>
                                                <i class="fas fa-file me-1 text-primary"></i>
                                                <?php echo htmlspecialchars($file); ?>
                                            </small>
                                        </div>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="info-item mt-2">
                        <div class="info-item-label"><i class="fas fa-paperclip"></i>Attachments</div>
                        <div class="info-item-value" style="font-weight:400;color:#6c757d;">
                            <i class="fas fa-folder-open me-1"></i>No attachments uploaded
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- ── Right Column ── -->
        <div class="col-lg-4">
            <!-- Complainant Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-user me-2 text-primary"></i>
                        <?php echo $is_resident ? 'Your Information' : 'Complainant Information'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-user"></i>Name</div>
                        <div class="info-item-value"><?php echo htmlspecialchars($complaint['first_name'] . ' ' . $complaint['last_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-phone"></i>Contact Number</div>
                        <div class="info-item-value">
                            <a href="tel:<?php echo htmlspecialchars($complaint['contact_number']); ?>">
                                <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($complaint['contact_number']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-envelope"></i>Email</div>
                        <div class="info-item-value">
                            <a href="mailto:<?php echo htmlspecialchars($complaint['email']); ?>">
                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($complaint['email']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-home"></i>Address</div>
                        <div class="info-item-value" style="display:block;"><?php echo htmlspecialchars($complaint['address']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Assignment Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-user-shield me-2 text-primary"></i>Assignment Status</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($complaint['assigned_to_name'])): ?>
                    <div class="info-item">
                        <div class="info-item-label"><i class="fas fa-user-shield"></i>Assigned To</div>
                        <div class="info-item-value">
                            <i class="fas fa-user-check text-primary me-1"></i>
                            <?php echo htmlspecialchars($complaint['assigned_to_name']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-<?php echo $is_resident ? 'secondary' : 'warning'; ?> border-0 mb-0">
                        <i class="fas fa-<?php echo $is_resident ? 'clock' : 'exclamation-triangle'; ?> me-2"></i>
                        <strong><?php echo $is_resident ? 'Status:' : 'Not Assigned'; ?></strong><br>
                        <small><?php echo $is_resident ? 'Awaiting assignment to a staff member' : 'This complaint has not been assigned yet'; ?></small>
                    </div>
                    <?php endif; ?>

                    <?php if ($can_modify): ?>
                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateComplaintModal">
                            <i class="fas fa-edit me-2"></i>Update Complaint
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Lightbox ── -->
<div id="lightboxOverlay" onclick="closeLightbox()">
    <span id="lightboxClose" onclick="closeLightbox()">&times;</span>
    <img id="lightboxImg" src="" alt="Attachment preview" onclick="event.stopPropagation()">
</div>

<?php if ($can_modify): ?>
<!-- Update Complaint Modal -->
<div class="modal fade" id="updateComplaintModal" tabindex="-1" aria-labelledby="updateComplaintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="updateComplaintModalLabel">
                    <i class="fas fa-edit me-2"></i>Update Complaint
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="updateComplaintForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-flag"></i>Update Status</label>
                            <select class="form-select" name="status" id="statusSelect" required>
                                <option value="Pending"     <?php echo ($complaint['status']==='Pending')     ?'selected':''; ?>>Pending</option>
                                <option value="In Progress" <?php echo ($complaint['status']==='In Progress') ?'selected':''; ?>>In Progress</option>
                                <option value="Resolved"    <?php echo ($complaint['status']==='Resolved')    ?'selected':''; ?>>Resolved</option>
                                <option value="Closed"      <?php echo ($complaint['status']==='Closed')      ?'selected':''; ?>>Closed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-exclamation-circle"></i>Update Priority</label>
                            <select class="form-select" name="priority" required>
                                <option value="Low"    <?php echo ($complaint['priority']==='Low')    ?'selected':''; ?>>Low</option>
                                <option value="Medium" <?php echo ($complaint['priority']==='Medium') ?'selected':''; ?>>Medium</option>
                                <option value="High"   <?php echo ($complaint['priority']==='High')   ?'selected':''; ?>>High</option>
                                <option value="Urgent" <?php echo ($complaint['priority']==='Urgent') ?'selected':''; ?>>Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-user-shield"></i>Assign Responder</label>
                            <select class="form-select" name="responder_id" required>
                                <option value="">-- Select Responder --</option>
                                <?php foreach ($staff_users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"
                                    <?php echo ($complaint['assigned_to']==$user['user_id'])?'selected':''; ?>>
                                    <?php echo htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['role']) . ')'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info border-0 mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Updating this complaint will send notifications to the complainant and assigned staff member.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="confirmUpdate()">
                        <i class="fas fa-save me-2"></i>Update Complaint
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Close Complaint Confirmation Modal -->
<div class="modal fade" id="closeComplaintModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>Close Complaint?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to close this complaint?</p>
                <div class="alert alert-info border-0 mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> This action indicates the complaint has been fully resolved and addressed.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-warning" onclick="submitForm()">
                    <i class="fas fa-check me-2"></i>Yes, Close Complaint
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () { try { new bootstrap.Alert(el).close(); } catch(e){} }, 5000);
    });
});

// ── Lightbox ──
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightboxOverlay').classList.remove('active');
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLightbox();
});

<?php if ($can_modify): ?>
function confirmUpdate() {
    const statusSelect = document.getElementById('statusSelect');
    if (statusSelect.value === 'Closed') {
        const updateModal = bootstrap.Modal.getInstance(document.getElementById('updateComplaintModal'));
        updateModal.hide();
        setTimeout(() => {
            new bootstrap.Modal(document.getElementById('closeComplaintModal')).show();
        }, 300);
    } else {
        submitForm();
    }
}
function submitForm() {
    const form = document.getElementById('updateComplaintForm');
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'update_complaint'; input.value = '1';
    form.appendChild(input);
    const modal = document.getElementById('closeComplaintModal');
    const inst = bootstrap.Modal.getInstance(modal);
    if (inst) inst.hide();
    form.submit();
}
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>