<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
requireLogin();

$notification_id = intval($_GET['id'] ?? 0);
$redirect = $_GET['redirect'] ?? 'index.php';
$user_id = $_SESSION['user_id'];

if ($notification_id) {
    $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

// Handle empty/hash redirects
if ($redirect === '#' || $redirect === '' || $redirect === '/barangaylink1/modules/notifications/#') {
    header('Location: index.php');
    exit();
}

// Sanitize redirect - allow relative paths and known absolute paths
$allowed_absolute_prefixes = [
    '/barangaylink1/modules/',
    '/barangaylink1/vehicles/',
    '/barangaylink1/disasters/'
];

$allowed_relative_prefixes = [
    '../incidents/',
    '../blotter/',
    '../complaints/',
    '../requests/',
    '../health/',
    '../notifications/',
    '../staff/',
    '../residents/',
];

// Same-folder files (no ../ prefix) — exact match or with query string
$allowed_same_folder = [
    'index.php',
    'notification-detail.php',
    'email-history.php',
    'email-residents.php',
];

$safe = false;

// Check absolute paths
foreach ($allowed_absolute_prefixes as $prefix) {
    if (str_starts_with($redirect, $prefix)) {
        $safe = true;
        break;
    }
}

// Check relative paths (with ../)
if (!$safe) {
    foreach ($allowed_relative_prefixes as $prefix) {
        if (str_starts_with($redirect, $prefix)) {
            $safe = true;
            break;
        }
    }
}

// Check same-folder files
if (!$safe) {
    foreach ($allowed_same_folder as $file) {
        if ($redirect === $file || str_starts_with($redirect, $file . '?')) {
            $safe = true;
            break;
        }
    }
}

header('Location: ' . ($safe ? $redirect : 'index.php'));
exit();