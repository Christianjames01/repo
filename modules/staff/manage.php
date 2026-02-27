<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Force login and check admin/staff role
requireLogin();
$user_role = getCurrentUserRole();
if ($user_role !== 'Admin' && $user_role !== 'Staff' && $user_role !== 'Super Admin') {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$page_title = 'Manage Staff';
$success_message = '';
$error_message = '';

// Create uploads directory if it doesn't exist
$upload_dir = '../../uploads/profiles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Check database structure
$check_status = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'status'");
$has_status = $check_status->num_rows > 0;

$check_is_active = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'is_active'");
$has_is_active = $check_is_active->num_rows > 0;

$check_phone = $conn->query("SHOW COLUMNS FROM tbl_residents LIKE 'phone'");
$has_phone = $check_phone->num_rows > 0;

if (!$has_phone) {
    $conn->query("ALTER TABLE tbl_residents ADD COLUMN phone VARCHAR(20) AFTER email");
    $has_phone = true;
}

$check_photo = $conn->query("SHOW COLUMNS FROM tbl_residents LIKE 'profile_photo'");
if ($check_photo->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_residents ADD COLUMN profile_photo VARCHAR(255) AFTER phone");
}

// Determine which status column to use
$status_column = $has_status ? 'u.status' : ($has_is_active ? 'u.is_active' : "'active' as status");

// ─── Role map: role name → role_id ───────────────────────────────────────────
$role_map = [
    'Admin'            => 1,
    'Staff'            => 6,
    'Secretary'        => 3,
    'Treasurer'        => 4,
    'Tanod'            => 5,
    'Barangay Tanod'   => 5,
    'Driver'           => 20,
    'Barangay Captain' => 2,
];

// ─── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Helper: upload a profile photo ───────────────────────────────────────
    function handlePhotoUpload($upload_dir, &$error_message) {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($_FILES['profile_photo']['type'], $allowed_types) || $_FILES['profile_photo']['size'] > 5242880) {
            $error_message = "Invalid photo format or size too large (max 5MB).";
            return false;
        }
        $extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $filename  = uniqid() . '_' . time() . '.' . $extension;
        if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $filename)) {
            $error_message = "Failed to upload profile photo.";
            return false;
        }
        return $filename;
    }

    // ── ADD STAFF — Super Admin only ──────────────────────────────────────────
    if ($_POST['action'] === 'add_staff') {
        if ($user_role !== 'Super Admin') {
            $_SESSION['temp_error'] = 'Only Super Admins can add new staff members.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        try {
            $username          = trim($_POST['username']);
            $email             = trim($_POST['email']);
            $password          = $_POST['password'];
            $first_name        = trim($_POST['first_name']);
            $middle_name       = trim($_POST['middle_name'] ?? '');
            $last_name         = trim($_POST['last_name']);
            $ext               = trim($_POST['ext'] ?? '');
            $role              = trim($_POST['role']);
            $phone             = trim($_POST['phone'] ?? '');
            $date_of_birth     = $_POST['date_of_birth'] ?? '';
            $gender            = $_POST['gender'] ?? '';
            $civil_status      = $_POST['civil_status'] ?? '';
            $occupation        = trim($_POST['occupation'] ?? '');
            $birthplace        = trim($_POST['birthplace'] ?? '');
            $permanent_address = trim($_POST['permanent_address'] ?? '');
            $street            = trim($_POST['street'] ?? '');
            $barangay          = trim($_POST['barangay'] ?? '');
            $town              = trim($_POST['town'] ?? '');
            $province          = trim($_POST['province'] ?? '');

            // Build full address
            $address_parts = array_filter([$permanent_address, $street, $barangay, $town, $province]);
            $address = implode(', ', $address_parts);

            // Resolve role_id
            $role_id = isset($role_map[$role]) ? $role_map[$role] : intval($_POST['role_id'] ?? 0);

            // Validate required fields
            if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($role)) {
                $error_message = "All required fields must be filled in.";
            }

            // Validate age (must be at least 18)
            if (!$error_message && !empty($date_of_birth)) {
                $dob = new DateTime($date_of_birth);
                $now = new DateTime();
                if ($now->diff($dob)->y < 18) {
                    $error_message = "Staff member must be at least 18 years old.";
                }
            }

            // Validate contact number (Philippine format)
            if (!$error_message && !empty($phone)) {
                $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($clean_phone) != 11 || substr($clean_phone, 0, 2) != '09') {
                    $error_message = "Contact number must be in format 09XXXXXXXXX";
                } else {
                    $phone = $clean_phone;
                }
            }

            // Photo upload
            if (!$error_message) {
                $photo_result  = handlePhotoUpload($upload_dir, $error_message);
                $profile_photo = ($photo_result === false) ? null : $photo_result;
            }

            // Username duplicate check
            if (!$error_message) {
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $dup = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($dup) $error_message = "Username already exists!";
            }

            // Email duplicate check
            if (!$error_message) {
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $dup = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($dup) $error_message = "Email already exists!";
            }

            // Insert resident with full fields
            if (!$error_message) {
                $is_verified = 1;
                $res_status  = 'active';

                // ── Safely get next resident_id (handles non-AUTO_INCREMENT tables) ──
                $conn->query("LOCK TABLES tbl_residents WRITE");
                $id_res = $conn->query("SELECT COALESCE(MAX(resident_id), 0) + 1 AS next_id FROM tbl_residents");
                if (!$id_res) {
                    $conn->query("UNLOCK TABLES");
                    $error_message = "Could not determine next resident_id: " . $conn->error;
                }
            }

            if (!$error_message) {
                $next_resident_id = (int) $id_res->fetch_assoc()['next_id'];

                $stmt = $conn->prepare("
                    INSERT INTO tbl_residents
                        (resident_id, first_name, middle_name, last_name, ext_name,
                         date_of_birth, birthplace, gender, civil_status,
                         address, permanent_address, street, barangay, town, province,
                         contact_number, phone, email, occupation,
                         profile_photo, status, is_verified, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param("issssssssssssssssssssi",
                    $next_resident_id,
                    $first_name, $middle_name, $last_name, $ext,
                    $date_of_birth, $birthplace, $gender, $civil_status,
                    $address, $permanent_address, $street, $barangay, $town, $province,
                    $phone, $phone,
                    $email, $occupation,
                    $profile_photo,
                    $res_status,
                    $is_verified
                );

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    // Use the manually assigned ID — insert_id is 0 on non-AUTO_INCREMENT tables
                    $resident_id = $next_resident_id;
                    $stmt->close();
                    $conn->query("UNLOCK TABLES");

                    // Insert user
                    if ($has_status && $has_is_active) {
                        $u_status  = 'active';
                        $is_active = 1;
                        $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, status, is_active, resident_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssisii", $username, $email, $password, $role, $role_id, $u_status, $is_active, $resident_id);
                    } elseif ($has_status) {
                        $u_status = 'active';
                        $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, status, resident_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssisi", $username, $email, $password, $role, $role_id, $u_status, $resident_id);
                    } elseif ($has_is_active) {
                        $is_active = 1;
                        $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, is_active, resident_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssiii", $username, $email, $password, $role, $role_id, $is_active, $resident_id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, resident_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssii", $username, $email, $password, $role, $role_id, $resident_id);
                    }

                    if ($stmt->execute()) {
                        $success_message = "Staff member added successfully!";
                    } else {
                        $error_message = "Error creating user account: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $conn->query("UNLOCK TABLES");
                    $error_message = "Error creating resident record: " . $stmt->error;
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            $conn->query("UNLOCK TABLES");
            $error_message = "Error: " . $e->getMessage();
        }
    }

    // ── EDIT STAFF ────────────────────────────────────────────────────────────
    elseif ($_POST['action'] === 'edit_staff') {
        try {
            $user_id    = intval($_POST['user_id']);
            $username   = trim($_POST['username']);
            $email      = trim($_POST['email']);
            $first_name = trim($_POST['first_name']);
            $last_name  = trim($_POST['last_name']);
            $role       = trim($_POST['role'] ?? '');
            $phone      = trim($_POST['phone'] ?? '');
            $password   = $_POST['password'] ?? '';

            if (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
                $error_message = "All required fields must be filled in.";
            }

            $profile_photo = null;
            $update_photo  = false;
            if (!$error_message) {
                $photo_result = handlePhotoUpload($upload_dir, $error_message);
                if ($photo_result === false) {
                    // error already set
                } elseif ($photo_result !== null) {
                    $profile_photo = $photo_result;
                    $update_photo  = true;

                    $stmt = $conn->prepare("SELECT r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id WHERE u.user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $old = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($old && $old['profile_photo'] && file_exists($upload_dir . $old['profile_photo'])) {
                        unlink($upload_dir . $old['profile_photo']);
                    }
                }
            }

            if (!$error_message) {
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? AND user_id != ?");
                $stmt->bind_param("si", $username, $user_id);
                $stmt->execute();
                $dup = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($dup) $error_message = "Username already exists!";
            }

            if (!$error_message) {
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? AND user_id != ?");
                $stmt->bind_param("si", $email, $user_id);
                $stmt->execute();
                $dup = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($dup) $error_message = "Email already in use by another account!";
            }

            if (!$error_message) {
                $stmt = $conn->prepare("SELECT resident_id, role, role_id FROM tbl_users WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $resident_id = $cur['resident_id'] ?? null;

                if (empty($role)) {
                    $role    = $cur['role'];
                    $role_id = intval($cur['role_id']);
                } else {
                    $role_id = isset($role_map[$role]) ? $role_map[$role] : intval($_POST['role_id'] ?? $cur['role_id']);
                }

                if ($resident_id) {
                    if ($update_photo) {
                        $stmt = $conn->prepare("UPDATE tbl_residents SET first_name=?, last_name=?, email=?, phone=?, profile_photo=? WHERE resident_id=?");
                        $stmt->bind_param("sssssi", $first_name, $last_name, $email, $phone, $profile_photo, $resident_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE tbl_residents SET first_name=?, last_name=?, email=?, phone=? WHERE resident_id=?");
                        $stmt->bind_param("ssssi", $first_name, $last_name, $email, $phone, $resident_id);
                    }
                    if (!$stmt->execute()) {
                        $error_message = "Error updating resident record: " . $stmt->error;
                    }
                    $stmt->close();
                }

                if (!$error_message) {
                    if (!empty($password)) {
                        $stmt = $conn->prepare("UPDATE tbl_users SET username=?, email=?, password=?, role=?, role_id=? WHERE user_id=?");
                        $stmt->bind_param("ssssii", $username, $email, $password, $role, $role_id, $user_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE tbl_users SET username=?, email=?, role=?, role_id=? WHERE user_id=?");
                        $stmt->bind_param("sssii", $username, $email, $role, $role_id, $user_id);
                    }
                    if ($stmt->execute()) {
                        $success_message = "Staff member updated successfully!";
                    } else {
                        $error_message = "Error updating user: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }

    // ── TOGGLE STATUS ─────────────────────────────────────────────────────────
    elseif ($_POST['action'] === 'toggle_status') {
        if (!$has_status && !$has_is_active) {
            $error_message = "Status toggle is not supported in your current database schema.";
        } else {
            try {
                $user_id    = intval($_POST['user_id']);
                $new_status = $_POST['new_status'];

                if ($has_status) {
                    $stmt = $conn->prepare("UPDATE tbl_users SET status = ? WHERE user_id = ?");
                    $stmt->bind_param("si", $new_status, $user_id);
                } else {
                    $is_active = ($new_status === 'active') ? 1 : 0;
                    $stmt = $conn->prepare("UPDATE tbl_users SET is_active = ? WHERE user_id = ?");
                    $stmt->bind_param("ii", $is_active, $user_id);
                }

                if ($stmt->execute()) {
                    $success_message = ($new_status === 'active') ? "Staff member activated!" : "Staff member deactivated!";
                } else {
                    $error_message = "Error updating status: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    }

    // ── DELETE STAFF ──────────────────────────────────────────────────────────
    elseif ($_POST['action'] === 'delete_staff') {
        try {
            $user_id = intval($_POST['user_id']);

            $stmt = $conn->prepare("SELECT r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id WHERE u.user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && $row['profile_photo'] && file_exists($upload_dir . $row['profile_photo'])) {
                unlink($upload_dir . $row['profile_photo']);
            }

            $stmt = $conn->prepare("DELETE FROM tbl_users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $success_message = "Staff member deleted successfully!";
            } else {
                $error_message = "Error deleting staff: " . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }

    $_SESSION['temp_success'] = $success_message;
    $_SESSION['temp_error']   = $error_message;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Pull flash messages
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']);   }

// ─── Fetch staff list ─────────────────────────────────────────────────────────
$staff_members = [];
$query = "
    SELECT u.user_id, u.username, u.email, $status_column AS status, u.created_at, u.role, u.role_id,
           r.first_name, r.last_name, r.phone, r.profile_photo
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.role IN ('Admin', 'Staff', 'Tanod', 'Barangay Tanod', 'Driver', 'Barangay Captain', 'Secretary', 'Treasurer')
    ORDER BY u.role, r.last_name, r.first_name
";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $staff_members[] = $row;
}

include '../../includes/header.php';
?>

<style>
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}
.staff-management { padding: 2rem; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
.page-title { font-size: 1.75rem; font-weight: 700; color: #1a202c; margin: 0; }
.add-btn { background: #0d6efd; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: all var(--transition-speed) ease; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.add-btn:hover { background: #0b5ed7; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.staff-table { background: white; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); overflow: hidden; }
.staff-table:hover { box-shadow: var(--shadow-md); }
.table-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); padding: 1.25rem 1.5rem; border-bottom: 2px solid #e9ecef; }
.search-box { position: relative; max-width: 400px; }
.search-box input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid #e9ecef; border-radius: 8px; font-size: 0.875rem; transition: all var(--transition-speed) ease; }
.search-box input:focus { outline: none; border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,0.1); }
.search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; }
table { width: 100%; border-collapse: collapse; }
thead { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); }
th { padding: 1rem 1.5rem; text-align: left; font-weight: 700; color: #495057; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #dee2e6; }
td { padding: 1rem 1.5rem; border-bottom: 1px solid #f1f3f5; color: #334155; }
tbody tr { transition: all var(--transition-speed) ease; background: white; }
tbody tr:hover { background: rgba(13,110,253,0.04); }
.staff-info { display: flex; align-items: center; gap: 1rem; }
.staff-avatar { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; overflow: hidden; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 2px 6px rgba(0,0,0,0.1); flex-shrink: 0; }
.staff-avatar img { width: 100%; height: 100%; object-fit: cover; }
.staff-details h4 { font-size: 0.875rem; font-weight: 600; color: #1e293b; margin: 0 0 0.25rem 0; }
.staff-details p { font-size: 0.75rem; color: #64748b; margin: 0; }
.badge { padding: 0.4rem 0.9rem; border-radius: 50px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.3px; display: inline-block; }
.badge-admin { background: #dbeafe; color: #1e40af; }
.badge-staff { background: #e0e7ff; color: #4338ca; }
.badge-tanod,.badge-barangay-tanod { background: #fef3c7; color: #92400e; }
.badge-driver { background: #d1fae5; color: #065f46; }
.badge-captain,.badge-barangay-captain { background: #fce7f3; color: #9f1239; }
.badge-secretary { background: #fef3c7; color: #92400e; }
.badge-treasurer { background: #dbeafe; color: #1e40af; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #fee2e2; color: #991b1b; }
.action-btns { display: flex; gap: 0.5rem; }
.btn-icon { padding: 0.5rem; border: none; background: transparent; color: #64748b; cursor: pointer; border-radius: 6px; transition: all var(--transition-speed) ease; }
.btn-icon:hover { background: #f1f5f9; color: #1e293b; }
.btn-icon.danger:hover { background: #fee2e2; color: #dc2626; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal.show { display: flex; }
.modal-content { background: white; border-radius: var(--border-radius-lg); width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg); animation: modalSlideIn 0.3s ease; }
@keyframes modalSlideIn { from { opacity: 0; transform: translateY(-20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
.modal-header { padding: 1.5rem; border-bottom: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0; position: sticky; top: 0; z-index: 10; }
.modal-title { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin: 0; }
.close-btn { background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all var(--transition-speed) ease; }
.close-btn:hover { background: #f1f5f9; color: #1e293b; }
.modal form { padding: 1.5rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; font-size: 0.875rem; font-weight: 700; color: #475569; margin-bottom: 0.5rem; }
.required { color: #ef4444; }
.form-control { width: 100%; padding: 0.75rem; border: 2px solid #e9ecef; border-radius: 8px; font-size: 0.875rem; transition: all var(--transition-speed) ease; box-sizing: border-box; background: white; }
.form-control:focus { outline: none; border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,0.1); }
.section-title {
    color: #1e3a8a; font-size: 0.95rem; font-weight: 700;
    margin: 1.5rem 0 1rem; padding-bottom: 0.5rem;
    border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; gap: 0.5rem;
}
.photo-upload-area { border: 2px dashed #e9ecef; border-radius: 8px; padding: 1.5rem; text-align: center; cursor: pointer; transition: all var(--transition-speed) ease; }
.photo-upload-area:hover { border-color: #0d6efd; background: #f8fafc; }
.photo-upload-area .upload-icon { font-size: 2rem; color: #94a3b8; margin-bottom: 0.5rem; }
.photo-preview { width: 100px; height: 100px; border-radius: 50%; margin: 0 auto 0.75rem; overflow: hidden; display: none; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.helper-text { font-size: 0.8rem; color: #64748b; margin-top: 0.25rem; }
.btn-submit { width: 100%; padding: 0.875rem; background: #0d6efd; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all var(--transition-speed) ease; font-size: 1rem; margin-top: 0.5rem; }
.btn-submit:hover { background: #0b5ed7; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn { padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all var(--transition-speed) ease; }
.btn-secondary { background: #e2e8f0; color: #475569; }
.btn-secondary:hover { background: #cbd5e1; }
.btn-danger { background: #dc2626; color: white; }
.btn-danger:hover { background: #b91c1c; }
.alert { padding: 1.25rem 1.5rem; border-radius: var(--border-radius); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; border-left: 4px solid; }
.alert-success { background: #d1fae5; color: #065f46; border-left-color: #198754; }
.alert-error { background: #fee2e2; color: #991b1b; border-left-color: #dc3545; }
.empty-state { text-align: center; padding: 4rem 2rem; color: #6c757d; }
.empty-state i { font-size: 4rem; color: #cbd5e1; margin-bottom: 1.5rem; display: block; }
.empty-state h3 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; }
.empty-state p { color: #94a3b8; margin-bottom: 1.5rem; }
.role-badge-note { font-size: 0.75rem; color: #64748b; margin-top: 0.35rem; }
@media (max-width: 768px) {
    .staff-management { padding: 1rem; }
    .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
    .form-row, .form-row-3 { grid-template-columns: 1fr; }
    th, td { font-size: 0.8rem; padding: 0.75rem; }
}
html { scroll-behavior: smooth; }
</style>

<div class="staff-management">

    <?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-users-cog me-2 text-primary"></i>Manage Staff
        </h1>
        <?php if ($user_role === 'Super Admin'): ?>
        <button class="add-btn" onclick="openModal('addStaffModal')">
            <i class="fas fa-plus"></i> Add Staff Member
        </button>
        <?php endif; ?>
    </div>

    <div class="staff-table">
        <div class="table-header">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search staff members…" onkeyup="searchTable()">
            </div>
        </div>

        <?php if (empty($staff_members)): ?>
        <div class="empty-state">
            <i class="fas fa-user-tie"></i>
            <h3>No Staff Members Yet</h3>
            <p>Start by adding your first staff member</p>
            <?php if ($user_role === 'Super Admin'): ?>
            <button class="add-btn" style="margin: 0 auto;" onclick="openModal('addStaffModal')">
                <i class="fas fa-plus"></i> Add Staff Member
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <table id="staffTable">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Role</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff_members as $staff): ?>
                <tr>
                    <td>
                        <div class="staff-info">
                            <div class="staff-avatar">
                                <?php if (!empty($staff['profile_photo']) && file_exists($upload_dir . $staff['profile_photo'])): ?>
                                    <img src="<?php echo $upload_dir . htmlspecialchars($staff['profile_photo']); ?>" alt="Profile">
                                <?php else:
                                    $f = !empty($staff['first_name']) ? substr($staff['first_name'],0,1) : substr($staff['username'],0,1);
                                    $l = !empty($staff['last_name'])  ? substr($staff['last_name'],0,1)  : '';
                                    echo strtoupper($f.$l);
                                endif; ?>
                            </div>
                            <div class="staff-details">
                                <h4><?php echo htmlspecialchars(!empty($staff['first_name']) ? $staff['first_name'].' '.$staff['last_name'] : $staff['username']); ?></h4>
                                <p>@<?php echo htmlspecialchars($staff['username']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php
                        $rn = $staff['role'];
                        $rc = 'badge-staff';
                        if ($rn==='Admin') $rc='badge-admin';
                        elseif ($rn==='Tanod'||$rn==='Barangay Tanod') $rc='badge-tanod';
                        elseif ($rn==='Driver') $rc='badge-driver';
                        elseif ($rn==='Barangay Captain') $rc='badge-captain';
                        elseif ($rn==='Secretary') $rc='badge-secretary';
                        elseif ($rn==='Treasurer') $rc='badge-treasurer';
                        ?>
                        <span class="badge <?php echo $rc; ?>"><?php echo htmlspecialchars($rn); ?></span>
                    </td>
                    <td>
                        <div style="font-size:.875rem;"><i class="fas fa-envelope text-muted me-1"></i><?php echo htmlspecialchars($staff['email']); ?></div>
                        <?php if (!empty($staff['phone'])): ?>
                        <div style="font-size:.75rem;color:#64748b;margin-top:.25rem;"><i class="fas fa-phone text-muted me-1"></i><?php echo htmlspecialchars($staff['phone']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $active = ($staff['status']==='active'||$staff['status']==1); ?>
                        <span class="badge <?php echo $active?'badge-active':'badge-inactive'; ?>">
                            <i class="fas fa-<?php echo $active?'check-circle':'times-circle'; ?> me-1"></i>
                            <?php echo $active?'Active':'Inactive'; ?>
                        </span>
                    </td>
                    <td><i class="fas fa-calendar-alt text-muted me-1"></i><?php echo date('M j, Y', strtotime($staff['created_at'])); ?></td>
                    <td>
                        <div class="action-btns">
                            <button class="btn-icon" onclick='editStaff(<?php echo json_encode($staff, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($has_status || $has_is_active): ?>
                            <button class="btn-icon" onclick='openStatusModal(<?php echo json_encode($staff, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="<?php echo $active?'Deactivate':'Activate'; ?>">
                                <i class="fas fa-<?php echo $active?'ban':'check'; ?>"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn-icon danger" onclick='openDeleteModal(<?php echo json_encode($staff, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════ ADD STAFF MODAL ═══════════════════════ -->
<div id="addStaffModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Staff Member</h2>
            <button class="close-btn" onclick="closeModal('addStaffModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="addStaffForm" onsubmit="return validateAddForm()">
            <input type="hidden" name="action" value="add_staff">

            <!-- ── Profile Photo ──────────────────────────────────── -->
            <div class="section-title"><i class="fas fa-camera"></i> Profile Photo</div>
            <div class="form-group">
                <div class="photo-upload-area" onclick="document.getElementById('add_photo').click()">
                    <div class="photo-preview" id="add_preview"></div>
                    <i class="fas fa-camera upload-icon"></i>
                    <p style="margin:0;color:#64748b;font-size:.875rem;">Click to upload photo <span style="color:#94a3b8;">(optional)</span></p>
                    <p style="margin:.25rem 0 0;font-size:.75rem;color:#94a3b8;">JPG, PNG or GIF · max 5 MB</p>
                </div>
                <input type="file" name="profile_photo" id="add_photo" accept="image/*" style="display:none;" onchange="previewPhoto(this,'add_preview')">
            </div>

            <!-- ── Personal Information ───────────────────────────── -->
            <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" class="form-control" required placeholder="Juan">
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" class="form-control" required placeholder="dela Cruz">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" class="form-control" placeholder="Middle name (optional)">
                </div>
                <div class="form-group">
                    <label>Extension</label>
                    <input type="text" name="ext" class="form-control" placeholder="Jr., Sr., III (optional)">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control"
                           max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                    <small class="helper-text">Must be 18 years or older</small>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender" class="form-control">
                        <option value="">Select gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Civil Status</label>
                    <select name="civil_status" class="form-control">
                        <option value="">Select status</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Occupation</label>
                    <input type="text" name="occupation" class="form-control" placeholder="Occupation (optional)">
                </div>
            </div>

            <div class="form-group">
                <label>Birthplace</label>
                <input type="text" name="birthplace" class="form-control" placeholder="City, Province">
            </div>

            <!-- ── Address Information ────────────────────────────── -->
            <div class="section-title"><i class="fas fa-map-marker-alt"></i> Address Information</div>

            <div class="form-group">
                <label>Permanent Address</label>
                <input type="text" name="permanent_address" class="form-control" placeholder="House No., Street">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Street</label>
                    <input type="text" name="street" class="form-control" placeholder="Street name (optional)">
                </div>
                <div class="form-group">
                    <label>Barangay</label>
                    <input type="text" name="barangay" class="form-control" placeholder="Barangay">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Town/City</label>
                    <input type="text" name="town" class="form-control" placeholder="Town/City">
                </div>
                <div class="form-group">
                    <label>Province</label>
                    <input type="text" name="province" class="form-control" placeholder="Province">
                </div>
            </div>

            <!-- ── Contact Information ────────────────────────────── -->
            <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>

            <div class="form-group">
                <label>Mobile Number</label>
                <input type="tel" name="phone" id="add_phone" class="form-control"
                       placeholder="09XXXXXXXXX" pattern="[0-9]{11}" maxlength="11">
                <small class="helper-text">Format: 09XXXXXXXXX</small>
            </div>

            <div class="form-group">
                <label>Email Address <span class="required">*</span></label>
                <input type="email" name="email" class="form-control" required placeholder="juan@example.com">
            </div>

            <!-- ── Role & Account ─────────────────────────────────── -->
            <div class="section-title"><i class="fas fa-id-badge"></i> Role & Account</div>

            <div class="form-group">
                <label>Role <span class="required">*</span></label>
                <select name="role" id="add_role" class="form-control" required>
                    <option value="">— Select Role —</option>
                    <option value="Admin">Admin</option>
                    <option value="Barangay Captain">Barangay Captain</option>
                    <option value="Secretary">Secretary</option>
                    <option value="Treasurer">Treasurer</option>
                    <option value="Barangay Tanod">Barangay Tanod</option>
                    <option value="Tanod">Tanod</option>
                    <option value="Staff">Staff</option>
                    <option value="Driver">Driver</option>
                </select>
                <input type="hidden" name="role_id" id="add_role_id" value="0">
                <p class="role-badge-note"><i class="fas fa-info-circle me-1"></i>Role ID is assigned automatically.</p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" required placeholder="juandelacruz"
                           minlength="5" maxlength="20">
                    <small class="helper-text">5–20 characters, letters, numbers, underscores only</small>
                </div>
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="add_password" class="form-control" required
                               placeholder="Password" style="padding-right:2.75rem;" minlength="8">
                        <button type="button" onclick="togglePw('add_password','add_pw_eye')"
                                style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;">
                            <i class="fas fa-eye" id="add_pw_eye"></i>
                        </button>
                    </div>
                    <small class="helper-text">Minimum 8 characters</small>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-user-plus"></i> Add Staff Member
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════ EDIT STAFF MODAL ══════════════════════ -->
<div id="editStaffModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Staff Member</h2>
            <button class="close-btn" onclick="closeModal('editStaffModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editStaffForm">
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="role_id" id="edit_role_id" value="0">

            <!-- Photo -->
            <div class="form-group">
                <label>Profile Photo <span style="font-weight:400;color:#94a3b8;">(leave blank to keep current)</span></label>
                <div class="photo-upload-area" onclick="document.getElementById('edit_photo').click()">
                    <div class="photo-preview" id="edit_preview"></div>
                    <i class="fas fa-camera upload-icon" id="edit_camera_icon"></i>
                    <p style="margin:0;color:#64748b;font-size:.875rem;" id="edit_upload_text">Click to change photo</p>
                    <p style="margin:.25rem 0 0;font-size:.75rem;color:#94a3b8;">JPG, PNG or GIF · max 5 MB</p>
                </div>
                <input type="file" name="profile_photo" id="edit_photo" accept="image/*" style="display:none;" onchange="previewPhoto(this,'edit_preview')">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label>Email <span class="required">*</span></label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" id="edit_phone" class="form-control">
            </div>

            <div class="form-group">
                <label>Role <span class="required">*</span></label>
                <select name="role" id="edit_role" class="form-control" required onchange="syncRoleId('edit')">
                    <option value="">— Select Role —</option>
                    <option value="Admin">Admin</option>
                    <option value="Barangay Captain">Barangay Captain</option>
                    <option value="Secretary">Secretary</option>
                    <option value="Treasurer">Treasurer</option>
                    <option value="Barangay Tanod">Barangay Tanod</option>
                    <option value="Tanod">Tanod</option>
                    <option value="Staff">Staff</option>
                    <option value="Driver">Driver</option>
                </select>
                <p class="role-badge-note"><i class="fas fa-info-circle me-1"></i>Role ID is assigned automatically.</p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username <span class="required">*</span></label>
                    <input type="text" name="username" id="edit_username" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="edit_password" class="form-control"
                               placeholder="Leave blank to keep current" style="padding-right:2.75rem;">
                        <button type="button" onclick="togglePw('edit_password','edit_pw_eye')"
                                style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;">
                            <i class="fas fa-eye" id="edit_pw_eye"></i>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Update Staff Member
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════ DELETE MODAL ══════════════════════════ -->
<div id="deleteStaffModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Delete Staff Member</h2>
            <button class="close-btn" onclick="closeModal('deleteStaffModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_staff">
            <input type="hidden" name="user_id" id="delete_user_id">
            <div style="padding:2rem;text-align:center;">
                <div style="width:80px;height:80px;margin:0 auto 1rem;background:linear-gradient(135deg,#fee2e2,#fecaca);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-user-times" style="font-size:2rem;color:#dc2626;"></i>
                </div>
                <h3 style="font-size:1.25rem;font-weight:600;margin-bottom:.5rem;color:#1e293b;">Are you sure?</h3>
                <p style="color:#64748b;margin:0;">You are about to delete:</p>
                <p style="font-weight:600;color:#1e293b;margin:.5rem 0;" id="delete_staff_name"></p>
                <p style="color:#64748b;font-size:.875rem;margin-top:1rem;">This action cannot be undone.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteStaffModal')">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════ STATUS MODAL ══════════════════════════ -->
<div id="statusToggleModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2 class="modal-title">
                <i id="status_modal_icon" class="fas fa-toggle-on me-2 text-primary"></i>
                <span id="status_modal_title">Change Status</span>
            </h2>
            <button class="close-btn" onclick="closeModal('statusToggleModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="user_id" id="status_user_id">
            <input type="hidden" name="new_status" id="status_new_status">
            <div style="padding:2rem;text-align:center;">
                <div id="status_icon_wrapper" style="width:80px;height:80px;margin:0 auto 1rem;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <i id="status_main_icon" style="font-size:2rem;"></i>
                </div>
                <h3 style="font-size:1.25rem;font-weight:600;margin-bottom:.5rem;color:#1e293b;" id="status_question"></h3>
                <p style="color:#64748b;margin:0;" id="status_action_text"></p>
                <p style="font-weight:600;color:#1e293b;margin:.5rem 0;" id="status_staff_name"></p>
                <p style="color:#64748b;font-size:.875rem;margin-top:1rem;" id="status_description"></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('statusToggleModal')">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn" id="status_confirm_btn">
                        <i class="fas fa-check me-1"></i><span id="status_btn_text">Confirm</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Role → role_id map ───────────────────────────────────────────────────────
const ROLE_MAP = {
    'Admin':            1,
    'Barangay Captain': 2,
    'Secretary':        3,
    'Treasurer':        4,
    'Tanod':            5,
    'Barangay Tanod':   5,
    'Staff':            6,
    'Driver':           20,
};

// ── Modal helpers ────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    if (id === 'addStaffModal')  resetPreview('add_preview',  'add_photo');
    if (id === 'editStaffModal') resetPreview('edit_preview', 'edit_photo');
}

function resetPreview(previewId, inputId) {
    const p = document.getElementById(previewId);
    if (p) { p.style.display = 'none'; p.innerHTML = ''; }
    const inp = document.getElementById(inputId);
    if (inp) inp.value = '';
}

// ── Photo preview ────────────────────────────────────────────────────────────
function previewPhoto(input, previewId) {
    const preview = document.getElementById(previewId);
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
        preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Sync role_id hidden field ────────────────────────────────────────────────
function syncRoleId(prefix) {
    const sel = document.getElementById(prefix + '_role');
    const val = sel ? sel.value : '';
    document.getElementById(prefix + '_role_id').value = ROLE_MAP[val] || 0;
}

document.getElementById('add_role').addEventListener('change', function() { syncRoleId('add'); });

// ── Phone: numbers only ──────────────────────────────────────────────────────
document.getElementById('add_phone').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
});

// ── Password toggle ──────────────────────────────────────────────────────────
function togglePw(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

// ── Validate Add form ────────────────────────────────────────────────────────
function validateAddForm() {
    const role = document.getElementById('add_role').value;
    if (!role) { alert('Please select a role.'); return false; }
    syncRoleId('add');

    // Validate phone if entered
    const phone = document.getElementById('add_phone').value;
    if (phone && (phone.length !== 11 || !phone.startsWith('09'))) {
        alert('Contact number must be in format 09XXXXXXXXX (11 digits).');
        return false;
    }

    return true;
}

// ── Populate Edit modal ──────────────────────────────────────────────────────
function editStaff(staff) {
    document.getElementById('edit_user_id').value    = staff.user_id;
    document.getElementById('edit_first_name').value = staff.first_name || '';
    document.getElementById('edit_last_name').value  = staff.last_name  || '';
    document.getElementById('edit_email').value      = staff.email      || '';
    document.getElementById('edit_phone').value      = staff.phone      || '';
    document.getElementById('edit_username').value   = staff.username   || '';
    document.getElementById('edit_password').value   = '';

    const roleSelect = document.getElementById('edit_role');
    roleSelect.value = staff.role || '';

    const mappedId = ROLE_MAP[staff.role];
    document.getElementById('edit_role_id').value = mappedId !== undefined ? mappedId : (staff.role_id || 0);

    const preview = document.getElementById('edit_preview');
    if (staff.profile_photo) {
        preview.innerHTML = '<img src="../../uploads/profiles/' + staff.profile_photo + '" alt="Current Photo">';
        preview.style.display = 'block';
    } else {
        preview.innerHTML     = '';
        preview.style.display = 'none';
    }

    openModal('editStaffModal');
}

// ── Delete modal ─────────────────────────────────────────────────────────────
function openDeleteModal(staff) {
    document.getElementById('delete_user_id').value = staff.user_id;
    const name = (staff.first_name && staff.last_name)
        ? staff.first_name + ' ' + staff.last_name
        : staff.username;
    document.getElementById('delete_staff_name').textContent = name + ' (' + staff.role + ')';
    openModal('deleteStaffModal');
}

// ── Status toggle modal ──────────────────────────────────────────────────────
function openStatusModal(staff) {
    const isActive  = (staff.status === 'active' || staff.status == 1);
    const newStatus = isActive ? 'inactive' : 'active';
    const name      = (staff.first_name && staff.last_name)
        ? staff.first_name + ' ' + staff.last_name
        : staff.username;

    document.getElementById('status_user_id').value    = staff.user_id;
    document.getElementById('status_new_status').value = newStatus;
    document.getElementById('status_staff_name').textContent = name + ' (' + staff.role + ')';

    const wrapper = document.getElementById('status_icon_wrapper');
    const icon    = document.getElementById('status_main_icon');
    const mIcon   = document.getElementById('status_modal_icon');
    const mTitle  = document.getElementById('status_modal_title');
    const q       = document.getElementById('status_question');
    const aText   = document.getElementById('status_action_text');
    const desc    = document.getElementById('status_description');
    const btn     = document.getElementById('status_confirm_btn');
    const btnTxt  = document.getElementById('status_btn_text');

    if (isActive) {
        wrapper.style.background = 'linear-gradient(135deg,#fef3c7,#fde68a)';
        icon.className = 'fas fa-user-slash'; icon.style.color = '#d97706';
        mIcon.className = 'fas fa-ban me-2 text-warning';
        mTitle.textContent = 'Deactivate Staff Member';
        q.textContent      = 'Deactivate this staff member?';
        aText.textContent  = 'You are about to deactivate:';
        desc.textContent   = 'They will lose system access. You can re-activate at any time.';
        btn.style.background = '#d97706'; btn.style.color = 'white';
        btnTxt.textContent = 'Deactivate';
    } else {
        wrapper.style.background = 'linear-gradient(135deg,#d1fae5,#a7f3d0)';
        icon.className = 'fas fa-user-check'; icon.style.color = '#059669';
        mIcon.className = 'fas fa-check-circle me-2 text-success';
        mTitle.textContent = 'Activate Staff Member';
        q.textContent      = 'Activate this staff member?';
        aText.textContent  = 'You are about to activate:';
        desc.textContent   = 'They will regain access to the system.';
        btn.style.background = '#059669'; btn.style.color = 'white';
        btnTxt.textContent = 'Activate';
    }

    openModal('statusToggleModal');
}

// ── Table search ─────────────────────────────────────────────────────────────
function searchTable() {
    const filter = document.getElementById('searchInput').value.toLowerCase();
    const rows   = document.querySelectorAll('#staffTable tbody tr');
    rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(filter) ? '' : 'none'; });
}

// ── Backdrop click to close ──────────────────────────────────────────────────
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) closeModal(e.target.id);
});

// ── Escape key to close ──────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(m => closeModal(m.id));
    }
});

// ── Auto-dismiss alerts ───────────────────────────────────────────────────────
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(a) {
        a.style.transition = 'opacity .3s';
        a.style.opacity    = '0';
        setTimeout(() => a.remove(), 300);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>