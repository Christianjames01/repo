<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Resident'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "My Business Permits";
$current_user_id = getCurrentUserId();

// Get resident_id from user
$stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$resident_id = $result['resident_id'] ?? null;

if (!$resident_id) {
    $_SESSION['error_message'] = "Resident profile not found";
    redirect('/modules/auth/login.php');
}

// Get my permits
$permits_query = "
    SELECT bp.*, bt.type_name
    FROM tbl_business_permits bp
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    WHERE bp.resident_id = ?
    ORDER BY bp.created_at DESC
";
$stmt = $conn->prepare($permits_query);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$permits = $stmt->get_result();

// Get statistics
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_business_permits WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$stats['total'] = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_business_permits WHERE resident_id = ? AND status = 'Approved'");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$stats['active'] = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_business_permits WHERE resident_id = ? AND status = 'Pending'");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$stats['pending'] = $stmt->get_result()->fetch_assoc()['total'];

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">My Business Permits</h1>
            <p class="text-muted">Manage your business permits and applications</p>
        </div>
        <a href="apply-permit.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Apply for New Permit
        </a>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Permits</p>
                            <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                            <i class="fas fa-briefcase fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Active Permits</p>
                            <h3 class="mb-0"><?php echo $stats['active']; ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Pending Applications</p>
                            <h3 class="mb-0"><?php echo $stats['pending']; ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Permits List -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">My Business Permits</h5>
        </div>
        <div class="card-body p-0">
            <?php if ($permits->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Permit #</th>
                                <th>Business Name</th>
                                <th>Type</th>
                                <th>Application Date</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($permit = $permits->fetch_assoc()): ?>
                                <?php
                                $is_expiring = false;
                                $is_expired = false;
                                if (($permit['status'] ?? '') === 'Approved' && !empty($permit['expiry_date'])) {
                                    $days_until_expiry = (strtotime($permit['expiry_date']) - time()) / (60 * 60 * 24);
                                    $is_expiring = $days_until_expiry <= 30 && $days_until_expiry > 0;
                                    $is_expired = $days_until_expiry <= 0;
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($permit['permit_number'] ?? 'Pending'); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($permit['business_name'] ?? 'N/A'); ?></strong>
                                        <?php if (!empty($permit['trade_name'])): ?>
                                            <br><small class="text-muted">DBA: <?php echo htmlspecialchars($permit['trade_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($permit['type_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($permit['application_date'])); ?></td>
                                    <td>
                                        <?php if (!empty($permit['expiry_date'])): ?>
                                            <?php echo date('M d, Y', strtotime($permit['expiry_date'])); ?>
                                            <?php if ($is_expiring): ?>
                                                <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Expiring soon</small>
                                            <?php elseif ($is_expired): ?>
                                                <br><small class="text-danger"><i class="fas fa-times-circle"></i> Expired</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'Pending' => 'warning',
                                            'For Review' => 'info',
                                            'Approved' => 'success',
                                            'Rejected' => 'danger',
                                            'Expired' => 'secondary',
                                            'Cancelled' => 'dark'
                                        ];
                                        $current_status = $permit['status'] ?? 'Pending';
                                        $class = $badge_class[$current_status] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $class; ?>">
                                            <?php echo $current_status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view-application.php?id=<?php echo $permit['permit_id']; ?>" 
                                               class="btn btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (($permit['status'] ?? '') === 'Approved' && ($permit['payment_status'] ?? '') === 'Paid'): ?>
                                                <a href="../admin/print-permit.php?id=<?php echo $permit['permit_id']; ?>" 
                                                   class="btn btn-outline-secondary" title="Print Certificate" target="_blank">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($is_expiring || $is_expired): ?>
                                                <a href="renewal.php?id=<?php echo $permit['permit_id']; ?>" 
                                                   class="btn btn-outline-success" title="Renew Permit">
                                                    <i class="fas fa-sync-alt"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-briefcase fa-4x text-muted mb-3"></i>
                    <h4>No Business Permits Yet</h4>
                    <p class="text-muted">You haven't applied for any business permits.</p>
                    <a href="apply-permit.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus"></i> Apply for Your First Permit
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Help Section -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>Need Help?</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Application Process:</h6>
                    <ol class="small">
                        <li>Submit application with required documents</li>
                        <li>Wait for review (3-5 business days)</li>
                        <li>Pay permit fees if approved</li>
                        <li>Receive permit certificate</li>
                    </ol>
                </div>
                <div class="col-md-6">
                    <h6>Required Documents:</h6>
                    <ul class="small">
                        <li>DTI Business Registration</li>
                        <li>BIR Certificate of Registration</li>
                        <li>Barangay Clearance</li>
                        <li>Valid ID of Owner</li>
                        <li>Location Sketch/Map</li>
                    </ul>
                </div>
            </div>
            <hr>
            <p class="mb-0 small">
                <i class="fas fa-phone me-2"></i><strong>Contact:</strong> <?php echo BARANGAY_CONTACT; ?> | 
                <i class="fas fa-envelope me-2 ms-3"></i><strong>Email:</strong> <?php echo BARANGAY_EMAIL; ?>
            </p>
        </div>
    </div>
</div>

<?php include_once '../../../includes/footer.php'; ?>