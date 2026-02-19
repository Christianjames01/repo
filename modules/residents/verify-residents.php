<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

// Require admin/staff login
requireLogin();
$user_role = getCurrentUserRole();
if ($user_role === 'Resident') {
    redirect('../../dashboard/index.php');
}

// Get resident ID
$resident_id = $_GET['id'] ?? null;
if (!$resident_id) {
    redirect('manage-residents.php');
}

// Verify resident
$success = verifyResident($conn, $resident_id, getCurrentUserId());

if ($success) {
    logActivity($conn, getCurrentUserId(), "Verified resident", "tbl_residents", $resident_id);
    $_SESSION['flash_success'] = "Resident verified successfully.";
} else {
    $_SESSION['flash_error'] = "Failed to verify resident.";
}

redirect('manage-residents.php');
