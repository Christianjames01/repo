<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "TEST 1: PHP is working<br>";

require_once '../../../config/database.php';
echo "TEST 2: Database config loaded<br>";

require_once '../../../config/auth.php';
echo "TEST 3: Auth config loaded<br>";

requireLogin();
echo "TEST 4: Login checked<br>";

if (!hasRole(['Resident'])) {
    echo "TEST 5: User is not a Resident<br>";
} else {
    echo "TEST 5: User is a Resident<br>";
}

$page_title = 'Test Page';
$current_user_id = getCurrentUserId();
echo "TEST 6: User ID = $current_user_id<br>";

echo "<hr>";
echo "About to include header...<br>";

// Try to include header
ob_start();
include '../../../includes/header.php';
$header_output = ob_get_clean();

echo "Header included successfully!<br>";
echo "Header output length: " . strlen($header_output) . " bytes<br>";

// Show a sample of the header
echo "<hr>";
echo "First 500 characters of header:<br>";
echo "<pre>" . htmlspecialchars(substr($header_output, 0, 500)) . "</pre>";

echo "<hr>";
echo "If you see this, the header is working but might have an issue with rendering.<br>";

// Actually output the header
echo $header_output;
?>

<div class="container-fluid px-4 py-4">
    <h1>This is the main content area</h1>
    <p>If you can see this, everything is working!</p>
</div>

<?php
echo "<hr>About to include footer...<br>";
include '../../../includes/footer.php';
echo "Footer included!<br>";
?>