<?php
/**
 * Staff Leave Management View
 * modules/attendance/manage-leaves.php
 * View own leave requests and see other staff leaves
 */

// Set timezone first
date_default_timezone_set('Asia/Manila');

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$page_title = 'My Leave Requests';
$current_user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

// Handle leave cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_leave'])) {
    $leave_id = (int)$_POST['leave_id'];
    
    // Verify this leave belongs to current user and is pending
    $leave = fetchOne($conn,
        "SELECT * FROM tbl_leave_requests WHERE leave_id = ? AND user_id = ? AND status = 'Pending'",
        [$leave_id, $current_user_id], 'ii'
    );
    
    if ($leave) {
        $sql = "UPDATE tbl_leave_requests SET status = 'Cancelled' WHERE leave_id = ?";
        if (executeQuery($conn, $sql, [$leave_id], 'i')) {
            logActivity($conn, $current_user_id, 'Cancelled leave request', 'tbl_leave_requests', $leave_id);
            $_SESSION['success_message'] = 'Leave request cancelled successfully';
        } else {
            $_SESSION['error_message'] = 'Failed to cancel leave request';
        }
    } else {
        $_SESSION['error_message'] = 'Leave request not found or cannot be cancelled';
    }
    
    header('Location: manage-leaves.php');
    exit();
}

// Pagination setup
$records_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $records_per_page;

// Filter setup
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$view_mode = isset($_GET['view']) ? sanitizeInput($_GET['view']) : 'my'; // 'my' or 'all'

// Build query for MY leaves - FIXED: Added admin_notes and processed_by fields
$where_conditions_my = ["lr.user_id = ?"];
$params_my = [$current_user_id];
$types_my = 'i';

if ($status_filter !== 'all') {
    $where_conditions_my[] = "lr.status = ?";
    $params_my[] = $status_filter;
    $types_my .= 's';
}

$where_clause_my = implode(' AND ', $where_conditions_my);

// Get MY leave requests - FIXED: Added admin_notes, processed_by, and processor name
$my_leaves = fetchAll($conn,
    "SELECT lr.*, 
            CONCAT(res.first_name, ' ', res.last_name) as staff_name,
            DATEDIFF(lr.end_date, lr.start_date) + 1 as duration_days,
            lr.admin_notes,
            lr.processed_by,
            lr.processed_at,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(pr.first_name, '')), ' ', TRIM(COALESCE(pr.last_name, ''))), ' '), pu.username) as processor_name
    FROM tbl_leave_requests lr
    INNER JOIN tbl_users u ON lr.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_users pu ON lr.processed_by = pu.user_id
    LEFT JOIN tbl_residents pr ON pu.resident_id = pr.resident_id
    WHERE $where_clause_my
    ORDER BY lr.created_at DESC
    LIMIT ? OFFSET ?",
    array_merge($params_my, [$records_per_page, $offset]),
    $types_my . 'ii'
);

// Count total MY leaves
$total_my = fetchOne($conn,
    "SELECT COUNT(*) as total FROM tbl_leave_requests lr WHERE $where_clause_my",
    $params_my, $types_my
)['total'];

// Build query for ALL STAFF leaves (excluding mine)
$where_conditions_all = ["lr.user_id != ?"];
$params_all = [$current_user_id];
$types_all = 'i';

if ($status_filter !== 'all') {
    $where_conditions_all[] = "lr.status = ?";
    $params_all[] = $status_filter;
    $types_all .= 's';
}

$where_clause_all = implode(' AND ', $where_conditions_all);

// Get ALL STAFF leave requests (excluding current user)
$all_staff_leaves = fetchAll($conn,
    "SELECT lr.*, 
            CONCAT(res.first_name, ' ', res.last_name) as staff_name,
            u.role as staff_role,
            DATEDIFF(lr.end_date, lr.start_date) + 1 as duration_days
    FROM tbl_leave_requests lr
    INNER JOIN tbl_users u ON lr.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    WHERE $where_clause_all
    ORDER BY lr.created_at DESC
    LIMIT ? OFFSET ?",
    array_merge($params_all, [$records_per_page, $offset]),
    $types_all . 'ii'
);

// Count total ALL STAFF leaves
$total_all = fetchOne($conn,
    "SELECT COUNT(*) as total FROM tbl_leave_requests lr WHERE $where_clause_all",
    $params_all, $types_all
)['total'];

// Get statistics for current user
$leave_stats = fetchOne($conn,
    "SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN status = 'Approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as total_days_taken
    FROM tbl_leave_requests 
    WHERE user_id = ? AND YEAR(start_date) = YEAR(CURDATE())",
    [$current_user_id], 'i'
);

// Calculate pagination
$total_records = ($view_mode === 'my') ? $total_my : $total_all;
$total_pages = ceil($total_records / $records_per_page);

// Helper function for status badge
function getLeaveStatusBadge($status) {
    $badges = [
        'Pending' => 'bg-warning text-dark',
        'Approved' => 'bg-success',
        'Rejected' => 'bg-danger',
        'Cancelled' => 'bg-secondary'
    ];
    $icon = [
        'Pending' => 'fa-clock',
        'Approved' => 'fa-check-circle',
        'Rejected' => 'fa-times-circle',
        'Cancelled' => 'fa-ban'
    ];
    return '<span class="badge ' . $badges[$status] . '"><i class="fas ' . $icon[$status] . ' me-1"></i>' . $status . '</span>';
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-times text-primary me-2"></i>
                Leave Management
            </h1>
            <p class="text-muted mb-0">View and manage your leave requests</p>
        </div>
        <div class="d-flex gap-2">
            <a href="my-schedule.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Schedule
            </a>
            <a href="leave-request.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> New Leave Request
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Requests</h6>
                            <h2 class="mb-0"><?php echo $leave_stats['total_requests']; ?></h2>
                            <small class="text-muted">This year</small>
                        </div>
                        <div class="fs-1 text-primary opacity-25">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Pending</h6>
                            <h2 class="mb-0"><?php echo $leave_stats['pending_count']; ?></h2>
                            <small class="text-muted">Awaiting approval</small>
                        </div>
                        <div class="fs-1 text-warning opacity-25">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Approved</h6>
                            <h2 class="mb-0"><?php echo $leave_stats['approved_count']; ?></h2>
                            <small class="text-muted"><?php echo $leave_stats['total_days_taken']; ?> days taken</small>
                        </div>
                        <div class="fs-1 text-success opacity-25">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Rejected</h6>
                            <h2 class="mb-0"><?php echo $leave_stats['rejected_count']; ?></h2>
                            <small class="text-muted">Not approved</small>
                        </div>
                        <div class="fs-1 text-danger opacity-25">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Toggle and Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h6 class="mb-0">
                        <i class="fas fa-filter me-2"></i>
                        Filter Leave Requests
                    </h6>
                </div>
                <div class="col-md-6">
                    <!-- Status Filter -->
                    <div class="d-flex justify-content-end gap-2">
                        <label class="col-form-label">Status:</label>
                        <select class="form-select w-auto" id="statusFilter">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leave Requests Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-list text-primary me-2"></i>
                My Leave Requests
            </h5>
        </div>
        <div class="card-body">
            <?php 
            if (!empty($my_leaves)): 
            ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Leave Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_leaves as $leave): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars($leave['leave_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <i class="fas fa-calendar-day text-success me-1"></i>
                                        <?php echo formatDate($leave['start_date']); ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-calendar-check text-danger me-1"></i>
                                        <?php echo formatDate($leave['end_date']); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo $leave['duration_days']; ?> day(s)
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo getLeaveStatusBadge($leave['status']); ?>
                                    </td>
                                    <td>
                                        <small class="text-muted" title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                            <?php 
                                            $reason = htmlspecialchars($leave['reason']);
                                            echo strlen($reason) > 50 ? substr($reason, 0, 50) . '...' : $reason; 
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo formatDateTime($leave['created_at']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" 
                                                    class="btn btn-outline-info" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewLeaveModal<?php echo $leave['leave_id']; ?>"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($leave['status'] === 'Pending'): ?>
                                            <button type="button" 
                                                    class="btn btn-outline-danger" 
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancelLeaveModal"
                                                    onclick="setCancelLeaveId(<?php echo $leave['leave_id']; ?>, '<?php echo htmlspecialchars($leave['leave_type']); ?>', '<?php echo formatDate($leave['start_date']); ?>', '<?php echo formatDate($leave['end_date']); ?>')"
                                                    title="Cancel Request">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- View Leave Modal - FIXED: Added admin_notes section -->
                                <div class="modal fade" id="viewLeaveModal<?php echo $leave['leave_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    Leave Request Details
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-tag me-1 text-primary"></i>
                                                            Leave Type:
                                                        </label>
                                                        <p><span class="badge bg-primary"><?php echo htmlspecialchars($leave['leave_type']); ?></span></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-traffic-light me-1 text-info"></i>
                                                            Status:
                                                        </label>
                                                        <p><?php echo getLeaveStatusBadge($leave['status']); ?></p>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-calendar-day me-1 text-success"></i>
                                                            Start Date:
                                                        </label>
                                                        <p><?php echo formatDate($leave['start_date']); ?></p>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-calendar-check me-1 text-danger"></i>
                                                            End Date:
                                                        </label>
                                                        <p><?php echo formatDate($leave['end_date']); ?></p>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-clock me-1 text-warning"></i>
                                                            Duration:
                                                        </label>
                                                        <p><span class="badge bg-secondary"><?php echo $leave['duration_days']; ?> day(s)</span></p>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-comment-alt me-1 text-primary"></i>
                                                            Reason:
                                                        </label>
                                                        <div class="border p-3 bg-light rounded">
                                                            <?php echo nl2br(htmlspecialchars($leave['reason'])); ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- FIXED: Display admin notes when available -->
                                                    <?php if (!empty($leave['admin_notes']) && $leave['admin_notes'] !== ''): ?>
                                                    <div class="col-md-12">
                                                        <label class="fw-bold text-info">
                                                            <i class="fas fa-sticky-note me-1"></i>
                                                            Admin Notes:
                                                        </label>
                                                        <div class="alert alert-info mb-0">
                                                            <i class="fas fa-info-circle me-2"></i>
                                                            <?php echo nl2br(htmlspecialchars($leave['admin_notes'])); ?>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="col-md-12">
                                                        <hr>
                                                    </div>
                                                    
                                                    <div class="col-md-6">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-calendar-plus me-1 text-muted"></i>
                                                            Submitted On:
                                                        </label>
                                                        <p class="text-muted"><?php echo formatDateTime($leave['created_at']); ?></p>
                                                    </div>
                                                    
                                                    <?php if ($leave['status'] !== 'Pending' && !empty($leave['processed_at'])): ?>
                                                    <div class="col-md-6">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-calendar-check me-1 text-muted"></i>
                                                            Processed On:
                                                        </label>
                                                        <p class="text-muted"><?php echo formatDateTime($leave['processed_at']); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($leave['processor_name'])): ?>
                                                    <div class="col-md-12">
                                                        <label class="fw-bold">
                                                            <i class="fas fa-user-shield me-1 text-muted"></i>
                                                            Processed By:
                                                        </label>
                                                        <p class="text-muted"><?php echo htmlspecialchars($leave['processor_name']); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <?php if ($leave['status'] === 'Pending'): ?>
                                                <button type="button" 
                                                        class="btn btn-danger" 
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#cancelLeaveModal"
                                                        onclick="setCancelLeaveId(<?php echo $leave['leave_id']; ?>, '<?php echo htmlspecialchars($leave['leave_type']); ?>', '<?php echo formatDate($leave['start_date']); ?>', '<?php echo formatDate($leave['end_date']); ?>')"
                                                        data-bs-dismiss="modal">
                                                    <i class="fas fa-times me-1"></i>
                                                    Cancel Leave Request
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    <i class="fas fa-times me-1"></i>
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">No Leave Requests Found</h5>
                    <p class="text-muted">You haven't submitted any leave requests yet.</p>
                    <a href="leave-request.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus me-2"></i>Submit Your First Leave Request
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Cancel Leave Request Modal -->
<div class="modal fade" id="cancelLeaveModal" tabindex="-1" aria-labelledby="cancelLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="cancelLeaveModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Cancel Leave Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="cancelLeaveModalForm">
                <div class="modal-body">
                    <input type="hidden" name="cancel_leave" value="1">
                    <input type="hidden" name="leave_id" id="modal_cancel_leave_id">
                    
                    <!-- Warning Alert -->
                    <div class="alert alert-warning border-warning">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-exclamation-circle fa-2x me-3 text-warning"></i>
                            <div>
                                <h6 class="alert-heading mb-2">Are you sure you want to cancel this leave request?</h6>
                                <p class="mb-0 small">This action cannot be undone. Once cancelled, you will need to submit a new leave request if needed.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Leave Details Summary -->
                    <div class="card bg-light border-0 mb-3">
                        <div class="card-body">
                            <h6 class="card-title text-danger mb-3">
                                <i class="fas fa-file-alt me-2"></i>
                                Leave Request Details
                            </h6>
                            <div class="row g-2">
                                <div class="col-12">
                                    <small class="text-muted d-block mb-1">
                                        <i class="fas fa-tag me-1"></i>
                                        Leave Type
                                    </small>
                                    <strong id="modal_cancel_leave_type" class="d-block"></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block mb-1">
                                        <i class="fas fa-calendar-day me-1"></i>
                                        Start Date
                                    </small>
                                    <strong id="modal_cancel_start_date" class="d-block"></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block mb-1">
                                        <i class="fas fa-calendar-check me-1"></i>
                                        End Date
                                    </small>
                                    <strong id="modal_cancel_end_date" class="d-block"></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Warning -->
                    <div class="alert alert-danger border-danger mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Important:</strong> If you need to request leave for the same dates again, you must submit a completely new request.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-1"></i>
                        No, Keep Request
                    </button>
                    <button type="submit" class="btn btn-danger" id="confirmCancelBtn">
                        <i class="fas fa-times-circle me-1"></i>
                        Yes, Cancel Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Leave Form (Hidden) - Keep for backward compatibility -->
<form method="POST" id="cancelLeaveForm" style="display: none;">
    <input type="hidden" name="cancel_leave" value="1">
    <input type="hidden" name="leave_id" id="cancel_leave_id">
</form>

<script>
// Status filter change handler
document.getElementById('statusFilter').addEventListener('change', function() {
    const status = this.value;
    window.location.href = `?status=${status}&page=1`;
});

// Set leave ID and details for cancel modal
function setCancelLeaveId(leaveId, leaveType, startDate, endDate) {
    document.getElementById('modal_cancel_leave_id').value = leaveId;
    document.getElementById('modal_cancel_leave_type').textContent = leaveType;
    document.getElementById('modal_cancel_start_date').textContent = startDate;
    document.getElementById('modal_cancel_end_date').textContent = endDate;
}

// Handle cancel leave form submission
document.getElementById('cancelLeaveModalForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Disable submit button to prevent double submission
    const submitBtn = document.getElementById('confirmCancelBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Cancelling...';
    
    // Submit the form
    this.submit();
});

// Cancel leave function (legacy - kept for backward compatibility)
function cancelLeave(leaveId) {
    if (confirm('Are you sure you want to cancel this leave request?\n\nThis action cannot be undone.')) {
        document.getElementById('cancel_leave_id').value = leaveId;
        document.getElementById('cancelLeaveForm').submit();
    }
}

// Show toast notification if there are new leave updates
<?php if (isset($_SESSION['leave_notification'])): ?>
    window.LeaveNotifications.show(
        '<?php echo $_SESSION['leave_notification']['type']; ?>',
        '<?php echo $_SESSION['leave_notification']['title']; ?>',
        '<?php echo $_SESSION['leave_notification']['message']; ?>'
    );
    <?php unset($_SESSION['leave_notification']); ?>
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>