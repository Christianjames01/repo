<?php
/**
 * Staff Special Schedules View
 * modules/attendance/special-schedules.php
 * Allows staff to view special schedules that apply to them
 */

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

$page_title = 'Special Schedules';
$current_user_id = getCurrentUserId();

// Get all special schedules from the system that the staff is assigned to
$my_special_schedules = fetchAll($conn,
    "SELECT ss.*, 
            CONCAT(cr.first_name, ' ', cr.last_name) as created_by_name,
            ssa.created_at as assigned_at
     FROM tbl_special_schedules ss
     INNER JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id = ssa.schedule_id
     LEFT JOIN tbl_users cu ON ss.created_by = cu.user_id
     LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
     WHERE ssa.user_id = ?
     ORDER BY ss.schedule_date DESC",
    [$current_user_id], 'i'
);

// Get user info
$user_info = fetchOne($conn,
    "SELECT u.*, CONCAT(r.first_name, ' ', r.last_name) as full_name
     FROM tbl_users u
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
     WHERE u.user_id = ?",
    [$current_user_id], 'i'
);

// Categorize schedules
$upcoming_schedules = [];
$today_schedules = [];
$past_schedules = [];

$today = date('Y-m-d');

foreach ($my_special_schedules as $schedule) {
    if ($schedule['schedule_date'] > $today) {
        $upcoming_schedules[] = $schedule;
    } elseif ($schedule['schedule_date'] == $today) {
        $today_schedules[] = $schedule;
    } else {
        $past_schedules[] = $schedule;
    }
}

// Helper function to get icon for schedule type
function getScheduleTypeIcon($type) {
    $icons = [
        'Holiday' => '<i class="fas fa-calendar-times text-danger"></i>',
        'Special Event' => '<i class="fas fa-star text-success"></i>',
        'Emergency' => '<i class="fas fa-exclamation-triangle text-warning"></i>',
        'Custom' => '<i class="fas fa-cog text-info"></i>'
    ];
    return $icons[$type] ?? '<i class="fas fa-calendar-alt text-secondary"></i>';
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-alt text-warning me-2"></i>
                My Special Schedules
            </h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($user_info['full_name']); ?> 
                <span class="badge bg-info"><?php echo htmlspecialchars($user_info['role'] ?? 'Staff'); ?></span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="my-schedule.php" class="btn btn-outline-primary">
                <i class="fas fa-calendar-week me-1"></i> My Schedule
            </a>
            <a href="my-attendance.php" class="btn btn-outline-success">
                <i class="fas fa-clipboard-list me-1"></i> My Attendance
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Today's Special Schedules</h6>
                            <h3 class="mb-0 text-primary"><?php echo count($today_schedules); ?></h3>
                        </div>
                        <div class="fs-2 text-primary opacity-50">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Upcoming</h6>
                            <h3 class="mb-0 text-success"><?php echo count($upcoming_schedules); ?></h3>
                        </div>
                        <div class="fs-2 text-success opacity-50">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="text-muted mb-1">Past Schedules</h6>
                            <h3 class="mb-0 text-secondary"><?php echo count($past_schedules); ?></h3>
                        </div>
                        <div class="fs-2 text-secondary opacity-50">
                            <i class="fas fa-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Special Schedules -->
    <?php if (!empty($today_schedules)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-calendar-day me-2"></i>
                Today's Special Schedules
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($today_schedules as $schedule): ?>
                    <div class="col-md-6">
                        <div class="card border-primary h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="card-title mb-2">
                                            <?php echo getScheduleTypeIcon($schedule['schedule_type']); ?>
                                            <?php echo htmlspecialchars($schedule['schedule_name']); ?>
                                        </h5>
                                        <span class="badge bg-<?php echo $schedule['is_working_day'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $schedule['is_working_day'] ? 'Working Day' : 'Non-Working Day'; ?>
                                        </span>
                                    </div>
                                    <span class="badge bg-primary">Today</span>
                                </div>
                                
                                <?php if ($schedule['is_working_day'] && ($schedule['custom_time_in'] || $schedule['custom_time_out'])): ?>
                                    <div class="row text-center mb-3">
                                        <div class="col-6">
                                            <div class="border rounded p-2">
                                                <i class="fas fa-sign-in-alt text-success"></i>
                                                <div class="mt-1">
                                                    <strong><?php echo date('h:i A', strtotime($schedule['custom_time_in'])); ?></strong>
                                                    <br><small class="text-muted">Time In</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="border rounded p-2">
                                                <i class="fas fa-sign-out-alt text-danger"></i>
                                                <div class="mt-1">
                                                    <strong><?php echo date('h:i A', strtotime($schedule['custom_time_out'])); ?></strong>
                                                    <br><small class="text-muted">Time Out</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($schedule['description']): ?>
                                    <p class="card-text text-muted mb-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php echo htmlspecialchars($schedule['description']); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <small class="text-muted">
                                    <i class="fas fa-user me-1"></i>
                                    Created by: <?php echo htmlspecialchars($schedule['created_by_name']); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upcoming Special Schedules -->
    <?php if (!empty($upcoming_schedules)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="fas fa-calendar-plus text-success me-2"></i>
                Upcoming Special Schedules
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Schedule Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming_schedules as $schedule): ?>
                            <tr>
                                <td>
                                    <strong><?php echo formatDate($schedule['schedule_date']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo date('l', strtotime($schedule['schedule_date'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($schedule['schedule_name']); ?></strong>
                                </td>
                                <td>
                                    <?php echo getScheduleTypeIcon($schedule['schedule_type']); ?>
                                    <span class="ms-1"><?php echo $schedule['schedule_type']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $schedule['is_working_day'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $schedule['is_working_day'] ? '✓ Working' : '✗ Non-Working'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($schedule['is_working_day'] && ($schedule['custom_time_in'] || $schedule['custom_time_out'])): ?>
                                        <small>
                                            <?php if ($schedule['custom_time_in']): ?>
                                                <i class="fas fa-sign-in-alt text-success"></i>
                                                <?php echo date('h:i A', strtotime($schedule['custom_time_in'])); ?>
                                            <?php endif; ?>
                                            <?php if ($schedule['custom_time_out']): ?>
                                                <br>
                                                <i class="fas fa-sign-out-alt text-danger"></i>
                                                <?php echo date('h:i A', strtotime($schedule['custom_time_out'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">Regular hours</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($schedule['description']): ?>
                                        <small class="text-muted" title="<?php echo htmlspecialchars($schedule['description']); ?>">
                                            <?php echo strlen($schedule['description']) > 40 ? substr($schedule['description'], 0, 40) . '...' : $schedule['description']; ?>
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
        </div>
    </div>
    <?php endif; ?>

    <!-- Past Special Schedules -->
    <?php if (!empty($past_schedules)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-history text-secondary me-2"></i>
                    Past Special Schedules
                </h5>
                <small class="text-muted">Showing last 10 records</small>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Schedule Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayed = 0;
                        foreach ($past_schedules as $schedule): 
                            if ($displayed >= 10) break;
                            $displayed++;
                        ?>
                            <tr class="text-muted">
                                <td>
                                    <strong><?php echo formatDate($schedule['schedule_date']); ?></strong>
                                    <br>
                                    <small><?php echo date('l', strtotime($schedule['schedule_date'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($schedule['schedule_name']); ?></td>
                                <td>
                                    <?php echo getScheduleTypeIcon($schedule['schedule_type']); ?>
                                    <span class="ms-1"><?php echo $schedule['schedule_type']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo $schedule['is_working_day'] ? 'Worked' : 'Off Day'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($schedule['is_working_day'] && ($schedule['custom_time_in'] || $schedule['custom_time_out'])): ?>
                                        <small>
                                            <?php if ($schedule['custom_time_in']): ?>
                                                <?php echo date('h:i A', strtotime($schedule['custom_time_in'])); ?>
                                            <?php endif; ?>
                                            <?php if ($schedule['custom_time_out']): ?>
                                                - <?php echo date('h:i A', strtotime($schedule['custom_time_out'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($schedule['description']): ?>
                                        <small title="<?php echo htmlspecialchars($schedule['description']); ?>">
                                            <?php echo strlen($schedule['description']) > 30 ? substr($schedule['description'], 0, 30) . '...' : $schedule['description']; ?>
                                        </small>
                                    <?php else: ?>
                                        <span>-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- No Schedules Message -->
    <?php if (empty($my_special_schedules)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Special Schedules Assigned</h5>
            <p class="text-muted">You don't have any special schedules assigned to you yet.</p>
            <a href="my-schedule.php" class="btn btn-primary mt-3">
                <i class="fas fa-calendar-week me-1"></i> View My Regular Schedule
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>