<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Assistance Requests';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = (int)$_POST['request_id'];
    
    switch ($_POST['action']) {
        case 'approve':
            $sql = "UPDATE tbl_education_assistance_requests SET 
                    status = 'approved',
                    approved_amount = ?,
                    approved_by = ?,
                    approval_date = NOW()
                    WHERE request_id = ?";
            executeQuery($conn, $sql, [$_POST['approved_amount'], getCurrentUserId(), $request_id], 'dii');
            setMessage('Assistance request approved', 'success');
            break;
            
        case 'reject':
            $sql = "UPDATE tbl_education_assistance_requests SET 
                    status = 'rejected',
                    rejection_reason = ?
                    WHERE request_id = ?";
            executeQuery($conn, $sql, [$_POST['rejection_reason'], $request_id], 'si');
            setMessage('Assistance request rejected', 'info');
            break;
            
        case 'complete':
            $sql = "UPDATE tbl_education_assistance_requests SET 
                    status = 'completed',
                    disbursement_date = ?
                    WHERE request_id = ?";
            executeQuery($conn, $sql, [$_POST['disbursement_date'], $request_id], 'si');
            setMessage('Assistance marked as completed', 'success');
            break;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get all assistance requests
$requests_sql = "SELECT ear.*, es.first_name, es.last_name, es.school_name
                 FROM tbl_education_assistance_requests ear
                 JOIN tbl_education_students es ON ear.student_id = es.student_id
                 ORDER BY ear.request_date DESC";
$requests = fetchAll($conn, $requests_sql);

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-0">
                <i class="fas fa-hand-holding-usd me-2 text-success"></i>Educational Assistance Requests
            </h2>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if (empty($requests)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No assistance requests</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th>
                                <th>Student</th>
                                <th>Assistance Type</th>
                                <th>Amount Requested</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td><strong>#<?php echo str_pad($request['request_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($request['school_name']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['assistance_type']); ?></td>
                                    <td><strong class="text-primary">₱<?php echo number_format($request['requested_amount'], 2); ?></strong></td>
                                    <td><small><?php echo htmlspecialchars(substr($request['purpose'], 0, 50)); ?>...</small></td>
                                    <td><?php echo getStatusBadge($request['status']); ?></td>
                                    <td><small><?php echo formatDate($request['request_date']); ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($request['status'] == 'pending'): ?>
                                                <button class="btn btn-success" onclick="approveRequest(<?php echo $request['request_id']; ?>, <?php echo $request['requested_amount']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['request_id']; ?>)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php elseif ($request['status'] == 'approved'): ?>
                                                <button class="btn btn-primary" onclick="completeRequest(<?php echo $request['request_id']; ?>)">
                                                    <i class="fas fa-check-double"></i> Complete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Approve Assistance Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="request_id" id="approveRequestId">
                    <div class="mb-3">
                        <label class="form-label">Approved Amount</label>
                        <input type="number" step="0.01" name="approved_amount" id="approvedAmount" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
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
                    <h5 class="modal-title">Reject Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="request_id" id="rejectRequestId">
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Complete Disbursement</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="request_id" id="completeRequestId">
                    <div class="mb-3">
                        <label class="form-label">Disbursement Date</label>
                        <input type="date" name="disbursement_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Mark as Completed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveRequest(id, amount) {
    document.getElementById('approveRequestId').value = id;
    document.getElementById('approvedAmount').value = amount;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function rejectRequest(id) {
    document.getElementById('rejectRequestId').value = id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function completeRequest(id) {
    document.getElementById('completeRequestId').value = id;
    new bootstrap.Modal(document.getElementById('completeModal')).show();
}
</script>

<?php
$conn->close();
include '../../../includes/footer.php';
?>