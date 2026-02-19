<?php
/**
 * Edit Blotter Record Page
 * Path: modules/blotter/edit-blotter.php
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

$page_title = 'Edit Blotter Record';
$success_message = '';
$error_message = '';

// Get blotter ID from URL
$blotter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($blotter_id <= 0) {
    header('Location: manage-blotter.php');
    exit();
}

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
    
    // Validate inputs
    if (empty($complainant_id) || empty($incident_date) || empty($incident_type) || empty($description)) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Update blotter record
        $stmt = $conn->prepare("UPDATE tbl_blotter SET complainant_id = ?, respondent_id = ?, incident_date = ?, incident_time = ?, incident_type = ?, description = ?, location = ?, status = ?, remarks = ?, updated_at = NOW() WHERE blotter_id = ?");
        $stmt->bind_param("iisssssssi", $complainant_id, $respondent_id, $incident_date, $incident_time, $incident_type, $description, $location, $status, $remarks, $blotter_id);
        
        if ($stmt->execute()) {
            $success_message = "Blotter record updated successfully!";
        } else {
            $error_message = "Error updating blotter record: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get blotter record
$sql = "SELECT * FROM tbl_blotter WHERE blotter_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $blotter_id);
$stmt->execute();
$result = $stmt->get_result();
$blotter = $result->fetch_assoc();
$stmt->close();

if (!$blotter) {
    header('Location: manage-blotter.php');
    exit();
}

// Get all residents for dropdown
$residents_sql = "SELECT resident_id, CONCAT(first_name, ' ', last_name) as full_name FROM tbl_residents ORDER BY last_name, first_name";
$residents_result = $conn->query($residents_sql);
$residents = $residents_result->fetch_all(MYSQLI_ASSOC);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-edit me-2"></i>Edit Blotter Record</h2>
                    <p class="text-muted">Case Number: <strong><?= htmlspecialchars($blotter['case_number'] ?? '#' . str_pad($blotter['blotter_id'], 5, '0', STR_PAD_LEFT)) ?></strong></p>
                </div>
                <div>
                    <a href="view-blotter.php?id=<?= $blotter_id ?>" class="btn btn-info me-2">
                        <i class="fas fa-eye me-2"></i>View
                    </a>
                    <a href="manage-blotter.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="POST" id="blotterForm">
                        <div class="row">
                            <!-- Complainant -->
                            <div class="col-md-6 mb-3">
                                <label for="complainant_id" class="form-label">Complainant <span class="text-danger">*</span></label>
                                <select name="complainant_id" id="complainant_id" class="form-select" required>
                                    <option value="">Select Complainant</option>
                                    <?php foreach ($residents as $resident): ?>
                                    <option value="<?= $resident['resident_id'] ?>" <?= $blotter['complainant_id'] == $resident['resident_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($resident['full_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Respondent -->
                            <div class="col-md-6 mb-3">
                                <label for="respondent_id" class="form-label">Respondent</label>
                                <select name="respondent_id" id="respondent_id" class="form-select">
                                    <option value="">Select Respondent (Optional)</option>
                                    <?php foreach ($residents as $resident): ?>
                                    <option value="<?= $resident['resident_id'] ?>" <?= $blotter['respondent_id'] == $resident['resident_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($resident['full_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Incident Date -->
                            <div class="col-md-6 mb-3">
                                <label for="incident_date" class="form-label">Incident Date <span class="text-danger">*</span></label>
                                <input type="date" name="incident_date" id="incident_date" class="form-control" value="<?= $blotter['incident_date'] ?>" required max="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- Incident Time -->
                            <div class="col-md-6 mb-3">
                                <label for="incident_time" class="form-label">Incident Time</label>
                                <input type="time" name="incident_time" id="incident_time" class="form-control" value="<?= $blotter['incident_time'] ?>">
                            </div>

                            <!-- Incident Type -->
                            <div class="col-md-6 mb-3">
                                <label for="incident_type" class="form-label">Incident Type <span class="text-danger">*</span></label>
                                <select name="incident_type" id="incident_type" class="form-select" required>
                                    <option value="">Select Type</option>
                                    <option value="Noise Complaint" <?= $blotter['incident_type'] == 'Noise Complaint' ? 'selected' : '' ?>>Noise Complaint</option>
                                    <option value="Physical Assault" <?= $blotter['incident_type'] == 'Physical Assault' ? 'selected' : '' ?>>Physical Assault</option>
                                    <option value="Verbal Abuse" <?= $blotter['incident_type'] == 'Verbal Abuse' ? 'selected' : '' ?>>Verbal Abuse</option>
                                    <option value="Theft" <?= $blotter['incident_type'] == 'Theft' ? 'selected' : '' ?>>Theft</option>
                                    <option value="Property Damage" <?= $blotter['incident_type'] == 'Property Damage' ? 'selected' : '' ?>>Property Damage</option>
                                    <option value="Boundary Dispute" <?= $blotter['incident_type'] == 'Boundary Dispute' ? 'selected' : '' ?>>Boundary Dispute</option>
                                    <option value="Domestic Violence" <?= $blotter['incident_type'] == 'Domestic Violence' ? 'selected' : '' ?>>Domestic Violence</option>
                                    <option value="Others" <?= $blotter['incident_type'] == 'Others' ? 'selected' : '' ?>>Others</option>
                                </select>
                            </div>

                            <!-- Location -->
                            <div class="col-md-6 mb-3">
                                <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                                <input type="text" name="location" id="location" class="form-control" placeholder="Enter incident location" value="<?= htmlspecialchars($blotter['location']) ?>" required>
                            </div>

                            <!-- Description -->
                            <div class="col-12 mb-3">
                                <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea name="description" id="description" class="form-control" rows="5" placeholder="Provide detailed description of the incident..." required><?= htmlspecialchars($blotter['description']) ?></textarea>
                            </div>

                            <!-- Status -->
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" id="status" class="form-select" required>
                                    <option value="Pending" <?= $blotter['status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Under Investigation" <?= $blotter['status'] == 'Under Investigation' ? 'selected' : '' ?>>Under Investigation</option>
                                    <option value="Resolved" <?= $blotter['status'] == 'Resolved' ? 'selected' : '' ?>>Resolved</option>
        
                                </select>
                            </div>

                            <!-- Remarks -->
                            <div class="col-md-6 mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Additional remarks (optional)"><?= htmlspecialchars($blotter['remarks']) ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div class="text-muted small">
                                <i class="fas fa-info-circle me-1"></i>
                                Last updated: <?= date('M d, Y h:i A', strtotime($blotter['updated_at'])) ?>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="manage-blotter.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Blotter Record
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
    if (selectedRespondent === complainantSelect.value) {
        alert('Complainant and Respondent cannot be the same person!');
        this.value = '';
    }
});

// Confirm before leaving with unsaved changes
let formChanged = false;
document.getElementById('blotterForm').addEventListener('change', function() {
    formChanged = true;
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.getElementById('blotterForm').addEventListener('submit', function() {
    formChanged = false;
});
</script>

<?php include '../../includes/footer.php'; ?>