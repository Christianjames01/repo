<?php
/**
 * DEBUG SCRIPT - Check tbl_waste_issues Table Structure
 * Upload this to your modules/waste_management/resident/ folder
 * Access it via browser to see what's wrong
 */

require_once('../../../config/config.php');
requireLogin();

echo "<!DOCTYPE html><html><head><title>Debug Table Structure</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "</head><body><div class='container mt-5'>";

echo "<h2>Debugging tbl_waste_issues Table</h2>";

// 1. Check if table exists
echo "<div class='card mt-3'><div class='card-header bg-primary text-white'><h5>1. Table Existence Check</h5></div>";
echo "<div class='card-body'>";

$tableCheck = $conn->query("SHOW TABLES LIKE 'tbl_waste_issues'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Table 'tbl_waste_issues' EXISTS</div>";
} else {
    echo "<div class='alert alert-danger'><i class='fas fa-times-circle'></i> Table 'tbl_waste_issues' DOES NOT EXIST</div>";
    echo "<p>You need to create this table first. Here's a sample SQL:</p>";
    echo "<pre>";
    echo "CREATE TABLE `tbl_waste_issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_type` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `location` varchar(255) NOT NULL,
  `urgency` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `photo_path` varchar(255) DEFAULT NULL,
  `reported_by` int(11) NOT NULL,
  `status` enum('pending','acknowledged','in_progress','resolved','closed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `reported_by` (`reported_by`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    echo "</pre>";
    echo "</div></div>";
    echo "</div></body></html>";
    exit;
}
echo "</div></div>";

// 2. Show table structure
echo "<div class='card mt-3'><div class='card-header bg-info text-white'><h5>2. Table Structure</h5></div>";
echo "<div class='card-body'>";
echo "<table class='table table-bordered table-sm'>";
echo "<thead class='table-dark'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>";
echo "<tbody>";

$result = $conn->query("SHOW COLUMNS FROM tbl_waste_issues");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
    echo "<tr>";
    echo "<td><strong>" . htmlspecialchars($row['Field']) . "</strong></td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
    echo "</tr>";
}

echo "</tbody></table>";
echo "</div></div>";

// 3. Check required columns
echo "<div class='card mt-3'><div class='card-header bg-warning'><h5>3. Required Columns Check</h5></div>";
echo "<div class='card-body'>";

$requiredColumns = [
    'issue_type' => 'varchar or text',
    'description' => 'text',
    'location' => 'varchar or text',
    'urgency' => 'varchar, enum, or text'
];

$optionalColumns = [
    'photo_path' => 'varchar (or photo, image_path)',
    'reported_by' => 'int (or user_id, reporter_id)',
    'status' => 'varchar or enum',
    'created_at' => 'timestamp or datetime (or date_created, report_date)'
];

echo "<h6>Required Columns:</h6>";
echo "<ul>";
foreach ($requiredColumns as $col => $type) {
    if (in_array($col, $columns)) {
        echo "<li class='text-success'><i class='fas fa-check'></i> <strong>$col</strong> - Found ($type)</li>";
    } else {
        echo "<li class='text-danger'><i class='fas fa-times'></i> <strong>$col</strong> - MISSING ($type)</li>";
    }
}
echo "</ul>";

echo "<h6>Optional Columns (at least one variant should exist):</h6>";
echo "<ul>";
foreach ($optionalColumns as $col => $variants) {
    if (in_array($col, $columns)) {
        echo "<li class='text-success'><i class='fas fa-check'></i> <strong>$col</strong> - Found</li>";
    } else {
        // Check for variants
        $found = false;
        if ($col === 'photo_path' && (in_array('photo', $columns) || in_array('image_path', $columns))) {
            $found = true;
            echo "<li class='text-success'><i class='fas fa-check'></i> Photo column - Found (variant)</li>";
        }
        if ($col === 'reported_by' && (in_array('user_id', $columns) || in_array('reporter_id', $columns))) {
            $found = true;
            echo "<li class='text-success'><i class='fas fa-check'></i> Reporter column - Found (variant)</li>";
        }
        if ($col === 'created_at' && (in_array('date_created', $columns) || in_array('report_date', $columns))) {
            $found = true;
            echo "<li class='text-success'><i class='fas fa-check'></i> Timestamp column - Found (variant)</li>";
        }
        
        if (!$found && !in_array($col, $columns)) {
            echo "<li class='text-warning'><i class='fas fa-exclamation-triangle'></i> <strong>$col</strong> - Not found ($variants)</li>";
        }
    }
}
echo "</ul>";
echo "</div></div>";

// 4. Test INSERT query
echo "<div class='card mt-3'><div class='card-header bg-success text-white'><h5>4. Sample INSERT Query</h5></div>";
echo "<div class='card-body'>";

// Build sample INSERT
$insert_fields = [];
$insert_values = [];

if (in_array('issue_type', $columns)) {
    $insert_fields[] = 'issue_type';
    $insert_values[] = "'Test Issue'";
}
if (in_array('description', $columns)) {
    $insert_fields[] = 'description';
    $insert_values[] = "'Test Description'";
}
if (in_array('location', $columns)) {
    $insert_fields[] = 'location';
    $insert_values[] = "'Test Location'";
}
if (in_array('urgency', $columns)) {
    $insert_fields[] = 'urgency';
    $insert_values[] = "'medium'";
}
if (in_array('reported_by', $columns)) {
    $insert_fields[] = 'reported_by';
    $insert_values[] = $_SESSION['user_id'];
} elseif (in_array('user_id', $columns)) {
    $insert_fields[] = 'user_id';
    $insert_values[] = $_SESSION['user_id'];
}
if (in_array('status', $columns)) {
    $insert_fields[] = 'status';
    $insert_values[] = "'pending'";
}
if (in_array('created_at', $columns)) {
    $insert_fields[] = 'created_at';
    $insert_values[] = 'NOW()';
}

$sampleSQL = "INSERT INTO tbl_waste_issues (" . implode(', ', $insert_fields) . ") 
              VALUES (" . implode(', ', $insert_values) . ")";

echo "<p>Based on your table structure, the INSERT query would be:</p>";
echo "<pre class='bg-light p-3'>" . htmlspecialchars($sampleSQL) . "</pre>";

echo "</div></div>";

// 5. Check permissions
echo "<div class='card mt-3'><div class='card-header bg-secondary text-white'><h5>5. Table Permissions</h5></div>";
echo "<div class='card-body'>";

$testInsert = "INSERT INTO tbl_waste_issues (" . implode(', ', $insert_fields) . ") 
               VALUES (" . implode(', ', $insert_values) . ")";

$stmt = $conn->prepare($testInsert);
if ($stmt) {
    echo "<div class='alert alert-success'><i class='fas fa-check'></i> Table is accessible and INSERT query prepared successfully</div>";
    echo "<p class='text-muted'><em>Note: We prepared the query but did not execute it to avoid creating test data.</em></p>";
    $stmt->close();
} else {
    echo "<div class='alert alert-danger'><i class='fas fa-times'></i> Error preparing INSERT query: " . htmlspecialchars($conn->error) . "</div>";
}

echo "</div></div>";

// 6. Check activity logs table
echo "<div class='card mt-3'><div class='card-header bg-dark text-white'><h5>6. Activity Logs Table Check</h5></div>";
echo "<div class='card-body'>";

$activityCheck = $conn->query("SHOW TABLES LIKE 'tbl_activity_logs'");
if ($activityCheck && $activityCheck->num_rows > 0) {
    echo "<div class='alert alert-success'><i class='fas fa-check'></i> Table 'tbl_activity_logs' EXISTS</div>";
    
    echo "<table class='table table-bordered table-sm'>";
    echo "<thead class='table-dark'><tr><th>Field</th><th>Type</th><th>Key</th></tr></thead>";
    echo "<tbody>";
    
    $activityCols = $conn->query("SHOW COLUMNS FROM tbl_activity_logs");
    while ($row = $activityCols->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($row['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle'></i> Table 'tbl_activity_logs' DOES NOT EXIST</div>";
    echo "<p>Activity logging will be skipped.</p>";
}

echo "</div></div>";

echo "<div class='mt-4'><a href='report-issue.php' class='btn btn-primary'>Back to Report Issue</a></div>";

echo "</div></body></html>";
?>