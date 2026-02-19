<?php
// Include config which handles session, database, and functions
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and is Super Admin
if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();

$page_title = '4Ps Registration';
$success_message = '';
$error_message = '';

// Fetch VERIFIED residents only (not yet in 4Ps program)
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
           CASE 
               WHEN r.middle_name IS NOT NULL AND r.middle_name != '' 
               THEN CONCAT(' ', SUBSTRING(r.middle_name, 1, 1), '.') 
               ELSE '' 
           END,
           ' ✓') as full_name_display,
    CASE 
        WHEN b.beneficiary_id IS NOT NULL THEN 1
        ELSE 0
    END as already_in_4ps
FROM tbl_residents r
LEFT JOIN tbl_4ps_beneficiaries b ON r.resident_id = b.resident_id
WHERE r.is_verified = 1
ORDER BY r.last_name, r.first_name";

$residents_result = $conn->query($residents_query);

if (!$residents_result) {
    die("Error fetching residents: " . $conn->error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Personal Information
    $resident_id = !empty($_POST['resident_id']) ? intval($_POST['resident_id']) : NULL;
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $ext = trim($_POST['ext']);
    $permanent_address = trim($_POST['permanent_address']);
    $street = trim($_POST['street']);
    $brgy = trim($_POST['brgy']);
    $town = trim($_POST['town']);
    $province = trim($_POST['province']);
    $birthplace = trim($_POST['birthplace']);
    $mobile_phone = trim($_POST['mobile_phone']);
    $birthday = $_POST['birthday'];
    $civil_status = $_POST['civil_status'];
    $gender = $_POST['gender'];
    
    // Family Background - Father
    $father_full_name = trim($_POST['father_full_name']);
    $father_address = trim($_POST['father_address']);
    $father_education = $_POST['father_education'];
    $father_income = !empty($_POST['father_income']) ? floatval($_POST['father_income']) : 0.0;
    
    // Family Background - Mother
    $mother_full_name = trim($_POST['mother_full_name']);
    $mother_address = trim($_POST['mother_address']);
    $mother_education = $_POST['mother_education'];
    $mother_income = !empty($_POST['mother_income']) ? floatval($_POST['mother_income']) : 0.0;
    
    // Academic Information
    $secondary_school = trim($_POST['secondary_school']);
    $degree_program = trim($_POST['degree_program']);
    $year_level = $_POST['year_level'];
    
    // Personal References
    $reference_1 = trim($_POST['reference_1']);
    $reference_2 = trim($_POST['reference_2']);
    $reference_3 = trim($_POST['reference_3']);
    
    // 4Ps Specific
    $household_id = trim($_POST['household_id']);
    $grantee_name = trim($_POST['grantee_name']);
    $date_registered = $_POST['date_registered'];
    $status = $_POST['status'];
    $set_number = trim($_POST['set_number']);
    $compliance_status = $_POST['compliance_status'];
    $monthly_grant = floatval($_POST['monthly_grant']);
    $remarks = trim($_POST['remarks']);
    
    // Handle photo upload
    $photo_filename = null;
    if (isset($_FILES['id_picture']) && $_FILES['id_picture']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/4ps/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['id_picture']['name'], PATHINFO_EXTENSION);
        $photo_filename = 'applicant_' . time() . '_' . uniqid() . '.' . $file_extension;
        
        if (!move_uploaded_file($_FILES['id_picture']['tmp_name'], $upload_dir . $photo_filename)) {
            $error_message = "Error uploading photo file.";
            $photo_filename = null;
        }
    }
    
    // Check if resident already exists in 4Ps (only if resident_id is provided)
    if (!empty($resident_id)) {
        $check_query = "SELECT * FROM tbl_4ps_beneficiaries WHERE resident_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $resident_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_message = "This resident is already registered in the 4Ps program.";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            // Proceed with registration
            processRegistration();
        }
    } else {
        // No resident selected - register as new
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
    
    // Generate control number
    $ctrl_no = 'CTRL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Step 1: Insert main 4Ps beneficiary record
        $insert_query = "INSERT INTO tbl_4ps_beneficiaries 
                        (resident_id, household_id, grantee_name, date_registered, status, 
                         set_number, compliance_status, monthly_grant, remarks, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($insert_query);
        if (!$stmt) {
            throw new Exception("Error preparing main query: " . $conn->error);
        }
        
        $stmt->bind_param("issssssds", 
            $resident_id,
            $household_id,
            $grantee_name,
            $date_registered,
            $status,
            $set_number,
            $compliance_status,
            $monthly_grant,
            $remarks
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting beneficiary: " . $stmt->error);
        }
        
        $beneficiary_id = $stmt->insert_id;
        $stmt->close();
        
        error_log("SUCCESS: Main beneficiary inserted with ID: " . $beneficiary_id);
        
        // Step 2: Insert extended details
        $ext_query = "INSERT INTO tbl_4ps_extended_details 
                      (beneficiary_id, last_name, first_name, middle_name, ext_name,
                       permanent_address, street, barangay, town, province,
                       birthplace, mobile_phone, birthday, civil_status, gender,
                       father_full_name, father_address, father_education, father_income,
                       mother_full_name, mother_address, mother_education, mother_income,
                       secondary_school, degree_program, year_level,
                       reference_1, reference_2, reference_3,
                       id_picture, ctrl_no) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $ext_stmt = $conn->prepare($ext_query);
        
        if (!$ext_stmt) {
            throw new Exception("Error preparing extended details query: " . $conn->error);
        }
        
        // Debug log
        error_log("DEBUG: Preparing to insert extended details for beneficiary_id: " . $beneficiary_id);
        error_log("DEBUG: Name: $last_name, $first_name $middle_name");
        error_log("DEBUG: Birthday: $birthday, Mobile: $mobile_phone");
        
      $ext_stmt->bind_param("issssssssssssssssdssssdssssssss",
    $beneficiary_id,        // 1  - i (integer)
    $last_name,             // 2  - s (string)
    $first_name,            // 3  - s
    $middle_name,           // 4  - s
    $ext,                   // 5  - s
    $permanent_address,     // 6  - s
    $street,                // 7  - s
    $brgy,                  // 8  - s
    $town,                  // 9  - s
    $province,              // 10 - s
    $birthplace,            // 11 - s
    $mobile_phone,          // 12 - s
    $birthday,              // 13 - s
    $civil_status,          // 14 - s
    $gender,                // 15 - s
    $father_full_name,      // 16 - s
    $father_address,        // 17 - s
    $father_education,      // 18 - s
    $father_income,         // 19 - d (double/float)
    $mother_full_name,      // 20 - s
    $mother_address,        // 21 - s
    $mother_education,      // 22 - s
    $mother_income,         // 23 - d (double/float)
    $secondary_school,      // 24 - s
    $degree_program,        // 25 - s
    $year_level,            // 26 - s
    $reference_1,           // 27 - s
    $reference_2,           // 28 - s
    $reference_3,           // 29 - s
    $photo_filename,        // 30 - s
    $ctrl_no                // 31 - s
);
        
        if (!$ext_stmt->execute()) {
            throw new Exception("Error inserting extended details: " . $ext_stmt->error);
        }
        
        $affected = $ext_stmt->affected_rows;
        error_log("SUCCESS: Extended details inserted. Affected rows: " . $affected);
        
        if ($affected === 0) {
            throw new Exception("Extended details insert returned 0 affected rows");
        }
        
        $ext_stmt->close();
        
        // Verify insertion (optional debug check)
        $verify_query = "SELECT * FROM tbl_4ps_extended_details WHERE beneficiary_id = ?";
        $verify_stmt = $conn->prepare($verify_query);
        $verify_stmt->bind_param("i", $beneficiary_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        error_log("DEBUG: Extended details count for beneficiary $beneficiary_id: " . $verify_result->num_rows);
        if ($verify_result->num_rows > 0) {
            $verify_data = $verify_result->fetch_assoc();
            error_log("DEBUG: Extended details data: " . print_r($verify_data, true));
        }
        $verify_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        error_log("SUCCESS: Transaction committed successfully for control no: " . $ctrl_no);
        
        $success_message = "4Ps beneficiary registered successfully! Control No: " . $ctrl_no;
        
        // Clear POST data
        $_POST = array();
        
        // Redirect
        header("Location: beneficiaries-debug.php?success=" . urlencode($success_message));
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Registration failed: " . $e->getMessage();
        error_log("ERROR during registration: " . $e->getMessage());
        error_log("ERROR Stack trace: " . $e->getTraceAsString());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-user-plus me-2"></i>4Ps Educational Assistance Application Form</h2>
            <p class="text-muted">Pantawid Pamilyang Pilipino Program - Educational Assistance Registration</p>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <form method="POST" action="" id="4psForm" enctype="multipart/form-data">
                <!-- Application Header Card -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-file-alt me-2"></i>APPLICATION FORM
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Select Verified Resident ✓</label>
                                <select class="form-select" id="resident_id" name="resident_id">
                                    <option value="">-- Select Verified Resident (Optional) --</option>
                                    <?php while ($resident = $residents_result->fetch_assoc()): ?>
                                        <option value="<?php echo $resident['resident_id']; ?>" 
                                                data-already-in-4ps="<?php echo $resident['already_in_4ps']; ?>"
                                                <?php echo $resident['already_in_4ps'] == 1 ? 'disabled' : ''; ?>>
                                            <?php echo htmlspecialchars($resident['full_name_display']); ?>
                                            <?php echo $resident['already_in_4ps'] == 1 ? ' (Already in 4Ps)' : ''; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Only verified residents are shown. 
                                    Selecting a resident will auto-fill their personal information.
                                </small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Control No.</label>
                                <input type="text" class="form-control" value="Auto-generated" disabled>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>PERSONAL INFORMATION</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Ext. (Jr., Sr., III)</label>
                                <input type="text" class="form-control" name="ext">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Permanent Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="permanent_address" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Street</label>
                                <input type="text" class="form-control" name="street">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Barangay <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="brgy" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Town/City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="town" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Province <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="province" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Birthplace <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="birthplace" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mobile/Phone No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="mobile_phone" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Birthday <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="birthday" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Civil Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="civil_status" required>
                                    <option value="">-- Select --</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select class="form-select" name="gender" required>
                                    <option value="">-- Select --</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Family Background - Father -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-male me-2"></i>FAMILY BACKGROUND - FATHER</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Father's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="father_full_name" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="father_address" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Educational Attainment</label>
                                <select class="form-select" name="father_education">
                                    <option value="">-- Select --</option>
                                    <option value="Elementary">Elementary</option>
                                    <option value="High School">High School</option>
                                    <option value="College">College</option>
                                    <option value="Vocational">Vocational</option>
                                    <option value="Post Graduate">Post Graduate</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monthly Income (₱)</label>
                                <input type="number" class="form-control" name="father_income" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Family Background - Mother -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-female me-2"></i>FAMILY BACKGROUND - MOTHER</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Mother's Full Maiden Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="mother_full_name" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="mother_address" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Educational Attainment</label>
                                <select class="form-select" name="mother_education">
                                    <option value="">-- Select --</option>
                                    <option value="Elementary">Elementary</option>
                                    <option value="High School">High School</option>
                                    <option value="College">College</option>
                                    <option value="Vocational">Vocational</option>
                                    <option value="Post Graduate">Post Graduate</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monthly Income (₱)</label>
                                <input type="number" class="form-control" name="mother_income" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>ACADEMIC INFORMATION</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Secondary School Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="secondary_school" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Degree Program/Course Taken <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="degree_program" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Year Level <span class="text-danger">*</span></label>
                                <select class="form-select" name="year_level" required>
                                    <option value="">-- Select --</option>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                    <option value="5th Year">5th Year</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal References -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-address-book me-2"></i>PERSONAL REFERENCES</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">1. Name & Contact</label>
                                <input type="text" class="form-control" name="reference_1" placeholder="Full Name, Contact Number">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">2. Name & Contact</label>
                                <input type="text" class="form-control" name="reference_2" placeholder="Full Name, Contact Number">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">3. Name & Contact</label>
                                <input type="text" class="form-control" name="reference_3" placeholder="Full Name, Contact Number">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4Ps Program Details -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-hands-helping me-2"></i>4Ps PROGRAM DETAILS</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Household ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="household_id" required placeholder="e.g., HH-2024-001">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grantee Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="grantee_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date Registered <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="date_registered" required max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="">-- Select --</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Suspended">Suspended</option>
                                    <option value="Graduated">Graduated</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Set Number</label>
                                <input type="text" class="form-control" name="set_number" placeholder="e.g., SET-01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Compliance Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="compliance_status" required>
                                    <option value="">-- Select --</option>
                                    <option value="Compliant">Compliant</option>
                                    <option value="Non-Compliant">Non-Compliant</option>
                                    <option value="Partial">Partial</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monthly Grant (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="monthly_grant" required step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ID Picture Upload -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-camera me-2"></i>ID PICTURE (2x2)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Upload Recent 2x2 ID Picture <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="id_picture" accept="image/*" required>
                                <small class="text-muted">Accepted formats: JPG, PNG, GIF (Max 5MB)</small>
                            </div>
                            <div class="col-md-6">
                                <div class="id-picture-preview" id="imagePreview">
                                    <i class="fas fa-user fa-3x text-muted"></i>
                                    <p class="text-muted mt-2">Preview will appear here</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Certification -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="certify" required>
                            <label class="form-check-label" for="certify">
                                <strong>I hereby certify that the foregoing statements are true and correct.</strong><br>
                                <small class="text-muted">Any misrepresentation or withholding of information will automatically disqualify me from the educational assistance program.</small>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between mb-5">
                    <a href="beneficiaries-debug.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-arrow-left me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Submit Application
                    </button>
                </div>

                <!-- Requirements Section -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>REQUIREMENTS</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li>4Ps Party-List Educational Assistance Application Form</li>
                            <li>Certificate of Enrollment (Photocopy)</li>
                            <li>Transcript of Records (from previous school year)</li>
                            <li>Student ID (or any government ID)</li>
                            <li>Barangay Clearance</li>
                        </ol>
                        <h6 class="mt-4"><strong>Qualifications:</strong></h6>
                        <ul>
                            <li>Must be enrolled in the current semester</li>
                            <li>No failing marks from the previous semester</li>
                            <li>At least no lower grades than 2.5</li>
                        </ul>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const residentSelect = document.getElementById('resident_id');
    const formFields = {};
    
    function cacheFormFields() {
        const fieldNames = [
            'last_name', 'first_name', 'middle_name', 'ext',
            'permanent_address', 'street', 'brgy', 'town', 'province',
            'birthplace', 'mobile_phone', 'birthday', 
            'civil_status', 'gender'
        ];
        
        fieldNames.forEach(name => {
            const element = document.querySelector(`[name="${name}"]`);
            if (element) {
                formFields[name] = element;
            }
        });
    }
    
    function showLoading(show = true) {
        const select = document.getElementById('resident_id');
        if (show) {
            select.disabled = true;
            select.style.cursor = 'wait';
        } else {
            select.disabled = false;
            select.style.cursor = 'pointer';
        }
    }
    
    function showNotification(message, type = 'info') {
        const existingAlerts = document.querySelectorAll('.auto-notification');
        existingAlerts.forEach(alert => alert.remove());
        
        const alertClass = type === 'success' ? 'alert-success' : 
                          type === 'error' ? 'alert-danger' : 
                          type === 'warning' ? 'alert-warning' : 'alert-info';
        
        const iconClass = type === 'success' ? 'fa-check-circle' : 
                         type === 'error' ? 'fa-exclamation-circle' : 
                         type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
        
        const alert = document.createElement('div');
        alert.className = `alert ${alertClass} alert-dismissible fade show auto-notification`;
        alert.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideInRight 0.3s ease-out;';
        alert.innerHTML = `
            <i class="fas ${iconClass} me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    }
    
    function clearPersonalInformation() {
        Object.values(formFields).forEach(field => {
            if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
            } else {
                field.value = '';
            }
            field.removeAttribute('readonly');
            field.style.backgroundColor = '';
        });
        
        document.querySelectorAll('.auto-notification').forEach(el => el.remove());
    }
    
    function autoFillForm(data) {
        const fieldMapping = {
            'last_name': data.last_name,
            'first_name': data.first_name,
            'middle_name': data.middle_name,
            'ext': data.ext_name,
            'permanent_address': data.permanent_address || data.address,
            'street': data.street,
            'brgy': data.barangay,
            'town': data.town || data.city,
            'province': data.province,
            'birthplace': data.birthplace,
            'mobile_phone': data.contact_no,
            'birthday': data.birthdate,
            'civil_status': data.civil_status,
            'gender': data.gender
        };
        
        Object.keys(fieldMapping).forEach(fieldName => {
            const field = formFields[fieldName];
            const value = fieldMapping[fieldName];
            
            if (field && value) {
                if (field.tagName === 'SELECT') {
                    const options = Array.from(field.options);
                    const matchingOption = options.find(opt => 
                        opt.value.toLowerCase() === value.toLowerCase()
                    );
                    if (matchingOption) {
                        field.value = matchingOption.value;
                    }
                } else {
                    field.value = value;
                }
                
                field.style.backgroundColor = '#e8f5e9';
                field.setAttribute('readonly', 'readonly');
                
                field.style.transition = 'background-color 0.3s ease';
                setTimeout(() => {
                    field.style.backgroundColor = '#f0f8ff';
                }, 300);
            }
        });
        
        const infoDiv = document.createElement('div');
        infoDiv.className = 'alert alert-info mt-3 auto-notification';
        infoDiv.innerHTML = `
            <i class="fas fa-info-circle me-2"></i>
            <strong>Info:</strong> Personal information fields have been auto-filled from verified resident data and are now read-only.
            You can still fill in family background, academic information, and 4Ps program details.
        `;
        
        const form = document.getElementById('4psForm');
        const firstCard = form.querySelector('.card');
        firstCard.after(infoDiv);
    }
    
    if (residentSelect) {
        cacheFormFields();
        
        residentSelect.addEventListener('change', function() {
            const residentId = this.value;
            const selectedOption = this.options[this.selectedIndex];
            
            if (!residentId) {
                clearPersonalInformation();
                return;
            }
            
            const alreadyIn4ps = selectedOption.getAttribute('data-already-in-4ps') === '1';
            if (alreadyIn4ps) {
                showNotification('This resident is already registered in the 4Ps program', 'warning');
                this.value = '';
                return;
            }
            
            showLoading(true);
            
            fetch('get_resident.php?id=' + residentId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(result => {
                    if (result.success && result.data) {
                        autoFillForm(result.data);
                        showNotification('✓ Resident information loaded successfully!', 'success');
                    } else {
                        showNotification(result.message || 'Error loading resident information', 'error');
                        clearPersonalInformation();
                        residentSelect.value = '';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to load resident information. Please try again.', 'error');
                    clearPersonalInformation();
                    residentSelect.value = '';
                })
                .finally(() => {
                    showLoading(false);
                });
        });
    }
    
    const imageInput = document.querySelector('input[name="id_picture"]');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('imagePreview');
            
            if (file) {
                if (file.size > 5242880) {
                    showNotification('File size must be less than 5MB', 'error');
                    this.value = '';
                    return;
                }
                
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    showNotification('Only JPG, PNG, or GIF files are allowed', 'error');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <img src="${e.target.result}" alt="ID Preview" 
                             style="max-width: 100%; max-height: 200px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <p class="text-success mt-2 mb-0">
                            <i class="fas fa-check-circle me-1"></i>Preview loaded
                        </p>
                    `;
                };
                reader.readAsDataURL(file);
            } else {
                preview.innerHTML = `
                    <i class="fas fa-user fa-3x text-muted"></i>
                    <p class="text-muted mt-2">Preview will appear here</p>
                `;
            }
        });
    }
    
    const form = document.getElementById('4psForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const certifyCheckbox = document.getElementById('certify');
            if (!certifyCheckbox.checked) {
                e.preventDefault();
                showNotification('Please certify that the information provided is true and correct', 'warning');
                certifyCheckbox.focus();
                return false;
            }
            
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
            }
        });
    }
});

const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    .form-control[readonly],
    .form-select[readonly] {
        cursor: not-allowed;
    }
`;
document.head.appendChild(style);
</script>

<style>
.card {
    border: none;
    border-radius: 10px;
}

.card-header {
    border-radius: 10px 10px 0 0 !important;
    padding: 1rem 1.5rem;
}

.form-label {
    font-weight: 500;
    color: #2d3748;
}

.form-control, .form-select {
    border: 1px solid #cbd5e0;
    border-radius: 8px;
    padding: 0.6rem 1rem;
}

.form-control:focus, .form-select:focus {
    border-color: #4299e1;
    box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
}

.id-picture-preview {
    border: 2px dashed #cbd5e0;
    border-radius: 8px;
    padding: 2rem;
    text-align: center;
    min-height: 200px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

#resident_id:disabled {
    opacity: 0.6;
    cursor: wait;
}

.alert {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>