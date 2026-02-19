<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Resident'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Renew Business Permit";
$current_user_id = getCurrentUserId();

// Get resident_id
$stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$resident_id = $stmt->get_result()->fetch_assoc()['resident_id'];

// Get permit to renew
$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$permit = null;

if ($permit_id) {
    // FIXED: Removed business_types join and changed status to lowercase
    $stmt = $conn->prepare("
        SELECT bp.*
        FROM tbl_business_permits bp
        WHERE bp.permit_id = ? AND bp.resident_id = ? AND bp.status = 'approved'
    ");
    $stmt->bind_param("ii", $permit_id, $resident_id);
    $stmt->execute();
    $permit = $stmt->get_result()->fetch_assoc();
    
    if (!$permit) {
        $_SESSION['error_message'] = "Permit not found or cannot be renewed";
        redirect('/modules/business/resident/my-permits.php');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permit_id && $permit) {
    $conn->begin_transaction();
    
    try {
        // Create renewal application - FIXED: Updated to match actual table structure
        $stmt = $conn->prepare("
            INSERT INTO tbl_business_permits (
                resident_id, business_name, business_type, business_category,
                business_address, owner_name, owner_contact, owner_email,
                tin_number, dti_registration, business_area_sqm, num_employees,
                capital_investment, permit_type, application_date, status,
                permit_fee
            ) SELECT 
                resident_id, business_name, business_type, business_category,
                business_address, owner_name, ?, ?,
                tin_number, dti_registration, business_area_sqm, ?,
                capital_investment, 'Renewal', NOW(), 'pending',
                ? * 0.75
            FROM tbl_business_permits
            WHERE permit_id = ?
        ");
        
        $renewal_fee = $permit['permit_fee'] * 0.75; // 25% discount for renewal
        $stmt->bind_param("ssidi",
            $_POST['contact_number'],
            $_POST['email'],
            $_POST['employees'],
            $renewal_fee,
            $permit_id
        );
        $stmt->execute();
        $new_permit_id = $conn->insert_id();
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Renewal application submitted successfully! Your renewal is now pending review.";
        redirect('/modules/business/resident/my-permits.php');
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error submitting renewal: " . $e->getMessage();
    }
}

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <h1 class="h3">Renew Business Permit</h1>
                <p class="text-muted">Submit renewal application for your business permit</p>
            </div>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($permit): ?>
                <!-- Current Permit Info -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Current Permit Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small">Permit Number</label>
                                <p class="mb-0"><strong><?php echo htmlspecialchars($permit['permit_number'] ?? 'N/A'); ?></strong></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Expiry Date</label>
                                <p class="mb-0">
                                    <?php if ($permit['expiry_date']): ?>
                                        <?php echo date('F d, Y', strtotime($permit['expiry_date'])); ?>
                                        <?php
                                        $days_until_expiry = (strtotime($permit['expiry_date']) - time()) / (60 * 60 * 24);
                                        if ($days_until_expiry < 0): ?>
                                            <br><small class="text-danger">Expired <?php echo abs(ceil($days_until_expiry)); ?> days ago</small>
                                        <?php else: ?>
                                            <br><small class="text-warning"><?php echo ceil($days_until_expiry); ?> days remaining</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-12">
                                <label class="text-muted small">Business Name</label>
                                <p class="mb-0"><strong><?php echo htmlspecialchars($permit['business_name']); ?></strong></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Business Type</label>
                                <p class="mb-0"><?php echo htmlspecialchars($permit['business_type']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Owner</label>
                                <p class="mb-0"><?php echo htmlspecialchars($permit['owner_name']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Renewal Form -->
                <form method="POST">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">Renewal Application</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Most information will be copied from your current permit. Please update any changes below.
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Contact Number</label>
                                    <input type="text" name="contact_number" class="form-control" 
                                           value="<?php echo htmlspecialchars($permit['owner_contact'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($permit['owner_email'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Current Number of Employees</label>
                                    <input type="number" name="employees" class="form-control" min="0"
                                           value="<?php echo $permit['num_employees'] ?? 0; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Renewal Fee (25% Discount)</label>
                                    <input type="text" class="form-control bg-light" 
                                           value="₱<?php echo number_format(($permit['permit_fee'] ?? 0) * 0.75, 2); ?>" readonly>
                                    <small class="text-muted">Original fee: ₱<?php echo number_format($permit['permit_fee'] ?? 0, 2); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <h6 class="mb-0">Fee Summary</h6>
                        </div>
                        <div class="card-body">
                            <?php 
                            $original_fee = $permit['permit_fee'] ?? 0;
                            $renewal_fee = $original_fee * 0.75;
                            ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Permit Fee (Renewal - 25% off):</span>
                                <strong>₱<?php echo number_format($renewal_fee, 2); ?></strong>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <strong>Total Renewal Fee:</strong>
                                <strong class="text-success">₱<?php echo number_format($renewal_fee, 2); ?></strong>
                            </div>
                            <small class="text-muted d-block mt-2">Additional fees may apply during processing</small>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                                <label class="form-check-label" for="agreeTerms">
                                    I certify that all information from the original permit remains accurate, except for the updates provided above.
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="my-permits.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-sync-alt me-2"></i>Submit Renewal
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h4>No Permit Selected</h4>
                        <p class="text-muted">Please select a permit to renew from your permits list.</p>
                        <a href="my-permits.php" class="btn btn-primary mt-3">
                            <i class="fas fa-arrow-left me-2"></i>Go to My Permits
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once '../../../includes/footer.php'; ?>