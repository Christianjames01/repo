<?php
/**
 * Process Attendance - Time In/Out Handler
 * modules/attendance/process-attendace.php
 */

// config.php starts the session, creates $conn, and loads functions.php + auth_functions.php
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$current_user_id = getCurrentUserId();
$action       = $_POST['action'] ?? '';
$today        = date('Y-m-d');
$current_time = date('H:i:s');

// Get attendance settings — fall back gracefully if table not yet created
$settings = null;
if (tableExists($conn, 'tbl_attendance_settings')) {
    $settings = fetchOne($conn, "SELECT * FROM tbl_attendance_settings LIMIT 1");
}
if (!$settings) {
    $settings = [
        'work_start_time'        => '08:00:00',
        'work_end_time'          => '17:00:00',
        'late_threshold_minutes' => 15,
        'grace_period_minutes'   => 5,
    ];
}

if ($action === 'time_in') {

    $existing = fetchOne($conn,
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $today], 'is'
    );

    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'You have already timed in today']);
        exit;
    }

    // Determine Present / Late
    $work_start = strtotime($settings['work_start_time']);
    $time_in_ts = strtotime($current_time);
    $grace_secs = (int)$settings['grace_period_minutes'] * 60;

    $status = 'Present';
    $notes  = '';

    if ($time_in_ts > ($work_start + $grace_secs)) {
        $late_minutes = (int)floor(($time_in_ts - $work_start) / 60);
        if ($late_minutes >= (int)$settings['late_threshold_minutes']) {
            $status = 'Late';
            $notes  = "Late by {$late_minutes} minutes";
        }
    }

    $success = executeQuery(
        $conn,
        "INSERT INTO tbl_attendance (user_id, attendance_date, time_in, status, notes) VALUES (?, ?, ?, ?, ?)",
        [$current_user_id, $today, $current_time, $status, $notes],
        'issss'
    );

    if ($success) {
        logActivity($conn, $current_user_id, "Time-in recorded — Status: {$status}", 'tbl_attendance');
        echo json_encode([
            'success' => true,
            'message' => 'Time in recorded successfully',
            'status'  => $status,
            'time'    => date('h:i A', strtotime($current_time))
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to record time in. Please try again.']);
    }

} elseif ($action === 'time_out') {

    $existing = fetchOne($conn,
        "SELECT * FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$current_user_id, $today], 'is'
    );

    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'You have not timed in today']);
        exit;
    }

    if (!empty($existing['time_out'])) {
        echo json_encode(['success' => false, 'message' => 'You have already timed out today']);
        exit;
    }

    $success = executeQuery(
        $conn,
        "UPDATE tbl_attendance SET time_out = ? WHERE user_id = ? AND attendance_date = ?",
        [$current_time, $current_user_id, $today],
        'sis'
    );

    if ($success) {
        logActivity($conn, $current_user_id, 'Time-out recorded', 'tbl_attendance');
        echo json_encode([
            'success' => true,
            'message' => 'Time out recorded successfully',
            'time'    => date('h:i A', strtotime($current_time))
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to record time out. Please try again.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>