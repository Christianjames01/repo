<?php
// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Job Board Diagnostic</h2>";
echo "<pre>";

// Step 1: Check database connection
echo "Step 1: Checking database connection...\n";
try {
    require_once '../../../config/database.php';
    echo "✅ Database connected successfully\n\n";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    exit;
}

// Step 2: Check if tables exist
echo "Step 2: Checking if job board tables exist...\n";
$tables = ['tbl_companies', 'tbl_jobs', 'tbl_job_applications'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✅ Table $table exists\n";
    } else {
        echo "❌ Table $table does NOT exist\n";
    }
}
echo "\n";

// Step 3: Check data in tables
echo "Step 3: Checking data in tables...\n";
$result = $conn->query("SELECT COUNT(*) as count FROM tbl_companies");
$row = $result->fetch_assoc();
echo "Companies: " . $row['count'] . "\n";

$result = $conn->query("SELECT COUNT(*) as count FROM tbl_jobs");
$row = $result->fetch_assoc();
echo "Jobs: " . $row['count'] . "\n";

$result = $conn->query("SELECT COUNT(*) as count FROM tbl_job_applications");
$row = $result->fetch_assoc();
echo "Applications: " . $row['count'] . "\n\n";

// Step 4: Check if user is logged in
echo "Step 4: Checking user session...\n";
session_start();
if (isset($_SESSION['user_id'])) {
    echo "✅ User is logged in\n";
    echo "User ID: " . $_SESSION['user_id'] . "\n";
    echo "Username: " . ($_SESSION['username'] ?? 'N/A') . "\n";
    echo "Role: " . ($_SESSION['role'] ?? 'N/A') . "\n\n";
    
    $user_id = $_SESSION['user_id'];
    
    // Step 5: Check applications for this user
    echo "Step 5: Checking applications for this user...\n";
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_job_applications WHERE applicant_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    echo "Applications for this user: " . $row['count'] . "\n\n";
    
    if ($row['count'] > 0) {
        echo "Step 6: Showing applications...\n";
        $stmt = $conn->prepare("
            SELECT ja.*, j.job_title, j.job_type, j.location, c.company_name
            FROM tbl_job_applications ja
            INNER JOIN tbl_jobs j ON ja.job_id = j.job_id
            LEFT JOIN tbl_companies c ON j.company_id = c.company_id
            WHERE ja.applicant_id = ?
            ORDER BY ja.application_date DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $apps = $stmt->get_result();
        
        while ($app = $apps->fetch_assoc()) {
            echo "\n---Application #" . $app['application_id'] . "---\n";
            echo "Job: " . $app['job_title'] . "\n";
            echo "Company: " . $app['company_name'] . "\n";
            echo "Status: " . $app['status'] . "\n";
            echo "Applied: " . $app['application_date'] . "\n";
        }
    }
} else {
    echo "❌ User is NOT logged in\n";
    echo "Session data: \n";
    print_r($_SESSION);
}

echo "\n";
echo "Step 7: Checking header file...\n";
if (file_exists('../../../includes/header.php')) {
    echo "✅ Header file exists\n";
} else {
    echo "❌ Header file does NOT exist at ../../../includes/header.php\n";
}

echo "\n";
echo "Step 8: Checking footer file...\n";
if (file_exists('../../../includes/footer.php')) {
    echo "✅ Footer file exists\n";
} else {
    echo "❌ Footer file does NOT exist at ../../../includes/footer.php\n";
}

echo "</pre>";

echo "<h3>Diagnostic Complete</h3>";
echo "<p><a href='my-applications.php'>Go to My Applications page</a></p>";
?>