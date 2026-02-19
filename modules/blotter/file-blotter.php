<?php
/**
 * Resident File Blotter Page
 * Path: modules/blotter/file-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = getCurrentResidentId();
$user_id = getCurrentUserId();

if ($user_role !== 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

// Check if resident is verified
$verify_sql = "SELECT is_verified FROM tbl_residents WHERE resident_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("i", $resident_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();
$verify_data = $verify_result->fetch_assoc();
$verify_stmt->close();

if (!$verify_data || $verify_data['is_verified'] != 1) {
    header('Location: not-verified-blotter.php');
    exit();
}

$page_title = 'File Blotter Complaint';
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complainant_id = $resident_id;
    $respondent_id = !empty($_POST['respondent_id']) ? intval($_POST['respondent_id']) : null;
    $respondent_name = !empty($_POST['respondent_name']) ? trim($_POST['respondent_name']) : null;
    $incident_date = $_POST['incident_date'];
    $incident_time = !empty($_POST['incident_time']) ? $_POST['incident_time'] : null;
    $incident_type = trim($_POST['incident_type']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    $status = 'Pending';
    
    // Generate case number (format: YEAR-XXXXXX)
    $year = date('Y');
    $count_sql = "SELECT COUNT(*) as count FROM tbl_blotter WHERE YEAR(created_at) = YEAR(CURDATE())";
    $count_result = $conn->query($count_sql);
    $count_row = $count_result->fetch_assoc();
    $case_number = $year . '-' . str_pad($count_row['count'] + 1, 6, '0', STR_PAD_LEFT);
    
    // Validate inputs
    if (empty($incident_date) || empty($incident_type) || empty($description) || empty($location)) {
        $error_message = "Please fill in all required fields.";
    } elseif (empty($respondent_id) && empty($respondent_name)) {
        $error_message = "Please select a respondent from the list or enter their name manually.";
    } else {
        // Insert blotter record
        $stmt = $conn->prepare("INSERT INTO tbl_blotter (case_number, complainant_id, respondent_id, respondent_name, incident_date, incident_time, incident_type, description, location, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siissssssss", $case_number, $complainant_id, $respondent_id, $respondent_name, $incident_date, $incident_time, $incident_type, $description, $location, $status, $remarks);
        
        if ($stmt->execute()) {
            $blotter_id = $conn->insert_id;
            $stmt->close();

            // ─── 1. Notify Admins/Staff ───────────────────────────────────
            $admin_sql = "SELECT user_id FROM tbl_users 
                          WHERE (
                              role LIKE '%Admin%' OR 
                              role LIKE '%admin%' OR 
                              role LIKE '%Staff%' OR 
                              role LIKE '%staff%'
                          ) 
                          AND is_active = 1";
            $admin_result = $conn->query($admin_sql);

            if ($admin_result && $admin_result->num_rows > 0) {
                $admin_title = "New Blotter Complaint Filed";
                $admin_message = "A new blotter complaint (Case #$case_number) has been filed by a resident. Type: $incident_type. Please review and take action.";
                
                $admin_notif_stmt = $conn->prepare("INSERT INTO tbl_notifications (user_id, title, message, type, reference_type, reference_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
                $admin_notif_type = 'blotter_filed';
                $ref_type = 'blotter';
                
                while ($admin_row = $admin_result->fetch_assoc()) {
                    $admin_notif_stmt->bind_param("issssi", $admin_row['user_id'], $admin_title, $admin_message, $admin_notif_type, $ref_type, $blotter_id);
                    $admin_notif_stmt->execute();
                }
                $admin_notif_stmt->close();
            }

            // ─── 2. Notify the Resident who filed ────────────────────────
            $res_notif_stmt = $conn->prepare("INSERT INTO tbl_notifications 
                (user_id, title, message, type, reference_type, reference_id, is_read, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
            $res_title   = "Blotter Complaint Filed Successfully";
            $res_message = "Your blotter complaint (Case #$case_number) has been received and is now pending review. Type: $incident_type. You will be contacted by barangay officials.";
            $res_type    = 'blotter_filed';
            $res_ref     = 'blotter';
            $res_notif_stmt->bind_param("issssi", $user_id, $res_title, $res_message, $res_type, $res_ref, $blotter_id);
            $res_notif_stmt->execute();
            $res_notif_stmt->close();

            $success_message = "Your complaint has been filed successfully! Case Number: " . $case_number . ". You will be contacted by barangay officials regarding your complaint.";
            
            // Clear form
            $_POST = array();
        } else {
            $error_message = "Error filing complaint: " . $conn->error;
            $stmt->close();
        }
    }
}

// Get all residents for respondent dropdown (excluding current resident)
$residents_sql = "SELECT resident_id, CONCAT(first_name, ' ', last_name) as full_name FROM tbl_residents WHERE resident_id != ? ORDER BY last_name, first_name";
$stmt = $conn->prepare($residents_sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$residents_result = $stmt->get_result();
$residents = $residents_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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

.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover { box-shadow: var(--shadow-md); }

.card-body { padding: 1.75rem; }

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

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn:active { transform: translateY(0); }
.btn-lg { padding: 0.875rem 2rem; font-size: 1.05rem; }

.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-success { background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%); border-left-color: #198754; }
.alert-danger  { background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%); border-left-color: #dc3545; }
.alert-info    { background: linear-gradient(135deg, #d1ecf1 0%, #e7f5f7 100%); border-left-color: #0dcaf0; }
.alert-warning { background: linear-gradient(135deg, #fff3cd 0%, #fff9e5 100%); border-left-color: #ffc107; }
.alert i { font-size: 1.1rem; }

.bg-light { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important; }

.form-check-input {
    width: 1.25rem;
    height: 1.25rem;
    border: 2px solid #dee2e6;
    border-radius: 4px;
}

.form-check-input:checked { background-color: #0d6efd; border-color: #0d6efd; }
.form-check-label { padding-left: 0.5rem; }

.modal-content { border-radius: var(--border-radius); border: none; box-shadow: var(--shadow-lg); }
.modal-header { border-bottom: 2px solid #e9ecef; border-radius: var(--border-radius) var(--border-radius) 0 0; }
.modal-header.bg-primary { background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%) !important; }
.modal-footer { border-top: 2px solid #e9ecef; }

@media (max-width: 768px) {
    .container-fluid { padding-left: 1rem; padding-right: 1rem; }
    .card-body { padding: 1.25rem; }
    .btn { padding: 0.5rem 1rem; font-size: 0.875rem; }
}

html { scroll-behavior: smooth; }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-file-alt me-2 text-primary"></i>
                        File Blotter Complaint
                    </h2>
                    <p class="text-muted mb-0">Submit a complaint to the barangay office</p>
                </div>
                <a href="my-blotter.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to My Blotter
                </a>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <div class="mt-2">
            <a href="my-blotter.php" class="btn btn-sm btn-success">
                <i class="fas fa-eye me-1"></i>View My Blotter Records
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Information Alert -->
    <div class="alert alert-info alert-dismissible fade show">
        <h5 class="alert-heading fw-bold">
            <i class="fas fa-info-circle me-2"></i>Before Filing a Complaint
        </h5>
        <p class="mb-2">Please ensure that:</p>
        <ul class="mb-0">
            <li>You provide accurate and truthful information</li>
            <li>The incident details are complete and clear</li>
            <li>You have tried to resolve the matter amicably if possible</li>
            <li>Filing false complaints may result in legal consequences</li>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Form Card -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" id="blotterForm">
                        <div class="row">
                            <!-- Respondent Selection -->
                            <div class="col-12 mb-3">
                                <label class="form-label">Who are you filing this complaint against? <span class="text-danger">*</span></label>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="respondent_id" class="form-label">Select from Registered Residents</label>
                                            <select name="respondent_id" id="respondent_id" class="form-select">
                                                <option value="">-- Select Respondent --</option>
                                                <?php foreach ($residents as $resident): ?>
                                                <option value="<?= $resident['resident_id'] ?>" <?= (isset($_POST['respondent_id']) && $_POST['respondent_id'] == $resident['resident_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($resident['full_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="text-center text-muted my-2">
                                            <small>- OR -</small>
                                        </div>
                                        <div>
                                            <label for="respondent_name" class="form-label">Enter Name Manually (if not in list)</label>
                                            <input type="text" name="respondent_name" id="respondent_name" class="form-control" placeholder="Enter respondent's full name" value="<?= htmlspecialchars($_POST['respondent_name'] ?? '') ?>">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Use this only if the person is not a registered resident
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Incident Date & Time -->
                            <div class="col-md-6 mb-3">
                                <label for="incident_date" class="form-label">When did the incident happen? <span class="text-danger">*</span></label>
                                <input type="date" name="incident_date" id="incident_date" class="form-control" value="<?= $_POST['incident_date'] ?? '' ?>" required max="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="incident_time" class="form-label">What time did it happen? (Optional)</label>
                                <input type="time" name="incident_time" id="incident_time" class="form-control" value="<?= $_POST['incident_time'] ?? '' ?>">
                            </div>

                            <!-- Incident Type & Location -->
                            <div class="col-md-6 mb-3">
                                <label for="incident_type" class="form-label">Type of Incident <span class="text-danger">*</span></label>
                                <select name="incident_type" id="incident_type" class="form-select" required>
                                    <option value="">Select Incident Type</option>
                                    <option value="Noise Complaint"    <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Noise Complaint')    ? 'selected' : '' ?>>Noise Complaint</option>
                                    <option value="Physical Assault"   <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Physical Assault')   ? 'selected' : '' ?>>Physical Assault</option>
                                    <option value="Verbal Abuse"       <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Verbal Abuse')       ? 'selected' : '' ?>>Verbal Abuse / Threats</option>
                                    <option value="Theft"              <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Theft')              ? 'selected' : '' ?>>Theft</option>
                                    <option value="Property Damage"    <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Property Damage')    ? 'selected' : '' ?>>Property Damage</option>
                                    <option value="Boundary Dispute"   <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Boundary Dispute')   ? 'selected' : '' ?>>Boundary Dispute</option>
                                    <option value="Domestic Violence"  <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Domestic Violence')  ? 'selected' : '' ?>>Domestic Violence</option>
                                    <option value="Harassment"         <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Harassment')         ? 'selected' : '' ?>>Harassment</option>
                                    <option value="Others"             <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Others')             ? 'selected' : '' ?>>Others</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="location" class="form-label">Where did it happen? <span class="text-danger">*</span></label>
                                <input type="text" name="location" id="location" class="form-control" placeholder="Enter exact location of incident" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" required>
                            </div>

                            <!-- Description -->
                            <div class="col-12 mb-3">
                                <label for="description" class="form-label">What happened? (Detailed Description) <span class="text-danger">*</span></label>
                                <textarea name="description" id="description" class="form-control" rows="6" placeholder="Please provide a detailed description of what happened. Include all relevant facts, witnesses (if any), and any other important information..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Be as detailed as possible. This will help the barangay officials understand and resolve your complaint.
                                </small>
                            </div>

                            <!-- Remarks -->
                            <div class="col-12 mb-3">
                                <label for="remarks" class="form-label">Additional Information (Optional)</label>
                                <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Any additional information, evidence, or witnesses you want to mention..."><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- Agreement Checkbox -->
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="agreement" required>
                            <label class="form-check-label" for="agreement">
                                I hereby declare that all information provided above is true and correct to the best of my knowledge. I understand that filing false complaints may result in legal consequences.
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="fas fa-paper-plane me-2"></i>Submit Complaint
                            </button>
                            <a href="my-blotter.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submit Confirmation Modal -->
<div class="modal fade" id="submitModal" tabindex="-1" aria-labelledby="submitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="submitModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Complaint Submission
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Please review your complaint carefully before submitting.</strong>
                </div>
                
                <h6 class="fw-bold mb-3">Complaint Summary:</h6>
                
                <div class="mb-3">
                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Respondent:</label>
                    <div class="fw-bold" id="modal_respondent">-</div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Incident Date:</label>
                        <div class="fw-bold" id="modal_date">-</div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Incident Time:</label>
                        <div class="fw-bold" id="modal_time">-</div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Incident Type:</label>
                    <div class="fw-bold" id="modal_type">-</div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Location:</label>
                    <div class="fw-bold" id="modal_location">-</div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Description:</label>
                    <div class="fw-bold text-break" id="modal_description" style="max-height: 150px; overflow-y: auto;">-</div>
                </div>

                <div class="alert alert-info mb-0">
                    <small>
                        <i class="fas fa-shield-alt me-1"></i>
                        By submitting this complaint, you confirm that all information provided is accurate and truthful. False complaints may result in legal consequences.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmSubmitBtn">
                    <i class="fas fa-check me-2"></i>Confirm & Submit
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Clear manual name if dropdown is selected
document.getElementById('respondent_id').addEventListener('change', function() {
    if (this.value) {
        document.getElementById('respondent_name').value = '';
        document.getElementById('respondent_name').disabled = true;
    } else {
        document.getElementById('respondent_name').disabled = false;
    }
});

// Clear dropdown if manual name is entered
document.getElementById('respondent_name').addEventListener('input', function() {
    if (this.value.trim()) {
        document.getElementById('respondent_id').value = '';
        document.getElementById('respondent_id').disabled = true;
    } else {
        document.getElementById('respondent_id').disabled = false;
    }
});

// Show modal with form data
document.getElementById('submitBtn').addEventListener('click', function() {
    const form = document.getElementById('blotterForm');
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    if (!document.getElementById('agreement').checked) {
        alert('Please agree to the declaration before submitting.');
        return;
    }

    const respondentId   = document.getElementById('respondent_id').value;
    const respondentName = document.getElementById('respondent_name').value;
    const incidentDate   = document.getElementById('incident_date').value;
    const incidentTime   = document.getElementById('incident_time').value;
    const incidentType   = document.getElementById('incident_type').value;
    const location       = document.getElementById('location').value;
    const description    = document.getElementById('description').value;

    if (!respondentId && !respondentName.trim()) {
        alert('Please select a respondent from the list or enter their name manually.');
        return;
    }

    let respondentDisplay = '';
    if (respondentId) {
        const selectedOption = document.querySelector(`#respondent_id option[value="${respondentId}"]`);
        respondentDisplay = selectedOption ? selectedOption.text : '-';
    } else {
        respondentDisplay = respondentName;
    }
    
    document.getElementById('modal_respondent').textContent  = respondentDisplay;
    document.getElementById('modal_date').textContent        = incidentDate ? new Date(incidentDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '-';
    document.getElementById('modal_time').textContent        = incidentTime || 'Not specified';
    document.getElementById('modal_type').textContent        = incidentType || '-';
    document.getElementById('modal_location').textContent    = location || '-';
    document.getElementById('modal_description').textContent = description || '-';

    const modal = new bootstrap.Modal(document.getElementById('submitModal'));
    modal.show();
});

// Handle confirm submit
document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
    document.getElementById('blotterForm').submit();
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