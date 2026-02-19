<?php
require_once '../../../config/config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
$is_logged_in = false;
$current_user_id = 0;
$user_role = '';

if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $is_logged_in = true;
    $current_user_id = $_SESSION['user_id'];
    
    // Get role from database
    $stmt = $conn->prepare("SELECT role FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $user_role = $user_data['role'] ?? '';
}

// If not logged in, redirect
if (!$is_logged_in) {
    $_SESSION['error_message'] = "Please login to access this page";
    header("Location: /modules/auth/login.php");
    exit;
}

// Check role permissions
$allowed_roles = ['Super Admin', 'Admin', 'Staff'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error_message'] = "You don't have permission to access this page.";
    header("Location: /modules/auth/login.php");
    exit;
}

// Only Super Admin and Admin can approve
$can_approve = in_array($user_role, ['Super Admin', 'Admin']);

$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$permit_id) {
    $_SESSION['error_message'] = "Invalid permit ID";
    header("Location: /modules/business/admin/applications.php");
    exit;
}

// Get permit details
$stmt = $conn->prepare("
    SELECT bp.*, r.first_name, r.last_name, r.contact_number as resident_contact,
           r.email as resident_email, bt.type_name, bt.base_fee,
           u.user_id, u.email as user_email
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    LEFT JOIN tbl_users u ON r.resident_id = u.resident_id
    WHERE bp.permit_id = ?
");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();

if (!$permit) {
    $_SESSION['error_message'] = "Permit not found";
    header("Location: /modules/business/admin/applications.php");
    exit;
}

$page_title = "Process Permit Application";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve' && $can_approve) {
        $conn->begin_transaction();
        
        try {
            // Validate and calculate fees
            $permit_fee = isset($_POST['permit_fee']) ? (float)$_POST['permit_fee'] : 0;
            $sanitary_fee = isset($_POST['sanitary_fee']) ? (float)$_POST['sanitary_fee'] : 0;
            $garbage_fee = isset($_POST['garbage_fee']) ? (float)$_POST['garbage_fee'] : 0;
            
            if ($permit_fee < 0 || $sanitary_fee < 0 || $garbage_fee < 0) {
                throw new Exception("Fees cannot be negative");
            }
            
            $total_fee = $permit_fee + $sanitary_fee + $garbage_fee;
            
            // Set expiry date (1 year from approval)
            $issue_date = date('Y-m-d');
            $expiry_date = date('Y-m-d', strtotime('+1 year'));
            
            // Update permit - Check if reviewed_by column exists
            $check_column = $conn->query("SHOW COLUMNS FROM tbl_business_permits LIKE 'reviewed_by'");
            
            if ($check_column->num_rows > 0) {
                // Column exists, include it in update
                $stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = 'Approved',
                        permit_fee = ?,
                        sanitary_fee = ?,
                        garbage_fee = ?,
                        total_fee = ?,
                        issue_date = ?,
                        expiry_date = ?,
                        approved_by = ?,
                        approved_date = NOW(),
                        reviewed_by = ?,
                        reviewed_date = NOW()
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("ddddssiii", 
                    $permit_fee, $sanitary_fee, $garbage_fee, $total_fee,
                    $issue_date, $expiry_date, 
                    $current_user_id, $current_user_id, $permit_id
                );
            } else {
                // Column doesn't exist, exclude it from update
                $stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = 'Approved',
                        permit_fee = ?,
                        sanitary_fee = ?,
                        garbage_fee = ?,
                        total_fee = ?,
                        issue_date = ?,
                        expiry_date = ?,
                        approved_by = ?,
                        approved_date = NOW()
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("ddddssii", 
                    $permit_fee, $sanitary_fee, $garbage_fee, $total_fee,
                    $issue_date, $expiry_date, 
                    $current_user_id, $permit_id
                );
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update permit: " . $stmt->error);
            }
            
            // Log activity
            $stmt = $conn->prepare("
                INSERT INTO tbl_business_permit_history 
                (permit_id, action, old_status, new_status, remarks, action_by)
                VALUES (?, 'Approved', ?, 'Approved', ?, ?)
            ");
            $old_status = $permit['status'] ?? 'Pending';
            $remarks = "Application approved. Total fee: ₱" . number_format($total_fee, 2);
            $stmt->bind_param("issi", $permit_id, $old_status, $remarks, $current_user_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to log history: " . $stmt->error);
            }
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Permit application approved successfully!";
            header("Location: /modules/business/admin/applications.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error approving permit: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $permit_id);
            exit;
        }
        
    } elseif ($action === 'reject' && $can_approve) {
        $conn->begin_transaction();
        
        try {
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            
            if (empty($rejection_reason)) {
                throw new Exception("Rejection reason is required");
            }
            
            // Check if reviewed_by column exists
            $check_column = $conn->query("SHOW COLUMNS FROM tbl_business_permits LIKE 'reviewed_by'");
            
            if ($check_column->num_rows > 0) {
                // Column exists, include it in update
                $stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = 'Rejected',
                        rejection_reason = ?,
                        reviewed_by = ?,
                        reviewed_date = NOW()
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("sii", $rejection_reason, $current_user_id, $permit_id);
            } else {
                // Column doesn't exist, exclude it from update
                $stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = 'Rejected',
                        rejection_reason = ?
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("si", $rejection_reason, $permit_id);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update permit: " . $stmt->error);
            }
            
            // Log activity
            $stmt = $conn->prepare("
                INSERT INTO tbl_business_permit_history 
                (permit_id, action, old_status, new_status, remarks, action_by)
                VALUES (?, 'Rejected', ?, 'Rejected', ?, ?)
            ");
            $old_status = $permit['status'] ?? 'Pending';
            $stmt->bind_param("issi", $permit_id, $old_status, $rejection_reason, $current_user_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to log history: " . $stmt->error);
            }
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Permit application rejected";
            header("Location: /modules/business/admin/applications.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error rejecting permit: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $permit_id);
            exit;
        }
        
    } elseif ($action === 'review') {
        try {
            // All roles can mark for review
            // Check if reviewed_by column exists
            $check_column = $conn->query("SHOW COLUMNS FROM tbl_business_permits LIKE 'reviewed_by'");
            
            if ($check_column->num_rows > 0) {
                // Mark as under review
                $stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = 'For Review',
                        reviewed_by = ?,
                        reviewed_date = NOW()
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("ii", $current_user_id, $permit_id);
            } else {
                // Column doesn't exist, just update status
                $stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = 'For Review'
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("i", $permit_id);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update status: " . $stmt->error);
            }
            
            $_SESSION['success_message'] = "Permit marked as under review";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $permit_id);
        exit;
    }
}

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Process Permit Application</h1>
            <p class="text-muted">Review and approve/reject business permit application</p>
        </div>
        <a href="applications.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Applications
        </a>
    </div>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php 
            echo htmlspecialchars($_SESSION['error_message']); 
            unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column - Permit Details -->
        <div class="col-lg-8">
            <!-- Business Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-building me-2"></i>Business Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Permit Number</label>
                            <p class="mb-0"><strong><?php echo htmlspecialchars($permit['permit_number'] ?? 'Pending'); ?></strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Application Date</label>
                            <p class="mb-0"><?php echo date('F d, Y', strtotime($permit['application_date'])); ?></p>
                        </div>
                        <div class="col-md-12">
                            <label class="text-muted small">Business Name</label>
                            <p class="mb-0"><strong><?php echo htmlspecialchars($permit['business_name'] ?? 'N/A'); ?></strong></p>
                        </div>
                        <?php if (!empty($permit['trade_name'])): ?>
                        <div class="col-md-12">
                            <label class="text-muted small">Trade Name / DBA</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['trade_name']); ?></p>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="text-muted small">Business Type</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['type_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Nature of Business</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['nature_of_business'] ?? 'Not specified'); ?></p>
                        </div>
                        <div class="col-md-12">
                            <label class="text-muted small">Business Address</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['business_address'] ?? 'Not specified'); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Capital Investment</label>
                            <p class="mb-0">₱<?php echo number_format($permit['capital_investment'] ?? 0, 2); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Number of Employees</label>
                            <p class="mb-0"><?php echo $permit['number_of_employees'] ?? 0; ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Floor Area</label>
                            <p class="mb-0"><?php echo $permit['floor_area'] ?? 0; ?> sq.m</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Owner Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-user me-2"></i>Owner Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Owner Name</label>
                            <p class="mb-0"><strong><?php echo htmlspecialchars($permit['owner_name'] ?? 'Not specified'); ?></strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Resident Name</label>
                            <p class="mb-0"><?php echo htmlspecialchars(($permit['first_name'] ?? '') . ' ' . ($permit['last_name'] ?? '')); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Contact Number</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['contact_number'] ?? $permit['resident_contact'] ?? 'Not provided'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Email Address</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['email'] ?? $permit['resident_email'] ?? 'Not provided'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">TIN</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['tax_identification_number'] ?? 'Not provided'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">DTI Registration Number</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['dti_registration_number'] ?? 'Not provided'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submitted Documents -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-warning">
                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Submitted Documents</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php
                        $documents = [
                            'dti_certificate' => 'DTI Business Registration',
                            'bir_certificate' => 'BIR Certificate of Registration',
                            'barangay_clearance' => 'Barangay Clearance',
                            'cedula' => 'Community Tax Certificate (Cedula)'
                        ];
                        
                        foreach ($documents as $key => $label):
                            $file = $permit[$key] ?? null;
                        ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-file-pdf text-danger me-2"></i>
                                <?php echo $label; ?>
                            </div>
                            <?php if ($file): ?>
                                <a href="<?php echo UPLOAD_URL; ?>business/<?php echo $file; ?>" 
                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            <?php else: ?>
                                <span class="badge bg-secondary">Not Submitted</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Action Panel -->
        <div class="col-lg-4">
            <!-- Status Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body text-center">
                    <?php
                    $status_badges = [
                        'Pending' => 'warning',
                        'For Review' => 'info',
                        'Approved' => 'success',
                        'Rejected' => 'danger'
                    ];
                    $current_status = $permit['status'] ?? 'Pending';
                    $badge_class = $status_badges[$current_status] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?php echo $badge_class; ?> fs-5 px-4 py-2">
                        <?php echo htmlspecialchars($current_status); ?>
                    </span>
                </div>
            </div>

            <?php if ($can_approve && in_array($permit['status'] ?? '', ['Pending', 'For Review'])): ?>
                <!-- Approval Form -->
                <div class="card border-0 shadow-sm mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Approve Application</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="approveForm" onsubmit="return confirm('Are you sure you want to approve this application?');">
                            <input type="hidden" name="action" value="approve">
                            
                            <div class="mb-3">
                                <label class="form-label">Base Fee (<?php echo htmlspecialchars($permit['type_name'] ?? 'N/A'); ?>)</label>
                                <input type="number" name="permit_fee" class="form-control" 
                                       value="<?php echo $permit['base_fee'] ?? 0; ?>" 
                                       step="0.01" min="0" required id="permitFee">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Sanitary Fee</label>
                                <input type="number" name="sanitary_fee" class="form-control" 
                                       value="500.00" step="0.01" min="0" required id="sanitaryFee">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Garbage Fee</label>
                                <input type="number" name="garbage_fee" class="form-control" 
                                       value="300.00" step="0.01" min="0" required id="garbageFee">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><strong>Total Fee</strong></label>
                                <input type="text" class="form-control bg-light" id="totalFee" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Permit will be valid for 1 year from approval date
                                </small>
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check me-2"></i>Approve Application
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Rejection Form -->
                <div class="card border-0 shadow-sm mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-times-circle me-2"></i>Reject Application</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="rejectForm" onsubmit="return confirm('Are you sure you want to reject this application?');">
                            <input type="hidden" name="action" value="reject">
                            
                            <div class="mb-3">
                                <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                                <textarea name="rejection_reason" class="form-control" rows="4" 
                                          required placeholder="Enter detailed reason..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-times me-2"></i>Reject Application
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (($permit['status'] ?? '') === 'Pending'): ?>
                <!-- Mark for Review -->
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="review">
                            <button type="submit" class="btn btn-outline-info w-100">
                                <i class="fas fa-eye me-2"></i>Mark as Under Review
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
            <!-- Already Processed or No Permission -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <?php if (!$can_approve): ?>
                        <p class="text-center text-muted">
                            You don't have permission to approve/reject permits.
                        </p>
                    <?php else: ?>
                        <p class="text-center text-muted">
                            This application has been <?php echo strtolower($permit['status'] ?? 'processed'); ?>.
                        </p>
                        <?php if (($permit['status'] ?? '') === 'Approved'): ?>
                            <a href="print-permit.php?id=<?php echo $permit_id; ?>" 
                               class="btn btn-primary w-100" target="_blank">
                                <i class="fas fa-print me-2"></i>Print Permit
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Calculate total fee
function calculateTotal() {
    const permitFee = parseFloat(document.getElementById('permitFee')?.value) || 0;
    const sanitaryFee = parseFloat(document.getElementById('sanitaryFee')?.value) || 0;
    const garbageFee = parseFloat(document.getElementById('garbageFee')?.value) || 0;
    const total = permitFee + sanitaryFee + garbageFee;
    
    const totalFeeElement = document.getElementById('totalFee');
    if (totalFeeElement) {
        totalFeeElement.value = '₱' + total.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
}

// Calculate on load and on change
document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();
    
    // Add event listeners
    const permitFeeInput = document.getElementById('permitFee');
    const sanitaryFeeInput = document.getElementById('sanitaryFee');
    const garbageFeeInput = document.getElementById('garbageFee');
    
    if (permitFeeInput) permitFeeInput.addEventListener('input', calculateTotal);
    if (sanitaryFeeInput) sanitaryFeeInput.addEventListener('input', calculateTotal);
    if (garbageFeeInput) garbageFeeInput.addEventListener('input', calculateTotal);
});
</script>

<?php include_once '../../../includes/footer.php'; ?>