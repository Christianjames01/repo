<?php
/**
 * Add Blotter Record Page
 * Path: modules/blotter/add-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: my-blotter.php');
    exit();
}

$page_title = 'Add Blotter Record';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complainant_id = intval($_POST['complainant_id']);
    $respondent_id = !empty($_POST['respondent_id']) ? intval($_POST['respondent_id']) : null;
    $incident_date = $_POST['incident_date'];
    $incident_time = !empty($_POST['incident_time']) ? $_POST['incident_time'] : null;
    $incident_type = trim($_POST['incident_type']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $status = $_POST['status'];
    $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    // Generate case number (format: YEAR-XXXXXX)
    $year = date('Y');
    $count_sql = "SELECT COUNT(*) as count FROM tbl_blotter WHERE YEAR(created_at) = YEAR(CURDATE())";
    $count_result = $conn->query($count_sql);
    $count_row = $count_result->fetch_assoc();
    $case_number = $year . '-' . str_pad($count_row['count'] + 1, 6, '0', STR_PAD_LEFT);
    
    // Validate inputs
    if (empty($complainant_id) || empty($incident_date) || empty($incident_type) || empty($description)) {
        $_SESSION['error_message'] = "Please fill in all required fields.";
    } else {
        // Insert blotter record
        $stmt = $conn->prepare("INSERT INTO tbl_blotter (case_number, complainant_id, respondent_id, incident_date, incident_time, incident_type, description, location, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisssssss", $case_number, $complainant_id, $respondent_id, $incident_date, $incident_time, $incident_type, $description, $location, $status, $remarks);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Blotter record added successfully! Case Number: " . $case_number;
            header('Location: add-blotter.php');
            exit();
        } else {
            $_SESSION['error_message'] = "Error adding blotter record: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get all residents for dropdown
$residents_sql = "SELECT resident_id, CONCAT(first_name, ' ', last_name) as full_name FROM tbl_residents ORDER BY last_name, first_name";
$residents_result = $conn->query($residents_sql);
$residents = $residents_result->fetch_all(MYSQLI_ASSOC);

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
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
}

.card-body {
    padding: 2rem;
}

/* Page Header */
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

.page-header p {
    font-size: 0.95rem;
    color: #6c757d;
    margin-bottom: 0;
}

/* Form Sections */
.form-section {
    background: #f8f9fa;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a1a1a;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 0.5rem;
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

.form-label .required {
    color: #dc3545;
    font-size: 1rem;
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

.form-control::placeholder {
    color: #adb5bd;
}

textarea.form-control {
    resize: vertical;
    min-height: 100px;
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
    padding: 0.75rem 2rem;
    font-size: 1.05rem;
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

.alert i {
    font-size: 1.1rem;
}

/* Form Help Text */
.form-text {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Action Buttons Container */
.action-buttons {
    background: #f8f9fa;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-top: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
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
        padding: 1.5rem;
    }
    
    .form-section {
        padding: 1.25rem;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .action-buttons .btn {
        width: 100%;
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
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div class="flex-grow-1">
                <h2>
                    <i class="fas fa-plus-circle me-2 text-primary"></i>
                    Add New Blotter Record
                </h2>
                <p>Create a new blotter entry in the system</p>
            </div>
            <div>
                <a href="manage-blotter.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-clipboard-list me-2 text-primary"></i>
                        Blotter Information
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="blotterForm">
                        <!-- Parties Involved Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-users text-primary"></i>
                                Parties Involved
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="complainant_id" class="form-label">
                                        <i class="fas fa-user"></i>
                                        Complainant
                                        <span class="required">*</span>
                                    </label>
                                    <select name="complainant_id" id="complainant_id" class="form-select" required>
                                        <option value="">Select Complainant</option>
                                        <?php foreach ($residents as $resident): ?>
                                        <option value="<?= $resident['resident_id'] ?>" <?= (isset($_POST['complainant_id']) && $_POST['complainant_id'] == $resident['resident_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($resident['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text">
                                        <i class="fas fa-info-circle"></i>
                                        Person filing the complaint
                                    </small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="respondent_id" class="form-label">
                                        <i class="fas fa-user"></i>
                                        Respondent
                                    </label>
                                    <select name="respondent_id" id="respondent_id" class="form-select">
                                        <option value="">Select Respondent (Optional)</option>
                                        <?php foreach ($residents as $resident): ?>
                                        <option value="<?= $resident['resident_id'] ?>" <?= (isset($_POST['respondent_id']) && $_POST['respondent_id'] == $resident['resident_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($resident['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text">
                                        <i class="fas fa-info-circle"></i>
                                        Person being complained about
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Incident Details Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-exclamation-triangle text-warning"></i>
                                Incident Details
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="incident_date" class="form-label">
                                        <i class="fas fa-calendar-alt"></i>
                                        Incident Date
                                        <span class="required">*</span>
                                    </label>
                                    <input type="date" name="incident_date" id="incident_date" class="form-control" value="<?= $_POST['incident_date'] ?? '' ?>" required max="<?= date('Y-m-d') ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="incident_time" class="form-label">
                                        <i class="fas fa-clock"></i>
                                        Incident Time
                                    </label>
                                    <input type="time" name="incident_time" id="incident_time" class="form-control" value="<?= $_POST['incident_time'] ?? '' ?>">
                                    <small class="form-text">
                                        <i class="fas fa-info-circle"></i>
                                        Optional - Approximate time
                                    </small>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-flag"></i>
                                        Status
                                        <span class="required">*</span>
                                    </label>
                                    <select name="status" id="status" class="form-select" required>
                                        <option value="Pending" <?= (isset($_POST['status']) && $_POST['status'] == 'Pending') ? 'selected' : '' ?>>Pending</option>
                                        <option value="Under Investigation" <?= (isset($_POST['status']) && $_POST['status'] == 'Under Investigation') ? 'selected' : '' ?>>Under Investigation</option>
                                        <option value="Resolved" <?= (isset($_POST['status']) && $_POST['status'] == 'Resolved') ? 'selected' : '' ?>>Resolved</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="incident_type" class="form-label">
                                        <i class="fas fa-tag"></i>
                                        Incident Type
                                        <span class="required">*</span>
                                    </label>
                                    <select name="incident_type" id="incident_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <option value="Noise Complaint" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Noise Complaint') ? 'selected' : '' ?>>Noise Complaint</option>
                                        <option value="Physical Assault" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Physical Assault') ? 'selected' : '' ?>>Physical Assault</option>
                                        <option value="Verbal Abuse" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Verbal Abuse') ? 'selected' : '' ?>>Verbal Abuse</option>
                                        <option value="Theft" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Theft') ? 'selected' : '' ?>>Theft</option>
                                        <option value="Property Damage" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Property Damage') ? 'selected' : '' ?>>Property Damage</option>
                                        <option value="Boundary Dispute" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Boundary Dispute') ? 'selected' : '' ?>>Boundary Dispute</option>
                                        <option value="Domestic Violence" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Domestic Violence') ? 'selected' : '' ?>>Domestic Violence</option>
                                        <option value="Others" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] == 'Others') ? 'selected' : '' ?>>Others</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="location" class="form-label">
                                        <i class="fas fa-map-marker-alt"></i>
                                        Location
                                        <span class="required">*</span>
                                    </label>
                                    <input type="text" name="location" id="location" class="form-control" placeholder="Enter incident location" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" required>
                                    <small class="form-text">
                                        <i class="fas fa-info-circle"></i>
                                        Specific address or landmark
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Description Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-file-alt text-info"></i>
                                Incident Description & Remarks
                            </div>
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label">
                                        <i class="fas fa-align-left"></i>
                                        Description
                                        <span class="required">*</span>
                                    </label>
                                    <textarea name="description" id="description" class="form-control" rows="6" placeholder="Provide detailed description of the incident..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                    <small class="form-text">
                                        <i class="fas fa-info-circle"></i>
                                        Include all relevant details about the incident
                                    </small>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="remarks" class="form-label">
                                        <i class="fas fa-sticky-note"></i>
                                        Remarks
                                    </label>
                                    <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Additional remarks (optional)"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                                    <small class="form-text">
                                        <i class="fas fa-info-circle"></i>
                                        Any additional notes or observations
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <div>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Fields marked with <span class="text-danger">*</span> are required
                                </small>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="manage-blotter.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save me-2"></i>Save Blotter Record
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Prevent selecting same person as complainant and respondent
document.getElementById('complainant_id').addEventListener('change', function() {
    const respondentSelect = document.getElementById('respondent_id');
    const selectedComplainant = this.value;
    
    // Reset respondent if same as complainant
    if (respondentSelect.value === selectedComplainant) {
        respondentSelect.value = '';
    }
});

document.getElementById('respondent_id').addEventListener('change', function() {
    const complainantSelect = document.getElementById('complainant_id');
    const selectedRespondent = this.value;
    
    // Show warning if same as complainant
    if (selectedRespondent && selectedRespondent === complainantSelect.value) {
        alert('Complainant and Respondent cannot be the same person!');
        this.value = '';
    }
});

// Form validation
document.getElementById('blotterForm').addEventListener('submit', function(e) {
    const complainant = document.getElementById('complainant_id').value;
    const respondent = document.getElementById('respondent_id').value;
    
    if (complainant && respondent && complainant === respondent) {
        e.preventDefault();
        alert('Complainant and Respondent cannot be the same person!');
        return false;
    }
});

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    var alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>