<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$permit_id) {
    $_SESSION['error_message'] = "Invalid permit ID";
    header('Location: applications.php');
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';
    $current_user_id = getCurrentUserId();
    
    $conn->begin_transaction();
    
    try {
        $new_status = '';
        $action_description = '';
        
        switch ($action) {
            case 'approve':
                $new_status = 'Approved';
                $action_description = 'Application Approved';
                
                // Calculate fees
                $permit_fee = isset($_POST['permit_fee']) ? (float)$_POST['permit_fee'] : 0;
                $sanitary_fee = isset($_POST['sanitary_fee']) ? (float)$_POST['sanitary_fee'] : 0;
                $garbage_fee = isset($_POST['garbage_fee']) ? (float)$_POST['garbage_fee'] : 0;
                $total_fee = $permit_fee + $sanitary_fee + $garbage_fee;
                
                // Set issue date and expiry date (1 year from issue)
                $issue_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime('+1 year'));
                
                $update_stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = ?, 
                        approved_by = ?,
                        approval_date = NOW(),
                        issue_date = ?,
                        expiry_date = ?,
                        permit_fee = ?,
                        amount_paid = 0.00,
                        payment_status = 'unpaid',
                        remarks = ?
                    WHERE permit_id = ?
                ");
                $update_stmt->bind_param("sissdsi", $new_status, $current_user_id, $issue_date, $expiry_date, $permit_fee, $remarks, $permit_id);
                
                // Update remarks to include fee info
                if (!empty($remarks)) {
                    $remarks .= " | ";
                }
                $remarks .= "Total Fee: ₱" . number_format($total_fee, 2);
                break;
                
            case 'reject':
                $new_status = 'Rejected';
                $action_description = 'Application Rejected';
                
                if (empty($remarks)) {
                    throw new Exception("Rejection reason is required");
                }
                
                $update_stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = ?,
                        rejection_reason = ?,
                        remarks = ?
                    WHERE permit_id = ?
                ");
                $update_stmt->bind_param("sssi", $new_status, $remarks, $remarks, $permit_id);
                break;
                
            case 'pending':
                $new_status = 'Pending';
                $action_description = 'Status Changed to Pending';
                
                $update_stmt = $conn->prepare("
                    UPDATE tbl_business_permits 
                    SET status = ?,
                        remarks = ?
                    WHERE permit_id = ?
                ");
                $update_stmt->bind_param("ssi", $new_status, $remarks, $permit_id);
                break;
                
            default:
                throw new Exception("Invalid action");
        }
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update permit status");
        }
        $update_stmt->close();
        
        // Log the action in history (only if table exists)
        try {
            $history_stmt = $conn->prepare("
                INSERT INTO tbl_business_permit_history 
                (permit_id, action, notes, action_by)
                VALUES (?, ?, ?, ?)
            ");
            $history_stmt->bind_param("issi", $permit_id, $action_description, $remarks, $current_user_id);
            $history_stmt->execute();
            $history_stmt->close();
        } catch (Exception $e) {
            // Table might not exist, continue anyway
            error_log("History logging failed: " . $e->getMessage());
        }
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Permit status updated successfully!";
        header('Location: view-permit.php?id=' . $permit_id);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating permit: " . $e->getMessage();
    }
}

// Get permit details with all related information
$stmt = $conn->prepare("
    SELECT bp.*, 
           r.first_name, r.last_name, r.contact_number as resident_contact,
           r.email as resident_email, r.address as resident_address,
           bt.type_name, bt.base_fee,
           u.username as approved_by_name
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    LEFT JOIN tbl_users u ON bp.approved_by = u.user_id
    WHERE bp.permit_id = ?
");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();

if (!$permit) {
    $_SESSION['error_message'] = "Permit not found";
    header('Location: applications.php');
    exit;
}

// Get permit history (if table exists)
$history = [];
try {
    $history_query = $conn->prepare("
        SELECT ph.*, u.username as action_by_name
        FROM tbl_business_permit_history ph
        LEFT JOIN tbl_users u ON ph.action_by = u.user_id
        WHERE ph.permit_id = ?
        ORDER BY ph.history_id DESC
    ");
    $history_query->bind_param("i", $permit_id);
    $history_query->execute();
    $history_result = $history_query->get_result();
    $history = $history_result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Table might not exist
    $history = [];
}

// Get inspections if table exists
$inspections = [];
try {
    $inspections_query = $conn->prepare("
        SELECT i.*, u.username as inspector_name
        FROM tbl_business_inspections i
        LEFT JOIN tbl_users u ON i.inspector_id = u.user_id
        WHERE i.permit_id = ?
        ORDER BY i.inspection_date DESC
    ");
    $inspections_query->bind_param("i", $permit_id);
    $inspections_query->execute();
    $inspections_result = $inspections_query->get_result();
    $inspections = $inspections_result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Table might not exist
    $inspections = [];
}

$page_title = "View Permit Details";

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Permit Details</h1>
            <p class="text-muted">Complete information for permit <?php echo htmlspecialchars($permit['permit_number'] ?? 'Pending'); ?></p>
        </div>
        <div class="btn-group">
            <a href="applications.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
            <?php if (($permit['status'] ?? '') === 'Approved'): ?>
                <a href="print-permit.php?id=<?php echo $permit_id; ?>" class="btn btn-primary" target="_blank">
                    <i class="fas fa-print me-2"></i>Print Certificate
                </a>
            <?php endif; ?>
            
            <!-- Status Management Buttons -->
            <?php if (in_array($permit['status'] ?? '', ['Pending', 'for_inspection'])): ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                    <i class="fas fa-check me-2"></i>Approve
                </button>
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                    <i class="fas fa-times me-2"></i>Reject
                </button>
            <?php elseif (($permit['status'] ?? '') === 'Approved'): ?>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#pendingModal">
                    <i class="fas fa-undo me-2"></i>Set to Pending
                </button>
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                    <i class="fas fa-times me-2"></i>Reject
                </button>
            <?php elseif (($permit['status'] ?? '') === 'Rejected'): ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                    <i class="fas fa-check me-2"></i>Approve
                </button>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#pendingModal">
                    <i class="fas fa-undo me-2"></i>Set to Pending
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php 
            echo $_SESSION['success_message']; 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php 
            echo $_SESSION['error_message']; 
            unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Status & Overview -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-1"><?php echo htmlspecialchars($permit['business_name'] ?? 'N/A'); ?></h4>
                            <?php if (!empty($permit['trade_name'])): ?>
                                <p class="text-muted mb-2">DBA: <?php echo htmlspecialchars($permit['trade_name']); ?></p>
                            <?php endif; ?>
                            <p class="mb-0">
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($permit['type_name'] ?? 'N/A'); ?></span>
                                <?php
                                $status_badges = [
                                    'Pending' => 'warning',
                                    'for_inspection' => 'info',
                                    'Approved' => 'success',
                                    'Rejected' => 'danger',
                                    'expired' => 'secondary',
                                    'cancelled' => 'dark'
                                ];
                                $current_status = $permit['status'] ?? 'Pending';
                                $badge_class = $status_badges[$current_status] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($current_status); ?></span>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <h5 class="text-primary mb-0"><?php echo $permit['permit_number'] ?? 'Pending'; ?></h5>
                            <small class="text-muted">Permit Number</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Business Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-building text-primary me-2"></i>Business Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="text-muted small mb-1">Business Type</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['business_type'] ?? 'Not specified'); ?></p>
                        </div>
                        <div class="col-md-12">
                            <label class="text-muted small mb-1">Business Address</label>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($permit['business_address'] ?? 'Not specified')); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small mb-1">Capital Investment</label>
                            <p class="mb-0"><strong>₱<?php echo number_format($permit['capital_investment'] ?? 0, 2); ?></strong></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small mb-1">Employees</label>
                            <p class="mb-0"><strong><?php echo $permit['num_employees'] ?? 0; ?></strong></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small mb-1">Floor Area</label>
                            <p class="mb-0"><strong><?php echo $permit['business_area_sqm'] ?? 0; ?> sq.m</strong></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Owner Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-user text-info me-2"></i>Owner Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Owner Name</label>
                            <p class="mb-0"><strong><?php echo htmlspecialchars($permit['owner_name'] ?? 'Not specified'); ?></strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Resident Name</label>
                            <p class="mb-0"><?php echo htmlspecialchars(($permit['first_name'] ?? '') . ' ' . ($permit['last_name'] ?? '')); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Contact Number</label>
                            <p class="mb-0">
                                <i class="fas fa-phone text-muted me-2"></i>
                                <?php echo htmlspecialchars($permit['owner_contact'] ?? $permit['resident_contact'] ?? 'Not provided'); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Email Address</label>
                            <p class="mb-0">
                                <i class="fas fa-envelope text-muted me-2"></i>
                                <?php echo htmlspecialchars($permit['owner_email'] ?? $permit['resident_email'] ?? 'Not provided'); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">TIN</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['tin_number'] ?? 'Not provided'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">DTI Registration</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['dti_registration'] ?? 'Not provided'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submitted Documents -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-file-alt text-warning me-2"></i>Submitted Documents</h5>
                </div>
                <div class="card-body">
                    <?php
                    $documents_json = $permit['documents'] ?? null;
                    if ($documents_json):
                        $documents = json_decode($documents_json, true);
                        if ($documents && is_array($documents)):
                    ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Document Type</th>
                                    <th>Uploaded</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $key => $doc): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-file-pdf text-danger me-2"></i>
                                        <?php echo htmlspecialchars($doc['label'] ?? $key); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">Submitted</span>
                                        <?php if (!empty($doc['uploaded_at'])): ?>
                                            <br><small class="text-muted"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="../../../uploads/business/<?php echo $doc['filename']; ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php 
                        else:
                            echo '<p class="text-muted text-center mb-0">No documents uploaded</p>';
                        endif;
                    else:
                        echo '<p class="text-muted text-center mb-0">No documents uploaded</p>';
                    endif;
                    ?>
                </div>
            </div>

            <!-- Inspection Records -->
            <?php if (!empty($inspections)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-clipboard-check text-success me-2"></i>Inspection Records</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Inspector</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inspections as $inspection): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($inspection['inspection_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($inspection['inspection_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($inspection['inspector_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $result_badge = [
                                            'Passed' => 'success',
                                            'Failed' => 'danger',
                                            'Conditional' => 'warning'
                                        ];
                                        $overall_result = $inspection['overall_result'] ?? 'Pending';
                                        $result_class = $result_badge[$overall_result] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $result_class; ?>">
                                            <?php echo $overall_result; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Activity History -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-history text-secondary me-2"></i>Activity History</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($history)): ?>
                    <div class="timeline">
                        <?php foreach ($history as $record): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="d-flex justify-content-between">
                                    <strong><?php echo htmlspecialchars($record['action'] ?? 'Action'); ?></strong>
                                </div>
                                <?php if (!empty($record['notes'])): ?>
                                    <p class="mb-0 mt-1 small"><?php echo htmlspecialchars($record['notes']); ?></p>
                                <?php endif; ?>
                                <small class="text-muted">By: <?php echo htmlspecialchars($record['action_by_name'] ?? 'System'); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted text-center mb-0">No activity history available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column - Quick Info -->
        <div class="col-lg-4">
            <!-- Dates & Validity -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Permit Dates</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($permit['application_date'])): ?>
                    <div class="mb-3">
                        <small class="text-muted">Application Date</small>
                        <p class="mb-0"><strong><?php echo date('F d, Y', strtotime($permit['application_date'])); ?></strong></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($permit['issue_date'])): ?>
                    <div class="mb-3">
                        <small class="text-muted">Issue Date</small>
                        <p class="mb-0"><strong><?php echo date('F d, Y', strtotime($permit['issue_date'])); ?></strong></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($permit['expiry_date'])): ?>
                    <div class="mb-3">
                        <small class="text-muted">Expiry Date</small>
                        <p class="mb-0">
                            <strong><?php echo date('F d, Y', strtotime($permit['expiry_date'])); ?></strong>
                            <?php
                            $days_until_expiry = (strtotime($permit['expiry_date']) - time()) / (60 * 60 * 24);
                            if ($days_until_expiry <= 30 && $days_until_expiry > 0):
                            ?>
                                <br><small class="text-warning">
                                    <i class="fas fa-exclamation-triangle"></i> Expires in <?php echo ceil($days_until_expiry); ?> days
                                </small>
                            <?php elseif ($days_until_expiry <= 0): ?>
                                <br><small class="text-danger">
                                    <i class="fas fa-times-circle"></i> Expired
                                </small>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Fee Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Fee Information</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($permit['permit_fee'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Permit Fee:</span>
                        <strong>₱<?php echo number_format($permit['permit_fee'] ?? 0, 2); ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Amount Paid:</span>
                        <strong>₱<?php echo number_format($permit['amount_paid'] ?? 0, 2); ?></strong>
                    </div>
                    
                    <?php if (!empty($permit['permit_fee'])): ?>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <strong>Balance:</strong>
                        <strong class="text-primary">₱<?php echo number_format(($permit['permit_fee'] ?? 0) - ($permit['amount_paid'] ?? 0), 2); ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <?php
                        $payment_badges = [
                            'unpaid' => 'danger',
                            'partial' => 'warning',
                            'paid' => 'success'
                        ];
                        $payment_status = $permit['payment_status'] ?? 'unpaid';
                        $payment_class = $payment_badges[$payment_status] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?php echo $payment_class; ?> w-100">
                            <?php echo ucfirst($payment_status); ?>
                        </span>
                    </div>
                    <?php if (!empty($permit['or_number'])): ?>
                    <div class="mt-2">
                        <small class="text-muted">OR Number: <?php echo htmlspecialchars($permit['or_number']); ?></small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Processing Info -->
            <?php if (!empty($permit['approved_by'])): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Processing Information</h6>
                </div>
                <div class="card-body">
                    <div>
                        <small class="text-muted">Approved By</small>
                        <p class="mb-0"><?php echo htmlspecialchars($permit['approved_by_name'] ?? 'N/A'); ?></p>
                        <?php if (!empty($permit['approval_date'])): ?>
                        <small class="text-muted">
                            <?php echo date('M d, Y', strtotime($permit['approval_date'])); ?>
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="approveForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check me-2"></i>Approve Permit Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Processing permit application for:</p>
                    <p class="mb-3"><strong><?php echo htmlspecialchars($permit['business_name']); ?></strong></p>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        The permit will be valid for 1 year from today.
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Permit Fee (<?php echo htmlspecialchars($permit['type_name'] ?? 'N/A'); ?>)</label>
                            <input type="number" name="permit_fee" class="form-control" 
                                   value="<?php echo $permit['base_fee'] ?? 0; ?>" 
                                   step="0.01" min="0" required id="permitFee">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Sanitary Fee</label>
                            <input type="number" name="sanitary_fee" class="form-control" 
                                   value="500.00" step="0.01" min="0" id="sanitaryFee">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Garbage Fee</label>
                            <input type="number" name="garbage_fee" class="form-control" 
                                   value="300.00" step="0.01" min="0" id="garbageFee">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label"><strong>Total Fee</strong></label>
                            <input type="text" class="form-control bg-light fw-bold" id="totalFee" readonly>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Add any notes or conditions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="approve">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Approve & Calculate Fees
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times me-2"></i>Reject Permit Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this permit application?</p>
                    <p class="mb-3"><strong><?php echo htmlspecialchars($permit['business_name']); ?></strong></p>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Please provide a reason for rejection.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="4" required placeholder="Explain why this application is being rejected..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="reject">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-2"></i>Reject Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set to Pending Modal -->
<div class="modal fade" id="pendingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Set Status to Pending</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to set this permit back to pending status?</p>
                    <p class="mb-3"><strong><?php echo htmlspecialchars($permit['business_name']); ?></strong></p>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will revert the permit to pending status for further review.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Explain why status is being changed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="pending">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-undo me-2"></i>Set to Pending
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
    border-left: 2px solid #dee2e6;
}

.timeline-item:last-child {
    border-left: 0;
}

.timeline-marker {
    position: absolute;
    left: -6px;
    top: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background-color: #0d6efd;
    border: 2px solid #fff;
}

.timeline-content {
    padding-left: 20px;
}
</style>

<script>
// Calculate total fee in approval modal
function calculateTotalFee() {
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

// Calculate on modal show and on input change
document.addEventListener('DOMContentLoaded', function() {
    const approveModal = document.getElementById('approveModal');
    if (approveModal) {
        approveModal.addEventListener('shown.bs.modal', calculateTotalFee);
        
        document.getElementById('permitFee')?.addEventListener('input', calculateTotalFee);
        document.getElementById('sanitaryFee')?.addEventListener('input', calculateTotalFee);
        document.getElementById('garbageFee')?.addEventListener('input', calculateTotalFee);
    }
});
</script>

<?php include_once '../../../includes/footer.php'; ?>