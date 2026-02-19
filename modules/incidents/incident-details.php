<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Allow both admin and resident roles
requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod', 'Resident']);

$current_user_id = getCurrentUserId();
$current_role = getCurrentUserRole();

// Define staff roles
$staff_roles = ['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod'];
$is_resident = !in_array($current_role, $staff_roles);
$is_tanod = ($current_role === 'Tanod' || $current_role === 'Barangay Tanod');

// Tanod can only view, not modify
$can_modify = !$is_resident && !$is_tanod;

// DEBUGGING - Remove after issue is fixed
error_log("=== INCIDENT DETAILS DEBUG ===");
error_log("GET params: " . print_r($_GET, true));
error_log("User ID: " . $current_user_id);
error_log("User Role: " . $current_role);
error_log("Is Resident: " . ($is_resident ? 'YES' : 'NO'));

// Get incident ID from URL
$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

error_log("Incident ID from URL: " . (isset($_GET['id']) ? $_GET['id'] : 'NOT SET'));
error_log("Parsed Incident ID: " . $incident_id);

if (!$incident_id || $incident_id <= 0) {
    error_log("ERROR: Invalid incident ID - Value: " . $incident_id);
    $_SESSION['error_message'] = 'Invalid incident ID provided';
    header('Location: view-incidents.php');
    exit;
}

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
    
    error_log("Resident ID: " . ($resident_id ? $resident_id : 'NULL'));
}

// Check if tables exist
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_images'");
$has_images_table = ($table_check && $table_check->num_rows > 0);

$table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_responses'");
$has_responses_table = ($table_check && $table_check->num_rows > 0);

// Get incident details with proper authorization check
$sql = "SELECT i.*, 
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.contact_number as resident_contact,
        r.email as resident_email,
        r.address as resident_address,
        CONCAT(resp_r.first_name, ' ', resp_r.last_name) as responder_name,
        resp_u.role as responder_role
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        LEFT JOIN tbl_users resp_u ON i.responder_id = resp_u.user_id
        LEFT JOIN tbl_residents resp_r ON resp_u.resident_id = resp_r.resident_id
        WHERE i.incident_id = ?";

// If resident, only allow viewing their own incidents
if ($is_resident && $resident_id) {
    $sql .= " AND i.resident_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $incident_id, $resident_id);
    error_log("SQL Query: Resident-specific (ID: $incident_id, Resident: $resident_id)");
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $incident_id);
    error_log("SQL Query: Staff view (ID: $incident_id)");
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    error_log("ERROR: Incident not found - ID: $incident_id | User: $current_user_id | Role: $current_role");
    
    // Check if incident exists at all
    $check_stmt = $conn->prepare("SELECT incident_id, resident_id FROM tbl_incidents WHERE incident_id = ?");
    $check_stmt->bind_param("i", $incident_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        error_log("Incident does not exist in database");
        $_SESSION['error_message'] = 'Incident not found. It may have been deleted.';
    } else {
        $incident_data = $check_result->fetch_assoc();
        error_log("Incident exists but access denied - Incident Resident ID: " . $incident_data['resident_id']);
        $_SESSION['error_message'] = 'You do not have permission to view this incident.';
    }
    $check_stmt->close();
    
    $stmt->close();
    header('Location: view-incidents.php');
    exit;
}

$incident = $result->fetch_assoc();
$stmt->close();

error_log("SUCCESS: Incident loaded - Reference: " . $incident['reference_no']);

// Get incident images if table exists
$images = [];
if ($has_images_table) {
    $stmt = $conn->prepare("SELECT * FROM tbl_incident_images WHERE incident_id = ? ORDER BY uploaded_at DESC");
    $stmt->bind_param("i", $incident_id);
    $stmt->execute();
    $images_result = $stmt->get_result();
    
    while ($img = $images_result->fetch_assoc()) {
        $images[] = $img;
    }
    $stmt->close();
}

// Get incident updates/responses if table exists
$updates = [];
if ($has_responses_table) {
    $stmt = $conn->prepare("SELECT ir.*, 
                            CONCAT(resp_r.first_name, ' ', resp_r.last_name) as responder_name 
                            FROM tbl_incident_responses ir
                            LEFT JOIN tbl_users resp_u ON ir.responder_id = resp_u.user_id
                            LEFT JOIN tbl_residents resp_r ON resp_u.resident_id = resp_r.resident_id
                            WHERE ir.incident_id = ?
                            ORDER BY ir.response_date DESC");
    $stmt->bind_param("i", $incident_id);
    $stmt->execute();
    $updates_result = $stmt->get_result();
    while ($update = $updates_result->fetch_assoc()) {
        $updates[] = $update;
    }
    $stmt->close();
}

// Get available responders (staff members) for assignment - only for those who can modify
$responders = [];
if ($can_modify) {
    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.role 
                            FROM tbl_users u
                            WHERE u.role IN ('Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Staff', 'Secretary', 'Treasurer', 'Barangay Tanod', 'Tanod')
                            AND u.status = 'active'
                            ORDER BY 
                                CASE 
                                    WHEN u.role IN ('Super Admin', 'Super Administrator', 'Admin') THEN 1
                                    WHEN u.role = 'Barangay Captain' THEN 2
                                    WHEN u.role IN ('Barangay Tanod', 'Tanod') THEN 3
                                    ELSE 4
                                END,
                                u.username ASC");
    
    $stmt->execute();
    $responders_result = $stmt->get_result();
    while ($responder = $responders_result->fetch_assoc()) {
        $responders[] = $responder;
    }
    $stmt->close();
}

// Define the base upload URL
$upload_url = defined('UPLOAD_URL') ? UPLOAD_URL : (defined('BASE_URL') ? BASE_URL . 'uploads/' : '/barangaylink/uploads/');
$upload_url = rtrim($upload_url, '/') . '/';

$page_title = 'Incident Details - ' . htmlspecialchars($incident['reference_no']);

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

/* Header Area */
.page-header {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: var(--border-radius-lg);
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-sm);
}

.page-header h2 {
    font-size: 1.75rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
    color: #1a1a1a;
}

.page-header .reference-badge {
    display: inline-flex;
    align-items: center;
    background: linear-gradient(135deg, #e9ecef 0%, #f8f9fa 100%);
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.9rem;
    font-weight: 600;
    margin-top: 0.5rem;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.badge-group {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
}

/* Info Sections */
.info-item {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 1rem;
    transition: all var(--transition-speed) ease;
}

.info-item:hover {
    background: #e9ecef;
    transform: translateX(4px);
}

.info-item-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    color: #6c757d;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-item-value {
    font-size: 1rem;
    font-weight: 600;
    color: #1a1a1a;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Image Gallery */
.image-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1.25rem;
    margin-top: 1.5rem;
}

.image-card {
    border-radius: var(--border-radius);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    background: white;
}

.image-card:hover {
    box-shadow: var(--shadow-lg);
    transform: translateY(-6px);
}

.image-card img {
    height: 220px;
    object-fit: cover;
    width: 100%;
    transition: transform var(--transition-speed) ease;
}

.image-card:hover img {
    transform: scale(1.05);
}

.image-card-body {
    padding: 1rem;
    background: white;
}

.image-card-footer {
    padding: 0.75rem 1rem;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
}

/* Timeline Updates */
.timeline-container {
    position: relative;
    padding-left: 2rem;
}

.timeline-item {
    position: relative;
    padding: 1.5rem;
    background: #f8f9fa;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
    border-left: 4px solid #0d6efd;
    transition: all var(--transition-speed) ease;
}

.timeline-item:hover {
    background: white;
    box-shadow: var(--shadow-md);
    transform: translateX(8px);
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -2.75rem;
    top: 1.5rem;
    width: 16px;
    height: 16px;
    background: #0d6efd;
    border: 4px solid white;
    border-radius: 50%;
    box-shadow: 0 0 0 2px #0d6efd;
}

.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.timeline-author {
    font-weight: 700;
    font-size: 1.05rem;
    color: #1a1a1a;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.timeline-date {
    font-size: 0.85rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.timeline-message {
    color: #495057;
    line-height: 1.7;
    font-size: 0.95rem;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
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

.btn-group-vertical .btn {
    margin-bottom: 0.75rem;
}

.btn-group-vertical .btn:last-child {
    margin-bottom: 0;
}

/* Alert Enhancements */
.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-info {
    background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%);
    border-left-color: #0dcaf0;
}

.alert i {
    font-size: 1.1rem;
}

/* Modal Enhancements */
.modal-content {
    border: none;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-lg);
}

.modal-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
    padding: 1.5rem;
}

.modal-title {
    font-weight: 700;
    font-size: 1.25rem;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
    padding: 1.25rem 2rem;
    border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
}

/* Form Enhancements */
.form-label {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
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

.form-check-input {
    width: 1.25rem;
    height: 1.25rem;
    border: 2px solid #e9ecef;
    border-radius: 4px;
}

.form-check-label {
    padding-left: 0.5rem;
    font-weight: 500;
}

/* Contact Links */
a[href^="tel:"], a[href^="mailto:"] {
    color: #0d6efd;
    text-decoration: none;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
}

a[href^="tel:"]:hover, a[href^="mailto:"]:hover {
    color: #0a58ca;
    text-decoration: underline;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .page-header {
        padding: 1.5rem;
    }
    
    .page-header h2 {
        font-size: 1.5rem;
    }
    
    .card-body {
        padding: 1.25rem;
    }
    
    .timeline-container {
        padding-left: 1.5rem;
    }
    
    .timeline-item::before {
        left: -2.25rem;
    }
    
    .image-gallery {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    .badge-group {
        flex-direction: column;
        align-items: flex-start;
    }
}

/* Loading States */
.btn:disabled {
    cursor: not-allowed;
    opacity: 0.7;
    transform: none !important;
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}

/* Print Styles */
@media print {
    .btn, .modal, .alert-info {
        display: none !important;
    }
    
    .card {
        box-shadow: none !important;
        page-break-inside: avoid;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div class="flex-grow-1">
                <a href="view-incidents.php" class="btn btn-outline-secondary btn-sm mb-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Incidents
                </a>
                <h2>
                    <i class="fas fa-shield-alt me-2 text-primary"></i>
                    Incident Details
                </h2>
                <div class="reference-badge">
                    <i class="fas fa-hashtag me-2"></i>
                    <span>Reference: <strong><?php echo htmlspecialchars($incident['reference_no']); ?></strong></span>
                </div>
            </div>
            <div class="badge-group">
                <?php echo getStatusBadge($incident['status']); ?>
                <?php echo getSeverityBadge($incident['severity']); ?>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <?php if ($is_tanod): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i>
        <strong>View Only Mode:</strong> As a Tanod, you can view incident details but cannot make modifications or assignments.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Main Incident Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle me-2 text-primary"></i>Incident Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-item-label">
                                    <i class="fas fa-tag"></i>
                                    Incident Type
                                </div>
                                <div class="info-item-value">
                                    <?php
                                    $icon_class = 'fa-exclamation-triangle';
                                    $icon_color = 'text-warning';
                                    switch($incident['incident_type']) {
                                        case 'Crime': $icon_class = 'fa-user-secret'; $icon_color = 'text-danger'; break;
                                        case 'Fire': $icon_class = 'fa-fire'; $icon_color = 'text-danger'; break;
                                        case 'Accident': $icon_class = 'fa-car-crash'; $icon_color = 'text-warning'; break;
                                        case 'Health Emergency': $icon_class = 'fa-ambulance'; $icon_color = 'text-danger'; break;
                                        case 'Violation': $icon_class = 'fa-gavel'; $icon_color = 'text-warning'; break;
                                        case 'Natural Disaster': $icon_class = 'fa-cloud-showers-heavy'; $icon_color = 'text-info'; break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon_class; ?> <?php echo $icon_color; ?>"></i>
                                    <?php echo htmlspecialchars($incident['incident_type']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-item-label">
                                    <i class="fas fa-calendar-alt"></i>
                                    Date Reported
                                </div>
                                <div class="info-item-value">
                                    <i class="fas fa-clock text-primary"></i>
                                    <?php echo date('F d, Y h:i A', strtotime($incident['date_reported'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-map-marker-alt"></i>
                            Location
                        </div>
                        <div class="info-item-value">
                            <i class="fas fa-map-pin text-danger"></i>
                            <?php echo htmlspecialchars($incident['location']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-align-left"></i>
                            Description
                        </div>
                        <div class="info-item-value" style="display: block; line-height: 1.7;">
                            <?php echo nl2br(htmlspecialchars($incident['description'])); ?>
                        </div>
                    </div>

                    <?php if ($has_images_table && !empty($images)): ?>
                    <div class="mt-4">
                        <div class="info-item-label mb-3">
                            <i class="fas fa-images"></i>
                            Incident Photos
                            <span class="badge bg-primary ms-2"><?php echo count($images); ?></span>
                        </div>
                        
                        <div class="image-gallery">
                            <?php foreach ($images as $image): ?>
                            <?php 
                                $raw_path = ltrim($image['image_path'], '/');
                                $raw_path = preg_replace('#^uploads/#', '', $raw_path);
                                if (strpos($raw_path, 'incidents/') !== 0) {
                                    $raw_path = 'incidents/' . $raw_path;
                                }
                                $image_url = rtrim($upload_url, '/') . '/' . $raw_path;
                            ?>
                            <div class="image-card">
                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                     alt="Incident Photo"
                                     onerror="this.onerror=null; this.src='../../assets/images/no-image.png';">
                                <div class="image-card-body">
                                    <small class="d-block mb-1">
                                        <i class="fas fa-camera me-1 text-primary"></i>
                                        <strong><?php echo isset($image['image_type']) ? ucfirst(htmlspecialchars($image['image_type'])) : 'Evidence'; ?></strong>
                                    </small>
                                    <?php if (isset($image['uploaded_at'])): ?>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('M d, Y g:i A', strtotime($image['uploaded_at'])); ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                                <div class="image-card-footer">
                                    <a href="<?php echo htmlspecialchars($image_url); ?>" 
                                       class="btn btn-sm btn-primary w-100" 
                                       target="_blank">
                                        <i class="fas fa-external-link-alt me-1"></i>View Full Size
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Updates and Responses -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-comments me-2 text-primary"></i>Updates & Responses</h5>
                </div>
                <div class="card-body">
                    <?php if (!$has_responses_table): ?>
                        <div class="empty-state">
                            <i class="fas fa-database"></i>
                            <p>Response tracking is not available</p>
                        </div>
                    <?php elseif (!empty($updates)): ?>
                        <div class="timeline-container">
                            <?php foreach ($updates as $update): ?>
                            <div class="timeline-item">
                                <div class="timeline-header">
                                    <div>
                                        <div class="timeline-author">
                                            <i class="fas fa-user-shield"></i>
                                            <?php echo htmlspecialchars($update['responder_name'] ?? 'System'); ?>
                                        </div>
                                        <div class="timeline-date">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('F d, Y h:i A', strtotime($update['response_date'])); ?>
                                        </div>
                                    </div>
                                    <?php if (isset($update['action_taken']) && !empty($update['action_taken'])): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($update['action_taken']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-message">
                                    <?php echo nl2br(htmlspecialchars($update['response_message'])); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No updates yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Reporter Information -->
            <?php if (!$is_resident): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-user me-2 text-primary"></i>Reporter Information</h5>
                </div>
                <div class="card-body">
                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-user"></i>
                            Name
                        </div>
                        <div class="info-item-value">
                            <?php echo htmlspecialchars($incident['resident_name'] ?? 'Unknown'); ?>
                        </div>
                    </div>
                    
                    <?php if (isset($incident['resident_contact']) && $incident['resident_contact']): ?>
                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-phone"></i>
                            Contact Number
                        </div>
                        <div class="info-item-value">
                            <a href="tel:<?php echo htmlspecialchars($incident['resident_contact']); ?>">
                                <i class="fas fa-phone-alt me-1"></i>
                                <?php echo htmlspecialchars($incident['resident_contact']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($incident['resident_email']) && $incident['resident_email']): ?>
                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-envelope"></i>
                            Email
                        </div>
                        <div class="info-item-value">
                            <a href="mailto:<?php echo htmlspecialchars($incident['resident_email']); ?>">
                                <i class="fas fa-envelope me-1"></i>
                                <?php echo htmlspecialchars($incident['resident_email']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($incident['resident_address']) && $incident['resident_address']): ?>
                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-home"></i>
                            Address
                        </div>
                        <div class="info-item-value" style="display: block;">
                            <?php echo htmlspecialchars($incident['resident_address']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Response Team -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-users me-2 text-primary"></i>Response Team</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($incident['responder_name']) && $incident['responder_name']): ?>
                    <div class="info-item">
                        <div class="info-item-label">
                            <i class="fas fa-user-shield"></i>
                            Assigned Responder
                        </div>
                        <div class="info-item-value" style="display: block;">
                            <div class="mb-1">
                                <i class="fas fa-user-check text-primary me-1"></i>
                                <strong><?php echo htmlspecialchars($incident['responder_name']); ?></strong>
                            </div>
                            <?php if (isset($incident['responder_role']) && $incident['responder_role']): ?>
                            <small class="text-muted">
                                <i class="fas fa-id-badge me-1"></i>
                                <?php echo htmlspecialchars($incident['responder_role']); ?>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-user-slash" style="font-size: 3rem;"></i>
                        <p style="font-size: 0.95rem;">No responder assigned yet</p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($can_modify): ?>
                    <div class="btn-group-vertical w-100 mt-3">
                        <?php if (!empty($responders)): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignResponderModal">
                            <i class="fas fa-user-plus me-2"></i>
                            <?php echo (isset($incident['responder_name']) && $incident['responder_name']) ? 'Reassign Responder' : 'Assign Responder'; ?>
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($incident['status'] !== 'Closed'): ?>
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                            <i class="fas fa-edit me-2"></i>Update Status
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($has_responses_table): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addResponseModal">
                            <i class="fas fa-reply me-2"></i>Add Response
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODALS SECTION -->
<?php if ($can_modify && $has_responses_table): ?>
<!-- Add Response Modal -->
<div class="modal fade" id="addResponseModal" tabindex="-1" aria-labelledby="addResponseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addResponseModalLabel">
                    <i class="fas fa-reply me-2"></i>Add Response
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="process-incident-response.php" method="POST" id="addResponseForm">
                <div class="modal-body">
                    <input type="hidden" name="incident_id" value="<?php echo $incident_id; ?>">
                    <input type="hidden" name="action" value="add_response">
                    
                    <div class="mb-3">
                        <label for="response_message" class="form-label">
                            <i class="fas fa-comment-dots"></i>
                            Response Message <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="response_message" name="response_message" rows="5" required placeholder="Enter your response or update..."></textarea>
                        <div class="invalid-feedback">Please enter a response message.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="action_taken" class="form-label">
                            <i class="fas fa-tasks"></i>
                            Action Taken
                        </label>
                        <select class="form-select" id="action_taken" name="action_taken">
                            <option value="">Select action type (optional)</option>
                            <option value="Investigated">Investigated</option>
                            <option value="Responded">Responded</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Forwarded">Forwarded</option>
                            <option value="Updated">Updated</option>
                            <option value="On-site">On-site</option>
                            <option value="Coordinated">Coordinated</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane me-2"></i>Submit Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_modify && $incident['status'] !== 'Closed'): ?>
<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="updateStatusModalLabel">
                    <i class="fas fa-edit me-2"></i>Update Incident Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="process-incident-response.php" method="POST" id="updateStatusForm">
                <div class="modal-body">
                    <input type="hidden" name="incident_id" value="<?php echo $incident_id; ?>">
                    <input type="hidden" name="action" value="update_status">
                    
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-info-circle"></i>
                            Current Status
                        </label>
                        <div class="p-3 bg-light rounded">
                            <?php echo getStatusBadge($incident['status']); ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_status" class="form-label">
                            <i class="fas fa-exchange-alt"></i>
                            Change Status To <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="new_status" name="new_status" required>
                            <option value="">Select new status</option>
                            <?php
                            $all_statuses = ['Pending', 'Under Investigation', 'In Progress', 'Resolved', 'Closed'];
                            foreach ($all_statuses as $status) {
                                if ($status !== $incident['status']) {
                                    echo '<option value="' . htmlspecialchars($status) . '">' . htmlspecialchars($status) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <div class="invalid-feedback">Please select a new status.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="severity" class="form-label">
                            <i class="fas fa-exclamation-triangle"></i>
                            Severity Level
                        </label>
                        <select class="form-select" id="severity" name="severity">
                            <option value="Low" <?php echo ($incident['severity'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                            <option value="Medium" <?php echo ($incident['severity'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="High" <?php echo ($incident['severity'] == 'High') ? 'selected' : ''; ?>>High</option>
                            <option value="Critical" <?php echo ($incident['severity'] == 'Critical') ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status_notes" class="form-label">
                            <i class="fas fa-sticky-note"></i>
                            Notes (Optional)
                        </label>
                        <textarea class="form-control" id="status_notes" name="status_notes" rows="3" placeholder="Add any notes about this status change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_modify && !empty($responders)): ?>
<!-- Assign Responder Modal -->
<div class="modal fade" id="assignResponderModal" tabindex="-1" aria-labelledby="assignResponderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignResponderModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Assign Responder
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="process-incident-response.php" method="POST" id="assignResponderForm">
                <div class="modal-body">
                    <input type="hidden" name="incident_id" value="<?php echo $incident_id; ?>">
                    <input type="hidden" name="action" value="assign_responder">
                    
                    <?php if (isset($incident['responder_name']) && $incident['responder_name']): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Current responder: <strong><?php echo htmlspecialchars($incident['responder_name']); ?></strong>
                        <br>
                        <small>Selecting a new responder will replace the current assignment</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="responder_id" class="form-label">
                            <i class="fas fa-user-shield"></i>
                            Select Responder <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="responder_id" name="responder_id" required>
                            <option value="">Choose a staff member...</option>
                            <?php foreach ($responders as $responder): ?>
                            <option value="<?php echo $responder['user_id']; ?>" 
                                    <?php echo (isset($incident['responder_id']) && $incident['responder_id'] == $responder['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($responder['username']) . ' - ' . htmlspecialchars($responder['role']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a responder.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="assignment_notes" class="form-label">
                            <i class="fas fa-clipboard"></i>
                            Assignment Notes (Optional)
                        </label>
                        <textarea class="form-control" id="assignment_notes" name="assignment_notes" rows="3" placeholder="Add any instructions or notes for the assigned responder..."></textarea>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="notify_responder" name="notify_responder" value="1" checked>
                        <label class="form-check-label" for="notify_responder">
                            <i class="fas fa-bell me-1"></i>Notify responder of assignment
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-check me-2"></i>Assign Responder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Form validation
    var forms = document.querySelectorAll('form[id]');
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Auto-dismiss alerts
    var alerts = document.querySelectorAll('.alert:not(.alert-info)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });

    // Button loading states
    var submitButtons = document.querySelectorAll('form button[type="submit"]');
    submitButtons.forEach(function(button) {
        button.closest('form').addEventListener('submit', function() {
            button.disabled = true;
            var originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            setTimeout(function() {
                button.disabled = false;
                button.innerHTML = originalText;
            }, 3000);
        });
    });

    // Status change confirmation
    var statusSelect = document.getElementById('new_status');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            if (this.value === 'Closed') {
                if (!confirm('Are you sure you want to close this incident? This action may prevent further modifications.')) {
                    this.value = '';
                }
            }
        });
    }

    // Reset forms when modal closes
    var modals = document.querySelectorAll('.modal');
    modals.forEach(function(modal) {
        modal.addEventListener('hidden.bs.modal', function () {
            var form = this.querySelector('form');
            if (form) {
                form.reset();
                form.classList.remove('was-validated');
            }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>