<?php
/**
 * View Blotter Record Page
 * Path: modules/blotter/view-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

// FIX: Get resident_id directly from DB to avoid session mismatch
$current_user_id = getCurrentUserId();
$resident_id = getCurrentResidentId(); // fallback

if ($user_role === 'Resident') {
    $res_stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $res_stmt->bind_param("i", $current_user_id);
    $res_stmt->execute();
    $res_row = $res_stmt->get_result()->fetch_assoc();
    $res_stmt->close();
    if (!empty($res_row['resident_id'])) {
        $resident_id = $res_row['resident_id'];
    }
}

$page_title = 'View Blotter Record';
$error_message = '';

// Get blotter ID from URL
$blotter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($blotter_id <= 0) {
    $_SESSION['error_message'] = 'Invalid blotter record ID.';
    if ($user_role === 'Resident') {
        header('Location: my-blotter.php');
    } else {
        header('Location: manage-blotter.php');
    }
    exit();
}

// Get blotter record
$sql = "SELECT b.*, 
        CONCAT(c.first_name, ' ', c.last_name) as complainant_name,
        c.contact_number as complainant_contact,
        c.address as complainant_address,
        CONCAT(r.first_name, ' ', COALESCE(r.last_name, '')) as respondent_resident_name,
        r.contact_number as respondent_contact,
        r.address as respondent_address,
        b.respondent_name as respondent_manual_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents c ON b.complainant_id = c.resident_id
        LEFT JOIN tbl_residents r ON b.respondent_id = r.resident_id
        WHERE b.blotter_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $blotter_id);
$stmt->execute();
$result = $stmt->get_result();
$blotter = $result->fetch_assoc();
$stmt->close();

if (!$blotter) {
    $_SESSION['error_message'] = 'Blotter record not found.';
    if ($user_role === 'Resident') {
        header('Location: my-blotter.php');
    } else {
        header('Location: manage-blotter.php');
    }
    exit();
}

// Determine display name for respondent
$respondent_display_name = !empty($blotter['respondent_resident_name']) 
    ? trim($blotter['respondent_resident_name']) 
    : ($blotter['respondent_manual_name'] ?? 'N/A');

// Permission check for residents
if ($user_role === 'Resident') {
    // Verify resident account
    $verify_sql = "SELECT is_verified FROM tbl_residents WHERE resident_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("i", $resident_id);
    $verify_stmt->execute();
    $verify_data = $verify_stmt->get_result()->fetch_assoc();
    $verify_stmt->close();

    if (!$verify_data || $verify_data['is_verified'] != 1) {
        $_SESSION['error_message'] = 'Your account must be verified to view blotter records.';
        header('Location: not-verified-blotter.php');
        exit();
    }

    // Check if resident is involved in this case
    if ($blotter['complainant_id'] != $resident_id && $blotter['respondent_id'] != $resident_id) {
        $_SESSION['error_message'] = 'You do not have permission to view this blotter record.';
        header('Location: my-blotter.php');
        exit();
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
    font-weight: 700;
}

.card-header.bg-primary {
    background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%) !important;
    border-bottom-color: #0a58ca;
}

.card-header.bg-info {
    background: linear-gradient(135deg, #0dcaf0 0%, #0aa2c0 100%) !important;
    border-bottom-color: #0995b3;
}

.card-header.bg-success {
    background: linear-gradient(135deg, #198754 0%, #157347 100%) !important;
    border-bottom-color: #146c43;
}

.card-header.bg-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #5c636a 100%) !important;
    border-bottom-color: #565e64;
}

.card-header.bg-dark {
    background: linear-gradient(135deg, #212529 0%, #1a1d20 100%) !important;
    border-bottom-color: #16181b;
}

.card-body {
    padding: 1.75rem;
}

.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.badge.fs-5 {
    font-size: 1rem !important;
    padding: 0.65rem 1.5rem;
}

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

.btn:active { transform: translateY(0); }

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.border.rounded {
    border-radius: var(--border-radius) !important;
    transition: all var(--transition-speed) ease;
}

.border.rounded.p-3.h-100 {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border: 2px solid #e9ecef !important;
}

.border.rounded.p-3.h-100:hover {
    border-color: #dee2e6 !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transform: translateY(-2px);
}

.border.rounded.p-3.bg-light {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
    border: 1px solid #e9ecef !important;
    font-size: 0.95rem;
    line-height: 1.6;
}

label.text-muted.small {
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.75rem;
    color: #6c757d !important;
    margin-bottom: 0.5rem;
    display: block;
}

.fw-bold { color: #212529; }

.modal-content {
    border-radius: var(--border-radius);
    border: none;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    border-bottom: 2px solid #e9ecef;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
}

.modal-header.bg-primary {
    background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%) !important;
}

.modal-footer { border-top: 2px solid #e9ecef; }

.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1rem 1.25rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-info {
    background: linear-gradient(135deg, #d1ecf1 0%, #e7f5f7 100%);
    border-left-color: #0dcaf0;
}

.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #fff9e5 100%);
    border-left-color: #ffc107;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 2px solid #e9ecef;
    padding: 0.625rem 1rem;
    transition: all var(--transition-speed) ease;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1);
}

h6.text-primary, h6.text-danger {
    font-weight: 700;
    font-size: 1rem;
    border-bottom: 2px solid currentColor;
    padding-bottom: 0.5rem;
}

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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-file-alt me-2 text-primary"></i>
                        Blotter Record Details
                    </h2>
                    <p class="text-muted mb-0">
                        Case Number: <strong class="text-primary"><?= htmlspecialchars($blotter['case_number'] ?? '#' . str_pad($blotter['blotter_id'], 5, '0', STR_PAD_LEFT)) ?></strong>
                    </p>
                </div>
                <div>
                    <?php if ($user_role !== 'Resident'): ?>
                    <a href="edit-blotter.php?id=<?= $blotter_id ?>" class="btn btn-warning me-2">
                        <i class="fas fa-edit me-2"></i>Edit
                    </a>
                    <?php endif; ?>
                    <a href="<?= $user_role === 'Resident' ? 'my-blotter.php' : 'manage-blotter.php' ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error_message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="row">
        <!-- Main Information -->
        <div class="col-lg-8">
            <!-- Incident Information Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Incident Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Incident Date</label>
                            <p class="fw-bold mb-0">
                                <i class="fas fa-calendar-alt me-2 text-primary"></i>
                                <?= date('F d, Y', strtotime($blotter['incident_date'])) ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Incident Time</label>
                            <p class="fw-bold mb-0">
                                <i class="fas fa-clock me-2 text-primary"></i>
                                <?= !empty($blotter['incident_time']) ? date('h:i A', strtotime($blotter['incident_time'])) : 'Not specified' ?>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Incident Type</label>
                            <p class="mb-0">
                                <span class="badge bg-info">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    <?= htmlspecialchars($blotter['incident_type']) ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Location</label>
                            <p class="fw-bold mb-0">
                                <i class="fas fa-map-marker-alt me-2 text-danger"></i>
                                <?= htmlspecialchars($blotter['location']) ?>
                            </p>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Description</label>
                        <div class="border rounded p-3 bg-light">
                            <?= nl2br(htmlspecialchars($blotter['description'])) ?>
                        </div>
                    </div>

                    <?php if (!empty($blotter['remarks'])): ?>
                    <div class="mb-0">
                        <label class="text-muted small">Remarks / Additional Notes</label>
                        <div class="border rounded p-3 bg-light">
                            <?= nl2br(htmlspecialchars($blotter['remarks'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Parties Involved Card -->
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Parties Involved</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Complainant -->
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="border rounded p-3 h-100">
                                <h6 class="text-primary mb-3">
                                    <i class="fas fa-user me-2"></i>Complainant
                                    <?php if ($user_role === 'Resident' && $resident_id == $blotter['complainant_id']): ?>
                                        <span class="badge bg-primary ms-2">You</span>
                                    <?php endif; ?>
                                </h6>
                                <p class="mb-2">
                                    <strong><i class="fas fa-id-card me-2 text-muted"></i>Name:</strong><br>
                                    <span class="ms-4"><?= htmlspecialchars($blotter['complainant_name'] ?? 'N/A') ?></span>
                                </p>
                                <?php if ($user_role !== 'Resident' || $resident_id == $blotter['complainant_id']): ?>
                                <p class="mb-2">
                                    <strong><i class="fas fa-phone me-2 text-muted"></i>Contact:</strong><br>
                                    <span class="ms-4"><?= htmlspecialchars($blotter['complainant_contact'] ?? 'N/A') ?></span>
                                </p>
                                <p class="mb-0">
                                    <strong><i class="fas fa-home me-2 text-muted"></i>Address:</strong><br>
                                    <span class="ms-4"><?= htmlspecialchars($blotter['complainant_address'] ?? 'N/A') ?></span>
                                </p>
                                <?php else: ?>
                                <div class="alert alert-warning mb-0 mt-2 py-2 px-3">
                                    <small><i class="fas fa-lock me-1"></i>Contact details hidden for privacy</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Respondent -->
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <h6 class="text-danger mb-3">
                                    <i class="fas fa-user-shield me-2"></i>Respondent
                                    <?php if ($user_role === 'Resident' && $resident_id == $blotter['respondent_id']): ?>
                                        <span class="badge bg-danger ms-2">You</span>
                                    <?php endif; ?>
                                </h6>
                                <p class="mb-2">
                                    <strong><i class="fas fa-id-card me-2 text-muted"></i>Name:</strong><br>
                                    <span class="ms-4"><?= htmlspecialchars($respondent_display_name) ?></span>
                                </p>
                                <?php if ($user_role !== 'Resident' || $resident_id == $blotter['respondent_id']): ?>
                                    <?php if (!empty($blotter['respondent_contact'])): ?>
                                    <p class="mb-2">
                                        <strong><i class="fas fa-phone me-2 text-muted"></i>Contact:</strong><br>
                                        <span class="ms-4"><?= htmlspecialchars($blotter['respondent_contact']) ?></span>
                                    </p>
                                    <?php endif; ?>
                                    <?php if (!empty($blotter['respondent_address'])): ?>
                                    <p class="mb-0">
                                        <strong><i class="fas fa-home me-2 text-muted"></i>Address:</strong><br>
                                        <span class="ms-4"><?= htmlspecialchars($blotter['respondent_address']) ?></span>
                                    </p>
                                    <?php endif; ?>
                                    <?php if (empty($blotter['respondent_contact']) && empty($blotter['respondent_address'])): ?>
                                    <div class="alert alert-info mb-0 mt-2 py-2 px-3">
                                        <small><i class="fas fa-info-circle me-1"></i>No additional contact details on file</small>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                <div class="alert alert-warning mb-0 mt-2 py-2 px-3">
                                    <small><i class="fas fa-lock me-1"></i>Contact details hidden for privacy</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4 mt-4 mt-lg-0">
            <!-- Status Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-flag me-2"></i>Case Status</h5>
                </div>
                <div class="card-body text-center">
                    <div class="mb-3">
                        <?= getStatusBadge($blotter['status']) ?>
                    </div>
                    <?php if ($user_role !== 'Resident'): ?>
                    <button type="button" class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#statusModal">
                        <i class="fas fa-sync-alt me-1"></i>Update Status
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Case Information -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Case Details</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small">Case Number</label>
                        <p class="mb-0 fw-bold">
                            <i class="fas fa-hashtag me-2 text-primary"></i>
                            <?= htmlspecialchars($blotter['case_number'] ?? '#' . str_pad($blotter['blotter_id'], 5, '0', STR_PAD_LEFT)) ?>
                        </p>
                    </div>
                    <div class="mb-0">
                        <label class="text-muted small">Your Role in This Case</label>
                        <p class="mb-0">
                            <?php if ($user_role === 'Resident'): ?>
                                <?php if ($resident_id == $blotter['complainant_id']): ?>
                                    <span class="badge bg-info">
                                        <i class="fas fa-user me-1"></i>Complainant
                                    </span>
                                <?php elseif ($resident_id == $blotter['respondent_id']): ?>
                                    <span class="badge bg-danger">
                                        <i class="fas fa-user-shield me-1"></i>Respondent
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-user-tie me-1"></i>Administrator
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Timeline Card -->
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Timeline</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-3 border-bottom">
                        <label class="text-muted small">Date Filed</label>
                        <p class="mb-1 fw-bold">
                            <i class="fas fa-calendar-plus me-2 text-success"></i>
                            <?= date('M d, Y', strtotime($blotter['created_at'])) ?>
                        </p>
                        <p class="mb-0 text-muted small ms-4">
                            <?= date('h:i A', strtotime($blotter['created_at'])) ?>
                        </p>
                    </div>
                    <div>
                        <label class="text-muted small">Last Updated</label>
                        <p class="mb-1 fw-bold">
                            <i class="fas fa-sync-alt me-2 text-info"></i>
                            <?= date('M d, Y', strtotime($blotter['updated_at'])) ?>
                        </p>
                        <p class="mb-0 text-muted small ms-4">
                            <?= date('h:i A', strtotime($blotter['updated_at'])) ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($user_role !== 'Resident'): ?>
<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="statusModalLabel">
                    <i class="fas fa-sync-alt me-2"></i>Update Case Status
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage-blotter.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="blotter_id" value="<?= $blotter_id ?>">

                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Current Status:</strong> <?= htmlspecialchars($blotter['status']) ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            New Status <span class="text-danger">*</span>
                        </label>
                        <select name="status" class="form-select" required>
                            <option value="">-- Select Status --</option>
                            <option value="Pending" <?= $blotter['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Under Investigation" <?= $blotter['status'] === 'Under Investigation' ? 'selected' : '' ?>>Under Investigation</option>
                            <option value="Resolved" <?= $blotter['status'] === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="Closed" <?= $blotter['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks (Optional)</label>
                        <textarea name="status_remarks" class="form-control" rows="3"
                            placeholder="Add any notes about this status update..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>