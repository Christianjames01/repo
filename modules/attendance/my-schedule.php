<?php
/**
 * Staff My Duty Schedule View with Attendance Marking
 * modules/attendance/my-schedule.php
 * FIXED PHILIPPINE TIME VERSION - WITH LEAVE MANAGEMENT LINK
 */

// CRITICAL: Set timezone FIRST before any other code
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

$page_title = 'My Duty Schedule';
$current_user_id = getCurrentUserId();

// ============================================================================
// CHECK TABLE STRUCTURE (Check if marked_by column exists)
// ============================================================================

$columns_result = $conn->query("SHOW COLUMNS FROM tbl_attendance");
$has_marked_by = false;
$has_notes = false;

while ($col = $columns_result->fetch_assoc()) {
    if ($col['Field'] === 'marked_by') {
        $has_marked_by = true;
    }
    if ($col['Field'] === 'notes') {
        $has_notes = true;
    }
}

// ============================================================================
// HANDLE ATTENDANCE MARKING (WITH PHILIPPINE TIME)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $action = sanitizeInput($_POST['action']); // 'time_in' or 'time_out'
    $attendance_date = date('Y-m-d');
    
    // FIXED: Use full datetime if columns are DATETIME type
    // Check column type and decide format
    $column_check = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'time_in'");
    $column_info = $column_check->fetch_assoc();
    
    if (strpos(strtoupper($column_info['Type']), 'DATETIME') !== false) {
        // Column is DATETIME - use full datetime
        $current_time = date('Y-m-d H:i:s');
    } else {
        // Column is TIME - use time only
        $current_time = date('H:i:s');
    }
    
    // Check if attendance record exists for today
    $existing = fetchOne($conn, 
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $attendance_date], 'is'
    );
    
    if ($action === 'time_in') {
        if (!$existing) {
            // Determine status based on schedule
            $status = 'Present';
            
            // Get today's schedule to check if late
            $today = date('l');
            $schedule = fetchOne($conn,
                "SELECT time_in FROM tbl_duty_schedules 
                WHERE user_id = ? AND day_of_week = ? AND is_active = 1",
                [$current_user_id, $today], 'is'
            );
            
            // Check for special schedule
            if (!$schedule) {
                $schedule = fetchOne($conn,
                    "SELECT time_in FROM tbl_special_duty_schedules 
                    WHERE user_id = ? AND schedule_date = ?",
                    [$current_user_id, $attendance_date], 'is'
                );
            }
            
            // Check if late (more than 15 minutes after scheduled time)
            if ($schedule && $schedule['time_in']) {
                $scheduled_time = strtotime($schedule['time_in']);
                $actual_time = strtotime(date('H:i:s'));
                $diff_minutes = ($actual_time - $scheduled_time) / 60;
                
                if ($diff_minutes > 15) {
                    $status = 'Late';
                }
            }
            
            // Create new attendance record
            if ($has_marked_by && $has_notes) {
                $note = "Self-marked at " . date('h:i A');
                if ($status === 'Late') {
                    $late_mins = round($diff_minutes);
                    $note .= " (Late by {$late_mins} minutes)";
                }
                
                $sql = "INSERT INTO tbl_attendance (user_id, attendance_date, time_in, status, marked_by, notes) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $result = executeQuery($conn, $sql, 
                    [$current_user_id, $attendance_date, $current_time, $status, $current_user_id, $note], 
                    'isssss');
            } elseif ($has_notes) {
                $note = "Self-marked at " . date('h:i A');
                if ($status === 'Late') {
                    $late_mins = round($diff_minutes);
                    $note .= " (Late by {$late_mins} minutes)";
                }
                
                $sql = "INSERT INTO tbl_attendance (user_id, attendance_date, time_in, status, notes) 
                        VALUES (?, ?, ?, ?, ?)";
                $result = executeQuery($conn, $sql, 
                    [$current_user_id, $attendance_date, $current_time, $status, $note], 
                    'issss');
            } else {
                $sql = "INSERT INTO tbl_attendance (user_id, attendance_date, time_in, status) 
                        VALUES (?, ?, ?, ?)";
                $result = executeQuery($conn, $sql, [$current_user_id, $attendance_date, $current_time, $status], 'isss');
            }
            
            if ($result) {
                logActivity($conn, $current_user_id, "Marked time in: $current_time ($status)", 'tbl_attendance');
                
                if ($status === 'Late') {
                    $_SESSION['warning_message'] = "Time in recorded at " . date('h:i A') . " - You are late by " . round($diff_minutes) . " minutes";
                } else {
                    $_SESSION['success_message'] = "Time in recorded successfully at " . date('h:i A');
                }
            } else {
                $_SESSION['error_message'] = "Failed to record time in";
            }
        } else {
            $_SESSION['error_message'] = "You have already marked time in today";
        }
    } 
    elseif ($action === 'time_out') {
        if ($existing && !$existing['time_out']) {
            // Update with time out
            if ($has_notes) {
                $note_append = " | Time out: Self-marked at " . date('h:i A');
                $sql = "UPDATE tbl_attendance 
                        SET time_out = ?, notes = CONCAT(COALESCE(notes, ''), ?)
                        WHERE attendance_id = ?";
                $result = executeQuery($conn, $sql, [$current_time, $note_append, $existing['attendance_id']], 'ssi');
            } else {
                $sql = "UPDATE tbl_attendance 
                        SET time_out = ?
                        WHERE attendance_id = ?";
                $result = executeQuery($conn, $sql, [$current_time, $existing['attendance_id']], 'si');
            }
            
            if ($result) {
                logActivity($conn, $current_user_id, "Marked time out: $current_time", 'tbl_attendance');
                $_SESSION['success_message'] = "Time out recorded successfully at " . date('h:i A');
            } else {
                $_SESSION['error_message'] = "Failed to record time out";
            }
        } elseif (!$existing) {
            $_SESSION['error_message'] = "Please mark time in first";
        } else {
            $_SESSION['error_message'] = "You have already marked time out today";
        }
    }
    
    header("Location: my-schedule.php");
    exit();
}

// ============================================================================
// GET SCHEDULE DATA
// ============================================================================

// Get user's weekly schedule
$weekly_schedule = fetchAll($conn,
    "SELECT * FROM tbl_duty_schedules 
    WHERE user_id = ? AND is_active = 1
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')",
    [$current_user_id], 'i'
);

// Get upcoming special schedules from BOTH tables
$special_schedules = [];

// Get from tbl_special_duty_schedules (individual assignments)
$individual_schedules = fetchAll($conn,
    "SELECT ss.*, 
            CONCAT(r.first_name, ' ', r.last_name) as assigned_by,
            'individual' as source
    FROM tbl_special_duty_schedules ss
    LEFT JOIN tbl_users u ON ss.created_by = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE ss.user_id = ? AND ss.schedule_date >= CURDATE()
    ORDER BY ss.schedule_date
    LIMIT 10",
    [$current_user_id], 'i'
);

// Get from tbl_special_schedules (event-based assignments where staff is assigned)
$event_schedules = fetchAll($conn,
    "SELECT ss.schedule_date,
            ss.custom_time_in as time_in,
            ss.custom_time_out as time_out,
            ss.schedule_type,
            ss.description as notes,
            CONCAT(r.first_name, ' ', r.last_name) as assigned_by,
            'event' as source
    FROM tbl_special_schedules ss
    INNER JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id = ssa.schedule_id
    LEFT JOIN tbl_users u ON ss.created_by = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE ssa.user_id = ? 
    AND ss.schedule_date >= CURDATE()
    AND ss.is_working_day = 1
    ORDER BY ss.schedule_date
    LIMIT 10",
    [$current_user_id], 'i'
);

// Merge both types of special schedules
$special_schedules = array_merge($individual_schedules, $event_schedules);

// Sort by date
usort($special_schedules, function($a, $b) {
    return strtotime($a['schedule_date']) - strtotime($b['schedule_date']);
});

// Limit to 10 total
$special_schedules = array_slice($special_schedules, 0, 10);

// Get today's schedule
$today = date('l'); // Day name (Monday, Tuesday, etc.)
$today_schedule = null;

// Check for special schedule first
$today_date = date('Y-m-d');
$today_special = fetchOne($conn,
    "SELECT * FROM tbl_special_duty_schedules WHERE user_id = ? AND schedule_date = ?",
    [$current_user_id, $today_date], 'is'
);

if ($today_special) {
    $today_schedule = [
        'type' => 'special',
        'time_in' => $today_special['time_in'],
        'time_out' => $today_special['time_out'],
        'schedule_type' => $today_special['schedule_type'],
        'notes' => $today_special['notes']
    ];
} else {
    // Check regular schedule
    foreach ($weekly_schedule as $sched) {
        if ($sched['day_of_week'] === $today) {
            $today_schedule = [
                'type' => 'regular',
                'time_in' => $sched['time_in'],
                'time_out' => $sched['time_out'],
                'schedule_name' => $sched['schedule_name'] ?? '',
                'notes' => $sched['notes'] ?? ''
            ];
            break;
        }
    }
}

// Calculate total weekly hours
$total_weekly_hours = 0;
foreach ($weekly_schedule as $sched) {
    $in = strtotime($sched['time_in']);
    $out = strtotime($sched['time_out']);
    $diff = ($out - $in) / 3600;
    if ($diff < 0) $diff += 24;
    $total_weekly_hours += $diff;
}

// Get today's attendance
$today_attendance = fetchOne($conn, 
    "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
    [$current_user_id, $today_date], 'is'
);

// Get leave statistics
$leave_stats = fetchOne($conn,
    "SELECT 
        COUNT(*) as total_leaves,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_leaves,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_leaves
    FROM tbl_leave_requests 
    WHERE user_id = ? AND YEAR(start_date) = YEAR(CURDATE())",
    [$current_user_id], 'i'
);

// Check if user can mark time in/out
$can_time_in = !$today_attendance;
$can_time_out = $today_attendance && !$today_attendance['time_out'];
$is_late = false;
$is_early_out = false;

if ($today_schedule && $today_attendance) {
    // Check if late (15 minute grace period)
    $scheduled_in = strtotime($today_schedule['time_in']);
    $actual_in = strtotime($today_attendance['time_in']);
    $diff_minutes = ($actual_in - $scheduled_in) / 60;
    $is_late = $diff_minutes > 15;
    
    // Check if early out
    if ($today_attendance['time_out']) {
        $scheduled_out = strtotime($today_schedule['time_out']);
        $actual_out = strtotime($today_attendance['time_out']);
        $is_early_out = $actual_out < $scheduled_out;
    }
}

// Helper function to format time consistently
function formatTimeDisplay($time_string) {
    if (empty($time_string)) return 'N/A';
    
    // Use DateTime for accurate parsing and formatting
    try {
        $time = new DateTime($time_string);
        return $time->format('g:i A'); // 12-hour format with leading zero removed
    } catch (Exception $e) {
        // Fallback: Parse time string manually
        $parts = explode(':', $time_string);
        if (count($parts) < 2) return 'N/A';
        
        $hour = (int)$parts[0];
        $minute = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        
        // Convert to 12-hour format
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $display_hour = $hour % 12;
        if ($display_hour === 0) $display_hour = 12;
        
        return sprintf('%d:%s %s', $display_hour, $minute, $ampm);
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-week text-primary me-2"></i>
                My Duty Schedule
            </h1>
            <p class="text-muted mb-0">View your schedule and mark attendance (Philippine Time)</p>
        </div>
        <div class="d-flex gap-2">
            <a href="my-payslips.php" class="btn btn-info">
            <i class="fas fa-money-check-alt me-1"></i> 
            PayChecks
        </a>
            <a href="manage-leaves.php" class="btn btn-success">
                <i class="fas fa-calendar-times me-1"></i> 
                Manage Leaves
                <?php if ($leave_stats['pending_leaves'] > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?php echo $leave_stats['pending_leaves']; ?></span>
                <?php endif; ?>
            </a>
            <a href="leave-request.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Request Leave
            </a>
            <a href="my-attendance.php" class="btn btn-outline-primary">
                <i class="fas fa-clipboard-list me-1"></i> Attendance History
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Today's Schedule Card with Attendance Marking -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-day me-2"></i>
                        Today's Schedule & Attendance
                    </h5>
                </div>
                <div class="card-body">
                    <div class="text-center py-3">
                        <h3 class="mb-2"><?php echo date('l, F j, Y'); ?></h3>
                        <div class="mb-4">
                            <h2 class="mb-0" id="current-time"><?php echo date('h:i A'); ?></h2>
                            <small class="text-muted">Current Time (Philippine Time)</small>
                        </div>
                        
                        <?php if ($today_schedule): ?>
                            <div class="alert alert-info mb-3">
                                <?php if ($today_schedule['type'] === 'special'): ?>
                                    <div class="mb-2">
                                        <span class="badge bg-warning text-dark fs-6">
                                            <i class="fas fa-star me-1"></i>
                                            <?php echo $today_schedule['schedule_type']; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-around my-3">
                                    <div>
                                        <div class="text-success">
                                            <i class="fas fa-sign-in-alt fa-2x mb-2"></i>
                                            <h4 class="mb-0"><?php echo formatTimeDisplay($today_schedule['time_in']); ?></h4>
                                            <small>Scheduled In</small>
                                        </div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-arrow-right fa-2x text-muted"></i>
                                    </div>
                                    <div>
                                        <div class="text-danger">
                                            <i class="fas fa-sign-out-alt fa-2x mb-2"></i>
                                            <h4 class="mb-0"><?php echo formatTimeDisplay($today_schedule['time_out']); ?></h4>
                                            <small>Scheduled Out</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($today_schedule['notes'])): ?>
                                    <div class="mt-3 text-start">
                                        <strong>Note:</strong> <?php echo htmlspecialchars($today_schedule['notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Attendance Marking Buttons -->
                            <div class="mb-3">
                                <?php if ($can_time_in): ?>
                                    <?php
                                    // Check if marking now would be late
                                    $would_be_late = false;
                                    $late_by_minutes = 0;
                                    if ($today_schedule) {
                                        $scheduled_time = strtotime($today_schedule['time_in']);
                                        $current_time_check = strtotime(date('H:i:s'));
                                        $diff_minutes = ($current_time_check - $scheduled_time) / 60;
                                        
                                        if ($diff_minutes > 15) {
                                            $would_be_late = true;
                                            $late_by_minutes = round($diff_minutes);
                                        }
                                    }
                                    ?>
                                    
                                    <?php if ($would_be_late): ?>
                                        <div class="alert alert-warning mb-3">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Warning:</strong> You are <?php echo $late_by_minutes; ?> minutes late. 
                                            Marking now will record as <strong>Late</strong>.
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-success mb-3">
                                            <i class="fas fa-check-circle me-2"></i>
                                            You're within the grace period. Marking now will record as <strong>Present</strong>.
                                        </div>
                                    <?php endif; ?>
                                    
                                    <form method="POST" id="timeInForm" class="d-inline">
                                        <input type="hidden" name="mark_attendance" value="1">
                                        <input type="hidden" name="action" value="time_in">
                                        <button type="button" class="btn btn-<?php echo $would_be_late ? 'warning' : 'success'; ?> btn-lg w-100" onclick="markTimeIn()">
                                            <i class="fas fa-sign-in-alt me-2"></i>
                                            Mark Time In <?php echo $would_be_late ? '(Late)' : ''; ?>
                                        </button>
                                    </form>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle"></i> 
                                        Grace period: 15 minutes after scheduled time
                                    </small>
                                <?php elseif ($can_time_out): ?>
                                    <form method="POST" id="timeOutForm" class="d-inline">
                                        <input type="hidden" name="mark_attendance" value="1">
                                        <input type="hidden" name="action" value="time_out">
                                        <button type="button" class="btn btn-danger btn-lg w-100" onclick="markTimeOut()">
                                            <i class="fas fa-sign-out-alt me-2"></i>
                                            Mark Time Out
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg w-100" disabled>
                                        <i class="fas fa-check-circle me-2"></i>
                                        Attendance Completed
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Attendance Status -->
                            <?php if ($today_attendance): ?>
                                <div class="alert alert-<?php echo $today_attendance['time_out'] ? 'success' : 'warning'; ?> mb-3">
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <strong>Actual Time In:</strong><br>
                                            <?php if ($today_attendance['time_in']): ?>
                                                <span class="fs-5 <?php echo $is_late ? 'text-danger' : ''; ?>">
                                                    <?php echo formatTimeDisplay($today_attendance['time_in']); ?>
                                                </span>
                                                <?php if ($is_late): ?>
                                                    <br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Late</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-6">
                                            <strong>Actual Time Out:</strong><br>
                                            <?php if ($today_attendance['time_out']): ?>
                                                <span class="fs-5 <?php echo $is_early_out ? 'text-warning' : ''; ?>">
                                                    <?php echo formatTimeDisplay($today_attendance['time_out']); ?>
                                                </span>
                                                <?php if ($is_early_out): ?>
                                                    <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Early Out</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <?php echo getStatusBadge($today_attendance['status']); ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Please mark your attendance!</strong><br>
                                    Click the button above to mark your time in.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-secondary">
                                <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                <p class="mb-0"><strong>No Schedule Today</strong></p>
                                <small>You don't have a duty schedule assigned for today. This is your rest day!</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line text-success me-2"></i>
                        Schedule Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 bg-primary bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Working Days</h6>
                                        <h2 class="mb-0"><?php echo count($weekly_schedule); ?></h2>
                                        <small class="text-muted">per week</small>
                                    </div>
                                    <div class="fs-1 text-primary opacity-50">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-success bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Weekly Hours</h6>
                                        <h2 class="mb-0"><?php echo number_format($total_weekly_hours, 1); ?></h2>
                                        <small class="text-muted">hours/week</small>
                                    </div>
                                    <div class="fs-1 text-success opacity-50">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-warning bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Special Schedules</h6>
                                        <h2 class="mb-0"><?php echo count($special_schedules); ?></h2>
                                        <small class="text-muted">upcoming</small>
                                    </div>
                                    <div class="fs-1 text-warning opacity-50">
                                        <i class="fas fa-star"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-info bg-opacity-10 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Leave Requests</h6>
                                        <h2 class="mb-0"><?php echo $leave_stats['total_leaves']; ?></h2>
                                        <small class="text-muted">
                                            <?php echo $leave_stats['pending_leaves']; ?> pending, 
                                            <?php echo $leave_stats['approved_leaves']; ?> approved
                                        </small>
                                    </div>
                                    <div class="fs-1 text-info opacity-50">
                                        <i class="fas fa-calendar-times"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="alert alert-light mb-0">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Reminder:</strong> Please mark your attendance on time. 
                                Late arrivals (more than 15 minutes) will be recorded.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Weekly Schedule -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-calendar-week text-primary me-2"></i>
                My Weekly Schedule
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($weekly_schedule)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="150">Day</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Total Hours</th>
                                <th>Schedule Name</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            $schedule_map = [];
                            foreach ($weekly_schedule as $sched) {
                                $schedule_map[$sched['day_of_week']] = $sched;
                            }
                            
                            foreach ($days as $day): 
                                $sched = $schedule_map[$day] ?? null;
                                $is_today = ($day === $today);
                            ?>
                                <tr <?php echo $is_today ? 'class="table-primary"' : ''; ?>>
                                    <td>
                                        <strong><?php echo $day; ?></strong>
                                        <?php if ($is_today): ?>
                                            <span class="badge bg-primary ms-2">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($sched): ?>
                                        <td>
                                            <span class="text-success">
                                                <i class="fas fa-sign-in-alt me-1"></i>
                                                <?php echo formatTimeDisplay($sched['time_in']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-danger">
                                                <i class="fas fa-sign-out-alt me-1"></i>
                                                <?php echo formatTimeDisplay($sched['time_out']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $in = strtotime($sched['time_in']);
                                            $out = strtotime($sched['time_out']);
                                            $diff = ($out - $in) / 3600;
                                            if ($diff < 0) $diff += 24;
                                            ?>
                                            <span class="badge bg-info">
                                                <?php echo number_format($diff, 1); ?> hrs
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($sched['schedule_name'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($sched['schedule_name']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($sched['notes'])): ?>
                                                <small class="text-muted" title="<?php echo htmlspecialchars($sched['notes']); ?>">
                                                    <?php echo substr($sched['notes'], 0, 30) . (strlen($sched['notes']) > 30 ? '...' : ''); ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td colspan="5" class="text-center text-muted">
                                            <i class="fas fa-calendar-times me-1"></i> Rest Day
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Regular Schedule Assigned</h5>
                    <p class="text-muted">You don't have a weekly duty schedule assigned yet. Please contact your administrator.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Special Schedules -->
    <?php if (!empty($special_schedules)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-star text-warning me-2"></i>
                Upcoming Special Schedules
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
                            <th>Duration</th>
                            <th>Type</th>
                            <th>Notes</th>
                            <th>Assigned By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($special_schedules as $special): ?>
                            <?php 
                            $is_today_special = ($special['schedule_date'] === $today_date);
                            $in = strtotime($special['time_in']);
                            $out = strtotime($special['time_out']);
                            $diff = ($out - $in) / 3600;
                            if ($diff < 0) $diff += 24;
                            ?>
                            <tr <?php echo $is_today_special ? 'class="table-warning"' : ''; ?>>
                                <td>
                                    <strong><?php echo formatDate($special['schedule_date']); ?></strong>
                                    <?php if ($is_today_special): ?>
                                        <span class="badge bg-warning text-dark ms-2">Today</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('l', strtotime($special['schedule_date'])); ?></td>
                                <td>
                                    <span class="text-success">
                                        <i class="fas fa-sign-in-alt me-1"></i>
                                        <?php echo formatTimeDisplay($special['time_in']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-danger">
                                        <i class="fas fa-sign-out-alt me-1"></i>
                                        <?php echo formatTimeDisplay($special['time_out']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo number_format($diff, 1); ?> hrs
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-warning text-dark">
                                        <?php echo htmlspecialchars($special['schedule_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($special['notes'])): ?>
                                        <small class="text-muted" title="<?php echo htmlspecialchars($special['notes']); ?>">
                                            <?php echo substr($special['notes'], 0, 30) . (strlen($special['notes']) > 30 ? '...' : ''); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($special['assigned_by'] ?? 'System'); ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Display current time (updates every second for user convenience)
function updateTime() {
    const now = new Date();
    const hours = now.getHours();
    const minutes = now.getMinutes();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours % 12 || 12;
    const displayMinutes = minutes < 10 ? '0' + minutes : minutes;
    
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = displayHours + ':' + displayMinutes + ' ' + ampm;
    }
}

// Mark Time In - server will use Philippine time
function markTimeIn() {
    const now = new Date();
    const displayTime = now.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit', 
        hour12: true 
    });
    
    if (confirm(`Mark your TIME IN now at ${displayTime}?\n\n(Time will be recorded in Philippine Time)`)) {
        document.getElementById('timeInForm').submit();
    }
}

// Mark Time Out - server will use Philippine time
function markTimeOut() {
    const now = new Date();
    const displayTime = now.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit', 
        hour12: true 
    });
    
    if (confirm(`Mark your TIME OUT now at ${displayTime}?\n\n(Time will be recorded in Philippine Time)`)) {
        document.getElementById('timeOutForm').submit();
    }
}

// Update display time every second
setInterval(updateTime, 1000);

// Initialize time on page load
updateTime();
</script>

<?php include '../../includes/footer.php'; ?>