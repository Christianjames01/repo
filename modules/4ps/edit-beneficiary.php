<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();
$page_title = 'Edit 4Ps Beneficiary';
$success_message = '';
$error_message = '';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: beneficiaries.php?error=' . urlencode('Beneficiary ID is required'));
    exit();
}

$beneficiary_id = intval($_GET['id']);

$query = "SELECT b.*, e.*
FROM tbl_4ps_beneficiaries b
LEFT JOIN tbl_4ps_extended_details e ON b.beneficiary_id = e.beneficiary_id
WHERE b.beneficiary_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $beneficiary_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: beneficiaries.php?error=' . urlencode('Beneficiary not found'));
    exit();
}

$beneficiary = $result->fetch_assoc();
$stmt->close();

$has_extended_details = !empty($beneficiary['detail_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name         = trim($_POST['last_name']);
    $first_name        = trim($_POST['first_name']);
    $middle_name       = trim($_POST['middle_name']);
    $ext               = trim($_POST['ext']);
    $permanent_address = trim($_POST['permanent_address']);
    $street            = trim($_POST['street']);
    $brgy              = trim($_POST['brgy']);
    $town              = trim($_POST['town']);
    $province          = trim($_POST['province']);
    $birthplace        = trim($_POST['birthplace']);
    $mobile_phone      = trim($_POST['mobile_phone']);
    $birthday          = $_POST['birthday'];
    $civil_status      = $_POST['civil_status'];
    $gender            = $_POST['gender'];
    $father_full_name  = trim($_POST['father_full_name']);
    $father_address    = trim($_POST['father_address']);
    $father_education  = $_POST['father_education'];
    $father_income     = !empty($_POST['father_income']) ? floatval($_POST['father_income']) : 0.0;
    $mother_full_name  = trim($_POST['mother_full_name']);
    $mother_address    = trim($_POST['mother_address']);
    $mother_education  = $_POST['mother_education'];
    $mother_income     = !empty($_POST['mother_income']) ? floatval($_POST['mother_income']) : 0.0;
    $secondary_school  = trim($_POST['secondary_school']);
    $degree_program    = trim($_POST['degree_program']);
    $year_level        = $_POST['year_level'];
    $reference_1       = trim($_POST['reference_1']);
    $reference_2       = trim($_POST['reference_2']);
    $reference_3       = trim($_POST['reference_3']);
    $household_id      = trim($_POST['household_id']);
    $grantee_name      = trim($_POST['grantee_name']);
    $date_registered   = $_POST['date_registered'];
    $status            = $_POST['status'];
    $set_number        = trim($_POST['set_number']);
    $compliance_status = $_POST['compliance_status'];
    $monthly_grant     = floatval($_POST['monthly_grant']);
    $remarks           = trim($_POST['remarks']);

    $photo_filename = $beneficiary['id_picture'];
    if (isset($_FILES['id_picture']) && $_FILES['id_picture']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/4ps/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_extension = pathinfo($_FILES['id_picture']['name'], PATHINFO_EXTENSION);
        $new_photo_filename = 'applicant_' . time() . '_' . uniqid() . '.' . $file_extension;
        if (move_uploaded_file($_FILES['id_picture']['tmp_name'], $upload_dir . $new_photo_filename)) {
            if (!empty($photo_filename) && file_exists($upload_dir . $photo_filename)) {
                unlink($upload_dir . $photo_filename);
            }
            $photo_filename = $new_photo_filename;
        } else {
            $error_message = "Error uploading photo file.";
        }
    }

    $conn->begin_transaction();
    try {
        $update_main = "UPDATE tbl_4ps_beneficiaries SET household_id=?, grantee_name=?, date_registered=?, status=?, set_number=?, compliance_status=?, monthly_grant=?, remarks=?, updated_at=NOW() WHERE beneficiary_id=?";
        $stmt_main = $conn->prepare($update_main);
        $stmt_main->bind_param("ssssssdsi", $household_id, $grantee_name, $date_registered, $status, $set_number, $compliance_status, $monthly_grant, $remarks, $beneficiary_id);
        if (!$stmt_main->execute()) throw new Exception("Error updating beneficiary: " . $stmt_main->error);
        $stmt_main->close();

        $ctrl_no = !empty($beneficiary['ctrl_no']) ? $beneficiary['ctrl_no'] : 'CTRL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        if ($has_extended_details) {
            $update_ext = "UPDATE tbl_4ps_extended_details SET last_name=?, first_name=?, middle_name=?, ext_name=?, permanent_address=?, street=?, barangay=?, town=?, province=?, birthplace=?, mobile_phone=?, birthday=?, civil_status=?, gender=?, father_full_name=?, father_address=?, father_education=?, father_income=?, mother_full_name=?, mother_address=?, mother_education=?, mother_income=?, secondary_school=?, degree_program=?, year_level=?, reference_1=?, reference_2=?, reference_3=?, id_picture=?, ctrl_no=? WHERE beneficiary_id=?";
            $stmt_ext = $conn->prepare($update_ext);
            $stmt_ext->bind_param("sssssssssssssssssdsssdssssssssi", $last_name, $first_name, $middle_name, $ext, $permanent_address, $street, $brgy, $town, $province, $birthplace, $mobile_phone, $birthday, $civil_status, $gender, $father_full_name, $father_address, $father_education, $father_income, $mother_full_name, $mother_address, $mother_education, $mother_income, $secondary_school, $degree_program, $year_level, $reference_1, $reference_2, $reference_3, $photo_filename, $ctrl_no, $beneficiary_id);
            if (!$stmt_ext->execute()) throw new Exception("Error updating extended details: " . $stmt_ext->error);
            $stmt_ext->close();
        } else {
            $insert_ext = "INSERT INTO tbl_4ps_extended_details (beneficiary_id, last_name, first_name, middle_name, ext_name, permanent_address, street, barangay, town, province, birthplace, mobile_phone, birthday, civil_status, gender, father_full_name, father_address, father_education, father_income, mother_full_name, mother_address, mother_education, mother_income, secondary_school, degree_program, year_level, reference_1, reference_2, reference_3, id_picture, ctrl_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_ext = $conn->prepare($insert_ext);
            $stmt_ext->bind_param("issssssssssssssssdsssdsssssssss", $beneficiary_id, $last_name, $first_name, $middle_name, $ext, $permanent_address, $street, $brgy, $town, $province, $birthplace, $mobile_phone, $birthday, $civil_status, $gender, $father_full_name, $father_address, $father_education, $father_income, $mother_full_name, $mother_address, $mother_education, $mother_income, $secondary_school, $degree_program, $year_level, $reference_1, $reference_2, $reference_3, $photo_filename, $ctrl_no);
            if (!$stmt_ext->execute()) throw new Exception("Error inserting extended details: " . $stmt_ext->error);
            $stmt_ext->close();
        }

        $conn->commit();
       header("Location: " . BASE_URL . "/modules/4ps/beneficiaries-debug.php?success=" . urlencode("Beneficiary updated successfully!"));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Update failed: " . $e->getMessage();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- PAGE HERO -->
<div class="bps-hero">
    <div class="bps-hero__ring bps-hero__ring--1"></div>
    <div class="bps-hero__ring bps-hero__ring--2"></div>
    <div class="bps-hero__ring bps-hero__ring--3"></div>
    <div class="bps-hero__inner">
        <div class="bps-hero__left">
            <div class="bps-hero__icon" style="background:linear-gradient(135deg,#f59e0b,#b45309);">
                <i class="fas fa-user-edit"></i>
            </div>
            <div>
                <h1 class="bps-hero__title">Edit 4Ps Beneficiary</h1>
                <p class="bps-hero__sub">
                    Pantawid Pamilyang Pilipino Program &mdash;
                    <?php if (!empty($beneficiary['ctrl_no'])): ?>
                        Control No: <strong style="color:#fbbf24"><?php echo htmlspecialchars($beneficiary['ctrl_no']); ?></strong>
                    <?php else: ?>
                        Update beneficiary record
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <a href="beneficiaries-debug.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<div class="container-fluid">

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
    <?php if (!$has_extended_details): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Missing extended details.</strong> Please complete the personal information fields below.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="editBeneficiaryForm" enctype="multipart/form-data">

        <div class="form-masonry">

            <!-- Personal Information -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-user" style="opacity:.7"></i> Personal Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Last Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="last_name"
                                   value="<?php echo htmlspecialchars($beneficiary['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="first_name"
                                   value="<?php echo htmlspecialchars($beneficiary['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name"
                                   value="<?php echo htmlspecialchars($beneficiary['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ext. (Jr., Sr., III)</label>
                            <input type="text" class="form-control" name="ext"
                                   value="<?php echo htmlspecialchars($beneficiary['ext_name'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Permanent Address <span class="req">*</span></label>
                            <input type="text" class="form-control" name="permanent_address"
                                   value="<?php echo htmlspecialchars($beneficiary['permanent_address'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Street</label>
                            <input type="text" class="form-control" name="street"
                                   value="<?php echo htmlspecialchars($beneficiary['street'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Barangay <span class="req">*</span></label>
                            <input type="text" class="form-control" name="brgy"
                                   value="<?php echo htmlspecialchars($beneficiary['barangay'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Town/City <span class="req">*</span></label>
                            <input type="text" class="form-control" name="town"
                                   value="<?php echo htmlspecialchars($beneficiary['town'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Province <span class="req">*</span></label>
                            <input type="text" class="form-control" name="province"
                                   value="<?php echo htmlspecialchars($beneficiary['province'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Birthplace <span class="req">*</span></label>
                            <input type="text" class="form-control" name="birthplace"
                                   value="<?php echo htmlspecialchars($beneficiary['birthplace'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile/Phone No. <span class="req">*</span></label>
                            <input type="text" class="form-control" name="mobile_phone"
                                   value="<?php echo htmlspecialchars($beneficiary['mobile_phone'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Birthday <span class="req">*</span></label>
                            <input type="date" class="form-control" name="birthday"
                                   value="<?php echo htmlspecialchars($beneficiary['birthday'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender <span class="req">*</span></label>
                            <select class="form-select" name="gender" required>
                                <option value="">&#8212; Select &#8212;</option>
                                <option value="Male"   <?php echo ($beneficiary['gender'] ?? '') == 'Male'   ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($beneficiary['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Civil Status <span class="req">*</span></label>
                            <select class="form-select" name="civil_status" required>
                                <option value="">&#8212; Select &#8212;</option>
                                <option value="Single"    <?php echo ($beneficiary['civil_status'] ?? '') == 'Single'    ? 'selected' : ''; ?>>Single</option>
                                <option value="Married"   <?php echo ($beneficiary['civil_status'] ?? '') == 'Married'   ? 'selected' : ''; ?>>Married</option>
                                <option value="Widowed"   <?php echo ($beneficiary['civil_status'] ?? '') == 'Widowed'   ? 'selected' : ''; ?>>Widowed</option>
                                <option value="Separated" <?php echo ($beneficiary['civil_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Father -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-male" style="opacity:.7"></i> Family Background &#8212; Father</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Father's Full Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="father_full_name"
                                   value="<?php echo htmlspecialchars($beneficiary['father_full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address <span class="req">*</span></label>
                            <input type="text" class="form-control" name="father_address"
                                   value="<?php echo htmlspecialchars($beneficiary['father_address'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Educational Attainment</label>
                            <select class="form-select" name="father_education">
                                <option value="">&#8212; Select &#8212;</option>
                                <?php foreach (['Elementary','High School','College','Vocational','Post Graduate'] as $edu): ?>
                                <option value="<?php echo $edu; ?>" <?php echo ($beneficiary['father_education'] ?? '') == $edu ? 'selected' : ''; ?>><?php echo $edu; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Monthly Income (&#8369;)</label>
                            <input type="number" class="form-control" name="father_income" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($beneficiary['father_income'] ?? '0'); ?>" placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mother -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-female" style="opacity:.7"></i> Family Background &#8212; Mother</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Mother's Full Maiden Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="mother_full_name"
                                   value="<?php echo htmlspecialchars($beneficiary['mother_full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address <span class="req">*</span></label>
                            <input type="text" class="form-control" name="mother_address"
                                   value="<?php echo htmlspecialchars($beneficiary['mother_address'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Educational Attainment</label>
                            <select class="form-select" name="mother_education">
                                <option value="">&#8212; Select &#8212;</option>
                                <?php foreach (['Elementary','High School','College','Vocational','Post Graduate'] as $edu): ?>
                                <option value="<?php echo $edu; ?>" <?php echo ($beneficiary['mother_education'] ?? '') == $edu ? 'selected' : ''; ?>><?php echo $edu; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Monthly Income (&#8369;)</label>
                            <input type="number" class="form-control" name="mother_income" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($beneficiary['mother_income'] ?? '0'); ?>" placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Academic Information -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-graduation-cap" style="opacity:.7"></i> Academic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Secondary School Address <span class="req">*</span></label>
                            <input type="text" class="form-control" name="secondary_school"
                                   value="<?php echo htmlspecialchars($beneficiary['secondary_school'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Degree Program / Course <span class="req">*</span></label>
                            <input type="text" class="form-control" name="degree_program"
                                   value="<?php echo htmlspecialchars($beneficiary['degree_program'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year Level <span class="req">*</span></label>
                            <select class="form-select" name="year_level" required>
                                <option value="">&#8212; Select &#8212;</option>
                                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year','5th Year'] as $yr): ?>
                                <option value="<?php echo $yr; ?>" <?php echo ($beneficiary['year_level'] ?? '') == $yr ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4Ps Program Details -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-hands-helping" style="opacity:.7"></i> 4Ps Program Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Household ID <span class="req">*</span></label>
                            <input type="text" class="form-control" name="household_id"
                                   value="<?php echo htmlspecialchars($beneficiary['household_id'] ?? ''); ?>" required placeholder="e.g. HH-2024-001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Grantee Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="grantee_name"
                                   value="<?php echo htmlspecialchars($beneficiary['grantee_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date Registered <span class="req">*</span></label>
                            <input type="date" class="form-control" name="date_registered"
                                   value="<?php echo htmlspecialchars($beneficiary['date_registered'] ?? ''); ?>"
                                   required max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Set Number</label>
                            <input type="text" class="form-control" name="set_number"
                                   value="<?php echo htmlspecialchars($beneficiary['set_number'] ?? ''); ?>" placeholder="e.g. SET-01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status <span class="req">*</span></label>
                            <select class="form-select" name="status" required>
                                <option value="">&#8212; Select &#8212;</option>
                                <?php foreach (['Active','Inactive','Suspended','Graduated'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo ($beneficiary['status'] ?? '') == $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Compliance Status <span class="req">*</span></label>
                            <select class="form-select" name="compliance_status" required>
                                <option value="">&#8212; Select &#8212;</option>
                                <?php foreach (['Compliant','Non-Compliant','Partial'] as $cs): ?>
                                <option value="<?php echo $cs; ?>" <?php echo ($beneficiary['compliance_status'] ?? '') == $cs ? 'selected' : ''; ?>><?php echo $cs; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Monthly Grant (&#8369;) <span class="req">*</span></label>
                            <input type="number" class="form-control" name="monthly_grant"
                                   value="<?php echo htmlspecialchars($beneficiary['monthly_grant'] ?? '0'); ?>"
                                   required step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3"><?php echo htmlspecialchars($beneficiary['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal References -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-address-book" style="opacity:.7"></i> Personal References</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">1. Name &amp; Contact</label>
                            <input type="text" class="form-control" name="reference_1"
                                   value="<?php echo htmlspecialchars($beneficiary['reference_1'] ?? ''); ?>"
                                   placeholder="Full Name, Contact Number">
                        </div>
                        <div class="col-12">
                            <label class="form-label">2. Name &amp; Contact</label>
                            <input type="text" class="form-control" name="reference_2"
                                   value="<?php echo htmlspecialchars($beneficiary['reference_2'] ?? ''); ?>"
                                   placeholder="Full Name, Contact Number">
                        </div>
                        <div class="col-12">
                            <label class="form-label">3. Name &amp; Contact</label>
                            <input type="text" class="form-control" name="reference_3"
                                   value="<?php echo htmlspecialchars($beneficiary['reference_3'] ?? ''); ?>"
                                   placeholder="Full Name, Contact Number">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ID Picture -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-camera" style="opacity:.7"></i> ID Picture (2&#215;2)</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Upload New Photo <span class="text-muted fw-normal">(optional &mdash; replaces current)</span></label>
                            <input type="file" class="form-control" name="id_picture" accept="image/*" id="photoInput">
                            <small class="text-muted d-block mt-1">
                                <i class="fas fa-info-circle"></i> JPG, PNG, GIF &middot; Max 5MB
                            </small>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">
                                <?php echo !empty($beneficiary['id_picture']) ? 'Current Photo' : 'Preview'; ?>
                            </label>
                            <div class="photo-preview-box" id="imagePreview">
                                <?php if (!empty($beneficiary['id_picture'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/4ps/<?php echo htmlspecialchars($beneficiary['id_picture']); ?>"
                                         alt="Current Photo" id="currentPhoto"
                                         onerror="this.parentNode.innerHTML='<i class=\'fas fa-user\'></i><p>No photo</p>'">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                    <p>No photo uploaded</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Control Number (read-only display) -->
            <?php if (!empty($beneficiary['ctrl_no'])): ?>
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-barcode" style="opacity:.7"></i> Control Number</h5>
                </div>
                <div class="card-body">
                    <div class="ctrl-badge">
                        <i class="fas fa-hashtag"></i>
                        <?php echo htmlspecialchars($beneficiary['ctrl_no']); ?>
                    </div>
                    <small class="text-muted">Control numbers are assigned at registration and cannot be changed.</small>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /form-masonry -->

        <!-- Save Actions -->
        <div class="card shadow mb-5">
            <div class="card-body">
                <div class="save-note mb-4">
                    <i class="fas fa-info-circle me-2" style="color:var(--db-sky)"></i>
                    All changes will be saved immediately upon clicking <strong>Save Changes</strong>.
                    Fields marked <span class="req">*</span> are required.
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <a href="beneficiaries.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>

    </form>
</div><!-- /container-fluid -->

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root {
    --db-navy:#0d1b36; --db-navy-mid:#152849; --db-navy-light:#1c3461;
    --db-amber:#f59e0b; --db-amber-light:#fef3c7; --db-amber-dark:#b45309;
    --db-teal:#0d9488; --db-teal-light:#ccfbf1;
    --db-rose:#e11d48; --db-sky:#0ea5e9; --db-sky-light:#e0f2fe;
    --db-indigo:#6366f1; --db-indigo-light:#e0e7ff;
    --db-success:#10b981; --db-success-light:#d1fae5;
    --db-warning:#f59e0b; --db-warning-light:#fef3c7;
    --db-danger:#ef4444; --db-danger-light:#fee2e2;
    --db-bg:#eef2f7; --db-surf:#ffffff; --db-surf2:#f8fafc;
    --db-border:#e2e8f0; --db-text:#0f172a; --db-muted:#64748b;
    --db-radius:14px; --db-radius-sm:8px; --db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;line-height:1.6}

/* ── Hero ── */
.bps-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden}
.bps-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none}
.bps-hero__ring--1{width:320px;height:320px;top:-140px;right:-80px}
.bps-hero__ring--2{width:200px;height:200px;top:-60px;right:60px;border-color:rgba(245,158,11,.12)}
.bps-hero__ring--3{width:120px;height:120px;bottom:-50px;left:35%;border-color:rgba(13,148,136,.14)}
.bps-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
.bps-hero__left{display:flex;align-items:center;gap:18px}
.bps-hero__icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.bps-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.4px;margin-bottom:2px}
.bps-hero__sub{font-size:13px;color:rgba(255,255,255,.55);margin:0}

/* ── Container ── */
.container-fluid{padding:0 24px 40px;max-width:1400px;margin:0 auto}

/* ── Alerts ── */
.alert{border-radius:var(--db-radius);border:none;border-left:4px solid;font-family:'Sora',sans-serif;font-size:13.5px;font-weight:500;padding:14px 18px;margin-bottom:16px;animation:dbFadeUp .3s ease both}
.alert-success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success)}
.alert-danger{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger)}
.alert-warning{background:var(--db-warning-light);color:#92400e;border-color:var(--db-warning)}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ── Cards ── */
.card{background:var(--db-surf);border-radius:var(--db-radius-lg) !important;border:1px solid var(--db-border) !important;box-shadow:var(--db-shadow);overflow:hidden;animation:dbFadeUp .35s ease both}
.card-header{padding:18px 22px !important;border-bottom:1px solid var(--db-border) !important;background:var(--db-surf) !important;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.card-header h5{font-size:15px;font-weight:700;color:var(--db-text);margin:0;display:flex;align-items:center;gap:8px}
.card-header h5::before{content:'';display:inline-block;width:4px;height:18px;background:linear-gradient(to bottom,var(--db-amber),var(--db-amber-dark));border-radius:2px}
.card-body{padding:20px 22px !important}

/* ── Form elements ── */
.form-label{font-size:12px;font-weight:600;color:var(--db-text);margin-bottom:5px;font-family:'Sora',sans-serif}
.form-control,.form-select{border:1.5px solid var(--db-border) !important;border-radius:var(--db-radius-sm) !important;font-family:'Sora',sans-serif !important;font-size:13px !important;color:var(--db-text) !important;background:var(--db-surf) !important;padding:9px 13px !important;transition:all .18s !important;box-shadow:none !important}
.form-control:focus,.form-select:focus{border-color:var(--db-amber) !important;box-shadow:0 0 0 3px rgba(245,158,11,.12) !important}
.form-control::placeholder{color:#94a3b8 !important}
textarea.form-control{resize:vertical;min-height:88px}

/* ── Buttons ── */
.btn{font-family:'Sora',sans-serif !important;font-weight:600 !important;border-radius:var(--db-radius-sm) !important;font-size:13px !important;transition:all .18s ease !important;display:inline-flex !important;align-items:center !important;gap:6px !important}
.btn-secondary{background:var(--db-surf2) !important;border-color:var(--db-border) !important;color:var(--db-text) !important}
.btn-secondary:hover{background:var(--db-border) !important;color:var(--db-text) !important}
.btn-primary{background:linear-gradient(135deg,var(--db-amber),var(--db-amber-dark)) !important;border-color:transparent !important;color:#fff !important}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(245,158,11,.35) !important;color:#fff !important}
.btn-lg{padding:11px 24px !important;font-size:14px !important}

.req{color:var(--db-rose)}
.text-muted{color:var(--db-muted) !important;font-size:11.5px}

/* ── Masonry 2-column ── */
.form-masonry{column-count:2;column-gap:18px}
.form-masonry>.card{break-inside:avoid;display:inline-block;width:100%}
@media(max-width:1100px){.form-masonry{column-count:1}}

/* ── Photo preview box ── */
.photo-preview-box{border:2px dashed var(--db-border);border-radius:var(--db-radius);padding:20px;text-align:center;min-height:110px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--db-surf2);transition:border-color .2s}
.photo-preview-box.has-new{border-color:var(--db-success);border-style:solid;background:var(--db-success-light)}
.photo-preview-box i{font-size:28px;color:var(--db-border)}
.photo-preview-box p{margin:6px 0 0;font-size:11px;color:var(--db-muted)}
.photo-preview-box img{max-width:100%;max-height:140px;border-radius:var(--db-radius-sm);box-shadow:0 2px 8px rgba(0,0,0,.1)}

/* ── Control number badge ── */
.ctrl-badge{display:inline-flex;align-items:center;gap:8px;background:var(--db-sky-light);border:1.5px solid #bae6fd;border-radius:var(--db-radius-sm);padding:10px 16px;font-family:'DM Mono',monospace;font-size:15px;font-weight:500;color:#0369a1;margin-bottom:8px}
.ctrl-badge i{opacity:.6}

/* ── Save note ── */
.save-note{padding:14px 16px;background:var(--db-surf2);border-radius:var(--db-radius);border:1px solid var(--db-border);font-size:13px;color:var(--db-muted)}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--db-surf2)}
::-webkit-scrollbar-thumb{background:var(--db-border);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--db-muted)}

@media(max-width:900px){.bps-hero{padding:20px;border-radius:0}.bps-hero__title{font-size:18px}.container-fluid{padding:0 14px 32px}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var photoInput   = document.getElementById('photoInput');
    var imagePreview = document.getElementById('imagePreview');
    var theForm      = document.getElementById('editBeneficiaryForm');
    var submitBtn    = document.getElementById('submitBtn');

    // ── Photo preview ──
    if (photoInput && imagePreview) {
        photoInput.addEventListener('change', function (e) {
            var file = e.target.files[0];
            if (!file) {
                // Restore original content if user clears selection
                imagePreview.classList.remove('has-new');
                return;
            }
            if (file.size > 5242880) {
                showNotification('danger', 'File must be under 5MB.');
                this.value = '';
                return;
            }
            if (['image/jpeg','image/jpg','image/png','image/gif'].indexOf(file.type) === -1) {
                showNotification('danger', 'Only JPG, PNG, or GIF files allowed.');
                this.value = '';
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                imagePreview.innerHTML = '<img src="' + ev.target.result + '" alt="New Photo Preview">' +
                    '<p style="color:var(--db-success);margin-top:6px;font-size:11px"><i class="fas fa-check-circle"></i> New photo selected</p>';
                imagePreview.classList.add('has-new');
            };
            reader.readAsDataURL(file);
        });
    }

    // ── Submit guard ──
    if (theForm) {
        theForm.addEventListener('submit', function () {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    }

    // ── Auto-dismiss server alerts after 6s ──
    setTimeout(function () {
        document.querySelectorAll('.alert:not(.dynamic-alert)').forEach(function (a) {
            a.classList.remove('show');
            setTimeout(function () { if (a.parentNode) a.remove(); }, 300);
        });
    }, 6000);

});

function showNotification(type, msg) {
    document.querySelectorAll('.dynamic-alert').forEach(function (a) { a.remove(); });
    var a = document.createElement('div');
    a.className = 'alert alert-' + type + ' alert-dismissible fade show dynamic-alert';
    a.innerHTML = '<i class="fas fa-info-circle me-2"></i>' + msg +
        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    var cf = document.querySelector('.container-fluid');
    if (cf) cf.prepend(a);
    setTimeout(function () {
        a.classList.remove('show');
        setTimeout(function () { if (a.parentNode) a.remove(); }, 300);
    }, 5000);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>