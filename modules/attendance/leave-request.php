<?php
/**
 * Leave Request Form (For Staff) - Modal Version
 * modules/attendance/leave-request.php
 */

// Load config first (contains session settings)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Start session after config is loaded
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('/barangaylink1/modules/auth/login.php', 'Please login to continue', 'error');
}

$page_title = 'Request Leave';
$current_user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

// Handle leave request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    $leave_type = sanitizeInput($_POST['leave_type']);
    $start_date = sanitizeInput($_POST['start_date']);
    $end_date = sanitizeInput($_POST['end_date']);
    $reason = sanitizeInput($_POST['reason']);
    
    // Validate dates
    if (strtotime($start_date) > strtotime($end_date)) {
        $_SESSION['error_message'] = 'End date must be after start date';
    } elseif (strtotime($start_date) < strtotime(date('Y-m-d'))) {
        $_SESSION['error_message'] = 'Start date cannot be in the past';
    } else {
        // Check for overlapping leave requests
        $overlap = fetchOne($conn,
            "SELECT leave_id FROM tbl_leave_requests 
            WHERE user_id = ? 
            AND status IN ('Pending', 'Approved')
            AND (
                (start_date <= ? AND end_date >= ?) OR
                (start_date <= ? AND end_date >= ?) OR
                (start_date >= ? AND end_date <= ?)
            )",
            [$current_user_id, $start_date, $start_date, $end_date, $end_date, $start_date, $end_date],
            'issssss'
        );
        
        if ($overlap) {
            $_SESSION['error_message'] = 'You already have a leave request for these dates';
        } else {
            // Insert leave request
            $sql = "INSERT INTO tbl_leave_requests (user_id, leave_type, start_date, end_date, reason, status) 
                    VALUES (?, ?, ?, ?, ?, 'Pending')";
            
            if (executeQuery($conn, $sql, [$current_user_id, $leave_type, $start_date, $end_date, $reason], 'issss')) {
                $leave_id = $conn->insert_id;
                
                logActivity($conn, $current_user_id, 'Submitted leave request', 'tbl_leave_requests', $leave_id);
                
                // Notify admins
                $admins = fetchAll($conn, 
                    "SELECT user_id FROM tbl_users WHERE role IN ('Admin', 'Super Admin') AND is_active = 1"
                );
                
                if ($admins) {
                    foreach ($admins as $admin) {
                        createNotification(
                            $conn,
                            $admin['user_id'],
                            'New Leave Request',
                            "A new $leave_type request has been submitted from $start_date to $end_date",
                            'leave_request',
                            $leave_id,
                            'leave'
                        );
                    }
                }
                
                $_SESSION['success_message'] = 'Leave request submitted successfully';
                
                // Redirect based on user role
                if (in_array($user_role, ['Admin', 'Super Admin'])) {
                    header('Location: admin/manage-leaves.php');
                } else {
                    header('Location: manage-leaves.php');
                }
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to submit leave request';
            }
        }
    }
}

// Get leave types
$leave_types = [
    'Sick Leave',
    'Vacation Leave',
    'Emergency Leave',
    'Personal Leave',
    'Bereavement Leave',
    'Maternity Leave',
    'Paternity Leave'
];

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-plus text-primary me-2"></i>
                Request Leave
            </h1>
            <p class="text-muted mb-0">Submit a new leave request</p>
        </div>
        <div>
            <a href="<?php echo in_array($user_role, ['Admin', 'Super Admin']) ? 'admin/manage-leaves.php' : 'manage-leaves.php'; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to My Leaves
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Leave Request Form -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-file-alt me-2"></i>
                        Leave Request Form
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="leaveRequestForm">
                        <div class="mb-4">
                            <label for="leave_type" class="form-label fw-bold">
                                <i class="fas fa-tag me-1"></i>
                                Leave Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-lg" id="leave_type" name="leave_type" required>
                                <option value="">Select leave type...</option>
                                <?php foreach ($leave_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="start_date" class="form-label fw-bold">
                                    <i class="fas fa-calendar-day me-1"></i>
                                    Start Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control form-control-lg" 
                                       id="start_date" name="start_date" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_date" class="form-label fw-bold">
                                    <i class="fas fa-calendar-check me-1"></i>
                                    End Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control form-control-lg" 
                                       id="end_date" name="end_date" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="alert alert-info" id="duration_display" style="display: none;">
                                <i class="fas fa-clock me-2"></i>
                                <strong>Duration:</strong> <span id="duration_text">0 day(s)</span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="reason" class="form-label fw-bold">
                                <i class="fas fa-comment me-1"></i>
                                Reason <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="reason" name="reason" 
                                      rows="5" required 
                                      placeholder="Please provide detailed reason for your leave request..."
                                      minlength="10"></textarea>
                            <small class="text-muted">Be specific and provide all necessary details (minimum 10 characters)</small>
                        </div>

                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Important Reminders:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Submit your leave request at least 3 days in advance when possible</li>
                                <li>For emergency leaves, contact your supervisor immediately</li>
                                <li>Your request will be reviewed by administration</li>
                                <li>You will be notified once your request is processed</li>
                                <li>Ensure all information is accurate before submitting</li>
                            </ul>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary btn-lg" id="previewBtn">
                                <i class="fas fa-eye me-2"></i>
                                Review & Submit Request
                            </button>
                            <a href="<?php echo in_array($user_role, ['Admin', 'Super Admin']) ? 'admin/manage-leaves.php' : 'manage-leaves.php'; ?>" class="btn btn-outline-secondary btn-lg">
                                <i class="fas fa-times me-2"></i>
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Guide Card -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-info bg-opacity-10">
                    <h6 class="mb-0 text-info">
                        <i class="fas fa-lightbulb me-2"></i>
                        Leave Request Guidelines
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-check-circle me-2"></i>Do's</h6>
                            <ul class="small">
                                <li>Plan and submit your leave in advance</li>
                                <li>Provide clear and valid reasons</li>
                                <li>Check your leave balance before requesting</li>
                                <li>Coordinate with your team</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-danger"><i class="fas fa-times-circle me-2"></i>Don'ts</h6>
                            <ul class="small">
                                <li>Don't submit last-minute requests (except emergencies)</li>
                                <li>Don't provide vague or incomplete reasons</li>
                                <li>Don't submit overlapping leave requests</li>
                                <li>Don't forget to follow up on pending requests</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmSubmitModal" tabindex="-1" aria-labelledby="confirmSubmitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="confirmSubmitModalLabel">
                    <i class="fas fa-check-circle me-2"></i>
                    Confirm Leave Request Submission
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Please review your leave request details before submitting.
                </div>
                
                <div class="card bg-light border-0">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-5 fw-bold text-muted">
                                <i class="fas fa-tag me-2"></i>Leave Type:
                            </div>
                            <div class="col-7" id="modal_leave_type"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold text-muted">
                                <i class="fas fa-calendar-day me-2"></i>Start Date:
                            </div>
                            <div class="col-7" id="modal_start_date"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold text-muted">
                                <i class="fas fa-calendar-check me-2"></i>End Date:
                            </div>
                            <div class="col-7" id="modal_end_date"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold text-muted">
                                <i class="fas fa-clock me-2"></i>Duration:
                            </div>
                            <div class="col-7">
                                <span class="badge bg-primary" id="modal_duration"></span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-5 fw-bold text-muted">
                                <i class="fas fa-comment me-2"></i>Reason:
                            </div>
                            <div class="col-7">
                                <div class="text-break" id="modal_reason" style="max-height: 150px; overflow-y: auto;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mt-3 mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Important:</strong> Once submitted, you cannot edit this request. Please ensure all information is correct.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-edit me-2"></i>
                    Go Back to Edit
                </button>
                <button type="button" class="btn btn-primary" id="finalSubmitBtn">
                    <i class="fas fa-paper-plane me-2"></i>
                    Submit Request
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Calculate duration when dates change
function calculateDuration() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = end - start;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        
        if (diffDays > 0) {
            document.getElementById('duration_text').textContent = diffDays + ' day(s)';
            document.getElementById('duration_display').style.display = 'block';
            
            // Warning for long leaves
            if (diffDays > 15) {
                document.getElementById('duration_display').className = 'alert alert-warning';
                document.getElementById('duration_text').innerHTML = diffDays + ' day(s) <small>(Long duration - may require additional approval)</small>';
            } else {
                document.getElementById('duration_display').className = 'alert alert-info';
            }
            
            return diffDays;
        } else {
            document.getElementById('duration_display').style.display = 'none';
            return 0;
        }
    } else {
        document.getElementById('duration_display').style.display = 'none';
        return 0;
    }
}

// Update end date minimum when start date changes
document.getElementById('start_date').addEventListener('change', function() {
    document.getElementById('end_date').min = this.value;
    calculateDuration();
});

document.getElementById('end_date').addEventListener('change', calculateDuration);

// Preview button - show modal with form data
document.getElementById('previewBtn').addEventListener('click', function() {
    const leaveType = document.getElementById('leave_type').value;
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const reason = document.getElementById('reason').value.trim();
    
    // Validate form
    if (!leaveType) {
        alert('Please select a leave type');
        document.getElementById('leave_type').focus();
        return;
    }
    
    if (!startDate) {
        alert('Please select a start date');
        document.getElementById('start_date').focus();
        return;
    }
    
    if (!endDate) {
        alert('Please select an end date');
        document.getElementById('end_date').focus();
        return;
    }
    
    // Validate dates
    if (new Date(startDate) > new Date(endDate)) {
        alert('End date must be after or equal to start date');
        document.getElementById('end_date').focus();
        return;
    }
    
    if (!reason || reason.length < 10) {
        alert('Please provide a more detailed reason (at least 10 characters)');
        document.getElementById('reason').focus();
        return;
    }
    
    // Calculate duration
    const duration = Math.ceil((new Date(endDate) - new Date(startDate)) / (1000 * 60 * 60 * 24)) + 1;
    
    // Populate modal with form data
    document.getElementById('modal_leave_type').textContent = leaveType;
    document.getElementById('modal_start_date').textContent = formatDate(startDate);
    document.getElementById('modal_end_date').textContent = formatDate(endDate);
    document.getElementById('modal_duration').textContent = duration + ' day(s)';
    document.getElementById('modal_reason').textContent = reason;
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('confirmSubmitModal'));
    modal.show();
});

// Final submit button in modal
document.getElementById('finalSubmitBtn').addEventListener('click', function() {
    // Add hidden input to indicate form submission
    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'submit_leave';
    hiddenInput.value = '1';
    document.getElementById('leaveRequestForm').appendChild(hiddenInput);
    
    // Disable button to prevent double submission
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
    
    // Submit the form
    document.getElementById('leaveRequestForm').submit();
});

// Format date for display
function formatDate(dateString) {
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', options);
}

// Auto-resize textarea
document.getElementById('reason').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
});
</script>

<?php include '../../includes/footer.php'; ?>