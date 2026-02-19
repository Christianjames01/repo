<?php
/**
 * Payslip List
 * modules/attendance/admin/payslip-list.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
$current_user_id = getCurrentUserId();

// Allow staff to view their own payslips
$is_admin = in_array($user_role, ['Admin', 'Super Admin']);

$page_title = 'Payslip Management';

// Get filter parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$selected_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Handle payslip deletion (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payslip']) && $is_admin) {
    $payslip_id = intval($_POST['payslip_id']);
    
    if (executeQuery($conn, "DELETE FROM tbl_payslips WHERE payslip_id = ?", [$payslip_id], 'i')) {
        logActivity($conn, $current_user_id, 'Deleted payslip', 'tbl_payslips', $payslip_id);
        $_SESSION['success_message'] = 'Payslip deleted successfully';
    } else {
        $_SESSION['error_message'] = 'Failed to delete payslip';
    }
    
    header("Location: payslip-list.php");
    exit();
}

// Build query based on user role
if ($is_admin) {
    // Admin can see all payslips
    $where_conditions = ["1=1"];
    $params = [];
    $types = '';
    
    if ($selected_user > 0) {
        $where_conditions[] = "p.user_id = ?";
        $params[] = $selected_user;
        $types .= 'i';
    }
    
    if ($selected_month) {
        $where_conditions[] = "DATE_FORMAT(p.pay_period_start, '%Y-%m') = ?";
        $params[] = $selected_month;
        $types .= 's';
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
 $payslips = fetchAll($conn,
    "SELECT p.*, 
            CONCAT(r.first_name, ' ', r.last_name) as staff_name,
            u.role,
            r.profile_photo,
            CONCAT(cr.first_name, ' ', cr.last_name) as created_by_name
    FROM tbl_payslips p
    LEFT JOIN tbl_users u ON p.user_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_users cu ON p.generated_by = cu.user_id
    LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
    WHERE $where_clause
    ORDER BY p.generated_at DESC",
    $params, $types
);
    
   
// Get all staff for filter
$staff = fetchAll($conn,
    "SELECT u.user_id, CONCAT(r.first_name, ' ', r.last_name) as full_name, u.role
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
    ORDER BY r.last_name, r.first_name"
);
} else {
    // Staff can only see their own payslips
    $where_conditions = ["p.user_id = ?"];
    $params = [$current_user_id];
    $types = 'i';
    
    if ($selected_month) {
        $where_conditions[] = "DATE_FORMAT(p.pay_period_start, '%Y-%m') = ?";
        $params[] = $selected_month;
        $types .= 's';
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $payslips = fetchAll($conn,
    "SELECT p.*, 
            CONCAT(r.first_name, ' ', r.last_name) as staff_name,
            u.role,
            r.profile_photo
    FROM tbl_payslips p
    LEFT JOIN tbl_users u ON p.user_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE $where_clause
    ORDER BY p.generated_at DESC",
    $params, $types
);
}

// Calculate statistics
$total_payslips = count($payslips);
$total_gross = array_sum(array_column($payslips, 'gross_pay'));
$total_deductions = array_sum(array_column($payslips, 'total_deductions'));
$total_net = array_sum(array_column($payslips, 'net_pay'));

include '../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-2">
            <i class="fas fa-file-invoice-dollar text-success me-2"></i>
            <?php echo $is_admin ? 'Payslip Management' : 'My Payslips'; ?>
        </h1>
        <p class="text-muted mb-0">View and manage salary payslips</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($is_admin): ?>
            <a href="generate-payslip.php" class="btn btn-success">
                <i class="fas fa-plus me-1"></i> Generate New Payslip
            </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Attendance
        </a>
    </div>
</div>

<?php echo displayMessage(); ?>

<!-- Statistics Cards -->
<?php if ($total_payslips > 0): ?>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Total Payslips</h6>
                        <h3 class="mb-0"><?php echo $total_payslips; ?></h3>
                    </div>
                    <div class="fs-2 text-primary opacity-50">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Total Gross Pay</h6>
                        <h3 class="mb-0 text-success">₱<?php echo number_format($total_gross, 2); ?></h3>
                    </div>
                    <div class="fs-2 text-success opacity-50">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Total Deductions</h6>
                        <h3 class="mb-0 text-danger">₱<?php echo number_format($total_deductions, 2); ?></h3>
                    </div>
                    <div class="fs-2 text-danger opacity-50">
                        <i class="fas fa-minus-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Total Net Pay</h6>
                        <h3 class="mb-0 text-info">₱<?php echo number_format($total_net, 2); ?></h3>
                    </div>
                    <div class="fs-2 text-info opacity-50">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <?php if ($is_admin): ?>
            <div class="col-md-4">
                <label for="user_id" class="form-label">Staff Member</label>
                <select class="form-select" id="user_id" name="user_id" onchange="this.form.submit()">
                    <option value="0">All Staff</option>
                    <?php foreach ($staff as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>" <?php echo $selected_user == $s['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['full_name']) . ' (' . $s['role'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-<?php echo $is_admin ? '4' : '8'; ?>">
                <label for="month" class="form-label">Pay Period</label>
                <input type="month" class="form-control" id="month" name="month" 
                       value="<?php echo $selected_month; ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-<?php echo $is_admin ? '4' : '4'; ?>">
                <label class="form-label">&nbsp;</label>
                <a href="payslip-list.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-redo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Payslips Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0">
            <i class="fas fa-list text-primary me-2"></i>
            Payslip Records
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Payslip #</th>
                        <?php if ($is_admin): ?>
                            <th>Staff Member</th>
                        <?php endif; ?>
                        <th>Pay Period</th>
                        <th>Days Present</th>
                        <th>Overtime Hrs</th>
                        <th>Gross Pay</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Generated</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($payslips) > 0): ?>
                        <?php foreach ($payslips as $payslip): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary">
                                        #<?php echo str_pad($payslip['payslip_id'], 6, '0', STR_PAD_LEFT); ?>
                                    </strong>
                                </td>
                                <?php if ($is_admin): ?>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if ($payslip['profile_photo'] && file_exists('../../../uploads/profiles/' . $payslip['profile_photo'])): ?>
                                            <img src="<?php echo '../../../uploads/profiles/' . $payslip['profile_photo']; ?>" 
                                                 class="rounded-circle me-2" width="32" height="32" alt="Profile">
                                        <?php else: ?>
                                            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                                 style="width: 32px; height: 32px;">
                                                <?php echo strtoupper(substr($payslip['staff_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($payslip['staff_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo $payslip['role']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <strong><?php echo date('M d', strtotime($payslip['pay_period_start'])); ?></strong> - 
                                    <strong><?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?></strong>
                                    <br><small class="text-muted"><?php echo date('F Y', strtotime($payslip['pay_period_start'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-success"><?php echo $payslip['days_present']; ?> days</span>
                                </td>
                                <td>
                                    <?php if ($payslip['overtime_hours'] > 0): ?>
                                        <span class="badge bg-info"><?php echo number_format($payslip['overtime_hours'], 1); ?> hrs</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-success fw-bold">₱<?php echo number_format($payslip['gross_pay'], 2); ?></td>
                                <td class="text-danger">₱<?php echo number_format($payslip['total_deductions'], 2); ?></td>
                                <td>
                                    <strong class="text-primary fs-6">₱<?php echo number_format($payslip['net_pay'], 2); ?></strong>
                                </td>
                               <td>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?>
                                        <?php if ($is_admin && $payslip['created_by_name']): ?>
                                            <br>by <?php echo htmlspecialchars($payslip['created_by_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="view-payslip.php?id=<?php echo $payslip['payslip_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($is_admin): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $payslip['payslip_id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $is_admin ? '10' : '9'; ?>" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                <p class="mb-0">No payslips found</p>
                                <?php if ($is_admin): ?>
                                    <a href="generate-payslip.php" class="btn btn-primary mt-3">
                                        <i class="fas fa-plus me-1"></i> Generate First Payslip
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<?php if ($is_admin): ?>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Confirm Deletion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete this payslip? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="delete_payslip" value="1">
                    <input type="hidden" name="payslip_id" id="delete_payslip_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i> Delete Payslip
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(payslipId) {
    document.getElementById('delete_payslip_id').value = payslipId;
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}
</script>
<?php endif; ?>

<?php include '../../../includes/footer.php'; ?>