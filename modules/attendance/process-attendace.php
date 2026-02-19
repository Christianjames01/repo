<?php
/**
 * Process Attendance - Time In/Out Handler (FIXED)
 * modules/attendance/process-attendance.php
 */

session_start();
require_once '../../config/db.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$current_user_id = getCurrentUserId();
$action = $_POST['action'] ?? '';
$today = date('Y-m-d');
$current_time = date('H:i:s');

// Get attendance settings
$settings = fetchOne($conn, "SELECT * FROM tbl_attendance_settings LIMIT 1");
if (!$settings) {
    $settings = [
        'work_start_time' => '08:00:00',
        'work_end_time' => '17:00:00',
        'late_threshold_minutes' => 15,
        'grace_period_minutes' => 5
    ];
}

if ($action === 'time_in') {
    // Check if already timed in today - FIXED: use attendance_date
    $existing = fetchOne($conn, 
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $today], 'is'
    );
    
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'You have already timed in today']);
        exit;
    }
    
    // Calculate status based on time
    $work_start = strtotime($settings['work_start_time']);
    $time_in = strtotime($current_time);
    $grace_period = $settings['grace_period_minutes'] * 60;
    $late_threshold = $settings['late_threshold_minutes'] * 60;
    
    $status = 'Present';
    $notes = '';
    
    if ($time_in > ($work_start + $grace_period)) {
        $late_minutes = floor(($time_in - $work_start) / 60);
        if ($late_minutes >= $settings['late_threshold_minutes']) {
            $status = 'Late';
            $notes = "Late by $late_minutes minutes";
        }
    }
    
    // Insert attendance record - FIXED: use attendance_date
    $sql = "INSERT INTO tbl_attendance (user_id, attendance_date, time_in, status, notes) VALUES (?, ?, ?, ?, ?)";
    $success = executeQuery($conn, $sql, [$current_user_id, $today, $current_time, $status, $notes], 'issss');
    
    if ($success) {
        logActivity($conn, $current_user_id, 'Timed in', 'tbl_attendance', 0, "Status: $status");
        echo json_encode([
            'success' => true, 
            'message' => 'Time in recorded successfully',
            'status' => $status,
            'time' => date('h:i A', strtotime($current_time))
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to record time in']);
    }
    
} elseif ($action === 'time_out') {
    // Check if timed in today - FIXED: use attendance_date
    $existing = fetchOne($conn, 
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $today], 'is'
    );
    
    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'You have not timed in today']);
        exit;
    }
    
    if ($existing['time_out']) {
        echo json_encode(['success' => false, 'message' => 'You have already timed out today']);
        exit;
    }
    
    // Update with time out - FIXED: use attendance_date
    $sql = "UPDATE tbl_attendance SET time_out = ? WHERE user_id = ? AND attendance_date = ?";
    $success = executeQuery($conn, $sql, [$current_time, $current_user_id, $today], 'sis');
    
    if ($success) {
        logActivity($conn, $current_user_id, 'Timed out', 'tbl_attendance', 0, '');
        echo json_encode([
            'success' => true, 
            'message' => 'Time out recorded successfully',
            'time' => date('h:i A', strtotime($current_time))
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to record time out']);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>