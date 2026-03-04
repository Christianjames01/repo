<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();
$page_title = '4Ps Registration';
$success_message = '';
$error_message = '';

$residents_query = "SELECT 
    r.resident_id,
    r.first_name,
    r.last_name,
    r.middle_name,
    r.email,
    COALESCE(r.contact_number, r.contact_no, r.phone) as contact_no,
    COALESCE(r.birthdate, r.date_of_birth) as birthdate,
    r.gender,
    r.civil_status,
    r.permanent_address,
    r.street,
    r.barangay,
    r.town,
    r.city,
    r.province,
    r.birthplace,
    r.address,
    CONCAT(r.last_name, ', ', r.first_name, 
           CASE WHEN r.middle_name IS NOT NULL AND r.middle_name != '' 
               THEN CONCAT(' ', SUBSTRING(r.middle_name, 1, 1), '.') 
               ELSE '' END, ' \u2713') as full_name_display,
    CASE WHEN b.beneficiary_id IS NOT NULL THEN 1 ELSE 0 END as already_in_4ps
FROM tbl_residents r
LEFT JOIN tbl_4ps_beneficiaries b ON r.resident_id = b.resident_id
WHERE r.is_verified = 1
ORDER BY r.last_name, r.first_name";

$residents_result = $conn->query($residents_query);
if (!$residents_result) die("Error fetching residents: " . $conn->error);

// Build rows array for the dropdown
$residents_rows = [];
while ($r = $residents_result->fetch_assoc()) {
    $residents_rows[] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Explicitly set to NULL (not 0) when not provided — intval(NULL)=0 which breaks PK
    $resident_id = (isset($_POST['resident_id']) && $_POST['resident_id'] !== '' && intval($_POST['resident_id']) > 0)
                   ? intval($_POST['resident_id'])
                   : null;
    $last_name         = trim($_POST['last_name']);         $first_name    = trim($_POST['first_name']);
    $middle_name       = trim($_POST['middle_name']);       $ext           = trim($_POST['ext']);
    $permanent_address = trim($_POST['permanent_address']); $street        = trim($_POST['street']);
    $brgy              = trim($_POST['brgy']);               $town          = trim($_POST['town']);
    $province          = trim($_POST['province']);          $birthplace    = trim($_POST['birthplace']);
    $mobile_phone      = trim($_POST['mobile_phone']);      $birthday      = $_POST['birthday'];
    $civil_status      = $_POST['civil_status'];            $gender        = $_POST['gender'];
    $father_full_name  = trim($_POST['father_full_name']);  $father_address   = trim($_POST['father_address']);
    $father_education  = $_POST['father_education'];        $father_income    = !empty($_POST['father_income']) ? floatval($_POST['father_income']) : 0.0;
    $mother_full_name  = trim($_POST['mother_full_name']);  $mother_address   = trim($_POST['mother_address']);
    $mother_education  = $_POST['mother_education'];        $mother_income    = !empty($_POST['mother_income']) ? floatval($_POST['mother_income']) : 0.0;
    $secondary_school  = trim($_POST['secondary_school']);  $degree_program   = trim($_POST['degree_program']);
    $year_level        = $_POST['year_level'];
    $reference_1       = trim($_POST['reference_1']);       $reference_2   = trim($_POST['reference_2']);
    $reference_3       = trim($_POST['reference_3']);
    $household_id      = trim($_POST['household_id']);      $grantee_name  = trim($_POST['grantee_name']);
    $date_registered   = $_POST['date_registered'];         $status        = $_POST['status'];
    $set_number        = trim($_POST['set_number']);         $compliance_status = $_POST['compliance_status'];
    $monthly_grant     = floatval($_POST['monthly_grant']); $remarks       = trim($_POST['remarks']);

    $photo_filename = null;
    if (isset($_FILES['id_picture']) && $_FILES['id_picture']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/4ps/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_extension = pathinfo($_FILES['id_picture']['name'], PATHINFO_EXTENSION);
        $photo_filename = 'applicant_' . time() . '_' . uniqid() . '.' . $file_extension;
        if (!move_uploaded_file($_FILES['id_picture']['tmp_name'], $upload_dir . $photo_filename)) {
            $error_message = "Error uploading photo file.";
            $photo_filename = null;
        }
    }

    if (!empty($resident_id)) {
        $check_stmt = $conn->prepare("SELECT * FROM tbl_4ps_beneficiaries WHERE resident_id = ?");
        $check_stmt->bind_param("i", $resident_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $error_message = "This resident is already registered in the 4Ps program.";
        } else {
            $check_stmt->close();
            processRegistration();
        }
    } else {
        processRegistration();
    }
}

function processRegistration() {
    global $conn, $resident_id, $household_id, $grantee_name, $date_registered, $status;
    global $set_number, $compliance_status, $monthly_grant, $remarks;
    global $last_name, $first_name, $middle_name, $ext, $permanent_address, $street;
    global $brgy, $town, $province, $birthplace, $mobile_phone, $birthday, $civil_status, $gender;
    global $father_full_name, $father_address, $father_education, $father_income;
    global $mother_full_name, $mother_address, $mother_education, $mother_income;
    global $secondary_school, $degree_program, $year_level;
    global $reference_1, $reference_2, $reference_3, $photo_filename;
    global $success_message, $error_message;

    // Re-evaluate resident_id inside function scope to guarantee it's truly null or a positive int
    $rid = (isset($resident_id) && is_int($resident_id) && $resident_id > 0) ? $resident_id : null;

    $ctrl_no = 'CTRL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $conn->begin_transaction();
    try {
        // Use PDO-style: omit resident_id column entirely when null to avoid binding NULL as 0
        if ($rid !== null) {
            $stmt = $conn->prepare("INSERT INTO tbl_4ps_beneficiaries (resident_id, household_id, grantee_name, date_registered, status, set_number, compliance_status, monthly_grant, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            $stmt->bind_param("issssssds", $rid, $household_id, $grantee_name, $date_registered, $status, $set_number, $compliance_status, $monthly_grant, $remarks);
        } else {
            $stmt = $conn->prepare("INSERT INTO tbl_4ps_beneficiaries (household_id, grantee_name, date_registered, status, set_number, compliance_status, monthly_grant, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            $stmt->bind_param("ssssssds", $household_id, $grantee_name, $date_registered, $status, $set_number, $compliance_status, $monthly_grant, $remarks);
        }
        if (!$stmt->execute()) throw new Exception("Insert beneficiary failed: " . $stmt->error);
        $beneficiary_id = $stmt->insert_id;
        if (!$beneficiary_id) throw new Exception("Insert succeeded but returned no ID — check table structure");
        $stmt->close();

        $ext_stmt = $conn->prepare("INSERT INTO tbl_4ps_extended_details (beneficiary_id, last_name, first_name, middle_name, ext_name, permanent_address, street, barangay, town, province, birthplace, mobile_phone, birthday, civil_status, gender, father_full_name, father_address, father_education, father_income, mother_full_name, mother_address, mother_education, mother_income, secondary_school, degree_program, year_level, reference_1, reference_2, reference_3, id_picture, ctrl_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ext_stmt->bind_param("issssssssssssssssdssssdssssssss", $beneficiary_id, $last_name, $first_name, $middle_name, $ext, $permanent_address, $street, $brgy, $town, $province, $birthplace, $mobile_phone, $birthday, $civil_status, $gender, $father_full_name, $father_address, $father_education, $father_income, $mother_full_name, $mother_address, $mother_education, $mother_income, $secondary_school, $degree_program, $year_level, $reference_1, $reference_2, $reference_3, $photo_filename, $ctrl_no);
        if (!$ext_stmt->execute()) throw new Exception("Error inserting extended details: " . $ext_stmt->error);
        $ext_stmt->close();

        $conn->commit();
        header("Location: beneficiaries.php?success=" . urlencode("4Ps beneficiary registered successfully! Control No: " . $ctrl_no));
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Registration failed: " . $e->getMessage();
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
            <div class="bps-hero__icon" style="background:linear-gradient(135deg,#0d9488,#0f766e);">
                <i class="fas fa-user-plus"></i>
            </div>
            <div>
                <h1 class="bps-hero__title">4Ps Educational Assistance Application</h1>
                <p class="bps-hero__sub">Pantawid Pamilyang Pilipino Program &mdash; Register a new beneficiary</p>
            </div>
        </div>
        <a href="beneficiaries.php" class="btn btn-secondary">
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

    <form method="POST" id="4psForm" enctype="multipart/form-data">

        <!-- Resident Lookup -->
        <div class="card shadow mb-4">
            <div class="card-header">
                <h5><i class="fas fa-search" style="opacity:.7"></i> Resident Lookup</h5>
                <small><i class="fas fa-info-circle"></i> Select a verified resident to auto-fill personal info</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Select Verified Resident <span style="color:var(--db-teal)">&#10003;</span></label>
                        <select class="form-select" id="resident_id" name="resident_id">
                            <option value="">&#8212; Optional: Select a verified resident &#8212;</option>
                            <?php foreach ($residents_rows as $resident): ?>
                            <option value="<?php echo $resident['resident_id']; ?>"
                                    data-already-in-4ps="<?php echo $resident['already_in_4ps']; ?>"
                                    <?php echo $resident['already_in_4ps'] == 1 ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($resident['full_name_display']); ?>
                                <?php echo $resident['already_in_4ps'] == 1 ? ' (Already in 4Ps)' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Control No.</label>
                        <input type="text" class="form-control" value="Auto-generated on submit" disabled>
                    </div>
                </div>
                <div id="autofill-notice" class="alert alert-success mt-3 mb-0" style="display:none;">
                    <i class="fas fa-check-circle me-2"></i>
                    Personal information loaded &mdash; fields are now read-only.
                    <button type="button" class="btn-close float-end" onclick="clearResident()"></button>
                </div>
            </div>
        </div>

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
                                <input type="text" class="form-control autofill-field" name="last_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">First Name <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control autofill-field" name="middle_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ext. (Jr., Sr., III)</label>
                                <input type="text" class="form-control autofill-field" name="ext">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Permanent Address <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="permanent_address" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Street</label>
                                <input type="text" class="form-control autofill-field" name="street">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Barangay <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="brgy" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Town/City <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="town" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Province <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="province" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Birthplace <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="birthplace" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mobile/Phone No. <span class="req">*</span></label>
                                <input type="text" class="form-control autofill-field" name="mobile_phone" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Birthday <span class="req">*</span></label>
                                <input type="date" class="form-control autofill-field" name="birthday" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender <span class="req">*</span></label>
                                <select class="form-select autofill-field" name="gender" required>
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Civil Status <span class="req">*</span></label>
                                <select class="form-select autofill-field" name="civil_status" required>
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
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
                                <input type="text" class="form-control" name="father_full_name" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address <span class="req">*</span></label>
                                <input type="text" class="form-control" name="father_address" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Educational Attainment</label>
                                <select class="form-select" name="father_education">
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option>Elementary</option><option>High School</option>
                                    <option>College</option><option>Vocational</option><option>Post Graduate</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Monthly Income (&#8369;)</label>
                                <input type="number" class="form-control" name="father_income" step="0.01" min="0" placeholder="0.00">
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
                                <input type="text" class="form-control" name="mother_full_name" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address <span class="req">*</span></label>
                                <input type="text" class="form-control" name="mother_address" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Educational Attainment</label>
                                <select class="form-select" name="mother_education">
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option>Elementary</option><option>High School</option>
                                    <option>College</option><option>Vocational</option><option>Post Graduate</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Monthly Income (&#8369;)</label>
                                <input type="number" class="form-control" name="mother_income" step="0.01" min="0" placeholder="0.00">
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
                                <input type="text" class="form-control" name="secondary_school" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Degree Program / Course <span class="req">*</span></label>
                                <input type="text" class="form-control" name="degree_program" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year Level <span class="req">*</span></label>
                                <select class="form-select" name="year_level" required>
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option>1st Year</option><option>2nd Year</option>
                                    <option>3rd Year</option><option>4th Year</option><option>5th Year</option>
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
                                <input type="text" class="form-control" name="household_id" required placeholder="e.g. HH-2024-001">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Grantee Name <span class="req">*</span></label>
                                <input type="text" class="form-control" name="grantee_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date Registered <span class="req">*</span></label>
                                <input type="date" class="form-control" name="date_registered" required max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Set Number</label>
                                <input type="text" class="form-control" name="set_number" placeholder="e.g. SET-01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status <span class="req">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Suspended">Suspended</option>
                                    <option value="Graduated">Graduated</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Compliance Status <span class="req">*</span></label>
                                <select class="form-select" name="compliance_status" required>
                                    <option value="">&#8212; Select &#8212;</option>
                                    <option value="Compliant">Compliant</option>
                                    <option value="Non-Compliant">Non-Compliant</option>
                                    <option value="Partial">Partial</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Monthly Grant (&#8369;) <span class="req">*</span></label>
                                <input type="number" class="form-control" name="monthly_grant" required step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks" rows="3"></textarea>
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
                                <input type="text" class="form-control" name="reference_1" placeholder="Full Name, Contact Number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">2. Name &amp; Contact</label>
                                <input type="text" class="form-control" name="reference_2" placeholder="Full Name, Contact Number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">3. Name &amp; Contact</label>
                                <input type="text" class="form-control" name="reference_3" placeholder="Full Name, Contact Number">
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
                                <label class="form-label">Upload Recent ID Photo <span class="req">*</span></label>
                                <input type="file" class="form-control" name="id_picture" accept="image/*" required>
                                <small class="text-muted d-block mt-1">
                                    <i class="fas fa-info-circle"></i> JPG, PNG, GIF &middot; Max 5MB
                                </small>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Preview</label>
                                <div id="imagePreview" class="photo-preview-box">
                                    <i class="fas fa-user"></i>
                                    <p>Preview here</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Requirements -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-clipboard-list" style="opacity:.7"></i> Requirements</h5>
                    </div>
                    <div class="card-body">
                        <ol class="req-list">
                            <li>4Ps Application Form</li>
                            <li>Certificate of Enrollment (Photocopy)</li>
                            <li>Transcript of Records (previous school year)</li>
                            <li>Student ID or any Government ID</li>
                            <li>Barangay Clearance</li>
                        </ol>
                        <div class="alert alert-warning mb-0 mt-3">
                            <strong><i class="fas fa-star me-1"></i> Qualifications:</strong>
                            <ul class="mb-0 mt-1 ps-3">
                                <li>Must be enrolled in the current semester</li>
                                <li>No failing marks from the previous semester</li>
                                <li>No grade lower than 2.5</li>
                            </ul>
                        </div>
                    </div>
                </div>

        </div><!-- /form-masonry -->

        <!-- Certification + Submit -->
        <div class="card shadow mb-5">
            <div class="card-body">
                <div class="certify-box mb-4">
                    <input type="checkbox" id="certify" required>
                    <label for="certify">
                        <strong>I hereby certify that the foregoing statements are true and correct.</strong><br>
                        <small class="text-muted">Any misrepresentation or withholding of information will automatically disqualify me from the educational assistance program.</small>
                    </label>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <a href="beneficiaries.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-warning btn-lg" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Submit Application
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

/* Hero */
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

/* Container */
.container-fluid{padding:0 24px 40px;max-width:1400px;margin:0 auto}

/* Alerts */
.alert{border-radius:var(--db-radius);border:none;border-left:4px solid;font-family:'Sora',sans-serif;font-size:13.5px;font-weight:500;padding:14px 18px;margin-bottom:16px;animation:dbFadeUp .3s ease both}
.alert-success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success)}
.alert-danger{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger)}
.alert-warning{background:var(--db-warning-light);color:#92400e;border-color:var(--db-warning)}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* Cards */
.card{background:var(--db-surf);border-radius:var(--db-radius-lg) !important;border:1px solid var(--db-border) !important;box-shadow:var(--db-shadow);overflow:hidden;animation:dbFadeUp .35s ease both}
.card-header{padding:18px 22px !important;border-bottom:1px solid var(--db-border) !important;background:var(--db-surf) !important;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.card-header h5{font-size:15px;font-weight:700;color:var(--db-text);margin:0;display:flex;align-items:center;gap:8px}
.card-header h5::before{content:'';display:inline-block;width:4px;height:18px;background:linear-gradient(to bottom,var(--db-teal),var(--db-sky));border-radius:2px}
.card-header small{font-size:11.5px;color:var(--db-muted);font-family:'DM Mono',monospace}
.card-body{padding:20px 22px !important}

/* Form */
.form-label{font-size:12px;font-weight:600;color:var(--db-text);margin-bottom:5px;font-family:'Sora',sans-serif}
.form-control,.form-select{border:1.5px solid var(--db-border) !important;border-radius:var(--db-radius-sm) !important;font-family:'Sora',sans-serif !important;font-size:13px !important;color:var(--db-text) !important;background:var(--db-surf) !important;padding:9px 13px !important;transition:all .18s !important;box-shadow:none !important}
.form-control:focus,.form-select:focus{border-color:var(--db-navy-light) !important;box-shadow:0 0 0 3px rgba(28,52,97,.1) !important}
.form-control::placeholder{color:#94a3b8 !important}
.form-control[readonly]{background:var(--db-surf2) !important;cursor:not-allowed;color:var(--db-muted) !important}
.form-control[disabled]{background:var(--db-surf2) !important;color:var(--db-muted) !important;font-family:'DM Mono',monospace !important}
textarea.form-control{resize:vertical;min-height:88px}
.autofill-field.filled{background:#f0fdf4 !important;border-color:#86efac !important}

/* Buttons */
.btn{font-family:'Sora',sans-serif !important;font-weight:600 !important;border-radius:var(--db-radius-sm) !important;font-size:13px !important;transition:all .18s ease !important;display:inline-flex !important;align-items:center !important;gap:6px !important}
.btn-secondary{background:var(--db-surf2) !important;border-color:var(--db-border) !important;color:var(--db-text) !important}
.btn-secondary:hover{background:var(--db-border) !important;color:var(--db-text) !important}
.btn-warning{background:linear-gradient(135deg,var(--db-amber),var(--db-amber-dark)) !important;border-color:transparent !important;color:#fff !important}
.btn-warning:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(245,158,11,.35) !important;color:#fff !important}
.btn-lg{padding:11px 24px !important;font-size:14px !important}

.req{color:var(--db-rose)}
.text-muted{color:var(--db-muted) !important;font-size:11.5px}

/* Masonry 2-column layout — no blank spaces */
.form-masonry{column-count:2;column-gap:18px}
.form-masonry>.card{break-inside:avoid;display:inline-block;width:100%}
@media(max-width:1100px){.form-masonry{column-count:1}}

/* Photo preview */
.photo-preview-box{border:2px dashed var(--db-border);border-radius:var(--db-radius);padding:20px;text-align:center;min-height:110px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--db-surf2)}
.photo-preview-box i{font-size:28px;color:var(--db-border)}
.photo-preview-box p{margin:6px 0 0;font-size:11px;color:var(--db-muted)}
.photo-preview-box img{max-width:100%;max-height:130px;border-radius:var(--db-radius-sm)}

/* Requirements list */
.req-list{padding-left:20px;margin:0}
.req-list li{padding:5px 0;font-size:13px;border-bottom:1px solid var(--db-border)}
.req-list li:last-child{border-bottom:none}

/* Certification */
.certify-box{display:flex;align-items:flex-start;gap:12px;padding:16px;background:var(--db-surf2);border-radius:var(--db-radius);border:1px solid var(--db-border)}
.certify-box input[type="checkbox"]{width:18px;height:18px;flex-shrink:0;margin-top:2px;accent-color:var(--db-navy);cursor:pointer}
.certify-box label{font-size:13.5px;line-height:1.6;cursor:pointer}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--db-surf2)}
::-webkit-scrollbar-thumb{background:var(--db-border);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--db-muted)}

@media(max-width:900px){.bps-hero{padding:20px;border-radius:0}.bps-hero__title{font-size:18px}.container-fluid{padding:0 14px 32px}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var residentSelect = document.getElementById('resident_id');
    var autofillNotice = document.getElementById('autofill-notice');
    var imagePreview   = document.getElementById('imagePreview');
    var pictureInput   = document.querySelector('[name="id_picture"]');
    var theForm        = document.getElementById('4psForm');
    var submitBtn      = document.getElementById('submitBtn');
    var certifyBox     = document.getElementById('certify');

    var fieldMap = {
        last_name:         'last_name',
        first_name:        'first_name',
        middle_name:       'middle_name',
        ext:               'ext_name',
        permanent_address: 'permanent_address',
        street:            'street',
        brgy:              'barangay',
        town:              'town',
        province:          'province',
        birthplace:        'birthplace',
        mobile_phone:      'contact_no',
        birthday:          'birthdate',
        civil_status:      'civil_status',
        gender:            'gender'
    };

    // ── Resident autofill ──
    if (residentSelect) {
        residentSelect.addEventListener('change', function () {
            var id  = this.value;
            var opt = this.options[this.selectedIndex];

            if (!id) { clearResident(); return; }

            if (opt.getAttribute('data-already-in-4ps') === '1') {
                showNotification('warning', 'This resident is already registered in the 4Ps program.');
                this.value = '';
                return;
            }

            residentSelect.disabled = true;

            fetch('get_resident.php?id=' + encodeURIComponent(id), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                if (!response.ok) throw new Error('Server error ' + response.status);
                var ct = response.headers.get('content-type') || '';
                if (!ct.includes('application/json')) throw new Error('Non-JSON response: ' + ct);
                return response.json();
            })
            .then(function (result) {
                if (!result.success) throw new Error(result.message || 'success:false');
                fillForm(result.data);
                if (autofillNotice) autofillNotice.style.display = 'block';
                showNotification('success', 'Resident information loaded successfully!');
            })
            .catch(function (err) {
                console.error('Autofill error:', err);
                showNotification('danger', 'Could not load resident: ' + err.message);
                clearResident();
            })
            .finally(function () {
                residentSelect.disabled = false;
            });
        });
    }

    function fillForm(d) {
        if (!d) return;
        Object.keys(fieldMap).forEach(function (fname) {
            var el  = document.querySelector('[name="' + fname + '"]');
            var raw = d[fieldMap[fname]];
            var val = (raw != null ? String(raw) : '').trim();
            if (!el || !val) return;
            if (el.tagName === 'SELECT') {
                Array.from(el.options).forEach(function (o) {
                    if (o.value.toLowerCase() === val.toLowerCase()) el.value = o.value;
                });
            } else {
                el.value = val;
            }
            el.classList.add('filled');
            el.setAttribute('readonly', true);
        });
    }

    window.clearResident = function () {
        if (residentSelect) residentSelect.value = '';
        document.querySelectorAll('.autofill-field').forEach(function (f) {
            if (f.tagName === 'SELECT') f.selectedIndex = 0;
            else f.value = '';
            f.classList.remove('filled');
            f.removeAttribute('readonly');
        });
        if (autofillNotice) autofillNotice.style.display = 'none';
    };

    // ── Image preview ──
    if (pictureInput) {
        pictureInput.addEventListener('change', function (e) {
            var file = e.target.files[0];
            if (!file) return;
            if (file.size > 5242880) {
                showNotification('danger', 'File must be under 5MB.');
                this.value = ''; return;
            }
            if (['image/jpeg','image/png','image/gif'].indexOf(file.type) === -1) {
                showNotification('danger', 'Only JPG, PNG, or GIF files allowed.');
                this.value = ''; return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                if (imagePreview) imagePreview.innerHTML = '<img src="' + ev.target.result + '" alt="Preview" style="max-width:100%;max-height:130px;border-radius:8px;">';
            };
            reader.readAsDataURL(file);
        });
    }

    // ── Submit guard ──
    if (theForm) {
        theForm.addEventListener('submit', function (e) {
            if (certifyBox && !certifyBox.checked) {
                e.preventDefault();
                showNotification('warning', 'Please tick the certification checkbox before submitting.');
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }
        });
    }

    // ── Auto-dismiss server alerts ──
    setTimeout(function () {
        document.querySelectorAll('.alert:not(.dynamic-alert)').forEach(function (a) {
            a.classList.remove('show');
            setTimeout(function () { if (a.parentNode) a.remove(); }, 300);
        });
    }, 5000);

}); // end DOMContentLoaded

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