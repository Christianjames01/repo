<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Resident'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "View Application";
$current_user_id = getCurrentUserId();
$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get resident_id
$stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$resident_id = $stmt->get_result()->fetch_assoc()['resident_id'];

// Get permit details
$stmt = $conn->prepare("
    SELECT bp.*, bt.type_name, bt.base_fee
    FROM tbl_business_permits bp
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    WHERE bp.permit_id = ? AND bp.resident_id = ?
");
$stmt->bind_param("ii", $permit_id, $resident_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();

if (!$permit) {
    $_SESSION['error_message'] = "Application not found";
    redirect('/modules/business/resident/my-permits.php');
}

// Get history
$history_query = $conn->prepare("
    SELECT * FROM tbl_business_permit_history 
    WHERE permit_id = ? 
    ORDER BY action_date DESC
");
$history_query->bind_param("i", $permit_id);
$history_query->execute();
$history = $history_query->get_result();

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Application Status</h1>
            <p class="text-muted">Track your business permit application</p>
        </div>
        <a href="my-permits.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to My Permits
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Status Timeline -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Application Status</h5>
                </div>
                <div class="card-body">
                    <?php
                    $status_badges = [
                        'Pending' => 'warning',
                        'For Review' => 'info',
                        'Approved' => 'success',
                        'Rejected' => 'danger'
                    ];
                    $badge_class = $status_badges[$permit['status']] ?? 'secondary';
                    ?>
                    <div class="text-center mb-4">
                        <span class="badge bg-<?php echo $badge_class; ?> fs-5 px-4 py-2">
                            <?php echo $permit['status']; ?>
                        </span>
                    </div>

                    <div class="progress mb-3" style="height: 30px;">
                        <?php
                        $progress = ['Pending' => 25, 'For Review' => 50, 'Approved' => 100, 'Rejected' => 0];
                        $width = $progress[$permit['status']] ?? 0;
                        ?>
                        <div class="progress-bar bg-<?php echo $badge_class; ?>" role="progressbar" 
                             style="width: <?php echo $width; ?>%">
                            <?php echo $width; ?>%
                        </div>
                    </div>

                    <?php if ($permit['status'] === 'Rejected' && $permit['rejection_reason']): ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-times-circle me-2"></i>Rejection Reason:</h6>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($permit['rejection_reason'])); ?></p>
                        </div>
                    <?php elseif ($permit['status'] === 'Approved'): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i>Application Approved!</h6>
                            <p class="mb-0">Your business permit has been approved. Please proceed to payment.</p>
                        </div>
                    <?php elseif ($permit['status'] === 'Pending'): ?>
                        <div class="alert alert-info">
                            <h6><i class="fas fa-clock me-2"></i>Under Review</h6>
                            <p class="mb-0">Your application is being reviewed. This typically takes 3-5 business days.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Application Details -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Application Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Business Name</label>
                            <p class="mb-0"><strong><?php echo htmlspecialchars($permit['business_name']); ?></strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Business Type</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['type_name']); ?></p>
                        </div>
                        <div class="col-md-12">
                            <label class="text-muted small">Business Address</label>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($permit['business_address'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Application Date</label>
                            <p class="mb-0"><?php echo date('F d, Y', strtotime($permit['application_date'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Permit Number</label>
                            <p class="mb-0"><?php echo htmlspecialchars($permit['permit_number'] ?? 'Will be assigned upon approval'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Activity History -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Activity History</h5>
                </div>
                <div class="card-body">
                    <?php if ($history->num_rows > 0): ?>
                        <div class="timeline">
                            <?php while ($record = $history->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <strong><?php echo htmlspecialchars($record['action']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y h:i A', strtotime($record['action_date'])); ?>
                                        </small>
                                        <?php if ($record['remarks']): ?>
                                            <p class="mb-0 mt-1 small"><?php echo htmlspecialchars($record['remarks']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center">No activity yet</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Payment Information -->
            <?php if ($permit['status'] === 'Approved'): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">Payment Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Permit Fee:</span>
                            <strong>₱<?php echo number_format($permit['permit_fee'], 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Sanitary Fee:</span>
                            <strong>₱<?php echo number_format($permit['sanitary_fee'], 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Garbage Fee:</span>
                            <strong>₱<?php echo number_format($permit['garbage_fee'], 2); ?></strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-3">
                            <strong>Total:</strong>
                            <strong class="text-primary">₱<?php echo number_format($permit['total_fee'], 2); ?></strong>
                        </div>

                        <div class="text-center">
                            <?php
                            $payment_badges = ['Unpaid' => 'danger', 'Paid' => 'success'];
                            $pay_class = $payment_badges[$permit['payment_status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $pay_class; ?> w-100 mb-3">
                                <?php echo $permit['payment_status']; ?>
                            </span>

                            <?php if ($permit['payment_status'] === 'Unpaid'): ?>
                                <a href="#" class="btn btn-primary w-100" onclick="alert('Payment feature coming soon!')">
                                    <i class="fas fa-credit-card me-2"></i>Pay Now
                                </a>
                                <small class="text-muted d-block mt-2">
                                    Or visit the barangay office to pay in person
                                </small>
                            <?php else: ?>
                                <a href="../admin/print-permit.php?id=<?php echo $permit_id; ?>" 
                                   class="btn btn-success w-100" target="_blank">
                                    <i class="fas fa-download me-2"></i>Download Permit
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Contact Information -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Need Help?</h6>
                </div>
                <div class="card-body">
                    <p class="small mb-2">
                        <i class="fas fa-phone text-primary me-2"></i>
                        <strong>Contact:</strong><br>
                        <?php echo BARANGAY_CONTACT; ?>
                    </p>
                    <p class="small mb-2">
                        <i class="fas fa-envelope text-primary me-2"></i>
                        <strong>Email:</strong><br>
                        <?php echo BARANGAY_EMAIL; ?>
                    </p>
                    <p class="small mb-0">
                        <i class="fas fa-clock text-primary me-2"></i>
                        <strong>Office Hours:</strong><br>
                        Mon-Fri: 8:00 AM - 5:00 PM
                    </p>
                </div>
            </div>
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

<?php include_once '../../../includes/footer.php'; ?>