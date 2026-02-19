<?php
/**
 * Attendance Time Log
 * modules/attendance/time-log.php
 */

session_start();
require_once '../../config/db.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$page_title = 'Attendance Time Log';
$current_user_id = getCurrentUserId();
$user_role = getCurrentUserRole();
$can_view_all = in_array($user_role, ['Admin', 'Super Admin', 'Staff']);

// Get filter parameters
$view_user_id = $can_view_all && isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user_id;
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month

// Get user info
$user_info = fetchOne($conn,
    "SELECT u.*, CONCAT(r.first_name, ' ', r.last_name) as full_name
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?",
    [$view_user_id], 'i'
);

if (!$user_info) {
    redirect('index.php', 'User not found', 'error');
}

// Get attendance records
$attendance_records = fetchAll($conn,
    "SELECT * FROM tbl_attendance 
    WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
    ORDER BY attendance_date DESC",
    [$view_user_id, $start_date, $end_date], 'iss'
);

// Calculate statistics
$total_days = count($attendance_records);
$present_days = 0;
$late_days = 0;
$absent_days = 0;
$leave_days = 0;
$total_hours = 0;

foreach ($attendance_records as $record) {
    switch ($record['status']) {
        case 'Present': $present_days++; break;
        case 'Late': $late_days++; break;
        case 'Absent': $absent_days++; break;
        case 'On Leave': $leave_days++; break;
    }
    
    if ($record['time_in'] && $record['time_out']) {
        $time_in = strtotime($record['time_in']);
        $time_out = strtotime($record['time_out']);
        $hours = ($time_out - $time_in) / 3600;
        $total_hours += $hours;
    }
}

// Get all users for dropdown (if admin)
$all_users = [];
if ($can_view_all) {
    $all_users = fetchAll($conn,
        "SELECT u.user_id, u.username, u.role, CONCAT(r.first_name, ' ', r.last_name) as full_name
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
        ORDER BY full_name"
    );
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-history text-primary me-2"></i>
                Attendance Time Log
            </h1>
            <p class="text-muted mb-0">
                Viewing records for: <strong><?php echo htmlspecialchars($user_info['full_name'] ?? $user_info['username']); ?></strong>
            </p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-primary">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <?php if ($can_view_all): ?>
                    <div class="col-md-3">
                        <label for="user_id" class="form-label">Staff/Tanod</label>
                        <select class="form-select" id="user_id" name="user_id">
                            <?php foreach ($all_users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>" 
                                        <?php echo $user['user_id'] == $view_user_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?> 
                                    (<?php echo $user['role']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" 
                           value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-primary fs-1 mb-2">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <h3 class="mb-0"><?php echo $total_days; ?></h3>
                    <small class="text-muted">Total Days</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-success fs-1 mb-2">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="mb-0"><?php echo $present_days; ?></h3>
                    <small class="text-muted">Present</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-warning fs-1 mb-2">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="mb-0"><?php echo $late_days; ?></h3>
                    <small class="text-muted">Late</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-danger fs-1 mb-2">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <h3 class="mb-0"><?php echo $absent_days; ?></h3>
                    <small class="text-muted">Absent</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-info fs-1 mb-2">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <h3 class="mb-0"><?php echo $leave_days; ?></h3>
                    <small class="text-muted">On Leave</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-secondary fs-1 mb-2">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <h3 class="mb-0"><?php echo number_format($total_hours, 1); ?></h3>
                    <small class="text-muted">Total Hours</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Records Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-list text-primary me-2"></i>
                Attendance Records
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Hours Worked</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendance_records)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <p class="mb-0">No attendance records found for the selected period</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attendance_records as $record): 
                                $hours_worked = 0;
                                if ($record['time_in'] && $record['time_out']) {
                                    $time_in = strtotime($record['time_in']);
                                    $time_out = strtotime($record['time_out']);
                                    $hours_worked = ($time_out - $time_in) / 3600;
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo formatDate($record['attendance_date']); ?></strong></td>
                                    <td><?php echo date('l', strtotime($record['attendance_date'])); ?></td>
                                    <td>
                                        <?php if ($record['time_in']): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success">
                                                <?php echo date('h:i A', strtotime($record['time_in'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['time_out']): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger">
                                                <?php echo date('h:i A', strtotime($record['time_out'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($hours_worked > 0): ?>
                                            <strong><?php echo number_format($hours_worked, 2); ?> hrs</strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo getStatusBadge($record['status']); ?></td>
                                    <td>
                                        <?php if ($record['notes']): ?>
                                            <small class="text-muted" title="<?php echo htmlspecialchars($record['notes']); ?>">
                                                <?php echo substr($record['notes'], 0, 30) . (strlen($record['notes']) > 30 ? '...' : ''); ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($attendance_records)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Total:</th>
                                <th><strong><?php echo number_format($total_hours, 2); ?> hrs</strong></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, nav, .sidebar, .header { display: none !important; }
    .main-content { margin: 0 !important; padding: 20px !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>

<?php include '../../includes/footer.php'; ?>