<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'Edit Resident';
$errors = [];

// Validate resident ID
$resident_id = 0;
if (isset($_GET['id'])) {
    $resident_id = intval($_GET['id']);
    if ($resident_id <= 0) {
        $_SESSION['error_message'] = 'Invalid resident ID.';
        header('Location: manage.php');
        exit();
    }
} else {
    $_SESSION['error_message'] = 'Resident ID is required.';
    header('Location: manage.php');
    exit();
}

// Fetch resident
$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resident) {
    $_SESSION['error_message'] = 'Resident not found.';
    header('Location: manage.php');
    exit();
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_resident'])) {
    $first_name     = sanitizeInput($_POST['first_name']     ?? '');
    $middle_name    = sanitizeInput($_POST['middle_name']    ?? '');
    $last_name      = sanitizeInput($_POST['last_name']      ?? '');
    $date_of_birth  = sanitizeInput($_POST['date_of_birth']  ?? '');
    $gender         = sanitizeInput($_POST['gender']         ?? '');
    $civil_status   = sanitizeInput($_POST['civil_status']   ?? '');
    $occupation     = sanitizeInput($_POST['occupation']     ?? '');
    $permanent_address = sanitizeInput($_POST['permanent_address'] ?? '');
    $street         = sanitizeInput($_POST['street']         ?? '');
    $barangay       = sanitizeInput($_POST['barangay']       ?? '');
    $town           = sanitizeInput($_POST['town']           ?? '');
    $province       = sanitizeInput($_POST['province']       ?? '');
    $birthplace     = sanitizeInput($_POST['birthplace']     ?? '');
    $contact_number = sanitizeInput($_POST['contact_number'] ?? '');
    $email          = sanitizeInput($_POST['email']          ?? '');
    $is_verified    = isset($_POST['is_verified']) ? 1 : 0;

    $address = implode(', ', array_filter([$permanent_address, $street, $barangay, $town, $province]));

    if (empty($first_name))    $errors[] = "First name is required";
    if (empty($last_name))     $errors[] = "Last name is required";
    if (empty($date_of_birth)) $errors[] = "Date of birth is required";
    if (empty($gender))        $errors[] = "Gender is required";
    if (empty($civil_status))  $errors[] = "Civil status is required";
    if (empty($address))       $errors[] = "Address is required";
    if (empty($email))         $errors[] = "Email address is required";

    if (!empty($contact_number)) {
        $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
        if (strlen($contact_number) != 11 || substr($contact_number, 0, 2) != '09')
            $errors[] = "Contact number must be in format 09XXXXXXXXX";
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = "Invalid email format. Please enter a valid, active email address";

    // Check if email is taken by a DIFFERENT resident
    if (empty($errors) && !empty($email)) {
        $chk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE email = ? AND resident_id != ?");
        $chk->bind_param("si", $email, $resident_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0)
            $errors[] = "Email address is already registered to another resident";
        $chk->close();
    }

    if (empty($errors)) {
        $sql = "UPDATE tbl_residents SET
                first_name=?, middle_name=?, last_name=?, date_of_birth=?, gender=?, civil_status=?,
                occupation=?, address=?, permanent_address=?, street=?, barangay=?, town=?, province=?,
                birthplace=?, contact_number=?, email=?, is_verified=?, updated_at=NOW()
                WHERE resident_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssssssssssii",
            $first_name, $middle_name, $last_name, $date_of_birth, $gender, $civil_status,
            $occupation, $address, $permanent_address, $street, $barangay, $town, $province,
            $birthplace, $contact_number, $email, $is_verified, $resident_id
        );
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Resident updated successfully!';
            header('Location: view.php?id=' . $resident_id);
            exit();
        } else {
            $errors[] = 'Failed to update resident. Please try again.';
        }
        $stmt->close();
    }

    // Repopulate $resident with submitted values so form retains input on error
    $resident = array_merge($resident, $_POST);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Resident | Barangay Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            background: #f8fafc;
        }

        /* Left panel */
        .left-side {
            width: 40%;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 3rem;
            position: fixed;
            height: 100vh;
            left: 0; top: 0;
            overflow: hidden;
        }
        .left-side::before {
            content: '';
            position: absolute;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBg 20s linear infinite;
        }
        @keyframes moveBg {
            0%   { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        .logo-container { position: relative; z-index: 1; text-align: center; }
        .logo-img {
            width: 110px; height: 110px;
            background: white; border-radius: 50%; padding: 14px;
            margin-bottom: 1.75rem;
            box-shadow: 0 10px 30px rgba(0,0,0,.2);
        }
        .panel-title    { font-size: 1.9rem; font-weight: 700; margin-bottom: .75rem; line-height: 1.3; }
        .panel-subtitle { font-size: 1.05rem; opacity: .9; max-width: 320px; line-height: 1.6; }
        .resident-badge {
            margin-top: 2rem;
            background: rgba(255,255,255,.15);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            width: 100%; max-width: 300px;
            text-align: left;
        }
        .resident-badge .label {
            font-size: .75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .6px; opacity: .75; margin-bottom: .4rem;
        }
        .resident-badge .name {
            font-size: 1.2rem; font-weight: 700; line-height: 1.3;
        }
        .resident-badge .meta {
            margin-top: .5rem; font-size: .83rem; opacity: .8;
            display: flex; flex-direction: column; gap: .25rem;
        }
        .panel-meta {
            margin-top: 1.25rem;
            display: flex; flex-direction: column; gap: .65rem;
            width: 100%; max-width: 300px;
        }
        .panel-meta-item {
            display: flex; align-items: center; gap: .75rem;
            background: rgba(255,255,255,.12);
            border-radius: 10px; padding: .65rem 1rem;
            font-size: .88rem;
        }
        .panel-meta-item i { font-size: 1rem; opacity: .85; }

        /* Right panel */
        .right-side {
            margin-left: 40%;
            width: 60%;
            padding: 2rem;
            overflow-y: auto;
            max-height: 100vh;
        }
        .form-container {
            max-width: 720px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,.05);
        }
        .form-header { text-align: center; margin-bottom: 2rem; }
        .form-header h2 { color: #1e3a8a; font-size: 1.9rem; margin-bottom: .4rem; }
        .form-header p  { color: #64748b; font-size: .97rem; }

        .section-title {
            color: #1e3a8a; font-size: 1rem; font-weight: 600;
            margin: 2rem 0 1rem;
            padding-bottom: .5rem;
            border-bottom: 2px solid #e2e8f0;
            display: flex; align-items: center; gap: .5rem;
        }

        .alert {
            padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;
            display: flex; align-items: flex-start; gap: .5rem;
        }
        .alert-danger { background: #fee2e2; color: #c62828; border-left: 4px solid #f44336; }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block; color: #1e3a8a; font-weight: 600;
            margin-bottom: .5rem; font-size: .88rem;
        }
        .required { color: #ef4444; }
        .form-control, .form-select {
            width: 100%; padding: .72rem .9rem;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: .93rem; transition: all .3s; background: white;
        }
        .form-control:focus, .form-select:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.1);
        }

        /* Highlight email field */
        input[name="email"].form-control {
            border-color: #93c5fd;
        }
        input[name="email"].form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }

        .helper-text { font-size: .82rem; color: #64748b; margin-top: .25rem; }

        /* Email verification notice */
        .email-notice {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            padding: .75rem 1rem;
            margin-top: .5rem;
            display: flex;
            gap: .6rem;
            align-items: flex-start;
            font-size: .83rem;
            color: #1e40af;
        }
        .email-notice i { margin-top: 1px; flex-shrink: 0; }

        .form-check { display: flex; align-items: center; gap: .6rem; margin-top: .4rem; }
        .form-check-input { width: 18px; height: 18px; cursor: pointer; accent-color: #3b82f6; }
        .form-check-label { font-size: .93rem; color: #334155; cursor: pointer; }

        .btn-row { display: flex; gap: 1rem; margin-top: 1.5rem; }
        .btn-submit {
            flex: 1; padding: .95rem;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white; border: none; border-radius: 8px;
            font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: all .3s;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(30,58,138,.3); }
        .btn-cancel {
            flex: 1; padding: .95rem;
            background: white; color: #64748b;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: all .3s;
            text-decoration: none; display: flex; align-items: center; justify-content: center; gap: .5rem;
        }
        .btn-cancel:hover { border-color: #cbd5e1; background: #f8fafc; transform: translateY(-2px); }

        .form-links {
            text-align: center; margin-top: 1.5rem;
            padding-top: 1.5rem; border-top: 1px solid #e2e8f0;
        }
        .form-links a { color: #3b82f6; text-decoration: none; font-weight: 500; }
        .form-links a:hover { text-decoration: underline; }

        @media (max-width: 968px) {
            body { flex-direction: column; }
            .left-side { position: relative; width: 100%; height: auto; min-height: 28vh; padding: 2rem; }
            .panel-meta, .resident-badge { display: none; }
            .right-side { margin-left: 0; width: 100%; padding: 1rem; }
            .form-container { padding: 1.5rem; }
            .form-row { grid-template-columns: 1fr; }
            .btn-row { flex-direction: column; }
        }
    </style>
</head>
<body>

    <!-- Left panel -->
    <div class="left-side">
        <div class="logo-container">
            <img src="/barangaylink1/uploads/officials/brgy.png" alt="Logo" class="logo-img" onerror="this.style.display='none'">
            <h1 class="panel-title">Edit Resident</h1>
            <p class="panel-subtitle">Update resident information in the Barangay Management System</p>

            <div class="resident-badge">
                <div class="label">Editing record</div>
                <div class="name">
                    <?php echo htmlspecialchars(trim($resident['first_name'] . ' ' . $resident['last_name'])); ?>
                </div>
                <div class="meta">
                    <?php if (!empty($resident['gender'])): ?>
                    <span><i class="fas fa-venus-mars" style="width:14px"></i> <?php echo htmlspecialchars($resident['gender']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($resident['contact_number'])): ?>
                    <span><i class="fas fa-phone" style="width:14px"></i> <?php echo htmlspecialchars($resident['contact_number']); ?></span>
                    <?php endif; ?>
                    <span>
                        <i class="fas fa-shield-alt" style="width:14px"></i>
                        <?php echo $resident['is_verified'] ? 'Verified' : 'Unverified'; ?>
                    </span>
                </div>
            </div>

            <div class="panel-meta">
                <div class="panel-meta-item"><i class="fas fa-history"></i> Changes are saved immediately</div>
                <div class="panel-meta-item"><i class="fas fa-envelope"></i> Email required for verification</div>
            </div>
        </div>
    </div>

    <!-- Right panel -->
    <div class="right-side">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-user-edit"></i> Edit Resident</h2>
                <p>Update the information for <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong></p>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
            </div>
            <?php endif; ?>

            <form method="POST" id="editResidentForm">

                <!-- Personal Information -->
                <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="form-control" required
                               placeholder="Enter first name"
                               value="<?php echo htmlspecialchars($resident['first_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                               placeholder="Enter last name"
                               value="<?php echo htmlspecialchars($resident['last_name'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               placeholder="Middle name (optional)"
                               value="<?php echo htmlspecialchars($resident['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Extension</label>
                        <input type="text" name="ext_name" class="form-control"
                               placeholder="Jr., Sr., III (optional)"
                               value="<?php echo htmlspecialchars($resident['ext_name'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Date of Birth <span class="required">*</span></label>
                        <input type="date" name="date_of_birth" class="form-control" required
                               max="<?php echo date('Y-m-d'); ?>"
                               value="<?php echo htmlspecialchars($resident['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Gender <span class="required">*</span></label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select gender</option>
                            <option value="Male"   <?php echo ($resident['gender'] ?? '') === 'Male'   ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($resident['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Civil Status <span class="required">*</span></label>
                        <select name="civil_status" class="form-select" required>
                            <option value="">Select status</option>
                            <option value="Single"    <?php echo ($resident['civil_status'] ?? '') === 'Single'    ? 'selected' : ''; ?>>Single</option>
                            <option value="Married"   <?php echo ($resident['civil_status'] ?? '') === 'Married'   ? 'selected' : ''; ?>>Married</option>
                            <option value="Widowed"   <?php echo ($resident['civil_status'] ?? '') === 'Widowed'   ? 'selected' : ''; ?>>Widowed</option>
                            <option value="Separated" <?php echo ($resident['civil_status'] ?? '') === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                            <option value="Divorced"  <?php echo ($resident['civil_status'] ?? '') === 'Divorced'  ? 'selected' : ''; ?>>Divorced</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Occupation</label>
                        <input type="text" name="occupation" class="form-control"
                               placeholder="Current occupation"
                               value="<?php echo htmlspecialchars($resident['occupation'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Address Information -->
                <div class="section-title"><i class="fas fa-map-marker-alt"></i> Address Information</div>

                <div class="form-group">
                    <label>Permanent Address <span class="required">*</span></label>
                    <input type="text" name="permanent_address" class="form-control" required
                           placeholder="House No., Street"
                           value="<?php echo htmlspecialchars($resident['permanent_address'] ?? $resident['address'] ?? ''); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Street</label>
                        <input type="text" name="street" class="form-control"
                               placeholder="Street name (optional)"
                               value="<?php echo htmlspecialchars($resident['street'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Barangay</label>
                        <input type="text" name="barangay" class="form-control"
                               placeholder="Barangay"
                               value="<?php echo htmlspecialchars($resident['barangay'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Town/City</label>
                        <input type="text" name="town" class="form-control"
                               placeholder="Town/City"
                               value="<?php echo htmlspecialchars($resident['town'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Province</label>
                        <input type="text" name="province" class="form-control"
                               placeholder="Province"
                               value="<?php echo htmlspecialchars($resident['province'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Birthplace</label>
                    <input type="text" name="birthplace" class="form-control"
                           placeholder="City, Province"
                           value="<?php echo htmlspecialchars($resident['birthplace'] ?? ''); ?>">
                </div>

                <!-- Contact Information -->
                <div class="section-title"><i class="fas fa-phone"></i> Contact Information</div>

                <div class="form-group">
                    <label>Mobile Number</label>
                    <input type="tel" name="contact_number" class="form-control"
                           placeholder="09XXXXXXXXX" maxlength="11"
                           value="<?php echo htmlspecialchars($resident['contact_number'] ?? ''); ?>">
                    <small class="helper-text">Format: 09XXXXXXXXX</small>
                </div>

                <!-- Email — required, full width with notice -->
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required
                           placeholder="email@example.com"
                           value="<?php echo htmlspecialchars($resident['email'] ?? ''); ?>">
                    <div class="email-notice">
                        <i class="fas fa-envelope"></i>
                        <span>
                            <strong>Active email required.</strong> This is used for the resident's account verification.
                            Changing it may affect the resident's ability to log in until re-verified.
                        </span>
                    </div>
                </div>

                <!-- Verification -->
                <div class="section-title"><i class="fas fa-shield-alt"></i> Verification Status</div>

                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" name="is_verified" class="form-check-input" id="is_verified"
                               <?php echo $resident['is_verified'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_verified">
                            <strong>Mark as Verified Resident</strong>
                        </label>
                    </div>
                    <small class="helper-text">Verified residents can request documents and access full system features</small>
                </div>

                <div class="btn-row">
                    <a href="view.php?id=<?php echo $resident_id; ?>" class="btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" name="update_resident" class="btn-submit">
                        <i class="fas fa-save"></i> Update Resident
                    </button>
                </div>
            </form>

            <div class="form-links">
                <i class="fas fa-arrow-left"></i>
                <a href="manage.php"> Back to Residents List</a>
            </div>
        </div>
    </div>

    <script>
        document.querySelector('input[name="contact_number"]').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Warn on unsaved changes
        let formChanged = false;
        document.querySelectorAll('#editResidentForm input, #editResidentForm select').forEach(el => {
            el.addEventListener('change', () => formChanged = true);
        });
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) { e.preventDefault(); e.returnValue = ''; }
        });
        document.getElementById('editResidentForm').addEventListener('submit', () => formChanged = false);
        document.querySelector('.btn-cancel').addEventListener('click', () => formChanged = false);
    </script>
</body>
</html>
<?php $conn->close(); ?>