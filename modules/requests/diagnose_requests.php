<?php
require_once '../../config/config.php';
require_once '../../config/session.php';

requireLogin();

echo "<!DOCTYPE html><html><head><title>Database Diagnostic</title></head><body>";
echo "<h1>Database Diagnostic - tbl_requests</h1>";

// Check table structure
echo "<h2>Table Structure</h2>";
$structure_sql = "DESCRIBE tbl_requests";
$structure_result = $conn->query($structure_sql);

if ($structure_result) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $structure_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check actual data
echo "<h2>Sample Request Data (First 5 Records)</h2>";
$data_sql = "SELECT request_id, resident_id, request_type_id, status, request_date 
             FROM tbl_requests 
             ORDER BY request_date DESC 
             LIMIT 5";
$data_result = $conn->query($data_sql);

if ($data_result) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>request_id</th><th>Type</th><th>resident_id</th><th>request_type_id</th><th>status</th><th>request_date</th></tr>";
    while ($row = $data_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['request_id']) . "</td>";
        echo "<td>" . gettype($row['request_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['resident_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['request_type_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['request_date']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check auto_increment status
echo "<h2>Auto Increment Status</h2>";
$ai_sql = "SHOW TABLE STATUS LIKE 'tbl_requests'";
$ai_result = $conn->query($ai_sql);

if ($ai_result && $row = $ai_result->fetch_assoc()) {
    echo "<p><strong>Auto_increment value:</strong> " . htmlspecialchars($row['Auto_increment'] ?? 'Not Set') . "</p>";
    echo "<p><strong>Engine:</strong> " . htmlspecialchars($row['Engine']) . "</p>";
}

// Count records with request_id = 0
echo "<h2>Problem Records</h2>";
$zero_sql = "SELECT COUNT(*) as zero_count FROM tbl_requests WHERE request_id = 0";
$zero_result = $conn->query($zero_sql);
if ($zero_result && $row = $zero_result->fetch_assoc()) {
    echo "<p><strong>Records with request_id = 0:</strong> " . $row['zero_count'] . "</p>";
}

// Suggested fix
echo "<h2>Suggested Fix</h2>";
echo "<div style='background: #ffffcc; padding: 15px; border: 2px solid #ffcc00;'>";
echo "<h3>Step 1: Check if request_id has AUTO_INCREMENT</h3>";
echo "<p>If the 'Extra' column above shows 'auto_increment' for request_id, it's configured correctly.</p>";
echo "<p>If NOT, run this SQL to fix it:</p>";
echo "<pre>ALTER TABLE tbl_requests MODIFY request_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY;</pre>";

echo "<h3>Step 2: Fix existing records with ID = 0</h3>";
echo "<p>If you have records with request_id = 0, you need to either:</p>";
echo "<ul>";
echo "<li><strong>Option A (Recommended):</strong> Delete those records if they're test data:<br>";
echo "<code>DELETE FROM tbl_requests WHERE request_id = 0;</code></li>";
echo "<li><strong>Option B:</strong> Reassign them proper IDs - contact your database admin</li>";
echo "</ul>";
echo "</div>";

echo "</body></html>";
?>