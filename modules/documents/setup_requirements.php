<?php
/**
 * Requirements Setup Script
 * This script helps set up requirements for document request types
 * Run this once to populate the requirements table
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';

requireLogin();
$user_role = getCurrentUserRole();

// Only admin can run this
if ($user_role !== 'Super Admin') {
    die('Unauthorized access. Only administrators can run this setup.');
}

// Check if tables exist
$tables_check = $conn->query("SHOW TABLES LIKE 'tbl_requirements'");
$requirements_table_exists = $tables_check->num_rows > 0;

$tables_check = $conn->query("SHOW TABLES LIKE 'tbl_request_attachments'");
$attachments_table_exists = $tables_check->num_rows > 0;

// Create tables if they don't exist
if (!$requirements_table_exists) {
    $sql = "CREATE TABLE `tbl_requirements` (
      `requirement_id` int(11) NOT NULL AUTO_INCREMENT,
      `request_type_id` int(11) NOT NULL,
      `requirement_name` varchar(255) NOT NULL,
      `description` text DEFAULT NULL,
      `is_mandatory` tinyint(1) DEFAULT 1,
      `is_active` tinyint(1) DEFAULT 1,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`requirement_id`),
      KEY `request_type_id` (`request_type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql)) {
        echo "✓ Created tbl_requirements table<br>";
    } else {
        echo "✗ Error creating tbl_requirements: " . $conn->error . "<br>";
    }
}

if (!$attachments_table_exists) {
    $sql = "CREATE TABLE `tbl_request_attachments` (
      `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
      `request_id` int(11) NOT NULL,
      `requirement_id` int(11) DEFAULT NULL,
      `file_name` varchar(255) NOT NULL,
      `file_path` varchar(500) NOT NULL,
      `file_type` varchar(100) DEFAULT NULL,
      `file_size` int(11) DEFAULT NULL,
      `uploaded_date` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`attachment_id`),
      KEY `request_id` (`request_id`),
      KEY `requirement_id` (`requirement_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql)) {
        echo "✓ Created tbl_request_attachments table<br>";
    } else {
        echo "✗ Error creating tbl_request_attachments: " . $conn->error . "<br>";
    }
}

// Get all request types
$request_types = [];
$sql = "SELECT * FROM tbl_request_types ORDER BY request_type_id";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $request_types[] = $row;
    }
}

echo "<h2>Request Types and Requirements Setup</h2>";
echo "<p>Found " . count($request_types) . " request types in your database.</p>";

// Check existing requirements
$existing_requirements = [];
$sql = "SELECT r.*, rt.request_type_name 
        FROM tbl_requirements r 
        JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        ORDER BY r.request_type_id, r.requirement_id";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (!isset($existing_requirements[$row['request_type_id']])) {
            $existing_requirements[$row['request_type_id']] = [];
        }
        $existing_requirements[$row['request_type_id']][] = $row;
    }
}

echo "<h3>Current Requirements:</h3>";
if (empty($existing_requirements)) {
    echo "<p><strong>No requirements found. You need to add requirements!</strong></p>";
} else {
    foreach ($request_types as $type) {
        echo "<h4>" . htmlspecialchars($type['request_type_name']) . " (ID: " . $type['request_type_id'] . ")</h4>";
        if (isset($existing_requirements[$type['request_type_id']])) {
            echo "<ul>";
            foreach ($existing_requirements[$type['request_type_id']] as $req) {
                $badge = $req['is_mandatory'] ? '<span style="color: red;">[REQUIRED]</span>' : '<span style="color: gray;">[OPTIONAL]</span>';
                echo "<li>" . $badge . " " . htmlspecialchars($req['requirement_name']);
                if ($req['description']) {
                    echo " - <em>" . htmlspecialchars($req['description']) . "</em>";
                }
                echo "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p><em>No requirements defined for this document type.</em></p>";
        }
    }
}

// Sample data to insert
$sample_requirements = [
    'Barangay Clearance' => [
        ['name' => 'Valid ID', 'desc' => 'Any government-issued ID (Driver\'s License, Passport, UMID, etc.)', 'mandatory' => 1],
        ['name' => 'Proof of Residency', 'desc' => 'Utility bill, Lease contract, or any proof showing current address', 'mandatory' => 1],
        ['name' => '1x1 ID Picture', 'desc' => 'Recent 1x1 picture with white background', 'mandatory' => 1],
        ['name' => 'Cedula', 'desc' => 'Current year Cedula (optional)', 'mandatory' => 0],
    ],
    'Certificate of Indigency' => [
        ['name' => 'Valid ID', 'desc' => 'Any government-issued ID', 'mandatory' => 1],
        ['name' => 'Proof of Residency', 'desc' => 'Document showing current address', 'mandatory' => 1],
        ['name' => 'Supporting Documents', 'desc' => 'Documents supporting indigency claim', 'mandatory' => 0],
    ],
    'Business Permit' => [
        ['name' => 'Valid ID of Owner', 'desc' => 'Government-issued ID of business owner', 'mandatory' => 1],
        ['name' => 'DTI/SEC Registration', 'desc' => 'Business registration certificate', 'mandatory' => 1],
        ['name' => 'Proof of Business Location', 'desc' => 'Contract of lease or proof of ownership', 'mandatory' => 1],
        ['name' => 'Barangay Clearance', 'desc' => 'Valid barangay clearance', 'mandatory' => 1],
    ],
    'Barangay ID' => [
        ['name' => 'Valid ID', 'desc' => 'Any government-issued ID or school ID', 'mandatory' => 1],
        ['name' => 'Proof of Residency', 'desc' => 'Document showing current address', 'mandatory' => 1],
        ['name' => '2x2 ID Picture', 'desc' => '2 pieces of recent 2x2 pictures', 'mandatory' => 1],
    ],
    'Cedula' => [
        ['name' => 'Valid ID', 'desc' => 'Any government-issued ID', 'mandatory' => 1],
        ['name' => 'Proof of Income', 'desc' => 'Certificate of Employment or ITR (if applicable)', 'mandatory' => 0],
    ],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_requirements'])) {
    $inserted = 0;
    $errors = [];
    
    foreach ($request_types as $type) {
        $type_name = $type['request_type_name'];
        $type_id = $type['request_type_id'];
        
        // Check if this type has sample requirements
        foreach ($sample_requirements as $sample_type => $requirements) {
            if (stripos($type_name, $sample_type) !== false || stripos($sample_type, $type_name) !== false) {
                // Insert requirements for this type
                foreach ($requirements as $req) {
                    $sql = "INSERT INTO tbl_requirements 
                            (request_type_id, requirement_name, description, is_mandatory, is_active) 
                            VALUES (?, ?, ?, ?, 1)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("issi", $type_id, $req['name'], $req['desc'], $req['mandatory']);
                    
                    if ($stmt->execute()) {
                        $inserted++;
                    } else {
                        $errors[] = "Failed to insert requirement '{$req['name']}' for {$type_name}";
                    }
                    $stmt->close();
                }
                break;
            }
        }
    }
    
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<strong>✓ Success!</strong> Inserted $inserted requirements.<br>";
    if (!empty($errors)) {
        echo "<br><strong>Errors:</strong><br>";
        foreach ($errors as $error) {
            echo "- $error<br>";
        }
    }
    echo "<a href='" . $_SERVER['PHP_SELF'] . "' style='color: #155724;'>Refresh page</a>";
    echo "</div>";
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Requirements Setup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h2, h3, h4 { color: #333; }
        .card {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn {
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if (empty($existing_requirements)): ?>
            <div class="warning">
                <strong>⚠ No requirements found!</strong><br>
                Click the button below to automatically add sample requirements based on your document types.
            </div>
            
            <form method="POST">
                <button type="submit" name="add_requirements" class="btn">
                    Add Sample Requirements
                </button>
            </form>
        <?php else: ?>
            <p><strong>✓ Requirements are configured.</strong></p>
            <p>You can manage requirements through the admin panel or manually edit the tbl_requirements table.</p>
        <?php endif; ?>
        
        <hr style="margin: 30px 0;">
        
        <h3>Manual SQL Query (Alternative Method)</h3>
        <p>If you prefer to add requirements manually, run these SQL queries in phpMyAdmin:</p>
        <pre style="background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto;">
<?php
foreach ($request_types as $type) {
    $type_name = $type['request_type_name'];
    $type_id = $type['request_type_id'];
    
    echo "-- Requirements for {$type_name} (ID: {$type_id})\n";
    
    // Find matching sample requirements
    foreach ($sample_requirements as $sample_type => $requirements) {
        if (stripos($type_name, $sample_type) !== false || stripos($sample_type, $type_name) !== false) {
            foreach ($requirements as $req) {
                $name = addslashes($req['name']);
                $desc = addslashes($req['desc']);
                echo "INSERT INTO tbl_requirements (request_type_id, requirement_name, description, is_mandatory, is_active) \n";
                echo "VALUES ({$type_id}, '{$name}', '{$desc}', {$req['mandatory']}, 1);\n";
            }
            echo "\n";
            break;
        }
    }
}
?>
        </pre>
        
        <p><a href="../dashboard/index.php">← Back to Dashboard</a></p>
    </div>
</body>
</html>