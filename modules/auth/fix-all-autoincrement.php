<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

$secret_code = "fix2024";

if (isset($_POST['access_code']) && $_POST['access_code'] !== $secret_code) {
    die("Invalid access code");
}

if (!isset($_POST['access_code'])) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>Check & Fix AUTO_INCREMENT</title>
    <style>
        body { font-family: Arial; max-width: 600px; margin: 50px auto; padding: 20px; }
        input { width: 100%; padding: 10px; margin: 10px 0; }
        button { padding: 15px 30px; background: #0d6efd; color: white; border: none; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
    <h1>Database AUTO_INCREMENT Checker</h1>
    <form method="POST">
        <label>Access Code:</label>
        <input type="password" name="access_code" required>
        <button type="submit">Check Tables</button>
    </form>
    <p><small>Access code: <strong>fix2024</strong></small></p>
</body>
</html>
<?php
exit();
}

echo "<!DOCTYPE html><html><head><title>AUTO_INCREMENT Status</title>";
echo "<style>";
echo "body { font-family: Arial; max-width: 900px; margin: 20px auto; padding: 20px; }";
echo "table { width: 100%; border-collapse: collapse; margin: 20px 0; }";
echo "th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }";
echo "th { background: #0d6efd; color: white; }";
echo ".good { color: green; font-weight: bold; }";
echo ".bad { color: red; font-weight: bold; }";
echo ".warning { color: orange; font-weight: bold; }";
echo "button { padding: 10px 20px; background: #28a745; color: white; border: none; cursor: pointer; margin: 10px 5px; font-size: 14px; }";
echo "</style></head><body>";

echo "<h1>Database AUTO_INCREMENT Status</h1>";

$tables_to_check = ['tbl_users', 'tbl_residents', 'tbl_calendar_events'];
$results = [];

foreach ($tables_to_check as $table) {
    // Get table status
    $status_query = $conn->query("SHOW TABLE STATUS LIKE '$table'");
    if ($status_query && $status_row = $status_query->fetch_assoc()) {
        $auto_increment = $status_row['Auto_increment'];
        
        // Get max ID
        $max_query = $conn->query("SELECT MAX(SUBSTRING_INDEX('$table', '_', -1)) as max_id FROM $table");
        
        // Try different ID column names
        $id_column = null;
        if (strpos($table, 'users') !== false) $id_column = 'user_id';
        elseif (strpos($table, 'residents') !== false) $id_column = 'resident_id';
        elseif (strpos($table, 'calendar') !== false) $id_column = 'id';
        
        if ($id_column) {
            $max_query = $conn->query("SELECT MAX($id_column) as max_id FROM $table");
            if ($max_query) {
                $max_row = $max_query->fetch_assoc();
                $max_id = $max_row['max_id'] ?? 0;
                
                // Check for ID = 0
                $zero_query = $conn->query("SELECT COUNT(*) as cnt FROM $table WHERE $id_column = 0");
                $zero_count = 0;
                if ($zero_query) {
                    $zero_row = $zero_query->fetch_assoc();
                    $zero_count = $zero_row['cnt'];
                }
                
                $results[] = [
                    'table' => $table,
                    'id_column' => $id_column,
                    'max_id' => $max_id,
                    'auto_increment' => $auto_increment,
                    'zero_count' => $zero_count,
                    'status' => ($auto_increment > $max_id && $zero_count == 0) ? 'GOOD' : 'NEEDS FIX'
                ];
            }
        }
    }
}

echo "<table>";
echo "<tr><th>Table</th><th>ID Column</th><th>Max ID</th><th>AUTO_INCREMENT</th><th>Records with ID=0</th><th>Status</th><th>Action</th></tr>";

foreach ($results as $result) {
    echo "<tr>";
    echo "<td><strong>" . $result['table'] . "</strong></td>";
    echo "<td>" . $result['id_column'] . "</td>";
    echo "<td>" . $result['max_id'] . "</td>";
    echo "<td>" . $result['auto_increment'] . "</td>";
    echo "<td>" . ($result['zero_count'] > 0 ? '<span class="bad">' . $result['zero_count'] . '</span>' : '<span class="good">0</span>') . "</td>";
    
    if ($result['status'] == 'GOOD') {
        echo "<td><span class='good'>✓ GOOD</span></td>";
        echo "<td>-</td>";
    } else {
        echo "<td><span class='bad'>✗ NEEDS FIX</span></td>";
        echo "<td>";
        echo "<form method='POST' style='display:inline;'>";
        echo "<input type='hidden' name='access_code' value='" . htmlspecialchars($secret_code) . "'>";
        echo "<input type='hidden' name='fix_table' value='" . $result['table'] . "'>";
        echo "<button type='submit'>Fix Now</button>";
        echo "</form>";
        echo "</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Handle fix request
if (isset($_POST['fix_table'])) {
    $table_to_fix = $_POST['fix_table'];
    
    echo "<hr><h2>Fixing: $table_to_fix</h2>";
    
    // Find the result for this table
    $table_info = null;
    foreach ($results as $r) {
        if ($r['table'] == $table_to_fix) {
            $table_info = $r;
            break;
        }
    }
    
    if ($table_info) {
        $next_id = $table_info['max_id'] + 1;
        $id_column = $table_info['id_column'];
        
        echo "<p>Setting AUTO_INCREMENT to: <strong>$next_id</strong></p>";
        
        // Fix AUTO_INCREMENT
        if ($conn->query("ALTER TABLE $table_to_fix AUTO_INCREMENT = $next_id")) {
            echo "<p style='color: green;'>✓ AUTO_INCREMENT updated successfully</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed: " . $conn->error . "</p>";
        }
        
        // Delete records with ID 0 if any
        if ($table_info['zero_count'] > 0) {
            echo "<p>Deleting " . $table_info['zero_count'] . " record(s) with $id_column = 0...</p>";
            if ($conn->query("DELETE FROM $table_to_fix WHERE $id_column = 0")) {
                echo "<p style='color: green;'>✓ Deleted records with ID = 0</p>";
            } else {
                echo "<p style='color: red;'>✗ Failed: " . $conn->error . "</p>";
            }
        }
        
        echo "<p><strong>✓ Table fixed! Refresh the page to see updated status.</strong></p>";
        echo "<a href='' style='padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; display: inline-block;'>Refresh Page</a>";
    }
}

echo "<hr>";
echo "<h2>Next Steps:</h2>";
echo "<ol>";
echo "<li>Make sure all tables show '<span class='good'>✓ GOOD</span>' status</li>";
echo "<li><a href='/barangaylink1/modules/auth/register.php'>Register a new account</a></li>";
echo "<li><a href='/barangaylink1/modules/auth/login.php'>Login with your new account</a></li>";
echo "</ol>";

echo "</body></html>";
?>