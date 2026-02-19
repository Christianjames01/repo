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

// Fetch list of staff users to assign (only for staff)
$staff_users = [];
if (!$is_resident) {
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

// Handle form submissions (only for staff)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_resident) {
    if (isset($_POST['update_complaint'])) {
        $new_status = trim($_POST['status']);
        $new_priority = trim($_POST['priority']);
        $responder_id = intval($_POST['responder_id']);
        
        $valid_statuses = ['Pending', 'In Progress', 'Resolved', 'Closed'];
        $valid_priorities = ['Low', 'Medium', 'High', 'Urgent'];
        
        $errors = [];
        
        if (!in_array($new_status, $valid_statuses)) {
            $errors[] = 'Invalid status selected.';
        }
        
        if (!in_array($new_priority, $valid_priorities)) {
            $errors[] = 'Invalid priority selected.';
        }
        
        if ($responder_id <= 0) {
            $errors[] = 'Please select a valid responder.';
        }
        
        if (empty($errors)) {
            $update_stmt = $conn->prepare("UPDATE tbl_complaints 
                                          SET status = ?, priority = ?, assigned_to = ? 
                                          WHERE complaint_id = ?");
            $update_stmt->bind_param("ssii", $new_status, $new_priority, $responder_id, $complaint_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success_message'] = 'Complaint updated successfully!';
                
                // Notify complainant about status change
                if (function_exists('notifyComplaintStatusUpdate') && !empty($complaint['resident_id'])) {
                    $user_stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE resident_id = ?");
                    $user_stmt->bind_param("i", $complaint['resident_id']);
                    $user_stmt->execute();
                    $user_result = $user_stmt->get_result();
                    if ($user_row = $user_result->fetch_assoc()) {
                        notifyComplaintStatusUpdate(
                            $conn, 
                            $complaint_id, 
                            $complaint['subject'], 
                            $new_status, 
                            $user_row['user_id'],
                            $responder_id
                        );
                    }
                    $user_stmt->close();
                }
                
                // Log activity
                if (function_exists('logActivity')) {
                    logActivity($conn, $current_user_id, "Updated complaint - Status: $new_status, Priority: $new_priority", 'tbl_complaints', $complaint_id);
                }
                
                header("Location: resident-complaint-details.php?id=$complaint_id");
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to update complaint: ' . $update_stmt->error;
            }
            $update_stmt->close();
        } else {
            $_SESSION['error_message'] = implode('<br>', $errors);
        }
    }
}

$page_title = 'Complaint Details';

// Helper functions - ONLY DECLARE ONCE
if (!function_exists('getComplaintStatusBadge')) {
    function getComplaintStatusBadge($status) {
        $status = trim($status);
        $badges = [
            'Pending' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>',
            'In Progress' => '<span class="badge bg-primary"><i class="fas fa-spinner me-1"></i>In Progress</span>',
            'Resolved' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Resolved</span>',
            'Closed' => '<span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>Closed</span>'
        ];
        return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

if (!function_exists('getComplaintPriorityBadge')) {
    function getComplaintPriorityBadge($priority) {
        $priority = trim($priority);
        $badges = [
            'Low' => '<span class="badge bg-success bg-opacity-25 text-success"><i class="fas fa-circle me-1"></i>Low</span>',
            'Medium' => '<span class="badge bg-warning bg-opacity-25 text-warning"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
            'High' => '<span class="badge bg-danger bg-opacity-25 text-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
            'Urgent' => '<span class="badge bg-danger text-white"><i class="fas fa-fire me-1"></i>Urgent</span>'
        ];
        return $badges[$priority] ?? '<span class="badge bg-secondary">' . htmlspecialchars($priority) . '</span>';
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <a href="view-complaints.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Complaints
            </a>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fas fa-file-alt me-2 text-primary"></i>
                    Complaint Details
                </h2>
                <div>
                    <?php echo getComplaintStatusBadge($complaint['status'] ?? 'Pending'); ?>
                </div>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo htmlspecialchars($_SESSION['success_message']); 
                unset($_SESSION['success_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php 
                echo htmlspecialchars($_SESSION['error_message']); 
                unset($_SESSION['error_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Complaint Information Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2 text-primary"></i>
                        Complaint Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p class="mb-2">
                                <strong class="text-muted">Complaint Number:</strong><br>
                                <span class="fs-5 text-primary fw-bold"><?php echo htmlspecialchars($complaint['complaint_number']); ?></span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2">
                                <strong class="text-muted">Date Filed:</strong><br>
                                <span><?php echo date('F d, Y g:i A', strtotime($complaint['date_filed'] ?? $complaint['created_at'])); ?></span>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p class="mb-2">
                                <strong class="text-muted">Category:</strong><br>
                                <?php
                                $icon_class = 'fa-comment';
                                switch($complaint['category']) {
                                    case 'Noise': $icon_class = 'fa-volume-up'; break;
                                    case 'Garbage': $icon_class = 'fa-trash'; break;
                                    case 'Property': $icon_class = 'fa-home'; break;
                                    case 'Infrastructure': $icon_class = 'fa-road'; break;
                                    case 'Public Safety': $icon_class = 'fa-shield-alt'; break;
                                    case 'Services': $icon_class = 'fa-concierge-bell'; break;
                                    case 'Animals': $icon_class = 'fa-paw'; break;
                                    case 'Utilities': $icon_class = 'fa-bolt'; break;
                                }
                                ?>
                                <i class="fas <?php echo $icon_class; ?> me-1"></i>
                                <span><?php echo htmlspecialchars($complaint['category'] ?? 'N/A'); ?></span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2">
                                <strong class="text-muted">Priority:</strong><br>
                                <?php echo getComplaintPriorityBadge($complaint['priority'] ?? 'Medium'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="mb-3">
                        <strong class="text-muted">Subject:</strong>
                        <p class="mt-1 fs-5 fw-bold"><?php echo htmlspecialchars($complaint['subject']); ?></p>
                    </div>

                    <div class="mb-3">
                        <strong class="text-muted">Description:</strong>
                        <p class="mt-1" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                    </div>

                    <?php
                    // Check for uploaded attachments
                    $upload_dir = '../../uploads/complaints/';
                    $attachments = [];
                    if (is_dir($upload_dir)) {
                        $files = scandir($upload_dir);
                        foreach ($files as $file) {
                            if (strpos($file, 'complaint_' . $complaint_id . '_') === 0) {
                                $attachments[] = $file;
                            }
                        }
                    }
                    
                    if (!empty($attachments)): ?>
                    <div class="mb-3">
                        <strong class="text-muted">
                            <i class="fas fa-paperclip me-1"></i>Attachments (<?php echo count($attachments); ?>):
                        </strong>
                        <div class="row mt-2">
                            <?php foreach ($attachments as $file): 
                                $file_path = $upload_dir . $file;
                                $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']);
                            ?>
                            <div class="col-md-3 col-6 mb-3">
                                <?php if ($is_image): ?>
                                    <a href="<?php echo $file_path; ?>" target="_blank" class="d-block position-relative attachment-image">
                                        <img src="<?php echo $file_path; ?>" class="img-fluid rounded shadow-sm" alt="Attachment" style="max-height: 200px; object-fit: cover; width: 100%;">
                                        <div class="attachment-overlay">
                                            <i class="fas fa-search-plus text-white fs-3"></i>
                                        </div>
                                    </a>
                                    <small class="text-muted d-block mt-1 text-truncate"><?php echo htmlspecialchars($file); ?></small>
                                <?php else: ?>
                                    <a href="<?php echo $file_path; ?>" target="_blank" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center py-3">
                                        <i class="fas fa-file fs-2 mb-2"></i>
                                        <span class="text-uppercase"><?php echo $file_ext; ?></span>
                                    </a>
                                    <small class="text-muted d-block mt-1 text-truncate"><?php echo htmlspecialchars($file); ?></small>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($complaint['assigned_to_name'])): ?>
                    <div class="alert alert-info border-0 mb-0">
                        <i class="fas fa-user-shield me-2"></i>
                        <strong>Assigned To:</strong> <?php echo htmlspecialchars($complaint['assigned_to_name']); ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-<?php echo $is_resident ? 'secondary' : 'warning'; ?> border-0 mb-0">
                        <i class="fas fa-<?php echo $is_resident ? 'clock' : 'exclamation-triangle'; ?> me-2"></i>
                        <strong><?php echo $is_resident ? 'Status:' : ''; ?></strong> 
                        <?php echo $is_resident ? 'Awaiting assignment to a staff member' : 'Not yet assigned to any staff member'; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Complainant Information Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0">
                        <i class="fas fa-user me-2 text-primary"></i>
                        <?php echo $is_resident ? 'Your Information' : 'Complainant Information'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <p class="mb-1"><strong class="text-muted">Name:</strong></p>
                            <p><?php echo htmlspecialchars($complaint['first_name'] . ' ' . $complaint['last_name']); ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <p class="mb-1"><strong class="text-muted">Contact Number:</strong></p>
                            <p><?php echo htmlspecialchars($complaint['contact_number']); ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <p class="mb-1"><strong class="text-muted">Email:</strong></p>
                            <p><?php echo htmlspecialchars($complaint['email']); ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <p class="mb-1"><strong class="text-muted">Address:</strong></p>
                            <p><?php echo htmlspecialchars($complaint['address']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$is_resident): ?>
            <!-- Admin Actions Card (Only visible to staff) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0">
                        <i class="fas fa-tools me-2 text-primary"></i>
                        Admin Actions
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="updateComplaintForm">
                        <div class="row">
                            <!-- Update Status -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-flag me-1"></i>Update Status
                                </label>
                                <select class="form-select" name="status" id="statusSelect" required>
                                    <option value="Pending" <?php echo ($complaint['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo ($complaint['status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Resolved" <?php echo ($complaint['status'] === 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="Closed" <?php echo ($complaint['status'] === 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>

                            <!-- Update Priority -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-exclamation-circle me-1"></i>Update Priority
                                </label>
                                <select class="form-select" name="priority" required>
                                    <option value="Low" <?php echo ($complaint['priority'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                    <option value="Medium" <?php echo ($complaint['priority'] === 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                    <option value="High" <?php echo ($complaint['priority'] === 'High') ? 'selected' : ''; ?>>High</option>
                                    <option value="Urgent" <?php echo ($complaint['priority'] === 'Urgent') ? 'selected' : ''; ?>>Urgent</option>
                                </select>
                            </div>

                            <!-- Assign Responder -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-user-shield me-1"></i>Assign Responder
                                </label>
                                <select class="form-select" name="responder_id" required>
                                    <option value="">-- Select Responder --</option>
                                    <?php foreach ($staff_users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>"
                                            <?php echo ($complaint['assigned_to'] == $user['user_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['role']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Single Update Button -->
                        <div class="row">
                            <div class="col-12">
                                <button type="button" class="btn btn-primary btn-lg w-100" onclick="confirmUpdate()">
                                    <i class="fas fa-save me-2"></i>Update Complaint
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if (!$is_resident): ?>
<!-- Close Complaint Confirmation Modal (Only for staff) -->
<div class="modal fade" id="closeComplaintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title text-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Close Complaint?
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
                    <i class="fas fa-times me-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-warning" onclick="submitForm()">
                    <i class="fas fa-check me-1"></i> Yes, Close Complaint
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.card {
    transition: transform 0.2s;
}

.card:hover {
    transform: translateY(-2px);
}

.form-select, .btn {
    transition: all 0.3s ease;
}

.form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.btn-primary.btn-lg {
    font-weight: 600;
    padding: 0.75rem 1.5rem;
}

.attachment-image {
    position: relative;
    display: block;
    overflow: hidden;
    border-radius: 0.375rem;
}

.attachment-image:hover .attachment-overlay {
    opacity: 1;
}

.attachment-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
}

.attachment-image img {
    transition: transform 0.3s;
}

.attachment-image:hover img {
    transform: scale(1.05);
}

@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .col-md-4 {
        margin-bottom: 1rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    var alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

<?php if (!$is_resident): ?>
function confirmUpdate() {
    const statusSelect = document.getElementById('statusSelect');
    const form = document.getElementById('updateComplaintForm');
    
    if (statusSelect.value === 'Closed') {
        // Show confirmation modal
        const modal = new bootstrap.Modal(document.getElementById('closeComplaintModal'));
        modal.show();
    } else {
        // Submit form directly
        submitForm();
    }
}

function submitForm() {
    const form = document.getElementById('updateComplaintForm');
    
    // Add the update_complaint input to the form
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'update_complaint';
    input.value = '1';
    form.appendChild(input);
    
    // Close modal if open
    const modalElement = document.getElementById('closeComplaintModal');
    if (modalElement) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    }
    
    // Submit the form
    form.submit();
}
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>