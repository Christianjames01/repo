<?php
require_once '../../config/database.php';

echo "<h1>Fixing Complaint Statuses - FORCE UPDATE</h1>";

// Check for triggers
echo "<h2>Checking for triggers on tbl_complaints:</h2>";
$triggers = $conn->query("SHOW TRIGGERS LIKE 'tbl_complaints'");
if ($triggers && $triggers->num_rows > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Trigger</th><th>Event</th><th>Timing</th></tr>";
    while ($trigger = $triggers->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($trigger['Trigger']) . "</td>";
        echo "<td>" . htmlspecialchars($trigger['Event']) . "</td>";
        echo "<td>" . htmlspecialchars($trigger['Timing']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No triggers found.</p>";
}

// Check table structure
echo "<h2>Checking status column definition:</h2>";
$columns = $conn->query("SHOW COLUMNS FROM tbl_complaints LIKE 'status'");
if ($columns && $row = $columns->fetch_assoc()) {
    echo "<pre>";
    print_r($row);
    echo "</pre>";
}

// FORCE UPDATE with explicit transaction
echo "<h2>Force Updating Statuses:</h2>";

// Start transaction
$conn->begin_transaction();

try {
    // Update complaint ID 1
    $stmt1 = $conn->prepare("UPDATE tbl_complaints SET status = ? WHERE complaint_id = 1");
    $status1 = 'Pending';
    $stmt1->bind_param("s", $status1);
    $stmt1->execute();
    echo "✓ Updated complaint 1, affected rows: " . $stmt1->affected_rows . "<br>";
    $stmt1->close();
    
    // Update complaint ID 2
    $stmt2 = $conn->prepare("UPDATE tbl_complaints SET status = ? WHERE complaint_id = 2");
    $status2 = 'Pending';
    $stmt2->bind_param("s", $status2);
    $stmt2->execute();
    echo "✓ Updated complaint 2, affected rows: " . $stmt2->affected_rows . "<br>";
    $stmt2->close();
    
    // Commit transaction
    $conn->commit();
    echo "<p style='color: green; font-weight: bold;'>✓ Transaction committed successfully</p>";
    
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

// Verify immediately after update
echo "<h2>Verification (immediately after update):</h2>";
$verify = $conn->query("SELECT complaint_id, status, LENGTH(status) as len, HEX(status) as hex_value FROM tbl_complaints ORDER BY complaint_id");
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Status</th><th>Length</th><th>HEX Value</th></tr>";
while ($row = $verify->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['complaint_id'] . "</td>";
    echo "<td>[" . htmlspecialchars($row['status']) . "]</td>";
    echo "<td>" . $row['len'] . "</td>";
    echo "<td>" . $row['hex_value'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// Show summary
echo "<h2>Final Summary:</h2>";
$summary = $conn->query("SELECT status, COUNT(*) as count FROM tbl_complaints GROUP BY status");
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Status</th><th>Count</th></tr>";
while ($row = $summary->fetch_assoc()) {
    echo "<tr>";
    echo "<td>[" . htmlspecialchars($row['status']) . "]</td>";
    echo "<td>" . $row['count'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<br><br>";
echo "<a href='view-complaints.php' style='padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px;'>Go to View Complaints</a>";

$conn->close();
?>