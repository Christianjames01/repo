<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Only drivers can access
requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'My Profile';

$success_message = '';
$error_message = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $contact_number = trim($_POST['contact_number']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        
        // Get resident_id
        $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user && $user['resident_id']) {
            $stmt = $conn->prepare("UPDATE tbl_residents SET contact_number = ?, email = ?, address = ? WHERE resident_id = ?");
            $stmt->bind_param("sssi", $contact_number, $email, $address, $user['resident_id']);
            
            if ($stmt->execute()) {
                $success_message = "Profile updated successfully!";
            } else {
                $error_message = "Error updating profile: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($_POST['action'] === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match!";
        } else {
            // Get current password
            $stmt = $conn->prepare("SELECT password FROM tbl_users WHERE user_id = ?");
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if ($user && $current_password === $user['password']) {
                // Update password (plain text as per your current system)
                $stmt = $conn->prepare("UPDATE tbl_users SET password = ? WHERE user_id = ?");
                $stmt->bind_param("si", $new_password, $current_user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Error changing password: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Current password is incorrect!";
            }
        }
    }
}

// Fetch driver info
$sql = "SELECT u.*, r.first_name, r.last_name, r.contact_number, r.email, r.address, r.profile_photo
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
$driver_info = $result->fetch_assoc();
$stmt->close();

// Get assigned vehicles count - FIXED: using assigned_driver_id instead of driver_user_id
$vehicle_count_sql = "SELECT COUNT(*) as count FROM tbl_vehicles WHERE assigned_driver_id = ?";
$stmt = $conn->prepare($vehicle_count_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
$vehicle_count = $result->fetch_assoc()['count'];
$stmt->close();

// Get recent activity logs count
$logs_count = 0;
if (tableExists($conn, 'tbl_vehicle_logs')) {
    $logs_sql = "SELECT COUNT(*) as count FROM tbl_vehicle_logs WHERE driver_id = ?";
    $stmt = $conn->prepare($logs_sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $logs_count = $result->fetch_assoc()['count'];
    $stmt->close();
}

// Helper function
if (!function_exists('tableExists')) {
    function tableExists($conn, $table_name) {
        $result = $conn->query("SHOW TABLES LIKE '$table_name'");
        return $result && $result->num_rows > 0;
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <a href="index.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
            <h2 class="mb-0">
                <i class="fas fa-user-circle me-2"></i>My Profile
            </h2>
            <p class="text-muted">Manage your account information</p>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Card -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="profile-avatar mb-3">
                        <?php if (!empty($driver_info['profile_photo']) && file_exists('../../uploads/profiles/' . $driver_info['profile_photo'])): ?>
                            <img src="../../uploads/profiles/<?php echo htmlspecialchars($driver_info['profile_photo']); ?>" 
                                 alt="Profile Photo" class="rounded-circle" width="120" height="120">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <i class="fas fa-user fa-4x text-muted"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mb-1">
                        <?php echo htmlspecialchars($driver_info['first_name'] . ' ' . $driver_info['last_name']); ?>
                    </h4>
                    <p class="text-muted mb-3">
                        <i class="fas fa-id-card me-2"></i>Driver
                    </p>
                    <div class="profile-stats">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-item">
                                    <h5 class="mb-0 text-primary"><?php echo $vehicle_count; ?></h5>
                                    <small class="text-muted">Vehicles</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-item">
                                    <h5 class="mb-0 text-info"><?php echo $logs_count; ?></h5>
                                    <small class="text-muted">Activity Logs</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Info Card -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="card-title mb-3">
                        <i class="fas fa-info-circle me-2"></i>Account Info
                    </h6>
                    <div class="info-item">
                        <small class="text-muted">Username</small>
                        <p class="mb-2"><strong><?php echo htmlspecialchars($driver_info['username']); ?></strong></p>
                    </div>
                    <div class="info-item">
                        <small class="text-muted">Role</small>
                        <p class="mb-2">
                            <span class="badge bg-primary">Driver</span>
                        </p>
                    </div>
                    <div class="info-item">
                        <small class="text-muted">Member Since</small>
                        <p class="mb-0">
                            <strong><?php echo date('F Y', strtotime($driver_info['created_at'])); ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Details & Settings -->
        <div class="col-md-8">
            <!-- Contact Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-address-card me-2"></i>Contact Information
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($driver_info['first_name']); ?>" 
                                       disabled>
                                <small class="text-muted">Contact admin to change</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($driver_info['last_name']); ?>" 
                                       disabled>
                                <small class="text-muted">Contact admin to change</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Number *</label>
                                <input type="text" name="contact_number" class="form-control" 
                                       value="<?php echo htmlspecialchars($driver_info['contact_number'] ?? ''); ?>" 
                                       placeholder="e.g., 09123456789" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($driver_info['email'] ?? ''); ?>" 
                                       placeholder="your.email@example.com">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3" 
                                      placeholder="Enter your complete address"><?php echo htmlspecialchars($driver_info['address'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Information
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-lock me-2"></i>Change Password
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" onsubmit="return validatePassword()">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Password *</label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="current_password" class="form-control" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Password *</label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="new_password" class="form-control" 
                                           minlength="6" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted">At least 6 characters</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Confirm New Password *</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                                           minlength="6" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning text-white">
                            <i class="fas fa-key me-2"></i>Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.profile-avatar {
    position: relative;
    display: inline-block;
}

.avatar-placeholder {
    width: 120px;
    height: 120px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}

.profile-stats {
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
    margin-top: 15px;
}

.stat-item {
    padding: 10px;
}

.stat-item h5 {
    font-weight: bold;
}

.info-item {
    padding: 10px 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.card {
    transition: transform 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
}
</style>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling;
    const icon = button.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function validatePassword() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        alert('New passwords do not match!');
        return false;
    }
    
    if (newPassword.length < 6) {
        alert('Password must be at least 6 characters long!');
        return false;
    }
    
    return true;
}
</script>

<?php include '../../includes/footer.php'; ?>