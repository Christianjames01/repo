<?php
/**
 * Admin/Super Admin Manage Leave Requests (WITH MODALS)
 * modules/attendance/admin/manage-leaves.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has permission
if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Manage Leave Requests';
$current_user_id = getCurrentUserId();

// ====================================================
// HANDLE LEAVE REQUEST FROM MODAL (ADMIN CAN ALSO REQUEST LEAVE)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave_modal'])) {
    $leave_type = sanitizeInput($_POST['leave_type']);
    $start_date = sanitizeInput($_POST['start_date']);
    $end_date = sanitizeInput($_POST['end_date']);
    $reason = sanitizeInput($_POST['reason']);
    
    $errors = [];
    
    // Validate dates
    if (strtotime($start_date) > strtotime($end_date)) {
        $errors[] = 'End date must be after start date';
    }
    
    if (strtotime($start_date) < strtotime(date('Y-m-d'))) {
        $errors[] = 'Start date cannot be in the past';
    }
    
    // Validate reason length
    if (strlen($reason) < 10) {
        $errors[] = 'Reason must be at least 10 characters long';
    }
    
    if (empty($errors)) {
        // Check for overlapping leave requests
        $overlap = fetchOne($conn,
            "SELECT leave_id FROM tbl_leave_requests 
            WHERE user_id = ? 
            AND status IN ('Pending', 'Approved')
            AND (
                (start_date <= ? AND end_date >= ?) OR
                (start_date <= ? AND end_date >= ?) OR
                (start_date >= ? AND end_date <= ?)
            )",
            [$current_user_id, $start_date, $start_date, $end_date, $end_date, $start_date, $end_date],
            'issssss'
        );
        
        if ($overlap) {
            $_SESSION['error_message'] = 'You already have a leave request for these dates';
        } else {
            // Insert leave request
            $sql = "INSERT INTO tbl_leave_requests (user_id, leave_type, start_date, end_date, reason, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'Pending', NOW())";
            
            if (executeQuery($conn, $sql, [$current_user_id, $leave_type, $start_date, $end_date, $reason], 'issss')) {
                $leave_id = $conn->insert_id;
                
                // Log activity
                logActivity($conn, $current_user_id, 'Submitted leave request', 'tbl_leave_requests', $leave_id);
                
                // Notify other admins
                $admins = fetchAll($conn, 
                    "SELECT user_id FROM tbl_users WHERE role IN ('Admin', 'Super Admin') AND user_id != ?",
                    [$current_user_id], 'i'
                );
                
                if ($admins) {
                    $user_name = getUserFullName($conn, $current_user_id);
                    foreach ($admins as $admin) {
                        createNotification(
                            $conn,
                            $admin['user_id'],
                            'New Leave Request',
                            "{$user_name} submitted a {$leave_type} request from {$start_date} to {$end_date}",
                            'leave_request',
                            $leave_id,
                            'leave'
                        );
                    }
                }
                
                $_SESSION['success_message'] = 'Leave request submitted successfully! You will be notified once it is processed.';
                header("Location: manage-leaves.php");
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to submit leave request. Please try again.';
            }
        }
    } else {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
}

// ====================================================
// HANDLE LEAVE APPROVAL/REJECTION
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['leave_id'])) {
    $leave_id = intval($_POST['leave_id']);
    $action = $_POST['action'];
    $admin_notes = sanitizeInput($_POST['admin_notes'] ?? '');
    
    if (in_array($action, ['approve', 'reject'])) {
        $status = ($action === 'approve') ? 'Approved' : 'Rejected';
        
        $sql = "UPDATE tbl_leave_requests 
                SET status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() 
                WHERE leave_id = ?";
        
        if (executeQuery($conn, $sql, [$status, $admin_notes, $current_user_id, $leave_id], 'ssii')) {
            // Get leave details for notification
            $leave = fetchOne($conn, 
                "SELECT lr.*, u.user_id, u.username
                FROM tbl_leave_requests lr
                LEFT JOIN tbl_users u ON lr.user_id = u.user_id
                WHERE lr.leave_id = ?",
                [$leave_id], 'i'
            );
            
            if ($leave) {
                // Notify the requester
                createNotification(
                    $conn,
                    $leave['user_id'],
                    "Leave Request $status",
                    "Your {$leave['leave_type']} request from {$leave['start_date']} to {$leave['end_date']} has been $status." . 
                    ($admin_notes ? " Notes: $admin_notes" : ""),
                    'leave_' . strtolower($status),
                    $leave_id,
                    'leave'
                );
                
                // If approved, create attendance records
                if ($status === 'Approved') {
                    $start = new DateTime($leave['start_date']);
                    $end = new DateTime($leave['end_date']);
                    $end->modify('+1 day');
                    
                    $period = new DatePeriod($start, new DateInterval('P1D'), $end);
                    foreach ($period as $date) {
                        $attendance_date = $date->format('Y-m-d');
                        
                        $existing = fetchOne($conn,
                            "SELECT attendance_id FROM tbl_attendance 
                            WHERE user_id = ? AND attendance_date = ?",
                            [$leave['user_id'], $attendance_date], 'is'
                        );
                        
                        if (!$existing) {
                            executeQuery($conn,
                                "INSERT INTO tbl_attendance (user_id, attendance_date, status, notes, created_by) 
                                VALUES (?, ?, 'On Leave', ?, ?)",
                                [$leave['user_id'], $attendance_date, $leave['leave_type'], $current_user_id], 'issi'
                            );
                        }
                    }
                }
            }
            
            logActivity($conn, $current_user_id, "{$status} leave request", 'tbl_leave_requests', $leave_id);
            $_SESSION['success_message'] = "Leave request has been $status successfully";
        } else {
            $_SESSION['error_message'] = "Failed to update leave request";
        }
        
        header("Location: manage-leaves.php");
        exit();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$user_filter = $_GET['user_id'] ?? 'all';

// Build query
$sql = "SELECT 
    lr.leave_id, 
    lr.user_id, 
    lr.leave_type, 
    lr.start_date, 
    lr.end_date, 
    lr.reason, 
    lr.status, 
    lr.admin_notes, 
    lr.created_at, 
    lr.processed_at, 
    lr.processed_by,
    u.username, 
    u.role,
    COALESCE(NULLIF(CONCAT(TRIM(COALESCE(r.first_name, '')), ' ', TRIM(COALESCE(r.last_name, ''))), ' '), u.username) as requester_name,
    COALESCE(NULLIF(CONCAT(TRIM(COALESCE(pr.first_name, '')), ' ', TRIM(COALESCE(pr.last_name, ''))), ' '), pu.username) as processor_name
FROM tbl_leave_requests lr
INNER JOIN tbl_users u ON lr.user_id = u.user_id
LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
LEFT JOIN tbl_users pu ON lr.processed_by = pu.user_id
LEFT JOIN tbl_residents pr ON pu.resident_id = pr.resident_id
WHERE 1=1";

$params = [];
$types = '';

if ($status_filter !== 'all') {
    $sql .= " AND lr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($user_filter !== 'all') {
    $sql .= " AND lr.user_id = ?";
    $params[] = intval($user_filter);
    $types .= 'i';
}

$sql .= " ORDER BY 
    CASE 
        WHEN lr.status = 'Pending' THEN 1 
        WHEN lr.status = 'Approved' THEN 2 
        WHEN lr.status = 'Rejected' THEN 3 
        ELSE 4 
    END,
    lr.created_at DESC";

$leaves = [];
try {
    if (!empty($params)) {
        $leaves = fetchAll($conn, $sql, $params, $types);
    } else {
        $leaves = fetchAll($conn, $sql);
    }
} catch (Exception $e) {
    error_log("Leave query error: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading leave requests: " . $e->getMessage();
}

// Calculate statistics - UPDATED to include cancelled
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0,
    'total' => count($leaves)
];

foreach ($leaves as $leave) {
    switch ($leave['status']) {
        case 'Pending':
            $stats['pending']++;
            break;
        case 'Approved':
            $stats['approved']++;
            break;
        case 'Rejected':
            $stats['rejected']++;
            break;
        case 'Cancelled':
            $stats['cancelled']++;
            break;
    }
}

// Get all users for filter
$all_users = fetchAll($conn,
    "SELECT u.user_id, 
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(r.first_name, '')), ' ', TRIM(COALESCE(r.last_name, ''))), ' '), u.username) as full_name,
            u.role
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.role IN ('Admin', 'Super Admin', 'Staff', 'Tanod', 'Driver')
    ORDER BY full_name"
);

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-check text-primary me-2"></i>
                Manage Leave Requests
            </h1>
            <p class="text-muted mb-0">Review and process all leave requests</p>
        </div>
        <div class="d-flex gap-2">
            <a href="../admin/index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Attendance
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards - UPDATED with Cancelled card -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Pending</h6>
                            <h2 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h2>
                        </div>
                        <div class="fs-1 text-warning opacity-50">
                            <i class="fas fa-clock"></i>
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
                            <h6 class="text-muted mb-1">Approved</h6>
                            <h2 class="mb-0 text-success"><?php echo $stats['approved']; ?></h2>
                        </div>
                        <div class="fs-1 text-success opacity-50">
                            <i class="fas fa-check-circle"></i>
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
                            <h6 class="text-muted mb-1">Rejected</h6>
                            <h2 class="mb-0 text-danger"><?php echo $stats['rejected']; ?></h2>
                        </div>
                        <div class="fs-1 text-danger opacity-50">
                            <i class="fas fa-times-circle"></i>
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
                            <h6 class="text-muted mb-1">Cancelled</h6>
                            <h2 class="mb-0 text-secondary"><?php echo $stats['cancelled']; ?></h2>
                        </div>
                        <div class="fs-1 text-secondary opacity-50">
                            <i class="fas fa-ban"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="user_id" class="form-label">Staff Member</label>
                    <select class="form-select" id="user_id" name="user_id" onchange="this.form.submit()">
                        <option value="all">All Staff</option>
                        <?php foreach ($all_users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>" <?php echo $user_filter == $user['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['role']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="manage-leaves.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Leave Requests Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Requester</th>
                            <th>Role</th>
                            <th>Leave Type</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th width="150">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leaves)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <p class="mb-0">No leave requests found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leaves as $leave): 
                                $duration = (strtotime($leave['end_date']) - strtotime($leave['start_date'])) / 86400 + 1;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($leave['requester_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                            <?php echo htmlspecialchars($leave['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($leave['leave_type']); ?></span>
                                    </td>
                                    <td><?php echo formatDate($leave['start_date']); ?></td>
                                    <td><?php echo formatDate($leave['end_date']); ?></td>
                                    <td><?php echo $duration; ?> day(s)</td>
                                    <td><?php echo getStatusBadge($leave['status']); ?></td>
                                    <td><small><?php echo formatDate($leave['created_at'], 'M d, Y h:i A'); ?></small></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewLeaveModal"
                                                    onclick='viewLeave(<?php echo json_encode($leave); ?>)'
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($leave['status'] === 'Pending'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#processLeaveModal"
                                                        onclick='processLeave(<?php echo json_encode($leave); ?>, "approve")'
                                                        title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#processLeaveModal"
                                                        onclick='processLeave(<?php echo json_encode($leave); ?>, "reject")'
                                                        title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Leave Modal -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-labelledby="viewLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary bg-opacity-10">
                <h5 class="modal-title" id="viewLeaveModalLabel">
                    <i class="fas fa-file-alt me-2"></i>
                    Leave Request Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="leaveDetailsContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Process Leave Modal (Approve/Reject) -->
<div class="modal fade" id="processLeaveModal" tabindex="-1" aria-labelledby="processLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="processLeaveForm">
                <div class="modal-header" id="processModalHeader">
                    <h5 class="modal-title" id="processModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="leave_id" id="processLeaveId">
                    <input type="hidden" name="action" id="processAction">
                    
                    <!-- Leave Summary -->
                    <div class="alert alert-light border mb-3">
                        <div class="row g-2">
                            <div class="col-12">
                                <strong><i class="fas fa-user me-2"></i>Staff:</strong>
                                <span id="processRequesterName"></span>
                            </div>
                            <div class="col-6">
                                <strong><i class="fas fa-tag me-2"></i>Type:</strong>
                                <span id="processLeaveType"></span>
                            </div>
                            <div class="col-6">
                                <strong><i class="fas fa-clock me-2"></i>Duration:</strong>
                                <span id="processDuration"></span>
                            </div>
                            <div class="col-12">
                                <strong><i class="fas fa-calendar me-2"></i>Period:</strong>
                                <span id="processPeriod"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert" id="processInfo">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="processInfoText"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="admin_notes" class="form-label fw-bold">
                            <i class="fas fa-sticky-note me-1"></i>
                            Admin Notes <span id="notesRequired" class="text-danger" style="display:none;">*</span>
                        </label>
                        <textarea class="form-control" id="admin_notes" name="admin_notes" rows="4" 
                                  placeholder="Add notes or comments about this decision..."></textarea>
                        <small class="text-muted">This note will be visible to the staff member</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn" id="processSubmitBtn">
                        <i class="fas fa-check me-1"></i>
                        <span id="processSubmitText">Confirm</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request Leave Modal (Admin can also request) -->
<div class="modal fade" id="leaveRequestModal" tabindex="-1" aria-labelledby="leaveRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gradient text-white">
                <h5 class="modal-title" id="leaveRequestModalLabel">
                    <i class="fas fa-calendar-plus me-2"></i>
                    Submit Leave Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="leaveRequestModalForm" method="POST">
                <div class="modal-body">
                    <div id="modalAlertContainer"></div>

                    <div class="mb-3">
                        <label for="modal_leave_type" class="form-label fw-bold">
                            <i class="fas fa-tag me-1 text-primary"></i>
                            Leave Type <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="modal_leave_type" name="leave_type" required>
                            <option value="">Select leave type...</option>
                            <option value="Sick Leave">Sick Leave</option>
                            <option value="Vacation Leave">Vacation Leave</option>
                            <option value="Emergency Leave">Emergency Leave</option>
                            <option value="Personal Leave">Personal Leave</option>
                            <option value="Bereavement Leave">Bereavement Leave</option>
                            <option value="Maternity Leave">Maternity Leave</option>
                            <option value="Paternity Leave">Paternity Leave</option>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="modal_start_date" class="form-label fw-bold">
                                <i class="fas fa-calendar-day me-1 text-success"></i>
                                Start Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="modal_start_date" name="start_date" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_end_date" class="form-label fw-bold">
                                <i class="fas fa-calendar-check me-1 text-danger"></i>
                                End Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="modal_end_date" name="end_date" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="alert alert-info d-none" id="modal_duration_display">
                            <i class="fas fa-clock me-2"></i>
                            <strong>Duration:</strong> <span id="modal_duration_text">0 day(s)</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="modal_reason" class="form-label fw-bold">
                            <i class="fas fa-comment me-1 text-warning"></i>
                            Reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="modal_reason" name="reason" rows="4" required
                                  placeholder="Please provide detailed reason for your leave request..."
                                  minlength="10"></textarea>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Be specific and provide all necessary details (minimum 10 characters)
                        </small>
                    </div>

                    <div class="alert alert-warning mb-0">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
                            <div><strong>Important Reminders:</strong>
                                <ul class="mb-0 mt-2 small">
                                    <li>Submit your leave request at least 3 days in advance</li>
                                    <li>For emergencies, contact your supervisor immediately</li>
                                    <li>You will be notified once your request is processed</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="submit_leave_modal" value="1">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="modalSubmitBtn">
                        <i class="fas fa-paper-plane me-1"></i>
                        Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.bg-gradient {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
</style>

<script>
// ====================================================
// VIEW LEAVE MODAL
// ====================================================
function viewLeave(leave) {
    const duration = Math.ceil((new Date(leave.end_date) - new Date(leave.start_date)) / (1000 * 60 * 60 * 24)) + 1;
    
    const statusColors = {
        'Pending': 'warning',
        'Approved': 'success',
        'Rejected': 'danger',
        'Cancelled': 'secondary'
    };
    
    const statusColor = statusColors[leave.status] || 'secondary';
    
    let html = `
        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-user me-2"></i>Requester</small>
                    <strong class="fs-6">${leave.requester_name}</strong>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-user-tag me-2"></i>Role</small>
                    <span class="badge bg-secondary">${leave.role}</span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-tag me-2"></i>Leave Type</small>
                    <span class="badge bg-primary fs-6">${leave.leave_type}</span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-traffic-light me-2"></i>Status</small>
                    <span class="badge bg-${statusColor} fs-6">${leave.status}</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-calendar-day me-2"></i>Start Date</small>
                    <strong>${formatDateLong(leave.start_date)}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-calendar-check me-2"></i>End Date</small>
                    <strong>${formatDateLong(leave.end_date)}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1"><i class="fas fa-clock me-2"></i>Duration</small>
                    <span class="badge bg-info fs-6">${duration} day(s)</span>
                </div>
            </div>
            <div class="col-12">
                <div class="p-3 bg-light rounded">
                    <small class="text-muted d-block mb-2"><i class="fas fa-comment-alt me-2"></i><strong>Reason</strong></small>
                    <p class="mb-0">${leave.reason}</p>
                </div>
            </div>
    `;
    
    if (leave.admin_notes && leave.admin_notes.trim() !== '') {
        html += `
            <div class="col-12">
                <div class="p-3 bg-warning bg-opacity-10 border border-warning rounded">
                    <small class="text-warning d-block mb-2"><i class="fas fa-sticky-note me-2"></i><strong>Admin Notes</strong></small>
                    <p class="mb-0">${leave.admin_notes}</p>
                </div>
            </div>
        `;
    }
    
    if (leave.processor_name && leave.processed_at) {
        html += `
            <div class="col-12">
                <hr class="my-2">
                <small class="text-muted">
                    <i class="fas fa-user-shield me-1"></i><strong>Processed By:</strong> ${leave.processor_name}<br>
                    <i class="fas fa-calendar me-1"></i><strong>Processed On:</strong> ${formatDateTimeLong(leave.processed_at)}
                </small>
            </div>
        `;
    }
    
    html += `
            <div class="col-12">
                <hr class="my-2">
                <small class="text-muted">
                    <i class="fas fa-clock me-1"></i><strong>Submitted on:</strong> ${formatDateTimeLong(leave.created_at)}
                </small>
            </div>
        </div>
    `;
    
    document.getElementById('leaveDetailsContent').innerHTML = html;
}

// ====================================================
// PROCESS LEAVE MODAL (APPROVE/REJECT)
// ====================================================
function processLeave(leave, action) {
    const duration = Math.ceil((new Date(leave.end_date) - new Date(leave.start_date)) / (1000 * 60 * 60 * 24)) + 1;
    
    document.getElementById('processLeaveId').value = leave.leave_id;
    document.getElementById('processAction').value = action;
    document.getElementById('admin_notes').value = '';
    
    // Populate leave summary
    document.getElementById('processRequesterName').textContent = leave.requester_name;
    document.getElementById('processLeaveType').textContent = leave.leave_type;
    document.getElementById('processDuration').textContent = duration + ' day(s)';
    document.getElementById('processPeriod').textContent = formatDateLong(leave.start_date) + ' - ' + formatDateLong(leave.end_date);
    
    if (action === 'approve') {
        document.getElementById('processModalHeader').className = 'modal-header bg-success bg-opacity-10';
        document.getElementById('processModalTitle').innerHTML = '<i class="fas fa-check-circle me-2"></i>Approve Leave Request';
        document.getElementById('processSubmitBtn').className = 'btn btn-success';
        document.getElementById('processSubmitText').textContent = 'Approve Leave';
        document.getElementById('processInfo').className = 'alert alert-success';
        document.getElementById('processInfoText').textContent = 'Approving this leave will automatically create "On Leave" attendance records for the specified dates.';
        document.getElementById('notesRequired').style.display = 'none';
        document.getElementById('admin_notes').required = false;
    } else {
        document.getElementById('processModalHeader').className = 'modal-header bg-danger bg-opacity-10';
        document.getElementById('processModalTitle').innerHTML = '<i class="fas fa-times-circle me-2"></i>Reject Leave Request';
        document.getElementById('processSubmitBtn').className = 'btn btn-danger';
        document.getElementById('processSubmitText').textContent = 'Reject Leave';
        document.getElementById('processInfo').className = 'alert alert-danger';
        document.getElementById('processInfoText').textContent = 'Please provide a clear reason for rejecting this leave request.';
        document.getElementById('notesRequired').style.display = 'inline';
        document.getElementById('admin_notes').required = true;
    }
}

// Form validation for processing leave
document.getElementById('processLeaveForm').addEventListener('submit', function(e) {
    const action = document.getElementById('processAction').value;
    const notes = document.getElementById('admin_notes').value.trim();
    
    if (action === 'reject' && notes === '') {
        e.preventDefault();
        alert('Please provide a reason for rejecting this leave request.');
        document.getElementById('admin_notes').focus();
        return false;
    }
    
    const confirmMsg = action === 'approve' 
        ? 'Are you sure you want to APPROVE this leave request?' 
        : 'Are you sure you want to REJECT this leave request?';
    
    if (!confirm(confirmMsg)) {
        e.preventDefault();
        return false;
    }
    
    // Disable submit button
    const submitBtn = document.getElementById('processSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
    
    return true;
});

// ====================================================
// LEAVE REQUEST MODAL FUNCTIONS
// ====================================================

// Calculate duration for modal dates
function calculateModalDuration() {
    const startDate = document.getElementById('modal_start_date').value;
    const endDate = document.getElementById('modal_end_date').value;
    const durationDisplay = document.getElementById('modal_duration_display');
    const durationText = document.getElementById('modal_duration_text');
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = end - start;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        
        if (diffDays > 0) {
            durationText.textContent = diffDays + ' day(s)';
            durationDisplay.classList.remove('d-none');
            
            if (diffDays > 15) {
                durationDisplay.className = 'alert alert-warning';
                durationText.innerHTML = diffDays + ' day(s) <small class="text-muted">(Long duration - may require additional approval)</small>';
            } else {
                durationDisplay.className = 'alert alert-info';
            }
        } else {
            durationDisplay.classList.add('d-none');
        }
    } else {
        durationDisplay.classList.add('d-none');
    }
}

document.getElementById('modal_start_date').addEventListener('change', function() {
    document.getElementById('modal_end_date').min = this.value;
    calculateModalDuration();
});

document.getElementById('modal_end_date').addEventListener('change', calculateModalDuration);

// Form validation before submission
document.getElementById('leaveRequestModalForm').addEventListener('submit', function(e) {
    const startDate = document.getElementById('modal_start_date').value;
    const endDate = document.getElementById('modal_end_date').value;
    const reason = document.getElementById('modal_reason').value.trim();
    const leaveType = document.getElementById('modal_leave_type').value;
    
    document.getElementById('modalAlertContainer').innerHTML = '';
    
    if (new Date(startDate) > new Date(endDate)) {
        e.preventDefault();
        showModalAlert('End date must be after or equal to start date', 'danger');
        return false;
    }
    
    if (reason.length < 10) {
        e.preventDefault();
        showModalAlert('Please provide a more detailed reason (at least 10 characters)', 'danger');
        document.getElementById('modal_reason').focus();
        return false;
    }
    
    const duration = Math.ceil((new Date(endDate) - new Date(startDate)) / (1000 * 60 * 60 * 24)) + 1;
    
    const confirmMsg = `Are you sure you want to submit this leave request?\n\nType: ${leaveType}\nDuration: ${duration} day(s)\nFrom: ${startDate} to ${endDate}`;
    
    if (!confirm(confirmMsg)) {
        e.preventDefault();
        return false;
    }
    
    const submitBtn = document.getElementById('modalSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting...';
    
    return true;
});

function showModalAlert(message, type) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    document.getElementById('modalAlertContainer').innerHTML = alertHtml;
}

// Reset form when modal is closed
document.getElementById('leaveRequestModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('leaveRequestModalForm').reset();
    document.getElementById('modal_duration_display').classList.add('d-none');
    document.getElementById('modalAlertContainer').innerHTML = '';
    
    const submitBtn = document.getElementById('modalSubmitBtn');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Submit Request';
});

// ====================================================
// UTILITY FUNCTIONS
// ====================================================
function formatDateLong(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatDateTimeLong(dateTimeString) {
    const date = new Date(dateTimeString);
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
}
</script>

<?php include '../../../includes/footer.php'; ?>