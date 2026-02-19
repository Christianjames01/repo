<?php
// FORCE ERROR DISPLAY
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output immediately
echo "<!-- Page started -->";
flush();

// Start session
session_start();

// Output something IMMEDIATELY to confirm PHP is working
echo "<!DOCTYPE html><html><head><title>Debug Test</title></head><body>";
echo "<h1>🔍 DEBUG TEST - PHP IS WORKING!</h1>";
echo "<hr>";

// Check if we can see session
echo "<h2>Session Check:</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green; font-size: 20px;'><strong>✅ YOU ARE LOGGED IN!</strong></p>";
    echo "<p>User ID: <strong>" . $_SESSION['user_id'] . "</strong></p>";
    echo "<p>Username: <strong>" . (isset($_SESSION['username']) ? $_SESSION['username'] : 'Not set') . "</strong></p>";
    echo "<p>Role: <strong>" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'Not set') . "</strong></p>";
    echo "<p>Role Name: <strong>" . (isset($_SESSION['role_name']) ? $_SESSION['role_name'] : 'Not set') . "</strong></p>";
} else {
    echo "<p style='color: red; font-size: 20px;'><strong>❌ NOT LOGGED IN</strong></p>";
    echo "<p>No user_id found in session</p>";
}

echo "<hr>";
echo "<h2>Full Session Data:</h2>";
echo "<pre style='background: #f0f0f0; padding: 20px; border-radius: 8px;'>";
print_r($_SESSION);
echo "</pre>";

echo "<hr>";
echo "<h2>PHP Info:</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'NOT ACTIVE') . "</p>";
echo "<p>Session ID: " . session_id() . "</p>";

echo "<hr>";
echo "<h2>✅ TEST COMPLETE</h2>";
echo "<p>If you can see this message, PHP is working and we can read your session!</p>";

echo "</body></html>";
?>