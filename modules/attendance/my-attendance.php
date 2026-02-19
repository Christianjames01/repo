<?php
/**
 * Staff My Attendance History View
 * modules/attendance/my-attendance-history.php
 * Allows staff to view their own attendance history
 */

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /barangaylink/modules/auth/login.php');
    exit();
}

$page_title = 'My Attendance';
$current_user_id = getCurrentUserId();

// Get filter parameters
$month = isset($_GET['month']) ? sanitizeInput($_GET['month']) : date('m');
$year = isset($_GET['year']) ? sanitizeInput($_GET['year']) : date('Y');
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

// Build query with filters
$where_conditions = ["user_id = ?"];
$params = [$current_user_id];
$types = 'i';

// Month and Year filter
if ($month && $year) {
    $where_conditions[] = "MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?";
    $params[] = $month;
    $params[] = $year;
    $types .= 'ii';
}

// Status filter
if ($status_filter) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_sql = implode(' AND ', $where_conditions);

// Get attendance records
$attendance_records = fetchAll($conn,
    "SELECT * FROM tbl_attendance 
    WHERE $where_sql
    ORDER BY attendance_date DESC, time_in DESC",
    $params, $types
);

// Calculate statistics for the filtered period
$total_present = 0;
$total_late = 0;
$total_absent = 0;
$total_on_leave = 0;
$total_hours = 0;

foreach ($attendance_records as $record) {
    switch ($record['status']) {
        case 'Present':
            $total_present++;
            break;
        case 'Late':
            $total_late++;
            break;
        case 'Absent':
            $total_absent++;
            break;
        case 'On Leave':
            $total_on_leave++;
            break;
    }
    
    // Calculate total hours worked
    if ($record['time_in'] && $record['time_out']) {
        $time_in = strtotime($record['time_in']);
        $time_out = strtotime($record['time_out']);
        $diff = ($time_out - $time_in) / 3600;
        if ($diff < 0) $diff += 24;
        $total_hours += $diff;
    }
}

// Get user info
$user_info = fetchOne($conn,
    "SELECT u.*, CONCAT(r.first_name, ' ', r.last_name) as full_name
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?",
    [$current_user_id], 'i'
);

// Helper function to format time
function formatTimeDisplay($time_string) {
    if (empty($time_string) || $time_string == '00:00:00') return 'N/A';
    
    try {
        $time = new DateTime($time_string);
        return $time->format('g:i A');
    } catch (Exception $e) {
        return 'N/A';
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-clipboard-list text-primary me-2"></i>
                My Attendance History
            </h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($user_info['full_name']); ?> 
                <span class="badge bg-info"><?php echo htmlspecialchars($user_info['role'] ?? 'Staff'); ?></span>
            </p>
        </div>
        <div>
            <a href="my-schedule.php" class="btn btn-outline-primary">
                <i class="fas fa-calendar-week me-1"></i> My Schedule
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Present Days</h6>
                            <h2 class="mb-0 text-success"><?php echo $total_present; ?></h2>
                        </div>
                        <div class="fs-1 text-success opacity-25">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Late Arrivals</h6>
                            <h2 class="mb-0 text-warning"><?php echo $total_late; ?></h2>
                        </div>
                        <div class="fs-1 text-warning opacity-25">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Absent Days</h6>
                            <h2 class="mb-0 text-danger"><?php echo $total_absent; ?></h2>
                        </div>
                        <div class="fs-1 text-danger opacity-25">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Total Hours</h6>
                            <h2 class="mb-0 text-primary"><?php echo number_format($total_hours, 1); ?></h2>
                        </div>
                        <div class="fs-1 text-primary opacity-25">
                            <i class="fas fa-business-time"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter and Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">
                        <i class="fas fa-filter text-primary me-2"></i>
                        Attendance Records
                    </h5>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </div>

            <!-- Filter Form -->
            <div class="collapse mt-3" id="filterCollapse">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" 
                                    <?php echo ($month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <?php 
                            $current_year = date('Y');
                            for ($y = $current_year; $y >= $current_year - 5; $y--): 
                            ?>
                                <option value="<?php echo $y; ?>" <?php echo ($year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Present" <?php echo ($status_filter == 'Present') ? 'selected' : ''; ?>>Present</option>
                            <option value="Late" <?php echo ($status_filter == 'Late') ? 'selected' : ''; ?>>Late</option>
                            <option value="Absent" <?php echo ($status_filter == 'Absent') ? 'selected' : ''; ?>>Absent</option>
                            <option value="On Leave" <?php echo ($status_filter == 'On Leave') ? 'selected' : ''; ?>>On Leave</option>
                            <option value="Half Day" <?php echo ($status_filter == 'Half Day') ? 'selected' : ''; ?>>Half Day</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i> Apply Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card-body">
            <?php if (!empty($attendance_records)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
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
                            <?php foreach ($attendance_records as $record): ?>
                                <?php
                                $hours_worked = 'N/A';
                                if ($record['time_in'] && $record['time_out']) {
                                    $time_in = strtotime($record['time_in']);
                                    $time_out = strtotime($record['time_out']);
                                    $diff = ($time_out - $time_in) / 3600;
                                    if ($diff < 0) $diff += 24;
                                    $hours_worked = number_format($diff, 1) . ' hrs';
                                }
                                $day_name = date('l', strtotime($record['attendance_date']));
                                $is_today = ($record['attendance_date'] == date('Y-m-d'));
                                ?>
                                <tr <?php echo $is_today ? 'class="table-primary"' : ''; ?>>
                                    <td>
                                        <strong><?php echo formatDate($record['attendance_date']); ?></strong>
                                        <?php if ($is_today): ?>
                                            <span class="badge bg-primary ms-2">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $day_name; ?></td>
                                    <td>
                                        <?php if ($record['time_in'] && $record['time_in'] != '00:00:00'): ?>
                                            <span class="text-success">
                                                <i class="fas fa-sign-in-alt me-1"></i>
                                                <?php echo formatTimeDisplay($record['time_in']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['time_out'] && $record['time_out'] != '00:00:00'): ?>
                                            <span class="text-danger">
                                                <i class="fas fa-sign-out-alt me-1"></i>
                                                <?php echo formatTimeDisplay($record['time_out']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($hours_worked != 'N/A'): ?>
                                            <span class="badge bg-info"><?php echo $hours_worked; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo getStatusBadge($record['status']); ?></td>
                                    <td>
                                        <?php if (!empty($record['notes'])): ?>
                                            <small class="text-muted" data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($record['notes']); ?>">
                                                <?php echo substr($record['notes'], 0, 30) . (strlen($record['notes']) > 30 ? '...' : ''); ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Attendance Records Found</h5>
                    <p class="text-muted">No attendance records match your filter criteria.</p>
                    <?php if ($month || $status_filter): ?>
                        <a href="my-attendance.php" class="btn btn-primary mt-3">
                            <i class="fas fa-redo me-1"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($attendance_records)): ?>
        <div class="card-footer bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Showing <?php echo count($attendance_records); ?> record(s) for 
                    <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                </small>
                <div>
                    <small class="text-muted">
                        <strong>Summary:</strong> 
                        <span class="text-success"><?php echo $total_present; ?> Present</span> | 
                        <span class="text-warning"><?php echo $total_late; ?> Late</span> | 
                        <span class="text-danger"><?php echo $total_absent; ?> Absent</span>
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Initialize Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>