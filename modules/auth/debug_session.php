<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Kill any existing session first
session_start();
session_destroy();
session_start();

require_once '../../config/database.php'; // just the $conn, nothing else

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    echo "<pre>DEBUG: username=$username, password=$password</pre>";

    $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE LOWER(username) = LOWER(?)");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    echo "<pre>USER FOUND: ";
    print_r($user);
    echo "</pre>";

    if ($user) {
        echo "<pre>Password in DB: " . $user['password'] . "</pre>";
        echo "<pre>Password entered: " . $password . "</pre>";
        echo "<pre>Direct match: " . ($password === $user['password'] ? 'YES' : 'NO') . "</pre>";
        echo "<pre>password_verify: " . (password_verify($password, $user['password']) ? 'YES' : 'NO') . "</pre>";
    }
    die(); // stop here so we can see the output
}
?>
<form method="POST">
    <input type="text" name="username" placeholder="username"><br>
    <input type="password" name="password" placeholder="password"><br>
    <button type="submit">Test Login</button>
</form>