<?php
/**
 * Improved Automatic Fix for Missing Poll Creator Names
 * Handles cases where admin users don't have resident_id mapped
 * 
 * Upload to: /modules/community/admin/fix-poll-creators-improved.php
 * Access: http://localhost/barangaylink1/modules/community/admin/fix-poll-creators-improved.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Super Admin']);

echo "<h1>Poll Creator Auto-Fix Tool (Improved)</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border: 1px solid #ddd; }
    h2 { color: #333; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
    .error { background: #fee; color: #c00; padding: 15px; border-radius: 4px; margin: 10px 0; }
    .success { background: #efe; color: #060; padding: 15px; border-radius: 4px; margin: 10px 0; }
    .warning { background: #ffa; color: #660; padding: 15px; border-radius: 4px; margin: 10px 0; }
    .info { background: #def; color: #036; padding: 15px; border-radius: 4px; margin: 10px 0; }
    .btn { display: inline-block; padding: 12px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px; border: none; cursor: pointer; font-size: 16px; }
    .btn-danger { background: #dc2626; }
    .btn-success { background: #10b981; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background: #3b82f6; color: white; }
</style>";

// Check if fix should be applied
if (isset($_POST['apply_fix'])) {
    echo "<div class='section'>";
    echo "<h2>Applying Fix...</h2>";
    
    $default_resident_id = intval($_POST['default_resident_id']);
    
    if (!$default_resident_id) {
        echo "<div class='error'>❌ ERROR: No valid resident ID selected!</div>";
        echo "<p><a href='?' class='btn'>← Go Back</a></p>";
        echo "</div>";
        exit;
    }
    
    // Verify the resident exists
    $verify = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM tbl_residents WHERE resident_id = ?");
    $verify->bind_param("i", $default_resident_id);
    $verify->execute();
    $result = $verify->get_result();
    
    if ($result->num_rows == 0) {
        echo "<div class='error'>❌ ERROR: Selected resident ID does not exist!</div>";
        echo "<p><a href='?' class='btn'>← Go Back</a></p>";
        echo "</div>";
        exit;
    }
    
    $resident_name = $result->fetch_assoc()['name'];
    
    $conn->begin_transaction();
    
    try {
        // Fix 1: Update NULL or 0 created_by
        $stmt1 = $conn->prepare("UPDATE tbl_polls SET created_by = ? WHERE created_by IS NULL OR created_by = 0");
        $stmt1->bind_param("i", $default_resident_id);
        $stmt1->execute();
        $fixed_null = $stmt1->affected_rows;
        
        // Fix 2: Update invalid resident_id references
        $stmt2 = $conn->prepare("
            UPDATE tbl_polls p
            LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
            SET p.created_by = ?
            WHERE r.resident_id IS NULL AND p.created_by IS NOT NULL AND p.created_by != 0
        ");
        $stmt2->bind_param("i", $default_resident_id);
        $stmt2->execute();
        $fixed_invalid = $stmt2->affected_rows;
        
        $conn->commit();
        
        echo "<div class='success'>";
        echo "<h3>✅ Fix Applied Successfully!</h3>";
        echo "<ul>";
        echo "<li>Fixed <strong>$fixed_null</strong> polls with NULL/0 created_by</li>";
        echo "<li>Fixed <strong>$fixed_invalid</strong> polls with invalid resident references</li>";
        echo "<li>Total polls fixed: <strong>" . ($fixed_null + $fixed_invalid) . "</strong></li>";
        echo "</ul>";
        echo "<p>All fixed polls are now assigned to: <strong>{$resident_name}</strong> (Resident ID: {$default_resident_id})</p>";
        echo "</div>";
        
        echo "<div class='info'>";
        echo "<p><strong>Next Steps:</strong></p>";
        echo "<ol>";
        echo "<li>Go back to Manage Polls page</li>";
        echo "<li>Hard refresh the page (Ctrl+F5)</li>";
        echo "<li>Creator names should now appear</li>";
        echo "<li>You can delete this fix script file</li>";
        echo "</ol>";
        echo "</div>";
        
        echo "<p><a href='polls-manage.php' class='btn btn-success'>← Back to Manage Polls</a></p>";
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "<div class='error'>❌ ERROR: " . $e->getMessage() . "</div>";
        echo "<p><a href='?' class='btn'>← Try Again</a></p>";
    }
    
    echo "</div>";
    
} else {
    // Show diagnosis and let user select which resident to use
    echo "<div class='section'>";
    echo "<h2>Step 1: Diagnosis</h2>";
    
    // Count problems
    $null_count = $conn->query("SELECT COUNT(*) as count FROM tbl_polls WHERE created_by IS NULL OR created_by = 0")->fetch_assoc()['count'];
    
    $invalid_count = $conn->query("
        SELECT COUNT(*) as count 
        FROM tbl_polls p 
        LEFT JOIN tbl_residents r ON p.created_by = r.resident_id 
        WHERE p.created_by IS NOT NULL AND p.created_by != 0 AND r.resident_id IS NULL
    ")->fetch_assoc()['count'];
    
    $total_problems = $null_count + $invalid_count;
    
    if ($total_problems > 0) {
        echo "<div class='warning'>";
        echo "<h3>⚠️ Problems Found:</h3>";
        echo "<ul>";
        if ($null_count > 0) {
            echo "<li><strong>$null_count</strong> polls have NULL or 0 as created_by</li>";
        }
        if ($invalid_count > 0) {
            echo "<li><strong>$invalid_count</strong> polls reference non-existent residents (IDs: ";
            $invalid_ids = $conn->query("
                SELECT DISTINCT p.created_by 
                FROM tbl_polls p 
                LEFT JOIN tbl_residents r ON p.created_by = r.resident_id 
                WHERE p.created_by IS NOT NULL AND p.created_by != 0 AND r.resident_id IS NULL
            ");
            $ids = [];
            while ($row = $invalid_ids->fetch_assoc()) {
                $ids[] = $row['created_by'];
            }
            echo implode(', ', $ids) . ")</li>";
        }
        echo "</ul>";
        echo "<p><strong>Total polls to fix: $total_problems</strong></p>";
        echo "</div>";
        
        echo "</div>";
        
        // Show affected polls
        echo "<div class='section'>";
        echo "<h3>Affected Polls Preview:</h3>";
        $affected = $conn->query("
            SELECT p.poll_id, p.question, p.created_by, p.created_at
            FROM tbl_polls p
            LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
            WHERE (p.created_by IS NULL OR p.created_by = 0) 
               OR (r.resident_id IS NULL AND p.created_by IS NOT NULL)
            LIMIT 10
        ");
        
        echo "<table>";
        echo "<tr><th>Poll ID</th><th>Question</th><th>Current created_by</th><th>Created At</th></tr>";
        while ($row = $affected->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['poll_id']}</td>";
            echo "<td>" . htmlspecialchars(substr($row['question'], 0, 60)) . "</td>";
            echo "<td>" . ($row['created_by'] ?: 'NULL') . "</td>";
            echo "<td>{$row['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if ($total_problems > 10) {
            echo "<p><em>... and " . ($total_problems - 10) . " more polls</em></p>";
        }
        echo "</div>";
        
        // Let user choose which resident to assign
        echo "<div class='section'>";
        echo "<h2>Step 2: Select Default Creator</h2>";
        echo "<p>Choose which resident should be set as the creator for all problematic polls:</p>";
        
        // Get all residents
        echo "<form method='POST'>";
        echo "<h3>Option 1: Admin/Staff Users</h3>";
        $admin_residents = $conn->query("
            SELECT r.resident_id, CONCAT(r.first_name, ' ', r.last_name) as name, u.username, u.role
            FROM tbl_residents r
            INNER JOIN tbl_users u ON r.resident_id = u.resident_id
            WHERE u.role IN ('Admin', 'Super Admin', 'Staff')
            ORDER BY u.role DESC, r.first_name ASC
        ");
        
        if ($admin_residents->num_rows > 0) {
            echo "<div style='margin: 10px 0;'>";
            while ($row = $admin_residents->fetch_assoc()) {
                echo "<label style='display: block; padding: 10px; margin: 5px 0; background: #f9fafb; border: 2px solid #d1d5db; border-radius: 6px; cursor: pointer;'>";
                echo "<input type='radio' name='default_resident_id' value='{$row['resident_id']}' required> ";
                echo "<strong>{$row['name']}</strong> ({$row['role']}) - Username: {$row['username']} - ID: {$row['resident_id']}";
                echo "</label>";
            }
            echo "</div>";
        } else {
            echo "<div class='warning'>No admin users with resident_id found.</div>";
        }
        
        echo "<h3>Option 2: All Residents</h3>";
        echo "<p><em>Select any resident from the system:</em></p>";
        $all_residents = $conn->query("
            SELECT r.resident_id, CONCAT(r.first_name, ' ', r.last_name) as name
            FROM tbl_residents r
            ORDER BY r.first_name ASC
            LIMIT 20
        ");
        
        echo "<div style='margin: 10px 0;'>";
        while ($row = $all_residents->fetch_assoc()) {
            echo "<label style='display: block; padding: 10px; margin: 5px 0; background: #f9fafb; border: 2px solid #d1d5db; border-radius: 6px; cursor: pointer;'>";
            echo "<input type='radio' name='default_resident_id' value='{$row['resident_id']}' required> ";
            echo "<strong>{$row['name']}</strong> - ID: {$row['resident_id']}";
            echo "</label>";
        }
        echo "</div>";
        
        echo "<div class='warning'>";
        echo "<h3>⚠️ Important:</h3>";
        echo "<p><strong>This will modify your database. Make sure you have a backup!</strong></p>";
        echo "</div>";
        
        echo "<div style='margin: 20px 0;'>";
        echo "<button type='submit' name='apply_fix' class='btn btn-success'>✅ Apply Fix Now</button>";
        echo "<a href='polls-manage.php' class='btn'>Cancel</a>";
        echo "</div>";
        echo "</form>";
        echo "</div>";
        
    } else {
        echo "<div class='success'>";
        echo "<h3>✅ No Problems Found!</h3>";
        echo "<p>All polls have valid creator references.</p>";
        echo "</div>";
        
        echo "<div class='info'>";
        echo "<p>If you still don't see creator names:</p>";
        echo "<ol>";
        echo "<li>Hard refresh the page (Ctrl+F5)</li>";
        echo "<li>Clear browser cache</li>";
        echo "<li>Check browser console for JavaScript errors (F12)</li>";
        echo "<li>Verify the files get-poll-details.php and check-poll-expiry.php are uploaded</li>";
        echo "</ol>";
        echo "</div>";
        
        echo "<p><a href='polls-manage.php' class='btn'>← Back to Manage Polls</a></p>";
    }
    
    echo "</div>";
}
?>