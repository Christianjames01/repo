<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
requireLogin();

$success = '';
$error = '';
$info = '';
$user_id = getCurrentUserId();
$resident_id = getCurrentResidentId();

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

$last_updated = 'Never';
if (!empty($user_data['updated_at']) && $user_data['updated_at'] != '0000-00-00 00:00:00')
    $last_updated = date('F d, Y h:i A', strtotime($user_data['updated_at']));
elseif (!empty($user_data['resident_updated_at']) && $user_data['resident_updated_at'] != '0000-00-00 00:00:00')
    $last_updated = date('F d, Y h:i A', strtotime($user_data['resident_updated_at']));

/* ── ID photo upload ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_id'])) {
    $errors = [];
    if ($user_data['is_verified'] == 1) {
        $error = "Cannot change ID photo. Your account is already verified.";
    } elseif (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','pdf'])) $errors[] = "ID photo must be JPG, JPEG, PNG, or PDF";
        elseif ($_FILES['id_photo']['size'] > 5242880) $errors[] = "ID photo must be less than 5MB";
        else {
            $upload_dir = '../../uploads/ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_filename = 'id_' . $resident_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['id_photo']['tmp_name'], $upload_dir . $new_filename)) {
                if ($user_data['id_photo'] && file_exists($upload_dir . $user_data['id_photo']))
                    unlink($upload_dir . $user_data['id_photo']);
                $stmt = $conn->prepare("UPDATE tbl_residents SET id_photo = ?, updated_at = NOW() WHERE resident_id = ?");
                $stmt->bind_param("si", $new_filename, $resident_id);
                if ($stmt->execute()) { $success = "ID photo uploaded successfully! Your account will be reviewed for verification."; $user_data['id_photo'] = $new_filename; }
                else { $errors[] = "Failed to update ID photo in database"; unlink($upload_dir . $new_filename); }
                $stmt->close();
            } else $errors[] = "Failed to upload ID photo";
        }
    } else $errors[] = "Please select an ID photo to upload";
    if (!empty($errors)) $error = implode("<br>", $errors);
}

/* ── Profile update ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $errors = [];
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
    $address = implode(', ', array_filter([$permanent_address, $street, $barangay, $town, $province]));

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
    if (!empty($contact_number)) {
        $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
        if (strlen($contact_number) != 11 || substr($contact_number, 0, 2) != '09')
            $errors[] = "Contact number must be in format 09XXXXXXXXX";
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (!empty($email)) {
        $stmt = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE email = ? AND resident_id != ?");
        $stmt->bind_param("si", $email, $resident_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = "Email already in use by another account";
        $stmt->close();
    }

    $profile_photo = $user_data['profile_photo'];
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif'])) $errors[] = "Profile photo must be JPG, JPEG, PNG, or GIF";
        elseif ($_FILES['profile_photo']['size'] > 5242880) $errors[] = "Profile photo must be less than 5MB";
        else {
            $upload_dir = '../../uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $new_filename)) {
                if ($profile_photo && file_exists($upload_dir . $profile_photo)) unlink($upload_dir . $profile_photo);
                $profile_photo = $new_filename;
            } else $errors[] = "Failed to upload profile photo";
        }
    }

    if (empty($errors)) {
        // Detect if anything actually changed
        $no_photo_change = !isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK;
        // Use == and trim to safely compare nullable DB values vs form strings
        $db = $user_data;
        $profile_unchanged =
            $first_name        == ($db['first_name']        ?? '') &&
            $middle_name       == ($db['middle_name']       ?? '') &&
            $last_name         == ($db['last_name']         ?? '') &&
            $ext_name          == ($db['ext_name']          ?? '') &&
            $date_of_birth     == ($db['date_of_birth']     ?? '') &&
            $gender            == ($db['gender']            ?? '') &&
            $civil_status      == ($db['civil_status']      ?? '') &&
            $permanent_address == ($db['permanent_address'] ?? '') &&
            $street            == ($db['street']            ?? '') &&
            $barangay          == ($db['barangay']          ?? '') &&
            $town              == ($db['town']              ?? $db['city'] ?? '') &&
            $province          == ($db['province']          ?? '') &&
            $birthplace        == ($db['birthplace']        ?? '') &&
            $contact_number    == ($db['contact_number']    ?? '') &&
            $email             == ($db['email']             ?? '') &&
            $occupation        == ($db['occupation']        ?? '') &&
            $no_photo_change;

        if ($profile_unchanged) {
            $info = "No changes were made to your profile.";
        } else {
            $stmt = $conn->prepare("UPDATE tbl_residents SET first_name=?,middle_name=?,last_name=?,ext_name=?,date_of_birth=?,gender=?,civil_status=?,address=?,permanent_address=?,street=?,barangay=?,town=?,province=?,birthplace=?,contact_number=?,email=?,occupation=?,profile_photo=?,updated_at=NOW() WHERE resident_id=?");
            $stmt->bind_param("ssssssssssssssssssi",$first_name,$middle_name,$last_name,$ext_name,$date_of_birth,$gender,$civil_status,$address,$permanent_address,$street,$barangay,$town,$province,$birthplace,$contact_number,$email,$occupation,$profile_photo,$resident_id);
            if ($stmt->execute()) {
                $success = "Profile updated successfully!";
                $stmt->close();
                $stmt = $conn->prepare("SELECT u.*,r.role_name,res.first_name,res.middle_name,res.last_name,res.ext_name,res.date_of_birth,res.gender,res.civil_status,res.address,res.contact_number,res.email,res.occupation,res.profile_photo,res.id_photo,res.is_verified,res.permanent_address,res.street,res.barangay,res.town,res.city,res.province,res.birthplace,res.updated_at as resident_updated_at FROM tbl_users u LEFT JOIN tbl_roles r ON u.role_id=r.role_id LEFT JOIN tbl_residents res ON u.resident_id=res.resident_id WHERE u.user_id=?");
                $stmt->bind_param("i",$user_id); $stmt->execute();
                $user_data = $stmt->get_result()->fetch_assoc();
                if (!empty($user_data['updated_at']) && $user_data['updated_at']!='0000-00-00 00:00:00') $last_updated = date('F d, Y h:i A',strtotime($user_data['updated_at']));
            } else $errors[] = "Failed to update profile";
            $stmt->close();
        }
    }
    if (!empty($errors)) $error = implode("<br>", $errors);
}

/* ── Password change ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $errors = [];
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    if (empty($current_password)) $errors[] = "Current password is required";
    if (empty($new_password)) $errors[] = "New password is required";
    if (strlen($new_password) < 8) $errors[] = "New password must be at least 8 characters";
    if ($new_password !== $confirm_password) $errors[] = "New passwords do not match";
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT password FROM tbl_users WHERE user_id = ?");
        $stmt->bind_param("i",$user_id); $stmt->execute();
        $user_pass = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($current_password !== $user_pass['password']) $errors[] = "Current password is incorrect";
        elseif ($new_password === $current_password) $info = "No changes were made. Your new password is the same as the current one.";
    }
    if (empty($errors) && empty($info)) {
        $stmt = $conn->prepare("UPDATE tbl_users SET password=?,updated_at=NOW() WHERE user_id=?");
        $stmt->bind_param("si",$new_password,$user_id);
        if ($stmt->execute()) $success = "Password changed successfully!";
        else $errors[] = "Failed to change password";
        $stmt->close();
    }
    if (!empty($errors)) $error = implode("<br>", $errors);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/profile.css">
    <style>
        /* ── Notification Modal ── */
        .notif-modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
            text-align: center;
            padding: 0 0 28px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.18);
        }
        .notif-modal-icon {
            width: 100%;
            padding: 36px 0 30px;
            font-size: 52px;
            line-height: 1;
        }
        .notif-modal-content.notif--success .notif-modal-icon {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #059669;
        }
        .notif-modal-content.notif--error .notif-modal-icon {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc2626;
        }
        .notif-modal-body {
            padding: 12px 32px 20px;
        }
        .notif-modal-title {
            font-family: 'Sora', sans-serif;
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 8px;
            color: #0f172a;
        }
        .notif-modal-msg {
            font-size: 14px;
            color: #475569;
            margin: 0;
            line-height: 1.65;
        }
        .notif-modal-close {
            display: inline-block;
            padding: 10px 44px;
            border-radius: 50px;
            border: none;
            font-family: 'Sora', sans-serif;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s, opacity 0.15s;
            margin-top: 4px;
        }
        .notif-modal-content.notif--success .notif-modal-close {
            background: #059669;
            color: #fff;
        }
        .notif-modal-content.notif--error .notif-modal-close {
            background: #dc2626;
            color: #fff;
        }
        .notif-modal-content.notif--info .notif-modal-icon {
            background: linear-gradient(135deg, #fef9c3 0%, #fde68a 100%);
            color: #d97706;
        }
        .notif-modal-content.notif--info .notif-modal-close {
            background: #d97706;
            color: #fff;
        }
        .notif-modal-close:hover {
            opacity: 0.88;
            transform: scale(1.03);
        }
    </style>
</head>
<body>

<!-- ═══════════ HERO BANNER ═══════════ -->
<div class="pf-hero">
    <div class="pf-hero__inner">
        <div class="pf-hero__left">
            <h1><i class="fas fa-user-circle"></i> My Profile</h1>
            <p>Manage your personal information and account settings</p>
        </div>
        <a href="../dashboard/index.php" class="pf-hero__back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    <div class="pf-hero__cut"></div>
</div>

<!-- ═══════════ MAIN WRAPPER ═══════════ -->
<div class="pf-wrap">

    <!-- IDENTITY STRIP -->
    <div class="pf-identity">
        <div class="pf-identity__body">
            <!-- Avatar -->
            <div class="pf-identity__av">
                <?php if (!empty($user_data['profile_photo']) && file_exists('../../uploads/profiles/' . $user_data['profile_photo'])): ?>
                    <img src="../../uploads/profiles/<?= htmlspecialchars($user_data['profile_photo']) ?>" alt="Avatar">
                <?php else: ?>
                    <div class="pf-identity__av-ph"><i class="fas fa-user"></i></div>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="pf-identity__info">
                <div class="pf-identity__name">
                    <?= htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']) ?>
                </div>
                <div class="pf-identity__un">@<?= htmlspecialchars($user_data['username']) ?></div>
                <div class="pf-identity__tags">
                    <span class="pf-tag pf-tag--role">
                        <i class="fas fa-user-tag"></i> <?= htmlspecialchars($user_data['role_name']) ?>
                    </span>
                    <?php if ($user_data['is_verified'] == 1): ?>
                        <span class="pf-tag pf-tag--verified"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php else: ?>
                        <span class="pf-tag pf-tag--pending"><i class="fas fa-clock"></i> Pending</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats -->
            <?php
            $joinedDate  = new DateTime($user_data['created_at']);
            $now         = new DateTime();
            $diff        = $joinedDate->diff($now);
            if ($diff->y >= 1) {
                $memberVal   = $diff->y;
                $memberLabel = $memberVal === 1 ? 'Yr Member' : 'Yrs Member';
            } elseif ($diff->m >= 1) {
                $memberVal   = $diff->m;
                $memberLabel = $memberVal === 1 ? 'Mo Member' : 'Mos Member';
            } else {
                $memberVal   = $diff->d === 0 ? 'Today' : $diff->d . 'd';
                $memberLabel = 'Member Since';
            }
            ?>
            <div class="pf-identity__stats">
                <div class="pf-stat">
                    <div class="pf-stat__n"><?= $memberVal ?></div>
                    <div class="pf-stat__l"><?= $memberLabel ?></div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat__n"><?= $user_data['is_verified'] ? '✓' : '–' ?></div>
                    <div class="pf-stat__l">ID Status</div>
                </div>
                <div class="pf-stat">
                    <div class="pf-stat__n"><?= date('M d', strtotime($user_data['created_at'])) ?></div>
                    <div class="pf-stat__l"><?= date('Y', strtotime($user_data['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($user_data['is_verified'] != 1): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle fa-lg" style="margin-top:2px;flex-shrink:0"></i>
        <div>
            <strong>Account Not Verified</strong> — Your account is pending verification. You need to be verified before you can submit document requests.
            <?php if (empty($user_data['id_photo'])): ?>
                <br><span class="mt-1 d-inline-block"><strong>Action Required:</strong> Upload your valid government-issued ID to start the verification process.</span>
                <br><button type="button" class="pf-btn pf-btn--amber pf-btn--sm mt-2" data-bs-toggle="modal" data-bs-target="#uploadIdModal">
                    <i class="fas fa-upload"></i> Upload ID Now
                </button>
            <?php else: ?>
                <br><i class="fas fa-check-circle text-success"></i> <strong>ID Uploaded:</strong> Your ID has been submitted. Please wait for admin verification.
            <?php endif; ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- TWO-COLUMN LAYOUT -->
    <div class="pf-layout">

        <!-- ═══ SIDEBAR ═══ -->
        <aside class="pf-sidebar">

            <!-- Quick Info -->
            <div class="pf-panel">
                <div class="pf-panel__hd"><i class="fas fa-info-circle"></i> Quick Info</div>
                <div class="pf-panel__bd">
                    <div class="pf-info-row">
                        <div class="pf-info-row__ic"><i class="fas fa-calendar"></i></div>
                        <div>
                            <div class="pf-info-row__lbl">Joined</div>
                            <div class="pf-info-row__val"><?= date('M d, Y', strtotime($user_data['created_at'])) ?></div>
                        </div>
                    </div>
                    <div class="pf-info-row">
                        <div class="pf-info-row__ic"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="pf-info-row__lbl">Last Updated</div>
                            <div class="pf-info-row__val"><?= $last_updated ?></div>
                        </div>
                    </div>
                    <div class="pf-info-row">
                        <div class="pf-info-row__ic"><i class="fas fa-envelope"></i></div>
                        <div>
                            <div class="pf-info-row__lbl">Email</div>
                            <div class="pf-info-row__val"><?= $user_data['email'] ? htmlspecialchars($user_data['email']) : 'Not set' ?></div>
                        </div>
                    </div>
                    <div class="pf-info-row">
                        <div class="pf-info-row__ic"><i class="fas fa-phone"></i></div>
                        <div>
                            <div class="pf-info-row__lbl">Contact</div>
                            <div class="pf-info-row__val"><?= $user_data['contact_number'] ? htmlspecialchars($user_data['contact_number']) : 'Not set' ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Identity Verification -->
            <div class="pf-panel">
                <div class="pf-panel__hd"><i class="fas fa-id-card"></i> Identity Verification</div>
                <div class="pf-panel__bd">
                    <?php if (!empty($user_data['id_photo']) && file_exists('../../uploads/ids/' . $user_data['id_photo'])): ?>
                        <?php
                        $id_path = '../../uploads/ids/' . $user_data['id_photo'];
                        $file_ext = strtolower(pathinfo($id_path, PATHINFO_EXTENSION));
                        ?>
                        <div class="pf-id-wrap">
                            <?php if ($file_ext == 'pdf'): ?>
                                <div class="pf-id-empty">
                                    <i class="fas fa-file-pdf" style="color:#e11d48"></i>
                                    <p class="mb-2 mt-1" style="font-size:13px;font-weight:600">PDF Document</p>
                                    <a href="../../uploads/ids/<?= htmlspecialchars($user_data['id_photo']) ?>" target="_blank" class="pf-btn pf-btn--navy pf-btn--sm">
                                        <i class="fas fa-eye"></i> View PDF
                                    </a>
                                </div>
                            <?php else: ?>
                                <img src="../../uploads/ids/<?= htmlspecialchars($user_data['id_photo']) ?>"
                                     alt="ID Photo" class="pf-id-display"
                                     data-bs-toggle="modal" data-bs-target="#idPhotoModal">
                                <p class="pf-id-hint"><i class="fas fa-expand-alt"></i> Click to view full size</p>
                            <?php endif; ?>
                            <small style="display:block;margin-top:8px;font-family:'DM Mono',monospace;font-size:10px;color:#64748b;">
                                Uploaded: <?= date('M d, Y', filectime($id_path)) ?>
                            </small>
                        </div>
                        <div style="margin-top:12px;text-align:center">
                            <?php if ($user_data['is_verified'] != 1): ?>
                                <button type="button" class="pf-btn pf-btn--outline pf-btn--sm" data-bs-toggle="modal" data-bs-target="#uploadIdModal">
                                    <i class="fas fa-upload"></i> Update ID
                                </button>
                            <?php else: ?>
                                <div class="alert alert-success mb-0 py-2" style="font-size:12px">
                                    <i class="fas fa-lock"></i> ID verified and locked
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="pf-id-empty">
                            <i class="fas fa-id-card"></i>
                            <p style="font-size:13px;font-weight:600;margin:6px 0 2px">No ID uploaded</p>
                            <small style="color:#94a3b8;font-size:11px">Upload your valid ID for verification</small>
                        </div>
                        <div style="text-align:center;margin-top:12px">
                            <button type="button" class="pf-btn pf-btn--amber pf-btn--sm" data-bs-toggle="modal" data-bs-target="#uploadIdModal">
                                <i class="fas fa-upload"></i> Upload ID Now
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </aside>

        <!-- ═══ MAIN CONTENT ═══ -->
        <main>
            <!-- Tabs -->
            <div class="pf-tabs" role="tablist">
                <button class="pf-tab active" data-target="tab-profile">
                    <i class="fas fa-user"></i> Profile Information
                </button>
                <button class="pf-tab" data-target="tab-settings">
                    <i class="fas fa-cog"></i> Account Settings
                </button>
            </div>

            <div class="pf-tab-panels">

                <!-- ── Profile Info Tab ── -->
                <div class="pf-tab-panel active" id="tab-profile">
                    <div class="pf-panel">
                        <div class="pf-panel__hd"><i class="fas fa-edit"></i> Edit Profile Information</div>
                        <div class="pf-panel__bd">
                            <form method="POST" enctype="multipart/form-data">

                                <!-- Profile Photo -->
                                <div class="mb-3" style="display:flex;align-items:center;gap:16px">
                                    <div>
                                        <label class="form-label"><i class="fas fa-camera"></i> Profile Photo</label>
                                        <input type="file" name="profile_photo" class="form-control" accept="image/*" id="profilePhotoInput">
                                        <small style="color:#64748b;font-size:11px">JPG, JPEG, PNG, or GIF · Max 5MB</small>
                                    </div>
                                    <img id="photoPreview" class="pf-photo-preview" src="" alt="">
                                </div>

                                <!-- Personal Info -->
                                <div class="pf-section">
                                    <div class="pf-section__ic"><i class="fas fa-user"></i></div>
                                    <span>Personal Information</span>
                                </div>

                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($user_data['last_name']) ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($user_data['first_name']) ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Ext. (Jr., Sr., III)</label>
                                        <input type="text" name="ext" class="form-control" maxlength="10" placeholder="Jr., Sr., III" value="<?= htmlspecialchars($user_data['ext_name'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Permanent Address <span class="text-danger">*</span></label>
                                    <input type="text" name="permanent_address" class="form-control" required maxlength="255" placeholder="House No., Street" value="<?= htmlspecialchars($user_data['permanent_address'] ?? '') ?>">
                                </div>

                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Street</label>
                                        <input type="text" name="street" class="form-control" maxlength="100" value="<?= htmlspecialchars($user_data['street'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Barangay <span class="text-danger">*</span></label>
                                        <input type="text" name="barangay" class="form-control" required maxlength="100" value="<?= htmlspecialchars($user_data['barangay'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Town/City <span class="text-danger">*</span></label>
                                        <input type="text" name="town" class="form-control" required maxlength="100" value="<?= htmlspecialchars($user_data['town'] ?? $user_data['city'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Province <span class="text-danger">*</span></label>
                                        <input type="text" name="province" class="form-control" required maxlength="100" value="<?= htmlspecialchars($user_data['province'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Birthplace <span class="text-danger">*</span></label>
                                        <input type="text" name="birthplace" class="form-control" required maxlength="100" placeholder="City/Municipality, Province" value="<?= htmlspecialchars($user_data['birthplace'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Mobile/Phone No. <span class="text-danger">*</span></label>
                                        <input type="tel" name="contact_number" class="form-control" required placeholder="09XXXXXXXXX" pattern="[0-9]{11}" maxlength="11" value="<?= htmlspecialchars($user_data['contact_number'] ?? '') ?>">
                                        <small style="color:#64748b;font-size:11px">Format: 09XXXXXXXXX (11 digits)</small>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Birthday <span class="text-danger">*</span></label>
                                        <input type="date" name="date_of_birth" class="form-control" required value="<?= htmlspecialchars($user_data['date_of_birth']) ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Civil Status <span class="text-danger">*</span></label>
                                        <select name="civil_status" class="form-select" required>
                                            <option value="">-- Select --</option>
                                            <?php foreach (['Single','Married','Widowed','Separated','Divorced'] as $cs): ?>
                                            <option value="<?= $cs ?>" <?= $user_data['civil_status'] === $cs ? 'selected' : '' ?>><?= $cs ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                                        <select name="gender" class="form-select" required>
                                            <option value="">-- Select --</option>
                                            <option value="Male" <?= $user_data['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= $user_data['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Occupation</label>
                                    <input type="text" name="occupation" class="form-control" maxlength="100" placeholder="e.g., Teacher, Engineer, Self-employed" value="<?= htmlspecialchars($user_data['occupation'] ?? '') ?>">
                                </div>

                                <!-- Contact -->
                                <div class="pf-section">
                                    <div class="pf-section__ic"><i class="fas fa-envelope"></i></div>
                                    <span>Additional Contact Information</span>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-control" maxlength="100" placeholder="your.email@example.com" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>">
                                    <small style="color:#64748b;font-size:11px">For account recovery and notifications</small>
                                </div>

                                <button type="submit" name="update_profile" class="pf-btn pf-btn--navy">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ── Account Settings Tab ── -->
                <div class="pf-tab-panel" id="tab-settings">
                    <div class="pf-panel">
                        <div class="pf-panel__hd"><i class="fas fa-key"></i> Change Password</div>
                        <div class="pf-panel__bd">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="new_password" class="form-control" required minlength="8" id="newPassword">
                                    <small style="color:#64748b;font-size:11px">Minimum 8 characters</small>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="confirm_password" class="form-control" required minlength="8" id="confirmPassword">
                                    <small id="passwordMatch"></small>
                                </div>
                                <button type="submit" name="change_password" class="pf-btn pf-btn--navy">
                                    <i class="fas fa-lock"></i> Change Password
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="pf-panel" style="margin-top:16px">
                        <div class="pf-panel__hd"><i class="fas fa-info-circle"></i> Account Information</div>
                        <div class="pf-panel__bd">
                            <?php
                            $rows = [
                                ['fas fa-user','Username', htmlspecialchars($user_data['username'])],
                                ['fas fa-user-tag','Role', htmlspecialchars($user_data['role_name'])],
                                ['fas fa-calendar','Account Created', date('F d, Y h:i A', strtotime($user_data['created_at']))],
                                ['fas fa-clock','Last Updated', $last_updated],
                            ];
                            foreach ($rows as [$ic, $lbl, $val]):
                            ?>
                            <div class="pf-info-row">
                                <div class="pf-info-row__ic"><i class="<?= $ic ?>"></i></div>
                                <div>
                                    <div class="pf-info-row__lbl"><?= $lbl ?></div>
                                    <div class="pf-info-row__val"><?= $val ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="pf-info-row">
                                <div class="pf-info-row__ic"><i class="fas fa-shield-alt"></i></div>
                                <div>
                                    <div class="pf-info-row__lbl">Verification Status</div>
                                    <div class="pf-info-row__val">
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

            </div><!-- /pf-tab-panels -->
        </main>

    </div><!-- /pf-layout -->
</div><!-- /pf-wrap -->


<!-- ═══ ID Photo View Modal ═══ -->
<div class="modal fade" id="idPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card"></i> Valid ID Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <?php if (!empty($user_data['id_photo']) && file_exists('../../uploads/ids/' . $user_data['id_photo'])): ?>
                    <img src="../../uploads/ids/<?= htmlspecialchars($user_data['id_photo']) ?>" alt="ID Photo" style="max-width:100%;height:auto;border-radius:8px">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ ID Upload Modal ═══ -->
<div class="modal fade" id="uploadIdModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-upload"></i> Upload Valid ID</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="idUploadForm">
                <div class="modal-body">
                    <?php if ($user_data['is_verified'] == 1): ?>
                        <div class="alert alert-warning"><i class="fas fa-lock"></i> <strong>Account Verified:</strong> Your account is verified. ID photo cannot be changed.</div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Important:</strong> Upload a clear photo of your valid government-issued ID for verification.
                            <?php if (!empty($user_data['id_photo'])): ?>
                                <br><small>Uploading a new ID will replace your current one.</small>
                            <?php endif; ?>
                        </div>

                        <div class="pf-upload-area" id="idUploadArea">
                            <input type="file" name="id_photo" id="idPhotoInput" accept="image/jpeg,image/jpg,image/png,application/pdf" class="d-none" required>
                            <div id="uploadPrompt">
                                <i class="fas fa-cloud-upload-alt fa-3x" style="color:#94a3b8;margin-bottom:12px;display:block"></i>
                                <p style="font-weight:700;margin-bottom:4px">Click to upload or drag and drop</p>
                                <p style="color:#64748b;font-size:12px;margin:0">JPG, PNG, or PDF (max 5MB)</p>
                            </div>
                            <div id="fileInfo" class="d-none">
                                <div class="pf-file-info">
                                    <i class="fas fa-file-alt fa-2x" style="display:block;margin-bottom:8px;color:#1c3461"></i>
                                    <p style="margin-bottom:2px;font-weight:600" id="fileName"></p>
                                    <p style="color:#64748b;font-size:12px;margin-bottom:8px" id="fileSize"></p>
                                    <button type="button" class="pf-btn pf-btn--danger pf-btn--sm" id="removeFile">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>
                                <img id="idPreview" class="pf-upload-preview d-none" src="" alt="Preview">
                            </div>
                        </div>

                        <div style="margin-top:14px">
                            <p style="font-size:12px;font-weight:700;margin-bottom:4px;color:#1e293b">Acceptable IDs:</p>
                            <ul style="font-size:12px;color:#64748b;padding-left:20px;margin:0">
                                <li>Driver's License</li>
                                <li>Passport</li>
                                <li>National ID / PhilSys ID</li>
                                <li>SSS / GSIS / UMID ID</li>
                                <li>Postal ID · Voter's ID</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="pf-btn pf-btn--ghost" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> <?= $user_data['is_verified'] == 1 ? 'Close' : 'Cancel' ?>
                    </button>
                    <?php if ($user_data['is_verified'] != 1): ?>
                        <button type="submit" name="upload_id" class="pf-btn pf-btn--amber" id="uploadBtn" disabled>
                            <i class="fas fa-upload"></i> Upload ID
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ Notification Modal ═══ -->
<div class="modal fade" id="notifModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content notif-modal-content" id="notifModalContent">
            <div class="notif-modal-icon" id="notifIcon"></div>
            <div class="notif-modal-body">
                <h5 class="notif-modal-title" id="notifTitle"></h5>
                <p class="notif-modal-msg" id="notifMessage"></p>
            </div>
            <button type="button" class="notif-modal-close" data-bs-dismiss="modal">OK</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Notification Modal (auto-trigger on page load) ── */
(function () {
    <?php if ($success): ?>
    const notifType    = 'success';
    const notifMessage = <?= json_encode($success) ?>;
    <?php elseif ($error): ?>
    const notifType    = 'error';
    const notifMessage = <?= json_encode($error) ?>;
    <?php elseif ($info): ?>
    const notifType    = 'info';
    const notifMessage = <?= json_encode($info) ?>;
    <?php else: ?>
    const notifType    = null;
    const notifMessage = null;
    <?php endif; ?>

    if (notifType && notifMessage) {
        const modal   = document.getElementById('notifModal');
        const content = document.getElementById('notifModalContent');
        const icon    = document.getElementById('notifIcon');
        const title   = document.getElementById('notifTitle');
        const msg     = document.getElementById('notifMessage');

        content.classList.remove('notif--success', 'notif--error', 'notif--info');
        content.classList.add('notif--' + notifType);

        icon.innerHTML = notifType === 'success'
            ? '<i class="fas fa-check-circle"></i>'
            : notifType === 'info'
                ? '<i class="fas fa-info-circle"></i>'
                : '<i class="fas fa-times-circle"></i>';

        title.textContent = notifType === 'success'
            ? 'Success!'
            : notifType === 'info'
                ? 'No Changes'
                : 'Something went wrong';
        msg.innerHTML = notifMessage;

        const bsModal = new bootstrap.Modal(modal, { backdrop: true, keyboard: true });
        bsModal.show();
    }
})();

/* ── Tab switching ── */
document.querySelectorAll('.pf-tab').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.pf-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.pf-tab-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.target).classList.add('active');
    });
});

/* ── Profile photo preview ── */
document.getElementById('profilePhotoInput')?.addEventListener('change', function() {
    const file = this.files[0];
    const preview = document.getElementById('photoPreview');
    if (file) {
        const r = new FileReader();
        r.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
        r.readAsDataURL(file);
    } else preview.style.display = 'none';
});

/* ── Password match ── */
document.getElementById('confirmPassword')?.addEventListener('input', function() {
    const match = document.getElementById('passwordMatch');
    if (!this.value) { match.innerHTML = ''; return; }
    if (this.value === document.getElementById('newPassword').value) {
        match.innerHTML = '<i class="fas fa-check text-success"></i> Passwords match';
        match.className = 'text-success';
    } else {
        match.innerHTML = '<i class="fas fa-times text-danger"></i> Passwords do not match';
        match.className = 'text-danger';
    }
});

/* ── Contact number ── */
document.querySelector('input[name="contact_number"]')?.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
});

/* ── ID Upload ── */
const uploadArea = document.getElementById('idUploadArea');
const idInput    = document.getElementById('idPhotoInput');
const prompt     = document.getElementById('uploadPrompt');
const fileInfo   = document.getElementById('fileInfo');
const fileNameEl = document.getElementById('fileName');
const fileSizeEl = document.getElementById('fileSize');
const previewEl  = document.getElementById('idPreview');
const removeBtn  = document.getElementById('removeFile');
const uploadBtn  = document.getElementById('uploadBtn');

uploadArea?.addEventListener('click', e => { if (!removeBtn?.contains(e.target)) idInput.click(); });
uploadArea?.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea?.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
uploadArea?.addEventListener('drop', e => {
    e.preventDefault(); uploadArea.classList.remove('dragover');
    if (e.dataTransfer.files[0]) { idInput.files = e.dataTransfer.files; handleFile(e.dataTransfer.files[0]); }
});
idInput?.addEventListener('change', e => { if (e.target.files[0]) handleFile(e.target.files[0]); });

function handleFile(file) {
    if (file.size > 5242880) { alert('File must be under 5MB'); idInput.value=''; return; }
    if (!['image/jpeg','image/jpg','image/png','application/pdf'].includes(file.type)) { alert('Only JPG, PNG, or PDF allowed'); idInput.value=''; return; }
    prompt.classList.add('d-none'); fileInfo.classList.remove('d-none');
    fileNameEl.textContent = file.name;
    fileSizeEl.textContent = (file.size/1024/1024).toFixed(2) + ' MB';
    uploadBtn.disabled = false;
    if (file.type.startsWith('image/')) {
        const r = new FileReader();
        r.onload = e => { previewEl.src = e.target.result; previewEl.classList.remove('d-none'); };
        r.readAsDataURL(file);
    } else previewEl.classList.add('d-none');
}

removeBtn?.addEventListener('click', e => {
    e.stopPropagation(); idInput.value = '';
    prompt.classList.remove('d-none'); fileInfo.classList.add('d-none');
    previewEl.classList.add('d-none'); uploadBtn.disabled = true;
});

document.getElementById('uploadIdModal')?.addEventListener('hidden.bs.modal', () => {
    idInput.value = '';
    prompt.classList.remove('d-none'); fileInfo.classList.add('d-none');
    previewEl.classList.add('d-none'); if(uploadBtn) uploadBtn.disabled = true;
});
</script>
</body>
</html>
<?php $conn->close(); ?>