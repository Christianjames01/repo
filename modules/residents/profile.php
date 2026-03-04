<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';

// Require login
requireLogin();

$success = '';
$error = '';
$user_id = getCurrentUserId();
$resident_id = getCurrentResidentId();
$document_requests = [];

// Fetch user data including ID photo
$stmt = $conn->prepare("
    SELECT u.*, r.role_name,
           res.first_name, res.middle_name, res.last_name, res.ext_name, res.date_of_birth,
           res.gender, res.civil_status, res.address, res.contact_number,
           res.email, res.occupation, res.profile_photo, res.id_photo, res.is_verified,
           res.permanent_address, res.street, res.barangay, res.town, res.city, 
           res.province, res.birthplace,
           res.updated_at as resident_updated_at
    FROM tbl_users u
    LEFT JOIN tbl_roles r ON u.role_id = r.role_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
    header("Location: ../dashboard/index.php");
    exit();
}

// Determine the most recent update timestamp
$last_updated = 'Never';
if (!empty($user_data['updated_at']) && $user_data['updated_at'] != '0000-00-00 00:00:00') {
    $last_updated = date('F d, Y h:i A', strtotime($user_data['updated_at']));
} elseif (!empty($user_data['resident_updated_at']) && $user_data['resident_updated_at'] != '0000-00-00 00:00:00') {
    $last_updated = date('F d, Y h:i A', strtotime($user_data['resident_updated_at']));
}

// Handle ID photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_id'])) {
    $errors = [];
    
    // Check if resident is already verified
    if ($user_data['is_verified'] == 1) {
        $error = "Cannot change ID photo. Your account is already verified.";
    } elseif (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
        $file_extension = strtolower(pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            $errors[] = "ID photo must be JPG, JPEG, PNG, or PDF";
        } elseif ($_FILES['id_photo']['size'] > 5242880) { // 5MB
            $errors[] = "ID photo must be less than 5MB";
        } else {
            $upload_dir = '../../uploads/ids/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = 'id_' . $resident_id . '_' . time() . '.' . $file_extension;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['id_photo']['tmp_name'], $destination)) {
                // Delete old ID photo if exists
                if ($user_data['id_photo'] && file_exists('../../uploads/ids/' . $user_data['id_photo'])) {
                    unlink('../../uploads/ids/' . $user_data['id_photo']);
                }
                
                // Update database
                $stmt = $conn->prepare("UPDATE tbl_residents SET id_photo = ?, updated_at = NOW() WHERE resident_id = ?");
                $stmt->bind_param("si", $new_filename, $resident_id);
                
                if ($stmt->execute()) {
                    $success = "ID photo uploaded successfully! Your account will be reviewed for verification.";
                    // Refresh user data
                    $user_data['id_photo'] = $new_filename;
                } else {
                    $errors[] = "Failed to update ID photo in database";
                    unlink($destination); // Remove uploaded file
                }
                $stmt->close();
            } else {
                $errors[] = "Failed to upload ID photo";
            }
        }
    } else {
        $errors[] = "Please select an ID photo to upload";
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $errors = [];
    
    // Collect form data
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $ext_name = trim($_POST['ext'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $permanent_address = trim($_POST['permanent_address'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $town = trim($_POST['town'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $birthplace = trim($_POST['birthplace'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    
    // Build complete address from parts
    $address_parts = array_filter([$permanent_address, $street, $barangay, $town, $province]);
    $address = implode(', ', $address_parts);
    
    // Validation
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($date_of_birth)) $errors[] = "Date of birth is required";
    if (empty($gender)) $errors[] = "Gender is required";
    if (empty($civil_status)) $errors[] = "Civil status is required";
    if (empty($permanent_address)) $errors[] = "Permanent address is required";
    if (empty($barangay)) $errors[] = "Barangay is required";
    if (empty($town)) $errors[] = "Town/City is required";
    if (empty($province)) $errors[] = "Province is required";
    if (empty($birthplace)) $errors[] = "Birthplace is required";
    if (empty($contact_number)) $errors[] = "Contact number is required";
    
    // Validate contact number
    if (!empty($contact_number)) {
        $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
        if (strlen($contact_number) != 11 || substr($contact_number, 0, 2) != '09') {
            $errors[] = "Contact number must be in format 09XXXXXXXXX";
        }
    }
    
    // Validate email
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if email is already used by another user
    if (!empty($email)) {
        $stmt = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE email = ? AND resident_id != ?");
        $stmt->bind_param("si", $email, $resident_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = "Email already in use by another account";
        }
        $stmt->close();
    }
    
    // Handle profile photo upload
    $profile_photo = $user_data['profile_photo'];
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        $file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            $errors[] = "Profile photo must be JPG, JPEG, PNG, or GIF";
        } elseif ($_FILES['profile_photo']['size'] > 5242880) { // 5MB
            $errors[] = "Profile photo must be less than 5MB";
        } else {
            $upload_dir = '../../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destination)) {
                // Delete old photo if exists
                if ($profile_photo && file_exists('../../uploads/profiles/' . $profile_photo)) {
                    unlink('../../uploads/profiles/' . $profile_photo);
                }
                $profile_photo = $new_filename;
            } else {
                $errors[] = "Failed to upload profile photo";
            }
        }
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE tbl_residents 
            SET first_name = ?, middle_name = ?, last_name = ?, ext_name = ?, date_of_birth = ?,
                gender = ?, civil_status = ?, address = ?, 
                permanent_address = ?, street = ?, barangay = ?, town = ?, province = ?,
                birthplace = ?, contact_number = ?, email = ?, occupation = ?, 
                profile_photo = ?, updated_at = NOW()
            WHERE resident_id = ?
        ");
        
        $stmt->bind_param("ssssssssssssssssssi",
            $first_name, $middle_name, $last_name, $ext_name, $date_of_birth,
            $gender, $civil_status, $address,
            $permanent_address, $street, $barangay, $town, $province,
            $birthplace, $contact_number, $email, $occupation, 
            $profile_photo, $resident_id
        );
        
        if ($stmt->execute()) {
            $success = "Profile updated successfully!";
            // Refresh user data
            $stmt->close();
            $stmt = $conn->prepare("
                SELECT u.*, r.role_name,
                       res.first_name, res.middle_name, res.last_name, res.ext_name, res.date_of_birth,
                       res.gender, res.civil_status, res.address, res.contact_number,
                       res.email, res.occupation, res.profile_photo, res.id_photo, res.is_verified,
                       res.permanent_address, res.street, res.barangay, res.town, res.city,
                       res.province, res.birthplace,
                       res.updated_at as resident_updated_at
                FROM tbl_users u
                LEFT JOIN tbl_roles r ON u.role_id = r.role_id
                LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
                WHERE u.user_id = ?
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            
            // Update last_updated display
            if (!empty($user_data['updated_at']) && $user_data['updated_at'] != '0000-00-00 00:00:00') {
                $last_updated = date('F d, Y h:i A', strtotime($user_data['updated_at']));
            } elseif (!empty($user_data['resident_updated_at']) && $user_data['resident_updated_at'] != '0000-00-00 00:00:00') {
                $last_updated = date('F d, Y h:i A', strtotime($user_data['resident_updated_at']));
            }
        } else {
            $errors[] = "Failed to update profile";
        }
        $stmt->close();
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    $errors = [];
    
    if (empty($current_password)) $errors[] = "Current password is required";
    if (empty($new_password)) $errors[] = "New password is required";
    if (empty($confirm_password)) $errors[] = "Please confirm new password";
    
    if (strlen($new_password) < 8) {
        $errors[] = "New password must be at least 8 characters";
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = "New passwords do not match";
    }
    
    // Verify current password
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT password FROM tbl_users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_pass = $result->fetch_assoc();
        $stmt->close();
        
        if ($current_password !== $user_pass['password']) {
            $errors[] = "Current password is incorrect";
        }
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE tbl_users SET password = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("si", $new_password, $user_id);
        
        if ($stmt->execute()) {
            $success = "Password changed successfully!";
        } else {
            $errors[] = "Failed to change password";
        }
        $stmt->close();
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile | BarangayLink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
   <link rel="stylesheet" href="../../assets/css/profile.css">
   <style>
   .id-upload-area {
       border: 2px dashed #dee2e6;
       border-radius: 8px;
       padding: 20px;
       text-align: center;
       background-color: #f8f9fa;
       cursor: pointer;
       transition: all 0.3s;
   }
   .id-upload-area:hover {
       border-color: #0d6efd;
       background-color: #e7f1ff;
   }
   .id-upload-area.dragover {
       border-color: #0d6efd;
       background-color: #cfe2ff;
   }
   .id-preview {
       max-width: 100%;
       max-height: 300px;
       margin-top: 15px;
       border-radius: 8px;
       box-shadow: 0 2px 8px rgba(0,0,0,0.1);
   }
   .id-file-info {
       background-color: #e7f1ff;
       padding: 12px;
       border-radius: 8px;
       margin-top: 15px;
   }
   </style>
</head>
<body>

<div class="profile-header">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2><i class="fas fa-user-circle"></i> My Profile</h2>
                <p class="mb-0">Manage your personal information and account settings</p>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="../dashboard/index.php" class="btn btn-light">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container mb-5">
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($user_data['is_verified'] != 1): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <div class="d-flex align-items-start">
                <i class="fas fa-exclamation-triangle fa-2x me-3 mt-1"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-2">
                        <i class="fas fa-lock me-2"></i>Account Not Verified
                    </h5>
                    <p class="mb-2">
                        Your account is pending verification. You need to be verified before you can submit document requests.
                    </p>
                    <?php if (empty($user_data['id_photo'])): ?>
                        <p class="mb-2">
                            <strong>Action Required:</strong> Please upload your valid government-issued ID below to start the verification process.
                        </p>
                        <button type="button" class="btn btn-primary btn-sm" 
                                data-bs-toggle="modal" data-bs-target="#uploadIdModal">
                            <i class="fas fa-upload me-2"></i>Upload ID Now
                        </button>
                    <?php else: ?>
                        <p class="mb-0">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <strong>ID Uploaded:</strong> Your ID has been submitted. Please wait for admin verification.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Sidebar -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="profile-photo-container">
                        <?php if (!empty($user_data['profile_photo']) && file_exists('../../uploads/profiles/' . $user_data['profile_photo'])): ?>
                            <img src="../../uploads/profiles/<?php echo htmlspecialchars($user_data['profile_photo']); ?>" 
                                 alt="Profile Photo" class="profile-photo">
                        <?php else: ?>
                            <div class="profile-photo-placeholder">
                                <i class="fas fa-user"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <h4><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h4>
                    <p class="text-muted mb-3">@<?php echo htmlspecialchars($user_data['username']); ?></p>
                    
                    <span class="badge bg-primary">
                        <i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($user_data['role_name']); ?>
                    </span>
                    
                    <?php if ($user_data['is_verified'] == 1): ?>
                        <div class="verification-badge badge-verified">
                            <i class="fas fa-check-circle"></i> Verified Account
                        </div>
                    <?php else: ?>
                        <div class="verification-badge badge-pending">
                            <i class="fas fa-clock"></i> Pending Verification
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Quick Info
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar"></i> Joined</div>
                        <div class="info-value"><?php echo date('M d, Y', strtotime($user_data['created_at'])); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-clock"></i> Last Updated</div>
                        <div class="info-value"><?php echo $last_updated; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="info-value"><?php echo $user_data['email'] ? htmlspecialchars($user_data['email']) : 'Not set'; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-phone"></i> Contact</div>
                        <div class="info-value"><?php echo $user_data['contact_number'] ? htmlspecialchars($user_data['contact_number']) : 'Not set'; ?></div>
                    </div>
                </div>
            </div>

            <!-- ID Photo Display/Upload Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-id-card"></i> Identity Verification
                </div>
                <div class="card-body">
                    <?php if (!empty($user_data['id_photo']) && file_exists('../../uploads/ids/' . $user_data['id_photo'])): ?>
                        <div class="id-photo-container">
                            <p class="mb-2"><strong>Valid ID Photo:</strong></p>
                            <?php 
                            $id_path = '../../uploads/ids/' . $user_data['id_photo'];
                            $file_ext = strtolower(pathinfo($id_path, PATHINFO_EXTENSION));
                            ?>
                            
                            <?php if ($file_ext == 'pdf'): ?>
                                <div class="id-photo-placeholder">
                                    <i class="fas fa-file-pdf"></i>
                                    <p class="mb-2 mt-2">PDF Document</p>
                                    <a href="../../uploads/ids/<?php echo htmlspecialchars($user_data['id_photo']); ?>" 
                                       target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View PDF
                                    </a>
                                </div>
                            <?php else: ?>
                                <img src="../../uploads/ids/<?php echo htmlspecialchars($user_data['id_photo']); ?>" 
                                     alt="ID Photo" class="id-photo-display" 
                                     data-bs-toggle="modal" data-bs-target="#idPhotoModal">
                                <p class="id-info-text">
                                    <i class="fas fa-info-circle"></i> Click to view full size
                                </p>
                            <?php endif; ?>
                            
                            <small class="text-muted d-block mt-2">
                                Uploaded: <?php echo date('M d, Y', filectime($id_path)); ?>
                            </small>
                            
                            <?php if ($user_data['is_verified'] != 1): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" 
                                        data-bs-toggle="modal" data-bs-target="#uploadIdModal">
                                    <i class="fas fa-upload"></i> Update ID Photo
                                </button>
                            <?php else: ?>
                                <div class="alert alert-success mt-2 mb-0 py-2">
                                    <i class="fas fa-lock"></i> ID verified and locked
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="id-photo-placeholder">
                            <i class="fas fa-id-card"></i>
                            <p class="mb-2 mt-2">No ID uploaded</p>
                            <small class="text-muted d-block mb-3">Upload your valid ID for verification</small>
                            <button type="button" class="btn btn-primary btn-sm" 
                                    data-bs-toggle="modal" data-bs-target="#uploadIdModal">
                                <i class="fas fa-upload"></i> Upload ID Now
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Profile Content -->
        <div class="col-md-8">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profile-info" type="button">
                        <i class="fas fa-user"></i> Profile Information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#account-settings" type="button">
                        <i class="fas fa-cog"></i> Account Settings
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Profile Information Tab -->
                <div class="tab-pane fade show active" id="profile-info">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-edit"></i> Edit Profile Information
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-camera"></i> Profile Photo
                                    </label>
                                    <input type="file" name="profile_photo" class="form-control" 
                                           accept="image/*" id="profilePhotoInput">
                                    <small class="text-muted">JPG, JPEG, PNG, or GIF. Max 5MB</small>
                                    <img id="photoPreview" class="photo-preview" src="" alt="Photo preview">
                                </div>

                                <!-- Personal Information Section -->
                                <h6 class="text-primary mt-4 mb-3">
                                    <i class="fas fa-user"></i> Personal Information
                                </h6>

                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" class="form-control" required
                                               value="<?php echo htmlspecialchars($user_data['last_name']); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" name="first_name" class="form-control" required
                                               value="<?php echo htmlspecialchars($user_data['first_name']); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" name="middle_name" class="form-control"
                                               value="<?php echo htmlspecialchars($user_data['middle_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Ext. (Jr., Sr., III)</label>
                                        <input type="text" name="ext" class="form-control" maxlength="10"
                                               placeholder="Jr., Sr., III"
                                               value="<?php echo htmlspecialchars($user_data['ext_name'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Permanent Address <span class="text-danger">*</span></label>
                                    <input type="text" name="permanent_address" class="form-control" required maxlength="255"
                                           placeholder="House No., Street"
                                           value="<?php echo htmlspecialchars($user_data['permanent_address'] ?? ''); ?>">
                                </div>

                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Street</label>
                                        <input type="text" name="street" class="form-control" maxlength="100"
                                               value="<?php echo htmlspecialchars($user_data['street'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Barangay <span class="text-danger">*</span></label>
                                        <input type="text" name="barangay" class="form-control" required maxlength="100"
                                               value="<?php echo htmlspecialchars($user_data['barangay'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Town/City <span class="text-danger">*</span></label>
                                        <input type="text" name="town" class="form-control" required maxlength="100"
                                               value="<?php echo htmlspecialchars($user_data['town'] ?? $user_data['city'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Province <span class="text-danger">*</span></label>
                                        <input type="text" name="province" class="form-control" required maxlength="100"
                                               value="<?php echo htmlspecialchars($user_data['province'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Birthplace <span class="text-danger">*</span></label>
                                        <input type="text" name="birthplace" class="form-control" required maxlength="100"
                                               placeholder="City/Municipality, Province"
                                               value="<?php echo htmlspecialchars($user_data['birthplace'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Mobile/Phone No. <span class="text-danger">*</span></label>
                                        <input type="tel" name="contact_number" class="form-control" required
                                               placeholder="09XXXXXXXXX" pattern="[0-9]{11}" maxlength="11"
                                               value="<?php echo htmlspecialchars($user_data['contact_number'] ?? ''); ?>">
                                        <small class="text-muted">Format: 09XXXXXXXXX (11 digits)</small>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Birthday <span class="text-danger">*</span></label>
                                        <input type="date" name="date_of_birth" class="form-control" required
                                               value="<?php echo htmlspecialchars($user_data['date_of_birth']); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Civil Status <span class="text-danger">*</span></label>
                                        <select name="civil_status" class="form-select" required>
                                            <option value="">-- Select --</option>
                                            <option value="Single" <?php echo $user_data['civil_status'] === 'Single' ? 'selected' : ''; ?>>Single</option>
                                            <option value="Married" <?php echo $user_data['civil_status'] === 'Married' ? 'selected' : ''; ?>>Married</option>
                                            <option value="Widowed" <?php echo $user_data['civil_status'] === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                            <option value="Separated" <?php echo $user_data['civil_status'] === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                            <option value="Divorced" <?php echo $user_data['civil_status'] === 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                                        <select name="gender" class="form-select" required>
                                            <option value="">-- Select --</option>
                                            <option value="Male" <?php echo $user_data['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo $user_data['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Occupation</label>
                                    <input type="text" name="occupation" class="form-control" maxlength="100"
                                           placeholder="e.g., Teacher, Engineer, Self-employed"
                                           value="<?php echo htmlspecialchars($user_data['occupation'] ?? ''); ?>">
                                </div>

                                <!-- Additional Contact Information -->
                                <h6 class="text-primary mt-4 mb-3">
                                    <i class="fas fa-envelope"></i> Additional Contact Information
                                </h6>

                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-control" maxlength="100"
                                           placeholder="your.email@example.com"
                                           value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                                    <small class="text-muted">For account recovery and notifications</small>
                                </div>

                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Account Settings Tab -->
                <div class="tab-pane fade" id="account-settings">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-key"></i> Change Password
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="new_password" class="form-control" 
                                           required minlength="8" id="newPassword">
                                    <small class="text-muted">Minimum 8 characters</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="confirm_password" class="form-control" 
                                           required minlength="8" id="confirmPassword">
                                    <small id="passwordMatch"></small>
                                </div>

                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fas fa-lock"></i> Change Password
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-info-circle"></i> Account Information
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-label">Username</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['username']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Role</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['role_name']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Account Created</div>
                                <div class="info-value"><?php echo date('F d, Y h:i A', strtotime($user_data['created_at'])); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo $last_updated; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Verification Status</div>
                                <div class="info-value">
                                    <?php if ($user_data['is_verified'] == 1): ?>
                                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ID Photo Modal (View) -->
<div class="modal fade" id="idPhotoModal" tabindex="-1" aria-labelledby="idPhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="idPhotoModalLabel">
                    <i class="fas fa-id-card"></i> Valid ID Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <?php if (!empty($user_data['id_photo']) && file_exists('../../uploads/ids/' . $user_data['id_photo'])): ?>
                    <img src="../../uploads/ids/<?php echo htmlspecialchars($user_data['id_photo']); ?>" 
                         alt="ID Photo" style="max-width: 100%; height: auto;">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ID Upload Modal -->
<div class="modal fade" id="uploadIdModal" tabindex="-1" aria-labelledby="uploadIdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadIdModalLabel">
                    <i class="fas fa-upload"></i> Upload Valid ID
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="idUploadForm">
                <div class="modal-body">
                    <?php if ($user_data['is_verified'] == 1): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-lock"></i> 
                            <strong>Account Verified:</strong> Your account is verified. ID photo cannot be changed.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Important:</strong> Upload a clear photo of your valid government-issued ID for verification.
                            <?php if (!empty($user_data['id_photo'])): ?>
                                <br><small class="text-muted">Uploading a new ID will replace your current one.</small>
                            <?php endif; ?>
                        </div>

                        <div class="id-upload-area" id="idUploadArea">
                            <input type="file" name="id_photo" id="idPhotoInput" 
                                   accept="image/jpeg,image/jpg,image/png,application/pdf" 
                                   class="d-none" required>
                            <div id="uploadPrompt">
                                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                <p class="mb-2"><strong>Click to upload or drag and drop</strong></p>
                                <p class="text-muted small mb-0">JPG, PNG, or PDF (max 5MB)</p>
                            </div>
                            <div id="fileInfo" class="d-none">
                                <div class="id-file-info">
                                    <i class="fas fa-file-alt fa-2x mb-2"></i>
                                    <p class="mb-1" id="fileName"></p>
                                    <p class="text-muted small mb-2" id="fileSize"></p>
                                    <button type="button" class="btn btn-sm btn-danger" id="removeFile">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                                <img id="idPreview" class="id-preview d-none" src="" alt="ID Preview">
                            </div>
                        </div>

                        <div class="mt-3">
                            <p class="small text-muted mb-1"><strong>Acceptable IDs:</strong></p>
                            <ul class="small text-muted">
                                <li>Driver's License</li>
                                <li>Passport</li>
                                <li>National ID / PhilSys ID</li>
                                <li>SSS / GSIS / UMID ID</li>
                                <li>Postal ID</li>
                                <li>Voter's ID</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> <?php echo $user_data['is_verified'] == 1 ? 'Close' : 'Cancel'; ?>
                    </button>
                    <?php if ($user_data['is_verified'] != 1): ?>
                        <button type="submit" name="upload_id" class="btn btn-primary" id="uploadBtn" disabled>
                            <i class="fas fa-upload"></i> Upload ID
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Profile photo preview
document.getElementById('profilePhotoInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('photoPreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }
});

// Password match validation
document.getElementById('confirmPassword')?.addEventListener('input', function() {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = this.value;
    const matchText = document.getElementById('passwordMatch');
    
    if (confirmPassword.length > 0) {
        if (newPassword === confirmPassword) {
            matchText.innerHTML = '<i class="fas fa-check text-success"></i> Passwords match';
            matchText.className = 'text-success';
        } else {
            matchText.innerHTML = '<i class="fas fa-times text-danger"></i> Passwords do not match';
            matchText.className = 'text-danger';
        }
    } else {
        matchText.innerHTML = '';
    }
});

// Contact number formatting
document.querySelector('input[name="contact_number"]')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9]/g, '');
});

// ID Upload functionality
const idUploadArea = document.getElementById('idUploadArea');
const idPhotoInput = document.getElementById('idPhotoInput');
const uploadPrompt = document.getElementById('uploadPrompt');
const fileInfo = document.getElementById('fileInfo');
const fileName = document.getElementById('fileName');
const fileSize = document.getElementById('fileSize');
const idPreview = document.getElementById('idPreview');
const removeFileBtn = document.getElementById('removeFile');
const uploadBtn = document.getElementById('uploadBtn');

// Click to upload
idUploadArea?.addEventListener('click', function(e) {
    if (e.target !== removeFileBtn && !removeFileBtn.contains(e.target)) {
        idPhotoInput.click();
    }
});

// Drag and drop
idUploadArea?.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

idUploadArea?.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

idUploadArea?.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        idPhotoInput.files = files;
        handleFileSelect(files[0]);
    }
});

// File input change
idPhotoInput?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        handleFileSelect(file);
    }
});

// Handle file selection
function handleFileSelect(file) {
    // Validate file size
    if (file.size > 5242880) {
        alert('File size must be less than 5MB');
        idPhotoInput.value = '';
        return;
    }
    
    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    if (!allowedTypes.includes(file.type)) {
        alert('Only JPG, PNG, or PDF files are allowed');
        idPhotoInput.value = '';
        return;
    }
    
    // Show file info
    uploadPrompt.classList.add('d-none');
    fileInfo.classList.remove('d-none');
    fileName.textContent = file.name;
    fileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
    uploadBtn.disabled = false;
    
    // Show preview for images
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            idPreview.src = e.target.result;
            idPreview.classList.remove('d-none');
        }
        reader.readAsDataURL(file);
    } else {
        idPreview.classList.add('d-none');
    }
}

// Remove file
removeFileBtn?.addEventListener('click', function(e) {
    e.stopPropagation();
    idPhotoInput.value = '';
    uploadPrompt.classList.remove('d-none');
    fileInfo.classList.add('d-none');
    idPreview.classList.add('d-none');
    uploadBtn.disabled = true;
});

// Reset form when modal is closed
document.getElementById('uploadIdModal')?.addEventListener('hidden.bs.modal', function() {
    idPhotoInput.value = '';
    uploadPrompt.classList.remove('d-none');
    fileInfo.classList.add('d-none');
    idPreview.classList.add('d-none');
    uploadBtn.disabled = true;
});
</script>

</body>
</html>
<?php $conn->close(); ?>