<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/email_helper.php';

// Redirect logged-in users
if (isLoggedIn()) {
    header("Location: /barangaylink1/modules/dashboard/index.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Collect form data
    $first_name        = trim($_POST['first_name']        ?? '');
    $middle_name       = trim($_POST['middle_name']       ?? '');
    $last_name         = trim($_POST['last_name']         ?? '');
    $ext               = trim($_POST['ext']               ?? '');
    $date_of_birth     = $_POST['date_of_birth']          ?? '';
    $gender            = $_POST['gender']                 ?? '';
    $civil_status      = $_POST['civil_status']           ?? '';
    $permanent_address = trim($_POST['permanent_address'] ?? '');
    $street            = trim($_POST['street']            ?? '');
    $barangay          = trim($_POST['barangay']          ?? '');
    $town              = trim($_POST['town']              ?? '');
    $province          = trim($_POST['province']          ?? '');
    $birthplace        = trim($_POST['birthplace']        ?? '');
    $contact_number    = trim($_POST['contact_number']    ?? '');
    $email             = trim($_POST['email']             ?? '');
    $occupation        = trim($_POST['occupation']        ?? '');
    $username          = trim($_POST['username']          ?? '');
    $password          = $_POST['password']               ?? '';
    $confirm_password  = $_POST['confirm_password']       ?? '';

    // Build complete address
    $address_parts = array_filter([$permanent_address, $street, $barangay, $town, $province]);
    $address = implode(', ', $address_parts);

    // ── Validation ────────────────────────────────────────────────────────────
    $errors = [];

    if (empty($first_name))        $errors[] = "First name is required";
    if (empty($last_name))         $errors[] = "Last name is required";
    if (empty($date_of_birth))     $errors[] = "Date of birth is required";
    if (empty($gender))            $errors[] = "Gender is required";
    if (empty($civil_status))      $errors[] = "Civil status is required";
    if (empty($permanent_address)) $errors[] = "Permanent address is required";
    if (empty($barangay))          $errors[] = "Barangay is required";
    if (empty($town))              $errors[] = "Town/City is required";
    if (empty($province))          $errors[] = "Province is required";
    if (empty($birthplace))        $errors[] = "Birthplace is required";
    if (empty($contact_number))    $errors[] = "Contact number is required";
    if (empty($email))             $errors[] = "Email address is required";
    if (empty($username))          $errors[] = "Username is required";
    if (empty($password))          $errors[] = "Password is required";

    // Validate ID upload
    if (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "Valid ID photo is required for verification";
    }

    // Validate age (must be at least 18)
    if (!empty($date_of_birth)) {
        $dob = new DateTime($date_of_birth);
        $now = new DateTime();
        if ($now->diff($dob)->y < 18) {
            $errors[] = "You must be at least 18 years old to register";
        }
    }

    // Validate contact number (Philippine format)
    if (!empty($contact_number)) {
        $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
        if (strlen($contact_number) != 11 || substr($contact_number, 0, 2) != '09') {
            $errors[] = "Contact number must be in format 09XXXXXXXXX";
        }
    }

    // Validate email (REQUIRED)
    if (empty($email)) {
        $errors[] = "Email address is required for account verification";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format. Please enter a valid, active email address";
    }

    // Validate username
    if (!empty($username)) {
        if (strlen($username) < 5 || strlen($username) > 20) {
            $errors[] = "Username must be 5-20 characters long";
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores";
        }
    }

    // Validate password
    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        }
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
    }

    // ── Handle ID photo upload ────────────────────────────────────────────────
    $id_photo_filename = null;
    if (empty($errors) && isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types  = ['jpg', 'jpeg', 'png', 'pdf'];
        $file_extension = strtolower(pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($file_extension, $allowed_types)) {
            $errors[] = "ID photo must be JPG, JPEG, PNG, or PDF";
        } elseif ($_FILES['id_photo']['size'] > 5242880) {
            $errors[] = "ID photo must be less than 5MB";
        } else {
            $upload_dir = '../../uploads/ids/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $id_photo_filename = 'id_' . uniqid() . '_' . time() . '.' . $file_extension;
            if (!move_uploaded_file($_FILES['id_photo']['tmp_name'], $upload_dir . $id_photo_filename)) {
                $errors[] = "Failed to upload ID photo";
                $id_photo_filename = null;
            }
        }
    }

    // ── Duplicate checks ──────────────────────────────────────────────────────
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE LOWER(username) = LOWER(?)");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Username already exists";
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Email already registered";
        }
        $stmt->close();
    }

    // ── Insert data ───────────────────────────────────────────────────────────
    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // ── 1. Insert resident ──────────────────────────────────────────
            // Generate a race-condition-safe resident_id using a lock
            $conn->query("LOCK TABLES tbl_residents WRITE");
            $id_res = $conn->query("SELECT COALESCE(MAX(resident_id), 0) + 1 AS next_id FROM tbl_residents");
            if (!$id_res) {
                $conn->query("UNLOCK TABLES");
                throw new Exception("Could not determine next resident_id: " . $conn->error);
            }
            $next_resident_id = (int) $id_res->fetch_assoc()['next_id'];

            // Match actual tbl_residents columns (status defaults to active)
            $status = 'active';
            $sql = "INSERT INTO tbl_residents
                        (resident_id, first_name, middle_name, last_name, ext_name,
                         date_of_birth, birthplace, gender, civil_status,
                         address, permanent_address, street, barangay, town, province,
                         contact_number, phone, email, occupation,
                         id_photo, status, is_verified, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $conn->query("UNLOCK TABLES");
                throw new Exception("Resident prepare failed: " . $conn->error);
            }

            // i=resident_id, then 20 strings (21 total bound values)
            $stmt->bind_param("issssssssssssssssssss",
                $next_resident_id,
                $first_name, $middle_name, $last_name, $ext,
                $date_of_birth, $birthplace, $gender, $civil_status,
                $address, $permanent_address, $street, $barangay, $town, $province,
                $contact_number, $contact_number,
                $email, $occupation,
                $id_photo_filename,
                $status
            );

            if (!$stmt->execute()) {
                $conn->query("UNLOCK TABLES");
                throw new Exception("Error saving resident data: " . $stmt->error);
            }

            $affected    = $stmt->affected_rows;
            $last_errno  = $conn->errno;
            $last_error  = $conn->error;
            $stmt->close();
            $conn->query("UNLOCK TABLES");

            // Use the manually assigned ID (insert_id is 0 when no AUTO_INCREMENT)
            $resident_id = $next_resident_id;

            if ($affected <= 0) {
                // Diagnose which NOT NULL columns might be missing values
                $diag = $conn->query("SHOW COLUMNS FROM tbl_residents WHERE `Null`='NO' AND `Default` IS NULL AND Extra NOT LIKE '%auto_increment%'");
                $required_cols = [];
                if ($diag) {
                    while ($col = $diag->fetch_assoc()) {
                        $required_cols[] = $col['Field'];
                    }
                }
                $col_list = !empty($required_cols) ? implode(', ', $required_cols) : '(none found)';
                throw new Exception(
                    "Resident INSERT failed silently. " .
                    "MySQL errno: [{$last_errno}] {$last_error}. " .
                    "NOT NULL columns without defaults: {$col_list}"
                );
            }

            error_log("✓ Resident created — ID: $resident_id");

            // ── 2. Get Resident role ────────────────────────────────────────
            $role_stmt = $conn->prepare("SELECT role_id FROM tbl_roles WHERE role_name = 'Resident' LIMIT 1");
            if (!$role_stmt) {
                throw new Exception("Role prepare failed: " . $conn->error);
            }
            $role_stmt->execute();
            $role_result = $role_stmt->get_result();
            if ($role_result->num_rows === 0) {
                throw new Exception("Resident role not found in tbl_roles");
            }
            $role_id = (int) $role_result->fetch_assoc()['role_id'];
            $role_stmt->close();

            error_log("✓ Role ID: $role_id");

            // ── 3. Prepare user values ──────────────────────────────────────
            $hashed_password   = $password;
            // FIX: generateVerificationCode() returns a STRING — keep it as string
            $verification_code = generateVerificationCode();
            $code_expires      = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            $role_name         = 'Resident';
            $email_verified    = 0;

            error_log("✓ Verification code generated: $verification_code");

            // ── 4. Insert user ──────────────────────────────────────────────
            // FIX: bind_param types corrected — "ssssiissi"
            //      s=username, s=email, s=hashed_pw, s=role_name,
            //      i=role_id, i=resident_id,
            //      s=verification_code (STRING, not integer),
            //      s=code_expires, i=email_verified
            $sql = "INSERT INTO tbl_users
                        (username, email, password, role, role_id, resident_id,
                         verification_code, verification_code_expires, email_verified,
                         status, account_status, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'Active', 1, NOW())";

            $user_stmt = $conn->prepare($sql);
            if (!$user_stmt) {
                throw new Exception("User prepare failed: " . $conn->error);
            }

            $user_stmt->bind_param("ssssiissi",
                $username,
                $email,
                $hashed_password,
                $role_name,
                $role_id,
                $resident_id,
                $verification_code,
                $code_expires,
                $email_verified
            );

            if (!$user_stmt->execute()) {
                throw new Exception("Error creating user account: " . $user_stmt->error);
            }

            $user_id = (int) $conn->insert_id;
            $user_stmt->close();

            error_log("✓ User created — ID: $user_id, linked to resident_id: $resident_id");

            // ── 5. Send verification email ──────────────────────────────────
            $full_name  = trim("$first_name $middle_name $last_name");
            $email_sent = sendVerificationCodeEmail($email, $full_name, $verification_code);

            if (!$email_sent) {
                error_log("⚠ Verification email failed to send — user can request resend");
            }

            // ── 6. Commit ───────────────────────────────────────────────────
            $conn->commit();
            error_log("✓✓✓ REGISTRATION SUCCESSFUL — User: $user_id, Resident: $resident_id");

            // Redirect directly to email verification — user verifies BEFORE logging in
            header("Location: /barangaylink1/modules/auth/verify-email.php?email=" . urlencode($email));
            exit();

        } catch (Exception $e) {
            $conn->rollback();

            if ($id_photo_filename && file_exists('../../uploads/ids/' . $id_photo_filename)) {
                unlink('../../uploads/ids/' . $id_photo_filename);
            }

            error_log("✗ REGISTRATION FAILED: " . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $error = implode("<br>", $errors);

        if ($id_photo_filename && file_exists('../../uploads/ids/' . $id_photo_filename)) {
            unlink('../../uploads/ids/' . $id_photo_filename);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | Barangay Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            background: #f8fafc;
        }

        .left-side {
            width: 40%;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            color: white; padding: 3rem;
            position: fixed; height: 100vh; left: 0; top: 0;
            overflow: hidden;
        }
        .left-side::before {
            content: '';
            position: absolute; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBackground 20s linear infinite;
        }
        @keyframes moveBackground {
            0%   { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        .logo-container { position: relative; z-index: 1; text-align: center; }
        .logo-img {
            width: 120px; height: 120px; background: white;
            border-radius: 50%; padding: 15px; margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .system-title { font-size: 2rem; font-weight: 700; margin-bottom: 1rem; line-height: 1.3; }
        .system-subtitle { font-size: 1.1rem; opacity: 0.9; max-width: 350px; line-height: 1.6; margin-bottom: 2rem; }

        .verify-steps { width: 100%; max-width: 300px; display: flex; flex-direction: column; gap: 0.75rem; }
        .verify-step {
            display: flex; align-items: center; gap: 0.75rem;
            background: rgba(255,255,255,0.12);
            border-radius: 10px; padding: 0.75rem 1rem; font-size: 0.9rem;
        }
        .step-num {
            width: 26px; height: 26px; background: rgba(255,255,255,0.25);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.8rem; flex-shrink: 0;
        }

        .right-side { margin-left: 40%; width: 60%; padding: 2rem; overflow-y: auto; max-height: 100vh; }
        .register-container {
            max-width: 700px; margin: 0 auto; background: white;
            border-radius: 12px; padding: 2.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .form-header { text-align: center; margin-bottom: 2rem; }
        .form-header h2 { color: #1e3a8a; font-size: 2rem; margin-bottom: 0.5rem; }
        .form-header p  { color: #64748b; font-size: 1rem; }

        .section-title {
            color: #1e3a8a; font-size: 1.1rem; font-weight: 600;
            margin: 2rem 0 1rem; padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            display: flex; align-items: center; gap: 0.5rem;
        }

        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.5rem; }
        .alert-danger  { background: #fee2e2; color: #c62828; border-left: 4px solid #f44336; }
        .alert-success { background: #f0fdf4; color: #15803d;  border-left: 4px solid #10b981; }

        .email-notice {
            background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6;
            border-radius: 8px; padding: 0.85rem 1rem; margin-top: 0.5rem;
            display: flex; gap: 0.6rem; align-items: flex-start;
            font-size: 0.88rem; color: #1e40af;
        }
        .email-notice i { margin-top: 1px; flex-shrink: 0; }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; color: #1e3a8a; font-weight: 600; margin-bottom: 0.5rem; font-size: 0.9rem; }
        .required { color: #ef4444; }

        .form-control, .form-select {
            width: 100%; padding: 0.75rem;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: 0.95rem; transition: all 0.3s; background: white;
        }
        .form-control:focus, .form-select:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        input[name="email"].form-control       { border-color: #93c5fd; }
        input[name="email"].form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }

        .helper-text { font-size: 0.85rem; color: #64748b; margin-top: 0.25rem; }

        .btn-register {
            width: 100%; padding: 1rem;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white; border: none; border-radius: 8px;
            font-size: 1.1rem; font-weight: 600; cursor: pointer;
            transition: all 0.3s; margin-top: 1.5rem;
        }
        .btn-register:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(30,58,138,0.3); }

        .form-links { text-align: center; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
        .form-links a { color: #3b82f6; text-decoration: none; font-weight: 500; }
        .form-links a:hover { text-decoration: underline; }

        @media (max-width: 968px) {
            body { flex-direction: column; }
            .left-side { position: relative; width: 100%; height: auto; min-height: 30vh; padding: 2rem; }
            .verify-steps { display: none; }
            .system-title { font-size: 1.5rem; }
            .right-side { margin-left: 0; width: 100%; padding: 1rem; }
            .register-container { padding: 1.5rem; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Left Side -->
    <div class="left-side">
        <div class="logo-container">
            <img src="/barangaylink1/uploads/officials/brgy.png" alt="Barangay Logo" class="logo-img" onerror="this.style.display='none'">
            <h1 class="system-title">Create Your Account</h1>
            <p class="system-subtitle">Fill in the details to register for BRM System</p>
            <div class="verify-steps">
                <div class="verify-step"><div class="step-num">1</div><span>Fill out the registration form</span></div>
                <div class="verify-step"><div class="step-num">2</div><span>A verification code will be sent to your email</span></div>
                <div class="verify-step"><div class="step-num">3</div><span>Enter the code to activate your account</span></div>
                <div class="verify-step"><div class="step-num">4</div><span>Log in and access the system</span></div>
            </div>
        </div>
    </div>

    <!-- Right Side -->
    <div class="right-side">
        <div class="register-container">
            <div class="form-header">
                <h2><i class="fas fa-user-plus"></i> Resident Registration</h2>
                <p>Join Brgy Centro's digital community</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
            <?php endif; ?>

            <form method="POST" id="registerForm" enctype="multipart/form-data">

                <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="form-control" required
                               placeholder="Enter your first name"
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                               placeholder="Enter your last name"
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               placeholder="Middle name (optional)"
                               value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Extension</label>
                        <input type="text" name="ext" class="form-control"
                               placeholder="Jr., Sr., III (optional)"
                               value="<?php echo htmlspecialchars($_POST['ext'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Date of Birth <span class="required">*</span></label>
                        <input type="date" name="date_of_birth" class="form-control" required
                               max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                               value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                        <small class="helper-text">Must be 18 years or older</small>
                    </div>
                    <div class="form-group">
                        <label>Gender <span class="required">*</span></label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select gender</option>
                            <option value="Male"   <?php echo ($_POST['gender'] ?? '') === 'Male'   ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($_POST['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Civil Status <span class="required">*</span></label>
                        <select name="civil_status" class="form-select" required>
                            <option value="">Select status</option>
                            <option value="Single"    <?php echo ($_POST['civil_status'] ?? '') === 'Single'    ? 'selected' : ''; ?>>Single</option>
                            <option value="Married"   <?php echo ($_POST['civil_status'] ?? '') === 'Married'   ? 'selected' : ''; ?>>Married</option>
                            <option value="Widowed"   <?php echo ($_POST['civil_status'] ?? '') === 'Widowed'   ? 'selected' : ''; ?>>Widowed</option>
                            <option value="Separated" <?php echo ($_POST['civil_status'] ?? '') === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Occupation</label>
                        <input type="text" name="occupation" class="form-control"
                               placeholder="Your occupation"
                               value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>">
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-map-marker-alt"></i> Address Information</div>

                <div class="form-group">
                    <label>Permanent Address <span class="required">*</span></label>
                    <input type="text" name="permanent_address" class="form-control" required
                           placeholder="House No., Street"
                           value="<?php echo htmlspecialchars($_POST['permanent_address'] ?? ''); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Street</label>
                        <input type="text" name="street" class="form-control"
                               placeholder="Street name (optional)"
                               value="<?php echo htmlspecialchars($_POST['street'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Barangay <span class="required">*</span></label>
                        <input type="text" name="barangay" class="form-control" required
                               placeholder="Barangay"
                               value="<?php echo htmlspecialchars($_POST['barangay'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Town/City <span class="required">*</span></label>
                        <input type="text" name="town" class="form-control" required
                               placeholder="Town/City"
                               value="<?php echo htmlspecialchars($_POST['town'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Province <span class="required">*</span></label>
                        <input type="text" name="province" class="form-control" required
                               placeholder="Province"
                               value="<?php echo htmlspecialchars($_POST['province'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Birthplace <span class="required">*</span></label>
                    <input type="text" name="birthplace" class="form-control" required
                           placeholder="City, Province"
                           value="<?php echo htmlspecialchars($_POST['birthplace'] ?? ''); ?>">
                </div>

                <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>

                <div class="form-group">
                    <label>Mobile Number <span class="required">*</span></label>
                    <input type="tel" name="contact_number" class="form-control" required
                           placeholder="09XXXXXXXXX" pattern="[0-9]{11}" maxlength="11"
                           value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>">
                    <small class="helper-text">Format: 09XXXXXXXXX</small>
                </div>

                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required
                           placeholder="your.email@example.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    <div class="email-notice">
                        <i class="fas fa-envelope"></i>
                        <span>
                            <strong>Active email required.</strong> A 6-digit verification code will be sent here after registration.
                            You must verify your email before you can log in.
                        </span>
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-lock"></i> Account Information</div>

                <div class="form-group">
                    <label>Username <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" required
                           minlength="5" maxlength="20" pattern="[a-zA-Z0-9_]+"
                           placeholder="Choose a username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    <small class="helper-text">5-20 characters, letters, numbers, underscores only</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="password" id="password" class="form-control" required
                               minlength="8" placeholder="Enter password">
                        <small class="helper-text">Minimum 8 characters</small>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <input type="password" name="confirm_password" id="confirmPassword"
                               class="form-control" required minlength="8" placeholder="Re-enter password">
                        <small class="helper-text" id="passwordMatch"></small>
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-id-card"></i> ID Verification</div>

                <div class="form-group">
                    <label>Valid ID Photo <span class="required">*</span></label>
                    <input type="file" name="id_photo" class="form-control" accept="image/*,.pdf" required>
                    <small class="helper-text">Upload Driver's License, Passport, National ID, or Voter's ID (Max 5MB)</small>
                </div>

                <button type="submit" class="btn-register">
                    <i class="fas fa-paper-plane"></i> Create Account &amp; Send Verification
                </button>
            </form>

            <div class="form-links">
                <i class="fas fa-arrow-left"></i>
                <a href="/barangaylink1/modules/auth/login.php">Already have an account? Sign in here</a>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const pw  = document.getElementById('password').value;
            const cpw = this.value;
            const el  = document.getElementById('passwordMatch');
            if (cpw.length > 0) {
                if (pw === cpw) {
                    el.innerHTML = '<i class="fas fa-check" style="color:#10b981"></i> Passwords match';
                    el.style.color = '#10b981';
                } else {
                    el.innerHTML = '<i class="fas fa-times" style="color:#ef4444"></i> Passwords do not match';
                    el.style.color = '#ef4444';
                }
            } else {
                el.innerHTML = '';
            }
        });

        document.querySelector('input[name="contact_number"]').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        document.getElementById('registerForm').addEventListener('submit', function(e) {
            if (document.getElementById('password').value !== document.getElementById('confirmPassword').value) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>