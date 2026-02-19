<?php
/**
 * Respond to Incident
 * Update status and add response notes
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Super Administrator', 'Barangay Captain', 'Barangay Tanod']);

$page_title = 'Respond to Incident';
$error = '';
$success = '';

// Get incident ID
$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$quick_resolve = isset($_GET['quick_resolve']) ? true : false;

if (!$incident_id) {
    header('Location: manage-incidents.php?error=invalid_id');
    exit();
}

// Get incident details
$sql = "SELECT i.*, 
        CONCAT(r.first_name, ' ', r.last_name) as reporter_name
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        WHERE i.incident_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $incident_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: manage-incidents.php?error=not_found');
    exit();
}

$incident = $result->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_incident'])) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $new_status = sanitizeInput($_POST['status']);
        $response_notes = sanitizeInput($_POST['response_notes']);
        $responder_id = getCurrentUserId();
        
        // Validate status
        $valid_statuses = ['Reported', 'Under Investigation', 'In Progress', 'Resolved', 'Closed'];
        if (!in_array($new_status, $valid_statuses)) {
            $error = 'Invalid status selected.';
        }
        
        // Validate response notes for certain statuses
        if (in_array($new_status, ['Resolved', 'Closed']) && empty($response_notes)) {
            $error = 'Response notes are required when resolving or closing an incident.';
        }
        
        if (empty($error)) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Check if response_notes and date_resolved columns exist
                $columns_check = $conn->query("SHOW COLUMNS FROM tbl_incidents");
                $existing_columns = [];
                while ($col = $columns_check->fetch_assoc()) {
                    $existing_columns[] = $col['Field'];
                }
                
                $has_response_notes = in_array('response_notes', $existing_columns);
                $has_date_resolved = in_array('date_resolved', $existing_columns);
                
                // Build UPDATE query based on available columns
                $update_parts = ["status = ?"];
                $params = [$new_status];
                $types = 's';
                
                if ($has_response_notes) {
                    $update_parts[] = "response_notes = ?";
                    $params[] = $response_notes;
                    $types .= 's';
                }
                
                $update_parts[] = "responder_id = ?";
                $params[] = $responder_id;
                $types .= 'i';
                
                if ($has_date_resolved) {
                    $update_parts[] = "date_resolved = CASE WHEN ? IN ('Resolved', 'Closed') THEN NOW() ELSE date_resolved END";
                    $params[] = $new_status;
                    $types .= 's';
                }
                
                $params[] = $incident_id;
                $types .= 'i';
                
                $update_sql = "UPDATE tbl_incidents SET " . implode(", ", $update_parts) . " WHERE incident_id = ?";
                
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param($types, ...$params);
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update incident.');
                }
                $stmt->close();
                
                // If response_notes column doesn't exist, store it in a separate table or log it
                if (!$has_response_notes && !empty($response_notes)) {
                    // Store response notes in activity log as a workaround
                    logActivity($conn, getCurrentUserId(), "Response notes: " . $response_notes, 'tbl_incidents', $incident_id, $incident['reference_no']);
                }
                
                // Log activity
                $action_text = "Updated incident status to: " . $new_status;
                logActivity($conn, getCurrentUserId(), $action_text, 'tbl_incidents', $incident_id, $incident['reference_no']);
                
                // FIXED: Send notification to reporter - Check table structure first
                // Check notification table structure
                $notif_columns_result = $conn->query("SHOW COLUMNS FROM tbl_notifications");
                $notification_columns = [];
                while ($col = $notif_columns_result->fetch_assoc()) {
                    $notification_columns[] = $col['Field'];
                }
                
                $has_title = in_array('title', $notification_columns);
                $has_notification_type = in_array('notification_type', $notification_columns);
                $has_reference_id = in_array('reference_id', $notification_columns);
                
                // Get user_id from resident
                $user_id_sql = "SELECT user_id FROM tbl_users WHERE resident_id = ? LIMIT 1";
                $stmt = $conn->prepare($user_id_sql);
                $stmt->bind_param('i', $incident['resident_id']);
                $stmt->execute();
                $user_result = $stmt->get_result();
                
                if ($user_result->num_rows > 0) {
                    $reporter_user = $user_result->fetch_assoc();
                    $reporter_user_id = $reporter_user['user_id'];
                    
                    $notif_title = "Incident Update: " . $incident['reference_no'];
                    $notif_message = "Your incident has been updated to status: " . $new_status . ". " . 
                                    ($response_notes ? "Response: " . substr($response_notes, 0, 100) : "");
                    
                    // Build query based on available columns
                    if ($has_title && $has_notification_type && $has_reference_id) {
                        $notif_sql = "INSERT INTO tbl_notifications (user_id, title, message, notification_type, reference_id, created_at) 
                                     VALUES (?, ?, ?, 'incident', ?, NOW())";
                        $stmt = $conn->prepare($notif_sql);
                        $stmt->bind_param('issi', $reporter_user_id, $notif_title, $notif_message, $incident_id);
                    } elseif ($has_title && $has_reference_id) {
                        $notif_sql = "INSERT INTO tbl_notifications (user_id, title, message, reference_id, created_at) 
                                     VALUES (?, ?, ?, ?, NOW())";
                        $stmt = $conn->prepare($notif_sql);
                        $stmt->bind_param('issi', $reporter_user_id, $notif_title, $notif_message, $incident_id);
                    } elseif ($has_title) {
                        $notif_sql = "INSERT INTO tbl_notifications (user_id, title, message, created_at) 
                                     VALUES (?, ?, ?, NOW())";
                        $stmt = $conn->prepare($notif_sql);
                        $stmt->bind_param('iss', $reporter_user_id, $notif_title, $notif_message);
                    } else {
                        // Minimal notification - just user_id and message
                        $notif_sql = "INSERT INTO tbl_notifications (user_id, message, created_at) 
                                     VALUES (?, ?, NOW())";
                        $stmt = $conn->prepare($notif_sql);
                        $stmt->bind_param('is', $reporter_user_id, $notif_message);
                    }
                    
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                $success = 'Incident updated successfully!';
                
                // Refresh incident data
                $stmt = $conn->prepare("SELECT * FROM tbl_incidents WHERE incident_id = ?");
                $stmt->bind_param('i', $incident_id);
                $stmt->execute();
                $incident = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                // Redirect after 2 seconds
                header("refresh:2;url=incident-details.php?id=" . $incident_id);
                
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Error updating incident: " . $e->getMessage());
                $error = 'Failed to update incident. Error: ' . $e->getMessage();
            }
        }
    }
}

// Handle quick resolve
if ($quick_resolve && $_SERVER['REQUEST_METHOD'] != 'POST') {
    $_POST['status'] = 'Resolved';
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-reply me-2 text-primary"></i>Respond to Incident
                    </h2>
                    <p class="text-muted mb-0">Reference: <strong><?php echo htmlspecialchars($incident['reference_no']); ?></strong></p>
                </div>
                <a href="incident-details.php?id=<?php echo $incident_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Details
                </a>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Response Form -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Update Incident Status</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="">
                        <?php echo getCSRFField(); ?>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Current Status
                            </label>
                            <div class="p-3 bg-light rounded">
                                <?php echo getStatusBadge($incident['status']); ?>
                                <span class="ms-2 text-muted">
                                    Last updated: <?php echo formatDate($incident['date_reported'], 'M d, Y h:i A'); ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                New Status <span class="text-danger">*</span>
                            </label>
                            <select name="status" class="form-select form-select-lg" required id="statusSelect">
                                <option value="">Select New Status</option>
                                <option value="Reported" <?php echo $incident['status'] === 'Reported' ? 'selected' : ''; ?>>
                                    Reported
                                </option>
                                <option value="Under Investigation" <?php echo $incident['status'] === 'Under Investigation' ? 'selected' : ''; ?>>
                                    Under Investigation
                                </option>
                                <option value="In Progress" <?php echo $incident['status'] === 'In Progress' ? 'selected' : ''; ?>>
                                    In Progress
                                </option>
                                <option value="Resolved" <?php echo ($quick_resolve || $incident['status'] === 'Resolved') ? 'selected' : ''; ?>>
                                    Resolved
                                </option>
                                <option value="Closed" <?php echo $incident['status'] === 'Closed' ? 'selected' : ''; ?>>
                                    Closed
                                </option>
                            </select>
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Select the appropriate status based on current situation
                            </small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Response Notes <span class="text-danger" id="requiredIndicator">*</span>
                            </label>
                            <textarea name="response_notes" class="form-control" rows="6" 
                                      placeholder="Provide detailed response notes about actions taken or findings..."
                                      id="responseNotes"><?php echo htmlspecialchars($incident['response_notes'] ?? ''); ?></textarea>
                            <small class="text-muted" id="notesHelp">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Required when marking as Resolved or Closed
                            </small>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <span id="charCount">0</span>/2000 characters
                                </small>
                            </div>
                        </div>

                        <!-- Quick Response Templates -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Quick Response Templates
                            </label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="insertTemplate('investigating')">
                                    Investigating
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="insertTemplate('resolved')">
                                    Resolved Successfully
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="insertTemplate('no_action')">
                                    No Action Required
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="insertTemplate('referred')">
                                    Referred to Authorities
                                </button>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> The reporter will be notified of this update via the system notifications.
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="update_incident" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-2"></i>Update Incident Status
                            </button>
                            <a href="incident-details.php?id=<?php echo $incident_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar - Incident Summary -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Incident Summary</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6 class="text-muted mb-1">Type</h6>
                        <p class="mb-0"><?php echo htmlspecialchars($incident['incident_type']); ?></p>
                    </div>
                    <div class="mb-3">
                        <h6 class="text-muted mb-1">Severity</h6>
                        <p class="mb-0"><?php echo getSeverityBadge($incident['severity']); ?></p>
                    </div>
                    <div class="mb-3">
                        <h6 class="text-muted mb-1">Location</h6>
                        <p class="mb-0">
                            <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                            <?php echo htmlspecialchars($incident['location']); ?>
                        </p>
                    </div>
                    <div class="mb-3">
                        <h6 class="text-muted mb-1">Reporter</h6>
                        <p class="mb-0"><?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?></p>
                    </div>
                    <div class="mb-3">
                        <h6 class="text-muted mb-1">Date Reported</h6>
                        <p class="mb-0">
                            <?php echo formatDate($incident['date_reported'], 'M d, Y h:i A'); ?>
                        </p>
                    </div>
                    <div>
                        <h6 class="text-muted mb-1">Description</h6>
                        <p class="mb-0 small">
                            <?php 
                            $desc = htmlspecialchars($incident['description']);
                            echo strlen($desc) > 150 ? substr($desc, 0, 150) . '...' : $desc; 
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm bg-warning bg-opacity-10 border-warning">
                <div class="card-body">
                    <h6 class="mb-2">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Tips
                    </h6>
                    <ul class="small mb-0 ps-3">
                        <li class="mb-1">Be clear and detailed in your response</li>
                        <li class="mb-1">Document all actions taken</li>
                        <li class="mb-1">Update status regularly to keep reporter informed</li>
                        <li>Mark as "Resolved" only when fully addressed</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Response templates
const templates = {
    investigating: "This incident is currently under investigation. Our team is gathering information and will take appropriate action. You will be notified of any updates.",
    resolved: "This incident has been successfully resolved. The necessary actions have been taken to address the reported issue. Thank you for your report.",
    no_action: "After thorough investigation, we have determined that no further action is required for this incident. However, we appreciate your vigilance in reporting.",
    referred: "This incident has been referred to the appropriate authorities for further action. We will monitor the progress and keep you updated."
};

function insertTemplate(type) {
    const textarea = document.getElementById('responseNotes');
    const template = templates[type];
    
    if (textarea.value.trim()) {
        if (confirm('This will replace your current notes. Continue?')) {
            textarea.value = template;
            updateCharCount();
        }
    } else {
        textarea.value = template;
        updateCharCount();
    }
}

// Character counter
const responseNotes = document.getElementById('responseNotes');
const charCount = document.getElementById('charCount');

function updateCharCount() {
    charCount.textContent = responseNotes.value.length;
    if (responseNotes.value.length > 2000) {
        charCount.classList.add('text-danger');
    } else {
        charCount.classList.remove('text-danger');
    }
}

responseNotes.addEventListener('input', updateCharCount);
updateCharCount();

// Status change handler
const statusSelect = document.getElementById('statusSelect');
const requiredIndicator = document.getElementById('requiredIndicator');
const notesHelp = document.getElementById('notesHelp');

statusSelect.addEventListener('change', function() {
    if (this.value === 'Resolved' || this.value === 'Closed') {
        requiredIndicator.style.display = 'inline';
        notesHelp.classList.remove('text-muted');
        notesHelp.classList.add('text-danger');
        responseNotes.setAttribute('required', 'required');
    } else {
        requiredIndicator.style.display = 'none';
        notesHelp.classList.add('text-muted');
        notesHelp.classList.remove('text-danger');
        responseNotes.removeAttribute('required');
    }
});

// Trigger on page load if quick resolve
window.addEventListener('DOMContentLoaded', function() {
    if ('<?php echo $quick_resolve ? "1" : "0"; ?>' === '1') {
        statusSelect.value = 'Resolved';
        statusSelect.dispatchEvent(new Event('change'));
    }
});
</script>

<?php include '../../includes/footer.php'; ?>