<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../config/session.php';

requireLogin();

$current_role = getCurrentUserRole();
$is_admin = in_array($current_role, ['Admin', 'Super Admin', 'Super Administrator']);

if (!$is_admin) {
    die("Access denied. Admin only.");
}

echo "<!DOCTYPE html><html><head><title>Quick Fix Auto Increment</title></head><body>";
echo "<h1>Quick Fix: Auto Increment Issue</h1>";

// Get the highest ID
$max_result = $conn->query("SELECT MAX(id) as max_id FROM tbl_calendar_events");
$max_row = $max_result->fetch_assoc();
$max_id = $max_row['max_id'] ?? 0;
$next_id = $max_id + 1;

echo "<p>Current highest ID: <strong>" . $max_id . "</strong></p>";
echo "<p>Setting AUTO_INCREMENT to: <strong>" . $next_id . "</strong></p>";

// Fix the auto increment
$fix_query = "ALTER TABLE tbl_calendar_events AUTO_INCREMENT = " . $next_id;
if ($conn->query($fix_query)) {
    echo "<p style='color: green; font-size: 18px;'><strong>✓ SUCCESS!</strong> Auto increment has been fixed.</p>";
    echo "<p>New events will now be assigned ID: " . $next_id . " and higher.</p>";
} else {
    echo "<p style='color: red;'><strong>✗ ERROR:</strong> " . $conn->error . "</p>";
}

// Check current status
echo "<hr>";
echo "<h2>Current Table Status</h2>";
$status_result = $conn->query("SHOW TABLE STATUS LIKE 'tbl_calendar_events'");
if ($status_result && $status_row = $status_result->fetch_assoc()) {
    echo "<p>Auto_increment is now: <strong style='color: green;'>" . $status_row['Auto_increment'] . "</strong></p>";
}

// Show any events with ID 0
echo "<hr>";
echo "<h2>Events with ID = 0</h2>";
$zero_result = $conn->query("SELECT id, title, event_date FROM tbl_calendar_events WHERE id = 0");
if ($zero_result && $zero_result->num_rows > 0) {
    echo "<p style='color: orange;'>Found " . $zero_result->num_rows . " event(s) with ID = 0:</p>";
    echo "<ul>";
    while ($row = $zero_result->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($row['title']) . " (" . $row['event_date'] . ")</li>";
    }
    echo "</ul>";
    echo "<p><a href='fix-event-ids.php'>Click here to fix these events</a></p>";
} else {
    echo "<p style='color: green;'>✓ No events with ID = 0</p>";
}

echo "<br><br>";
echo "<a href='index.php' style='padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px; display: inline-block;'>Go to Calendar</a>";
echo "</body></html>";
?>