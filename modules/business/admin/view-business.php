<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Details";
$current_user_id = getCurrentUserId();

// Get permit ID
$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($permit_id === 0) {
    $_SESSION['error_message'] = "Invalid business permit ID.";
    redirect('registry.php');
}

// Debug: Show table structures (comment out after checking)
/*
echo "<pre>";
echo "=== TBL_BUSINESS_PERMITS ===\n";
$result = $conn->query("DESCRIBE tbl_business_permits");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\n=== TBL_USERS ===\n";
$result = $conn->query("DESCRIBE tbl_users");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\n=== TBL_RESIDENTS ===\n";
$result = $conn->query("DESCRIBE tbl_residents");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\n=== TBL_BUSINESS_TYPES ===\n";
$result = $conn->query("DESCRIBE tbl_business_types");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}
echo "</pre>";
exit;
*/

// Get business permit details
$sql = "
    SELECT 
        bp.*,
        bt.type_name,
        bt.description as type_description,
        r.resident_id,
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.contact_number as resident_contact,
        r.email as resident_email,
        r.address as resident_address,
        u.username as approved_by_name,
        CASE 
            WHEN bp.expiry_date < CURDATE() THEN 'expired'
            WHEN bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
            ELSE 'active'
        END as permit_status,
        DATEDIFF(bp.expiry_date, CURDATE()) as days_until_expiry
    FROM tbl_business_permits bp
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_users u ON bp.approved_by = u.user_id
    WHERE bp.permit_id = ? AND bp.status = 'Approved'
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$business = $stmt->get_result()->fetch_assoc();

if (!$business) {
    $_SESSION['error_message'] = "Business permit not found.";
    redirect('registry.php');
}

// Get permit history
$history_sql = "
    SELECT 
        h.*,
        u.username as performed_by
    FROM tbl_business_permit_history h
    LEFT JOIN tbl_users u ON h.action_by = u.user_id
    WHERE h.permit_id = ?
    ORDER BY h.history_id DESC
";
$history_stmt = $conn->prepare($history_sql);
$history_stmt->bind_param("i", $permit_id);
$history_stmt->execute();
$history = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get related documents/attachments if any (table doesn't exist yet)
$documents = [];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_permit':
                $business_name = trim($_POST['business_name']);
                $business_address = trim($_POST['business_address']);
                $contact_number = trim($_POST['contact_number']);
                $business_type_id = (int)$_POST['business_type_id'];
                $expiry_date = $_POST['expiry_date'];
                
                $update_sql = "
                    UPDATE tbl_business_permits 
                    SET business_name = ?, 
                        business_address = ?, 
                        contact_number = ?,
                        business_type_id = ?,
                        expiry_date = ?
                    WHERE permit_id = ?
                ";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sssisi", $business_name, $business_address, $contact_number, 
                                         $business_type_id, $expiry_date, $permit_id);
                
                if ($update_stmt->execute()) {
                    // Log action
                    $log_stmt = $conn->prepare("
                        INSERT INTO tbl_business_permit_history (permit_id, action, notes, action_by)
                        VALUES (?, 'Permit Updated', 'Business information updated', ?)
                    ");
                    $log_stmt->bind_param("ii", $permit_id, $current_user_id);
                    $log_stmt->execute();
                    
                    $_SESSION['success_message'] = "Business permit updated successfully.";
                    redirect($_SERVER['PHP_SELF'] . '?id=' . $permit_id);
                } else {
                    $_SESSION['error_message'] = "Error updating permit.";
                }
                break;
                
            case 'add_note':
                $note = trim($_POST['note']);
                if (!empty($note)) {
                    $note_stmt = $conn->prepare("
                        INSERT INTO tbl_business_permit_history (permit_id, action, notes, action_by)
                        VALUES (?, 'Note Added', ?, ?)
                    ");
                    $note_stmt->bind_param("isi", $permit_id, $note, $current_user_id);
                    
                    if ($note_stmt->execute()) {
                        $_SESSION['success_message'] = "Note added successfully.";
                        redirect($_SERVER['PHP_SELF'] . '?id=' . $permit_id);
                    }
                }
                break;
                
            case 'extend_permit':
                $new_expiry = $_POST['new_expiry_date'];
                $reason = trim($_POST['extension_reason']);
                
                $extend_stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET expiry_date = ?
                    WHERE permit_id = ?
                ");
                $extend_stmt->bind_param("si", $new_expiry, $permit_id);
                
                if ($extend_stmt->execute()) {
                    // Log action
                    $log_stmt = $conn->prepare("
                        INSERT INTO tbl_business_permit_history (permit_id, action, notes, action_by)
                        VALUES (?, 'Permit Extended', ?, ?)
                    ");
                    $log_stmt->bind_param("isi", $permit_id, $reason, $current_user_id);
                    $log_stmt->execute();
                    
                    $_SESSION['success_message'] = "Permit expiry date extended successfully.";
                    redirect($_SERVER['PHP_SELF'] . '?id=' . $permit_id);
                }
                break;
        }
    }
}

// Get business types for dropdown
$types_result = $conn->query("SELECT * FROM tbl_business_types ORDER BY type_name");
$business_types = $types_result->fetch_all(MYSQLI_ASSOC);

include_once '../../../includes/header.php';
?>

<style>
.detail-card {
    border-left: 4px solid #0d6efd;
}
.info-row {
    padding: 0.75rem 0;
    border-bottom: 1px solid #e9ecef;
}
.info-row:last-child {
    border-bottom: none;
}
.info-label {
    font-weight: 600;
    color: #6c757d;
    font-size: 0.875rem;
}
.info-value {
    color: #212529;
}
.timeline {
    position: relative;
    padding-left: 30px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}
.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}
.timeline-item::before {
    content: '';
    position: absolute;
    left: -26px;
    top: 0;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    border: 3px solid #0d6efd;
}
.profile-image {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 10px;
}
.status-badge {
    font-size: 0.875rem;
    padding: 0.375rem 1rem;
    border-radius: 50px;
}
</style>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="registry.php">Business Registry</a></li>
                    <li class="breadcrumb-item active">Business Details</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0"><?php echo htmlspecialchars($business['business_name']); ?></h1>
        </div>
        <div>
            <a href="registry.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Registry
            </a>
            <button class="btn btn-success" onclick="printCertificate()">
                <i class="fas fa-print me-2"></i>Print Certificate
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Business Information -->
            <div class="card detail-card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-store text-primary me-2"></i>Business Information
                    </h5>
                    <div>
                        <?php if ($business['permit_status'] === 'active'): ?>
                            <span class="status-badge bg-success text-white">
                                <i class="fas fa-check-circle me-1"></i>Active
                            </span>
                        <?php elseif ($business['permit_status'] === 'expiring'): ?>
                            <span class="status-badge bg-warning text-dark">
                                <i class="fas fa-clock me-1"></i>Expiring in <?php echo $business['days_until_expiry']; ?> days
                            </span>
                        <?php else: ?>
                            <span class="status-badge bg-danger text-white">
                                <i class="fas fa-times-circle me-1"></i>Expired
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Permit Number</div>
                                <div class="info-value">
                                    <i class="fas fa-certificate text-primary me-2"></i>
                                    <strong><?php echo htmlspecialchars($business['permit_number']); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Business Type</div>
                                <div class="info-value">
                                    <i class="fas fa-briefcase text-info me-2"></i>
                                    <?php echo htmlspecialchars($business['type_name']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Owner Name</div>
                                <div class="info-value">
                                    <i class="fas fa-user text-secondary me-2"></i>
                                    <?php echo htmlspecialchars($business['owner_name']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Contact Number</div>
                                <div class="info-value">
                                    <i class="fas fa-phone text-success me-2"></i>
                                    <?php echo htmlspecialchars($business['contact_number'] ?? $business['resident_contact'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="info-row">
                                <div class="info-label">Business Address</div>
                                <div class="info-value">
                                    <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                    <?php echo htmlspecialchars($business['business_address']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">Issue Date</div>
                                <div class="info-value">
                                    <i class="fas fa-calendar-plus text-success me-2"></i>
                                    <?php echo date('F d, Y', strtotime($business['issue_date'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">Expiry Date</div>
                                <div class="info-value">
                                    <i class="fas fa-calendar-times text-danger me-2"></i>
                                    <?php echo date('F d, Y', strtotime($business['expiry_date'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">Approved By</div>
                                <div class="info-value">
                                    <i class="fas fa-user-check text-primary me-2"></i>
                                    <?php echo htmlspecialchars($business['approved_by_name'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($business['is_renewal'])): ?>
                            <div class="col-12">
                                <div class="info-row">
                                    <div class="info-label">Renewal Information</div>
                                    <div class="info-value">
                                        <span class="badge bg-info">
                                            <i class="fas fa-sync-alt me-1"></i>
                                            This is a renewal permit 
                                            <?php echo !empty($business['renewal_count']) ? '(Renewal #' . $business['renewal_count'] . ')' : ''; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($business['notes'])): ?>
                            <div class="col-12">
                                <div class="info-row">
                                    <div class="info-label">Notes</div>
                                    <div class="info-value">
                                        <i class="fas fa-sticky-note text-warning me-2"></i>
                                        <?php echo nl2br(htmlspecialchars($business['notes'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-3 pt-3 border-top">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal">
                            <i class="fas fa-edit me-2"></i>Edit Information
                        </button>
                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#extendModal">
                            <i class="fas fa-calendar-plus me-2"></i>Extend Permit
                        </button>
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#noteModal">
                            <i class="fas fa-comment-medical me-2"></i>Add Note
                        </button>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <?php if (!empty($documents)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-file-alt text-primary me-2"></i>Documents
                    </h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($documents as $doc): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-file-pdf text-danger me-2"></i>
                                        <strong><?php echo htmlspecialchars($doc['document_name']); ?></strong>
                                    </div>
                                    <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                        <i class="fas fa-download me-1"></i>Download
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- History Timeline -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-history text-primary me-2"></i>Permit History
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($history)): ?>
                        <p class="text-muted text-center py-3 mb-0">No history available.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($history as $item): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($item['action']); ?></h6>
                                            <?php if (!empty($item['notes'])): ?>
                                                <p class="text-muted small mb-1">
                                                    <?php echo nl2br(htmlspecialchars($item['notes'])); ?>
                                                </p>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo htmlspecialchars($item['performed_by'] ?? 'System'); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-lg-4">
            <!-- Owner Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-user-tie text-primary me-2"></i>Owner Information
                    </h5>
                </div>
                <div class="card-body text-center">
                    <div class="profile-image bg-light d-flex align-items-center justify-content-center mb-3 mx-auto">
                        <i class="fas fa-user fa-3x text-muted"></i>
                    </div>
                    
                    <h5 class="mb-2"><?php echo htmlspecialchars($business['resident_name']); ?></h5>
                    <p class="text-muted small mb-3">Business Owner</p>
                    
                    <div class="text-start mt-3">
                        <div class="info-row">
                            <div class="info-label">Contact Number</div>
                            <div class="info-value">
                                <i class="fas fa-phone text-success me-2"></i>
                                <?php echo htmlspecialchars($business['resident_contact'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Email Address</div>
                            <div class="info-value">
                                <i class="fas fa-envelope text-info me-2"></i>
                                <?php echo htmlspecialchars($business['resident_email'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Residential Address</div>
                            <div class="info-value">
                                <i class="fas fa-home text-danger me-2"></i>
                                <?php echo htmlspecialchars($business['resident_address'] ?? 'N/A'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3 d-grid">
                        <a href="../../residents/view.php?id=<?php echo $business['resident_id']; ?>" 
                           class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-user-circle me-2"></i>View Full Profile
                        </a>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt text-primary me-2"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button class="btn btn-success" onclick="printCertificate()">
                            <i class="fas fa-print me-2"></i>Print Certificate
                        </button>
                        
                        <?php if ($business['permit_status'] === 'expiring' || $business['permit_status'] === 'expired'): ?>
                            <button class="btn btn-warning" onclick="sendReminder()">
                                <i class="fas fa-bell me-2"></i>Send Expiry Reminder
                            </button>
                        <?php endif; ?>
                        
                        <a href="renew-permit.php?id=<?php echo $permit_id; ?>" class="btn btn-info">
                            <i class="fas fa-sync-alt me-2"></i>Process Renewal
                        </a>
                        
                        <button class="btn btn-outline-secondary" onclick="generateReport()">
                            <i class="fas fa-file-alt me-2"></i>Generate Report
                        </button>
                        
                        <hr class="my-2">
                        
                        <button class="btn btn-danger" onclick="revokePermit()">
                            <i class="fas fa-ban me-2"></i>Revoke Permit
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Business Information
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_permit">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Business Name <span class="text-danger">*</span></label>
                            <input type="text" name="business_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($business['business_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business Type <span class="text-danger">*</span></label>
                            <select name="business_type_id" class="form-select" required>
                                <?php foreach ($business_types as $type): ?>
                                    <option value="<?php echo $type['type_id']; ?>" 
                                            <?php echo $type['type_id'] == $business['business_type_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Business Address <span class="text-danger">*</span></label>
                            <input type="text" name="business_address" class="form-control" 
                                   value="<?php echo htmlspecialchars($business['business_address']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" name="contact_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($business['contact_number'] ?? $business['resident_contact'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" 
                                   value="<?php echo $business['expiry_date']; ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Extend Permit Modal -->
<div class="modal fade" id="extendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus me-2"></i>Extend Permit
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="extend_permit">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Current expiry date: <strong><?php echo date('F d, Y', strtotime($business['expiry_date'])); ?></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Expiry Date <span class="text-danger">*</span></label>
                        <input type="date" name="new_expiry_date" class="form-control" 
                               min="<?php echo date('Y-m-d', strtotime($business['expiry_date'] . ' +1 day')); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Extension <span class="text-danger">*</span></label>
                        <textarea name="extension_reason" class="form-control" rows="3" required 
                                  placeholder="Enter reason for extending the permit..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-calendar-plus me-2"></i>Extend Permit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-comment-medical me-2"></i>Add Note
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_note">
                    
                    <div class="mb-3">
                        <label class="form-label">Note <span class="text-danger">*</span></label>
                        <textarea name="note" class="form-control" rows="4" required 
                                  placeholder="Enter your note about this business permit..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-save me-2"></i>Add Note
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function printCertificate() {
    window.open('print-certificate.php?id=<?php echo $permit_id; ?>', '_blank');
}

function sendReminder() {
    if (confirm('Send expiry reminder to business owner?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'registry.php';
        form.innerHTML = `
            <input type="hidden" name="action" value="send_reminder">
            <input type="hidden" name="permit_id" value="<?php echo $permit_id; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function revokePermit() {
    if (confirm('Are you sure you want to revoke this business permit? This action cannot be undone.')) {
        window.location.href = 'registry.php?revoke=<?php echo $permit_id; ?>';
    }
}

function generateReport() {
    window.open('generate-business-report.php?id=<?php echo $permit_id; ?>', '_blank');
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

<?php include_once '../../../includes/footer.php'; ?>