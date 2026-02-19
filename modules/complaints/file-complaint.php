<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Only residents can file complaints
requireRole('Resident');

$current_user_id = getCurrentUserId();
$page_title = 'File a Complaint';

// Get resident info
$resident_id = null;
$resident_info = [];

$stmt = $conn->prepare("SELECT u.resident_id, r.first_name, r.last_name, r.email, r.contact_number, r.address 
                        FROM tbl_users u 
                        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id 
                        WHERE u.user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $resident_info = $result->fetch_assoc();
    $resident_id = $resident_info['resident_id'];
}
$stmt->close();

if (!$resident_id) {
    $_SESSION['error_message'] = 'Resident information not found';
    header('Location: view-complaints.php');
    exit;
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

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    font-weight: 700;
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

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

.alert i {
    font-size: 1.1rem;
}

/* Info Cards */
.bg-light {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
}

/* Form Text */
.form-text {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.5rem;
}

/* Checkbox Enhancement */
.form-check-input {
    width: 1.25rem;
    height: 1.25rem;
    border: 2px solid #dee2e6;
    border-radius: 4px;
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.form-check-label {
    padding-left: 0.5rem;
}

/* Priority Border Colors */
.border-success {
    border-width: 2px !important;
}

.border-warning {
    border-width: 2px !important;
}

.border-danger {
    border-width: 2px !important;
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
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold">
                                <i class="fas fa-file-alt me-2 text-primary"></i>
                                File a Complaint
                            </h2>
                            <p class="text-muted mb-0">Submit your concern or complaint to the barangay office</p>
                        </div>
                        <a href="view-complaints.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Complaints
                        </a>
                    </div>
                </div>
            </div>

            <!-- Error Message -->
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

            <form action="process-complaint.php" method="POST" enctype="multipart/form-data" id="complaintForm">
                <input type="hidden" name="action" value="file_complaint">
                <input type="hidden" name="resident_id" value="<?php echo $resident_id; ?>">

                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-8">
                        <!-- Complaint Information -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Complaint Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-lg" id="subject" name="subject" 
                                           placeholder="Brief description of your complaint" required maxlength="255">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>Keep it short and descriptive
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-lg" id="category" name="category" required>
                                            <option value="">Select category</option>
                                            <option value="Noise">Noise Disturbance</option>
                                            <option value="Garbage">Garbage/Waste Management</option>
                                            <option value="Property">Property Dispute</option>
                                            <option value="Infrastructure">Infrastructure/Road Issues</option>
                                            <option value="Public Safety">Public Safety Concern</option>
                                            <option value="Services">Barangay Services</option>
                                            <option value="Utilities">Utilities (Water/Electric)</option>
                                            <option value="Animals">Stray Animals</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="priority" class="form-label">Priority Level <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-lg" id="priority" name="priority" required>
                                            <option value="Low">Low - Not urgent, can wait</option>
                                            <option value="Medium" selected>Medium - Normal concern</option>
                                            <option value="High">High - Needs attention soon</option>
                                            <option value="Urgent">Urgent - Immediate attention required</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Detailed Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="description" name="description" rows="6" 
                                              placeholder="Please provide as much detail as possible about your complaint..." required></textarea>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Include: What happened? When? Where? Who was involved?
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="location" class="form-label">Location/Address</label>
                                    <input type="text" class="form-control form-control-lg" id="location" name="location" 
                                           placeholder="Specific location where the issue occurred" value="<?php echo htmlspecialchars($resident_info['address']); ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="attachments" class="form-label">Attachments (Optional)</label>
                                    <input type="file" class="form-control" id="attachments" name="attachments[]" 
                                           accept="image/*,.pdf,.doc,.docx" multiple>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Upload photos, documents, or evidence (Max 5MB per file, up to 5 files)
                                    </div>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        I certify that the information provided is true and accurate to the best of my knowledge.
                                    </label>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Complaint
                                    </button>
                                    <a href="view-complaints.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-lg-4">
                        <!-- Your Information -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Your Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Name</label>
                                    <p class="mb-0 fw-bold"><?php echo htmlspecialchars($resident_info['first_name'] . ' ' . $resident_info['last_name']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Contact Number</label>
                                    <p class="mb-0"><?php echo htmlspecialchars($resident_info['contact_number']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Email</label>
                                    <p class="mb-0"><?php echo htmlspecialchars($resident_info['email']); ?></p>
                                </div>
                                <div class="mb-0">
                                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Address</label>
                                    <p class="mb-0"><?php echo htmlspecialchars($resident_info['address']); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Important Notes -->
                        <div class="card border-0 shadow-sm bg-light">
                            <div class="card-body">
                                <h6 class="fw-bold mb-3">
                                    <i class="fas fa-lightbulb me-2 text-warning"></i>Important Notes
                                </h6>
                                <ul class="small mb-0 ps-3">
                                    <li class="mb-2">Provide accurate and honest information</li>
                                    <li class="mb-2">You will receive a complaint number for tracking</li>
                                    <li class="mb-2">Response time depends on priority level</li>
                                    <li class="mb-2">You can track your complaint status online</li>
                                    <li class="mb-0">For emergencies, call the barangay hotline</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('complaintForm');
    const submitBtn = document.getElementById('submitBtn');
    
    // Form validation
    form.addEventListener('submit', function(e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        } else {
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
        }
        form.classList.add('was-validated');
    });

    // File upload validation
    const fileInput = document.getElementById('attachments');
    fileInput.addEventListener('change', function() {
        const files = this.files;
        let totalSize = 0;
        let valid = true;

        if (files.length > 5) {
            alert('Maximum 5 files allowed');
            this.value = '';
            return;
        }

        for (let file of files) {
            totalSize += file.size;
            if (file.size > 5242880) { // 5MB
                alert('Each file must be less than 5MB');
                this.value = '';
                return;
            }
        }

        if (totalSize > 26214400) { // 25MB total
            alert('Total file size must be less than 25MB');
            this.value = '';
            return;
        }
    });

    // Priority color coding
    const prioritySelect = document.getElementById('priority');
    prioritySelect.addEventListener('change', function() {
        this.classList.remove('border-success', 'border-warning', 'border-danger');
        
        switch(this.value) {
            case 'Low':
                this.classList.add('border-success');
                break;
            case 'Medium':
                this.classList.add('border-warning');
                break;
            case 'High':
            case 'Urgent':
                this.classList.add('border-danger');
                break;
        }
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

<?php include '../../includes/footer.php'; ?>