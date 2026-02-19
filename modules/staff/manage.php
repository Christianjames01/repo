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

// If phone column doesn't exist, add it
if (!$has_phone) {
    $conn->query("ALTER TABLE tbl_residents ADD COLUMN phone VARCHAR(20) AFTER email");
    $has_phone = true;
}

// Check if profile_photo column exists in tbl_residents
$check_photo = $conn->query("SHOW COLUMNS FROM tbl_residents LIKE 'profile_photo'");
if ($check_photo->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_residents ADD COLUMN profile_photo VARCHAR(255) AFTER phone");
}

// Determine which status column to use
$status_column = $has_status ? 'u.status' : ($has_is_active ? 'u.is_active' : "'active' as status");

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // ADD STAFF
        if ($_POST['action'] === 'add_staff') {
            try {
                $username = trim($_POST['username']);
                $email = trim($_POST['email']);
                $password = $_POST['password'];
                $first_name = trim($_POST['first_name']);
                $last_name = trim($_POST['last_name']);
                $role = $_POST['role'];
                $role_id = intval($_POST['role_id']);
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
                
                // Handle photo upload
                $profile_photo = null;
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    $file_type = $_FILES['profile_photo']['type'];
                    $file_size = $_FILES['profile_photo']['size'];
                    
                    if (in_array($file_type, $allowed_types) && $file_size <= 5242880) {
                        $extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                        $profile_photo = uniqid() . '_' . time() . '.' . $extension;
                        $upload_path = $upload_dir . $profile_photo;
                        
                        if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
                            $profile_photo = null;
                            $error_message = "Failed to upload profile photo.";
                        }
                    } else {
                        $error_message = "Invalid photo format or size too large (max 5MB).";
                    }
                }
                
                if (!$error_message) {
                    // Check if username exists
                    $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ?");
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $error_message = "Username already exists!";
                    } else {
                        $stmt->close();
                        
                        // Check if email exists
                        $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
                        $stmt->bind_param("s", $email);
                        $stmt->execute();
                        if ($stmt->get_result()->num_rows > 0) {
                            $error_message = "Email already exists!";
                        } else {
                            $stmt->close();
                            
                            // Insert into tbl_residents
                            $stmt = $conn->prepare("INSERT INTO tbl_residents (first_name, last_name, email, phone, profile_photo, is_verified) VALUES (?, ?, ?, ?, ?, 1)");
                            $stmt->bind_param("sssss", $first_name, $last_name, $email, $phone, $profile_photo);
                            
                            if ($stmt->execute()) {
                                $resident_id = $stmt->insert_id;
                                $stmt->close();
                                
                                // Insert into tbl_users with proper status handling
                                if ($has_status && $has_is_active) {
                                    $status = 'active';
                                    $is_active = 1;
                                    $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, status, is_active, resident_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("ssssisii", $username, $email, $password, $role, $role_id, $status, $is_active, $resident_id);
                                } elseif ($has_status) {
                                    $status = 'active';
                                    $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, status, resident_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("ssssisi", $username, $email, $password, $role, $role_id, $status, $resident_id);
                                } elseif ($has_is_active) {
                                    $is_active = 1;
                                    $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, is_active, resident_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("ssssiii", $username, $email, $password, $role, $role_id, $is_active, $resident_id);
                                } else {
                                    $stmt = $conn->prepare("INSERT INTO tbl_users (username, email, password, role, role_id, resident_id) VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("sssiii", $username, $email, $password, $role, $role_id, $resident_id);
                                }
                                
                                if ($stmt->execute()) {
                                    $success_message = "Staff member added successfully!";
                                } else {
                                    $error_message = "Error creating user account: " . $stmt->error;
                                }
                                $stmt->close();
                            } else {
                                $error_message = "Error creating resident record: " . $stmt->error;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
        
        // EDIT STAFF
        elseif ($_POST['action'] === 'edit_staff') {
            try {
                $user_id = intval($_POST['user_id']);
                $username = trim($_POST['username']);
                $email = trim($_POST['email']);
                $first_name = trim($_POST['first_name']);
                $last_name = trim($_POST['last_name']);
                $role = $_POST['role'];
                $role_id = intval($_POST['role_id']);
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
                $password = trim($_POST['password']);
                
                $profile_photo = null;
                $update_photo = false;
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    $file_type = $_FILES['profile_photo']['type'];
                    $file_size = $_FILES['profile_photo']['size'];
                    
                    if (in_array($file_type, $allowed_types) && $file_size <= 5242880) {
                        $extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                        $profile_photo = uniqid() . '_' . time() . '.' . $extension;
                        $upload_path = $upload_dir . $profile_photo;
                        
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
                            $update_photo = true;
                            
                            $stmt = $conn->prepare("SELECT r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id WHERE u.user_id = ?");
                            $stmt->bind_param("i", $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                if ($row['profile_photo'] && file_exists($upload_dir . $row['profile_photo'])) {
                                    unlink($upload_dir . $row['profile_photo']);
                                }
                            }
                            $stmt->close();
                        }
                    }
                }
                
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? AND user_id != ?");
                $stmt->bind_param("si", $username, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error_message = "Username already exists!";
                } else {
                    $stmt->close();
                    
                    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user_data = $result->fetch_assoc();
                    $resident_id = $user_data['resident_id'];
                    $stmt->close();
                    
                    if ($resident_id) {
                        if ($update_photo) {
                            $stmt = $conn->prepare("UPDATE tbl_residents SET first_name = ?, last_name = ?, email = ?, phone = ?, profile_photo = ? WHERE resident_id = ?");
                            $stmt->bind_param("sssssi", $first_name, $last_name, $email, $phone, $profile_photo, $resident_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE tbl_residents SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE resident_id = ?");
                            $stmt->bind_param("ssssi", $first_name, $last_name, $email, $phone, $resident_id);
                        }
                        $stmt->execute();
                        $stmt->close();
                    }
                    
                    // Update user password if provided
                    if (!empty($password)) {
                        $stmt = $conn->prepare("UPDATE tbl_users SET username = ?, email = ?, password = ?, role = ?, role_id = ? WHERE user_id = ?");
                        $stmt->bind_param("ssssii", $username, $email, $password, $role, $role_id, $user_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE tbl_users SET username = ?, email = ?, role = ?, role_id = ? WHERE user_id = ?");
                        $stmt->bind_param("sssii", $username, $email, $role, $role_id, $user_id);
                    }
                    
                    if ($stmt->execute()) {
                        $success_message = "Staff member updated successfully!";
                    } else {
                        $error_message = "Error updating user: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
        
        // TOGGLE STATUS
        elseif ($_POST['action'] === 'toggle_status') {
            if (!$has_status && !$has_is_active) {
                $error_message = "Status toggle is not supported in your current database schema.";
            } else {
                try {
                    $user_id = intval($_POST['user_id']);
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
        
        // DELETE STAFF
        elseif ($_POST['action'] === 'delete_staff') {
            try {
                $user_id = intval($_POST['user_id']);
                
                $stmt = $conn->prepare("SELECT r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id WHERE u.user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    if ($row['profile_photo'] && file_exists($upload_dir . $row['profile_photo'])) {
                        unlink($upload_dir . $row['profile_photo']);
                    }
                }
                $stmt->close();
                
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
        
        if ($success_message || $error_message) {
            $_SESSION['temp_success'] = $success_message;
            $_SESSION['temp_error'] = $error_message;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

$staff_members = [];
$query = "
    SELECT u.user_id, u.username, u.email, $status_column as status, u.created_at, u.role, u.role_id,
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

$roles = [];
$role_query = "SELECT DISTINCT role, role_id FROM tbl_users WHERE role != 'Resident' AND role != 'Super Admin' AND role IS NOT NULL ORDER BY role";
$role_result = $conn->query($role_query);
while ($row = $role_result->fetch_assoc()) {
    $roles[] = $row;
}

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

.staff-management {
    padding: 2rem;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1a202c;
    margin: 0;
}

/* Buttons */
.add-btn {
    background: #0d6efd;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all var(--transition-speed) ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.add-btn:hover {
    background: #0b5ed7;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.add-btn:active {
    transform: translateY(0);
}

/* Card Enhancements */
.staff-table {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: all var(--transition-speed) ease;
}

.staff-table:hover {
    box-shadow: var(--shadow-md);
}

.table-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    padding: 1.25rem 1.5rem;
    border-bottom: 2px solid #e9ecef;
}

.search-box {
    position: relative;
    max-width: 400px;
}

.search-box input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 2.5rem;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.875rem;
    transition: all var(--transition-speed) ease;
}

.search-box input:focus {
    outline: none;
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

.search-box i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

/* Table Enhancements */
table {
    width: 100%;
    border-collapse: collapse;
}

thead {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-weight: 700;
    color: #495057;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #dee2e6;
}

td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f1f3f5;
    color: #334155;
}

tbody tr {
    transition: all var(--transition-speed) ease;
    background: white;
}

tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
    transform: scale(1.01);
}

/* Staff Info */
.staff-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.staff-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 16px;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.staff-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.staff-details h4 {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 0.25rem 0;
}

.staff-details p {
    font-size: 0.75rem;
    color: #64748b;
    margin: 0;
}

/* Enhanced Badges */
.badge {
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.badge-admin { background: #dbeafe; color: #1e40af; }
.badge-staff { background: #e0e7ff; color: #4338ca; }
.badge-tanod, .badge-barangay-tanod { background: #fef3c7; color: #92400e; }
.badge-driver { background: #d1fae5; color: #065f46; }
.badge-captain, .badge-barangay-captain { background: #fce7f3; color: #9f1239; }
.badge-secretary { background: #fef3c7; color: #92400e; }
.badge-treasurer { background: #dbeafe; color: #1e40af; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #fee2e2; color: #991b1b; }

/* Action Buttons */
.action-btns {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    padding: 0.5rem;
    border: none;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    border-radius: 6px;
    transition: all var(--transition-speed) ease;
}

.btn-icon:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.btn-icon.danger:hover {
    background: #fee2e2;
    color: #dc2626;
}

/* Modal Enhancements */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--border-radius-lg);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
}

.modal-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #64748b;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all var(--transition-speed) ease;
}

.close-btn:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.modal form {
    padding: 1.5rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-size: 0.875rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 0.5rem;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.875rem;
    transition: all var(--transition-speed) ease;
}

.form-control:focus {
    outline: none;
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

.photo-upload-area {
    border: 2px dashed #e9ecef;
    border-radius: 8px;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-speed) ease;
}

.photo-upload-area:hover {
    border-color: #0d6efd;
    background: #f8fafc;
}

.photo-upload-area i {
    font-size: 3rem;
    color: #94a3b8;
    margin-bottom: 1rem;
}

.photo-preview {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    margin: 0 auto 1rem;
    overflow: hidden;
    display: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.photo-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.btn-submit {
    width: 100%;
    padding: 0.75rem;
    background: #0d6efd;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all var(--transition-speed) ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn-submit:hover {
    background: #0b5ed7;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-submit:active {
    transform: translateY(0);
}

/* Button Variants */
.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all var(--transition-speed) ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn-secondary {
    background: #e2e8f0;
    color: #475569;
}

.btn-secondary:hover {
    background: #cbd5e1;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}

.btn-danger:active, .btn-secondary:active {
    transform: translateY(0);
}

/* Alert Enhancements */
.alert {
    padding: 1.25rem 1.5rem;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
    transition: opacity 0.3s ease;
}

.alert-success {
    background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
    color: #065f46;
    border-left-color: #198754;
}

.alert-error {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    color: #991b1b;
    border-left-color: #dc3545;
}

.alert i {
    font-size: 1.1rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    color: #cbd5e1;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state h3 {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: #94a3b8;
    margin-bottom: 1.5rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .staff-management {
        padding: 1rem;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    th, td {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="staff-management">
    <?php echo displayMessage(); ?>

    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-users-cog me-2 text-primary"></i>
            Manage Staff
        </h1>
        <button class="add-btn" onclick="openModal('addStaffModal')">
            <i class="fas fa-plus"></i>
            Add Staff Member
        </button>
    </div>

    <div class="staff-table">
        <div class="table-header">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search staff members..." onkeyup="searchTable()">
            </div>
        </div>

        <?php if (empty($staff_members)): ?>
            <div class="empty-state">
                <i class="fas fa-user-tie"></i>
                <h3>No Staff Members Yet</h3>
                <p>Start by adding your first staff member</p>
                <button class="add-btn" onclick="openModal('addStaffModal')">
                    <i class="fas fa-plus"></i>
                    Add Staff Member
                </button>
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
                                        $first = !empty($staff['first_name']) ? substr($staff['first_name'], 0, 1) : substr($staff['username'], 0, 1);
                                        $last = !empty($staff['last_name']) ? substr($staff['last_name'], 0, 1) : '';
                                        echo strtoupper($first . $last);
                                    endif; ?>
                                </div>
                                <div class="staff-details">
                                    <h4><?php echo htmlspecialchars((!empty($staff['first_name']) ? $staff['first_name'] . ' ' . $staff['last_name'] : $staff['username'])); ?></h4>
                                    <p><?php echo htmlspecialchars($staff['username']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php 
                            $role_class = 'badge-staff';
                            $role_name = $staff['role'];
                            if ($role_name === 'Admin') $role_class = 'badge-admin';
                            elseif ($role_name === 'Tanod' || $role_name === 'Barangay Tanod') $role_class = 'badge-tanod';
                            elseif ($role_name === 'Driver') $role_class = 'badge-driver';
                            elseif ($role_name === 'Barangay Captain') $role_class = 'badge-captain';
                            elseif ($role_name === 'Secretary') $role_class = 'badge-secretary';
                            elseif ($role_name === 'Treasurer') $role_class = 'badge-treasurer';
                            ?>
                            <span class="badge <?php echo $role_class; ?>">
                                <?php echo htmlspecialchars($role_name); ?>
                            </span>
                        </td>
                        <td>
                            <div>
                                <div style="font-size: 0.875rem;">
                                    <i class="fas fa-envelope text-muted me-1"></i>
                                    <?php echo htmlspecialchars($staff['email']); ?>
                                </div>
                                <?php if (!empty($staff['phone'])): ?>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                                    <i class="fas fa-phone text-muted me-1"></i>
                                    <?php echo htmlspecialchars($staff['phone']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo ($staff['status'] === 'active' || $staff['status'] == 1) ? 'badge-active' : 'badge-inactive'; ?>">
                                <i class="fas fa-<?php echo ($staff['status'] === 'active' || $staff['status'] == 1) ? 'check-circle' : 'times-circle'; ?> me-1"></i>
                                <?php echo ($staff['status'] === 'active' || $staff['status'] == 1) ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <i class="fas fa-calendar-alt text-muted me-1"></i>
                            <?php echo date('M j, Y', strtotime($staff['created_at'])); ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="btn-icon" onclick='editStaff(<?php echo json_encode($staff); ?>)' title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($has_status || $has_is_active): ?>
                                <button class="btn-icon" onclick='openStatusModal(<?php echo json_encode($staff); ?>)' title="<?php echo ($staff['status'] === 'active' || $staff['status'] == 1) ? 'Deactivate' : 'Activate'; ?>">
                                    <i class="fas fa-<?php echo ($staff['status'] === 'active' || $staff['status'] == 1) ? 'ban' : 'check'; ?>"></i>
                                </button>
                                <?php endif; ?>
                                <button class="btn-icon danger" onclick='openDeleteModal(<?php echo json_encode($staff); ?>)' title="Delete">
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

<!-- Add Staff Modal -->
<div id="addStaffModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Staff Member</h2>
            <button class="close-btn" onclick="closeModal('addStaffModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_staff">
            
            <div class="form-group">
                <label>Profile Photo</label>
                <div class="photo-upload-area" onclick="document.getElementById('add_photo').click()">
                    <div class="photo-preview" id="add_preview"></div>
                    <i class="fas fa-camera"></i>
                    <p style="margin: 0; color: #64748b;">Click to upload photo</p>
                    <p style="margin: 0.5rem 0 0; font-size: 0.75rem; color: #94a3b8;">JPG, PNG or GIF (max 5MB)</p>
                </div>
                <input type="file" name="profile_photo" id="add_photo" accept="image/*" style="display: none;" onchange="previewPhoto(this, 'add_preview')">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" class="form-control" placeholder="+63 XXX XXX XXXX">
            </div>

            <div class="form-group">
                <label>Role *</label>
                <select name="role" class="form-control" required onchange="updateRoleId(this, 'add')">
                    <option value="">Select Role</option>
                    <option value="Admin" data-role-id="19">Admin</option>
                    <option value="Barangay Captain" data-role-id="2">Barangay Captain</option>
                    <option value="Secretary" data-role-id="3">Secretary</option>
                    <option value="Treasurer" data-role-id="4">Treasurer</option>
                    <option value="Tanod" data-role-id="5">Tanod</option>
                    <option value="Staff" data-role-id="6">Staff</option>
                    <option value="Driver" data-role-id="20">Driver</option>
                </select>
                <input type="hidden" name="role_id" id="add_role_id">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i>
                Add Staff Member
            </button>
        </form>
    </div>
</div>

<!-- Edit Staff Modal -->
<div id="editStaffModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Staff Member</h2>
            <button class="close-btn" onclick="closeModal('editStaffModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div class="form-group">
                <label>Profile Photo</label>
                <div class="photo-upload-area" onclick="document.getElementById('edit_photo').click()">
                    <div class="photo-preview" id="edit_preview"></div>
                    <i class="fas fa-camera"></i>
                    <p style="margin: 0; color: #64748b;">Click to change photo</p>
                    <p style="margin: 0.5rem 0 0; font-size: 0.75rem; color: #94a3b8;">JPG, PNG or GIF (max 5MB)</p>
                </div>
                <input type="file" name="profile_photo" id="edit_photo" accept="image/*" style="display: none;" onchange="previewPhoto(this, 'edit_preview')">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" id="edit_phone" class="form-control">
            </div>

            <div class="form-group">
                <label>Role *</label>
                <select name="role" id="edit_role" class="form-control" required onchange="updateRoleId(this, 'edit')">
                    <option value="">Select Role</option>
                    <option value="Admin" data-role-id="19">Admin</option>
                    <option value="Barangay Captain" data-role-id="2">Barangay Captain</option>
                    <option value="Secretary" data-role-id="3">Secretary</option>
                    <option value="Treasurer" data-role-id="4">Treasurer</option>
                    <option value="Tanod" data-role-id="5">Tanod</option>
                    <option value="Staff" data-role-id="6">Staff</option>
                    <option value="Driver" data-role-id="20">Driver</option>
                </select>
                <input type="hidden" name="role_id" id="edit_role_id">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" id="edit_username" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" id="edit_password" class="form-control" placeholder="Leave blank to keep current">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i>
                Update Staff Member
            </button>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteStaffModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Delete Staff Member</h2>
            <button class="close-btn" onclick="closeModal('deleteStaffModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_staff">
            <input type="hidden" name="user_id" id="delete_user_id">
            
            <div style="padding: 2rem;">
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <div style="width: 80px; height: 80px; margin: 0 auto 1rem; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-user-times" style="font-size: 2rem; color: #dc2626;"></i>
                    </div>
                    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; color: #1e293b;">Are you sure?</h3>
                    <p style="color: #64748b; margin: 0;">You are about to delete:</p>
                    <p style="font-weight: 600; color: #1e293b; margin: 0.5rem 0;" id="delete_staff_name"></p>
                    <p style="color: #64748b; font-size: 0.875rem; margin-top: 1rem;">This action cannot be undone. All data associated with this staff member will be permanently deleted.</p>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteStaffModal')" style="background: #e2e8f0; color: #475569; box-shadow: none;">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" style="background: #dc2626; color: white;">
                        <i class="fas fa-trash me-2"></i>Delete Staff
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Status Toggle Modal -->
<div id="statusToggleModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-toggle-on me-2 text-primary" id="status_modal_icon"></i><span id="status_modal_title">Change Status</span></h2>
            <button class="close-btn" onclick="closeModal('statusToggleModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="user_id" id="status_user_id">
            <input type="hidden" name="new_status" id="status_new_status">
            
            <div style="padding: 2rem;">
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <div id="status_icon_wrapper" style="width: 80px; height: 80px; margin: 0 auto 1rem; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i id="status_main_icon" style="font-size: 2rem;"></i>
                    </div>
                    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; color: #1e293b;" id="status_question">Are you sure?</h3>
                    <p style="color: #64748b; margin: 0;" id="status_action_text">You are about to change the status of:</p>
                    <p style="font-weight: 600; color: #1e293b; margin: 0.5rem 0;" id="status_staff_name"></p>
                    <p style="color: #64748b; font-size: 0.875rem; margin-top: 1rem;" id="status_description"></p>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('statusToggleModal')" style="background: #e2e8f0; color: #475569; box-shadow: none;">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn" id="status_confirm_btn">
                        <i class="fas fa-check me-2"></i><span id="status_btn_text">Confirm</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.getElementById('add_preview').style.display = 'none';
    document.getElementById('add_preview').innerHTML = '';
    document.getElementById('edit_preview').style.display = 'none';
    document.getElementById('edit_preview').innerHTML = '';
}

function updateRoleId(selectElement, prefix) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const roleId = selectedOption.getAttribute('data-role-id');
    document.getElementById(prefix + '_role_id').value = roleId;
}

function previewPhoto(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function editStaff(staff) {
    document.getElementById('edit_user_id').value = staff.user_id;
    document.getElementById('edit_first_name').value = staff.first_name || '';
    document.getElementById('edit_last_name').value = staff.last_name || '';
    document.getElementById('edit_email').value = staff.email;
    document.getElementById('edit_phone').value = staff.phone || '';
    document.getElementById('edit_username').value = staff.username;
    document.getElementById('edit_role').value = staff.role;
    document.getElementById('edit_role_id').value = staff.role_id;
    document.getElementById('edit_password').value = '';
    
    const preview = document.getElementById('edit_preview');
    if (staff.profile_photo) {
        preview.innerHTML = '<img src="../../uploads/profiles/' + staff.profile_photo + '" alt="Current Photo">';
        preview.style.display = 'block';
    }
    
    openModal('editStaffModal');
}

function openDeleteModal(staff) {
    document.getElementById('delete_user_id').value = staff.user_id;
    const fullName = (staff.first_name && staff.last_name) 
        ? staff.first_name + ' ' + staff.last_name 
        : staff.username;
    document.getElementById('delete_staff_name').textContent = fullName + ' (' + staff.role + ')';
    openModal('deleteStaffModal');
}

function openStatusModal(staff) {
    const isActive = (staff.status === 'active' || staff.status == 1);
    const newStatus = isActive ? 'inactive' : 'active';
    const fullName = (staff.first_name && staff.last_name) 
        ? staff.first_name + ' ' + staff.last_name 
        : staff.username;
    
    // Set form values
    document.getElementById('status_user_id').value = staff.user_id;
    document.getElementById('status_new_status').value = newStatus;
    document.getElementById('status_staff_name').textContent = fullName + ' (' + staff.role + ')';
    
    // Update modal content based on action
    const iconWrapper = document.getElementById('status_icon_wrapper');
    const mainIcon = document.getElementById('status_main_icon');
    const modalIcon = document.getElementById('status_modal_icon');
    const modalTitle = document.getElementById('status_modal_title');
    const question = document.getElementById('status_question');
    const actionText = document.getElementById('status_action_text');
    const description = document.getElementById('status_description');
    const confirmBtn = document.getElementById('status_confirm_btn');
    const btnText = document.getElementById('status_btn_text');
    
    if (isActive) {
        // Deactivation
        iconWrapper.style.background = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
        mainIcon.className = 'fas fa-user-slash';
        mainIcon.style.color = '#d97706';
        modalIcon.className = 'fas fa-ban me-2 text-warning';
        modalTitle.textContent = 'Deactivate Staff Member';
        question.textContent = 'Deactivate this staff member?';
        actionText.textContent = 'You are about to deactivate:';
        description.textContent = 'This staff member will no longer be able to access the system. You can reactivate them at any time.';
        confirmBtn.style.background = '#d97706';
        confirmBtn.style.color = 'white';
        btnText.textContent = 'Deactivate';
    } else {
        // Activation
        iconWrapper.style.background = 'linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)';
        mainIcon.className = 'fas fa-user-check';
        mainIcon.style.color = '#059669';
        modalIcon.className = 'fas fa-check-circle me-2 text-success';
        modalTitle.textContent = 'Activate Staff Member';
        question.textContent = 'Activate this staff member?';
        actionText.textContent = 'You are about to activate:';
        description.textContent = 'This staff member will be able to access the system and perform their assigned duties.';
        confirmBtn.style.background = '#059669';
        confirmBtn.style.color = 'white';
        btnText.textContent = 'Activate';
    }
    
    openModal('statusToggleModal');
}

function searchTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('staffTable');
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    }
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        const modalId = event.target.id;
        closeModal(modalId);
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            if (modal.classList.contains('show')) {
                closeModal(modal.id);
            }
        });
    }
});

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.style.display = 'none';
        }, 300);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>