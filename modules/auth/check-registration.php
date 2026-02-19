<?php
/**
 * REGISTRATION DIAGNOSTIC TOOL
 * Place this at /barangaylink1/modules/auth/check-registration.php
 * 
 * This will show you what's in the database after registration
 */

require_once '../../config/config.php';
require_once '../../config/database.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Registration Diagnostic</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            background: #f5f5f5;
        }
        .box {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
            border-left: 4px solid #3b82f6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
    </style>
</head>
<body>
    <h1>📊 Registration Diagnostic</h1>
    
    <div class="box">
        <h2>Recent Residents (Last 10)</h2>
        <?php
        $stmt = $conn->prepare("SELECT * FROM tbl_residents ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<table>";
            echo "<tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Contact</th>
                    <th>Verified</th>
                    <th>Created</th>
                  </tr>";
            
            while ($row = $result->fetch_assoc()) {
                $verified = $row['is_verified'] ? '<span class="success">✓ Yes</span>' : '<span class="warning">⚠ No</span>';
                echo "<tr>
                        <td><strong>{$row['resident_id']}</strong></td>
                        <td>{$row['first_name']} {$row['last_name']}</td>
                        <td>{$row['email']}</td>
                        <td>{$row['contact_number']}</td>
                        <td>{$verified}</td>
                        <td>" . date('Y-m-d H:i:s', strtotime($row['created_at'])) . "</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ No residents found in database!</p>";
        }
        $stmt->close();
        ?>
    </div>

    <div class="box">
        <h2>Recent Users (Last 10)</h2>
        <?php
        $stmt = $conn->prepare("
            SELECT u.*, r.role_name 
            FROM tbl_users u
            LEFT JOIN tbl_roles r ON u.role_id = r.role_id
            ORDER BY u.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<table>";
            echo "<tr>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Resident ID</th>
                    <th>Status</th>
                    <th>Created</th>
                  </tr>";
            
            while ($row = $result->fetch_assoc()) {
                $status = ($row['is_active'] == 1) ? '<span class="success">✓ Active</span>' : '<span class="error">✗ Inactive</span>';
                $resident_link = $row['resident_id'] ? 
                    "<strong class='success'>#{$row['resident_id']}</strong>" : 
                    "<span class='error'>NULL</span>";
                
                echo "<tr>
                        <td><strong>{$row['user_id']}</strong></td>
                        <td>{$row['username']}</td>
                        <td>{$row['email']}</td>
                        <td>{$row['role_name']}</td>
                        <td>{$resident_link}</td>
                        <td>{$status}</td>
                        <td>" . date('Y-m-d H:i:s', strtotime($row['created_at'])) . "</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ No users found in database!</p>";
        }
        $stmt->close();
        ?>
    </div>

    <div class="box">
        <h2>User-Resident Linkage Check</h2>
        <?php
        $stmt = $conn->prepare("
            SELECT 
                u.user_id,
                u.username,
                u.resident_id as user_resident_id,
                r.resident_id as actual_resident_id,
                r.first_name,
                r.last_name,
                r.is_verified,
                CASE 
                    WHEN u.resident_id IS NULL THEN 'NO LINK'
                    WHEN u.resident_id = r.resident_id THEN 'LINKED'
                    ELSE 'BROKEN LINK'
                END as link_status
            FROM tbl_users u
            LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
            WHERE u.role_id = (SELECT role_id FROM tbl_roles WHERE role_name = 'Resident' LIMIT 1)
            ORDER BY u.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<table>";
            echo "<tr>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Link Status</th>
                    <th>User's Resident ID</th>
                    <th>Resident Name</th>
                    <th>Verified</th>
                  </tr>";
            
            while ($row = $result->fetch_assoc()) {
                $status_color = ($row['link_status'] == 'LINKED') ? 'success' : 'error';
                $verified = $row['is_verified'] ? '<span class="success">✓</span>' : '<span class="warning">✗</span>';
                
                echo "<tr>
                        <td>{$row['user_id']}</td>
                        <td>{$row['username']}</td>
                        <td class='{$status_color}'><strong>{$row['link_status']}</strong></td>
                        <td>{$row['user_resident_id']}</td>
                        <td>{$row['first_name']} {$row['last_name']}</td>
                        <td>{$verified}</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠ No resident users found!</p>";
        }
        $stmt->close();
        ?>
    </div>

    <div class="box">
        <h2>🔍 Common Issues</h2>
        <ul>
            <li><strong>User has NULL resident_id:</strong> Registration failed to link user to resident record</li>
            <li><strong>Resident not verified (is_verified = 0):</strong> Normal for new registrations - admin must verify</li>
            <li><strong>Broken link:</strong> resident_id points to non-existent resident</li>
        </ul>
    </div>

    <div class="box">
        <h2>Database Table Check</h2>
        <?php
        // Check if tables exist
        $tables = ['tbl_residents', 'tbl_users', 'tbl_roles'];
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->num_rows > 0) {
                $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
                $count = $count_result->fetch_assoc()['count'];
                echo "<p class='success'>✓ $table exists ({$count} records)</p>";
            } else {
                echo "<p class='error'>✗ $table does NOT exist!</p>";
            }
        }
        ?>
    </div>

    <div class="box">
        <h2>📝 What to Look For</h2>
        <ol>
            <li>Check if the newly registered resident appears in "Recent Residents"</li>
            <li>Check if the corresponding user appears in "Recent Users"</li>
            <li>In "User-Resident Linkage Check", verify that Link Status is "LINKED"</li>
            <li>New residents should have is_verified = 0 (unverified) until admin approves</li>
        </ol>
    </div>
</body>
</html>