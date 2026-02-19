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

echo "<!DOCTYPE html><html><head><title>Emergency Fix</title></head><body>";
echo "<h1>Emergency Fix - Delete ID=0 Event</h1>";

// Just delete it - simple and direct
$result = $conn->query("DELETE FROM tbl_calendar_events WHERE id = 0");

if ($result) {
    echo "<p style='color: green; font-size: 20px;'><strong>✓ SUCCESS!</strong></p>";
    echo "<p>The event with ID = 0 has been deleted.</p>";
    
    // Check if it's really gone
    $check = $conn->query("SELECT COUNT(*) as cnt FROM tbl_calendar_events WHERE id = 0");
    $row = $check->fetch_assoc();
    
    if ($row['cnt'] == 0) {
        echo "<p style='color: green;'>✓ Confirmed: No more events with ID = 0</p>";
    } else {
        echo "<p style='color: red;'>⚠️ Warning: Still found " . $row['cnt'] . " event(s) with ID = 0</p>";
    }
} else {
    echo "<p style='color: red;'><strong>✗ ERROR:</strong> " . $conn->error . "</p>";
}

// Verify auto increment
echo "<hr>";
echo "<h2>Auto Increment Status</h2>";
$status = $conn->query("SHOW TABLE STATUS LIKE 'tbl_calendar_events'");
if ($status && $row = $status->fetch_assoc()) {
    echo "<p>Current AUTO_INCREMENT: <strong>" . $row['Auto_increment'] . "</strong></p>";
    
    if ($row['Auto_increment'] < 8) {
        echo "<p style='color: orange;'>Fixing AUTO_INCREMENT...</p>";
        $conn->query("ALTER TABLE tbl_calendar_events AUTO_INCREMENT = 8");
        echo "<p style='color: green;'>✓ AUTO_INCREMENT set to 8</p>";
    }
}

echo "<br><br>";
echo "<h2>You can now:</h2>";
echo "<ul>";
echo "<li>Go back to the calendar</li>";
echo "<li>Add new events</li>";
echo "<li>Edit and delete events</li>";
echo "</ul>";

echo "<a href='index.php' style='padding: 15px 30px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; margin-top: 20px;'>Go to Calendar</a>";
echo "</body></html>";
?>