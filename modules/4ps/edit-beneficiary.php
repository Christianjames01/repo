<?php
// Include config which handles session, database, and functions
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and is Super Admin
if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();
$page_title = 'Edit 4Ps Beneficiary';
$success_message = '';
$error_message = '';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: beneficiaries.php?error=' . urlencode('Beneficiary ID is required'));
    exit();
}

$beneficiary_id = intval($_GET['id']);

// Fetch beneficiary data with extended details
$query = "SELECT 
    b.*,
    e.*
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

// Check if extended details exist
$has_extended_details = !empty($beneficiary['detail_id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Personal Information
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
    $photo_filename = $beneficiary['id_picture']; // Keep existing photo by default
    if (isset($_FILES['id_picture']) && $_FILES['id_picture']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/4ps/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['id_picture']['name'], PATHINFO_EXTENSION);
        $new_photo_filename = 'applicant_' . time() . '_' . uniqid() . '.' . $file_extension;
        
        if (move_uploaded_file($_FILES['id_picture']['tmp_name'], $upload_dir . $new_photo_filename)) {
            // Delete old photo if exists
            if (!empty($photo_filename) && file_exists($upload_dir . $photo_filename)) {
                unlink($upload_dir . $photo_filename);
            }
            $photo_filename = $new_photo_filename;
        } else {
            $error_message = "Error uploading photo file.";
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update main beneficiary record
        $update_main = "UPDATE tbl_4ps_beneficiaries 
                       SET household_id = ?, 
                           grantee_name = ?, 
                           date_registered = ?, 
                           status = ?, 
                           set_number = ?, 
                           compliance_status = ?, 
                           monthly_grant = ?, 
                           remarks = ?,
                           updated_at = NOW()
                       WHERE beneficiary_id = ?";
        
        $stmt_main = $conn->prepare($update_main);
        $stmt_main->bind_param("ssssssdsi", 
            $household_id,
            $grantee_name,
            $date_registered,
            $status,
            $set_number,
            $compliance_status,
            $monthly_grant,
            $remarks,
            $beneficiary_id
        );
        
        if (!$stmt_main->execute()) {
            throw new Exception("Error updating beneficiary: " . $stmt_main->error);
        }
        $stmt_main->close();
        
        // Generate control number if not exists
        $ctrl_no = !empty($beneficiary['ctrl_no']) ? $beneficiary['ctrl_no'] : 'CTRL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Update or Insert extended details
        if ($has_extended_details) {
            // UPDATE existing extended details
            $update_ext = "UPDATE tbl_4ps_extended_details 
                          SET last_name = ?, 
                              first_name = ?, 
                              middle_name = ?, 
                              ext_name = ?,
                              permanent_address = ?, 
                              street = ?, 
                              barangay = ?, 
                              town = ?, 
                              province = ?,
                              birthplace = ?, 
                              mobile_phone = ?, 
                              birthday = ?, 
                              civil_status = ?, 
                              gender = ?,
                              father_full_name = ?, 
                              father_address = ?, 
                              father_education = ?, 
                              father_income = ?,
                              mother_full_name = ?, 
                              mother_address = ?, 
                              mother_education = ?, 
                              mother_income = ?,
                              secondary_school = ?, 
                              degree_program = ?, 
                              year_level = ?,
                              reference_1 = ?, 
                              reference_2 = ?, 
                              reference_3 = ?,
                              id_picture = ?,
                              ctrl_no = ?
                          WHERE beneficiary_id = ?";
            
            $stmt_ext = $conn->prepare($update_ext);
            $stmt_ext->bind_param("sssssssssssssssssdsssdssssssssi",
                $last_name,
                $first_name,
                $middle_name,
                $ext,
                $permanent_address,
                $street,
                $brgy,
                $town,
                $province,
                $birthplace,
                $mobile_phone,
                $birthday,
                $civil_status,
                $gender,
                $father_full_name,
                $father_address,
                $father_education,
                $father_income,
                $mother_full_name,
                $mother_address,
                $mother_education,
                $mother_income,
                $secondary_school,
                $degree_program,
                $year_level,
                $reference_1,
                $reference_2,
                $reference_3,
                $photo_filename,
                $ctrl_no,
                $beneficiary_id
            );
            
            if (!$stmt_ext->execute()) {
                throw new Exception("Error updating extended details: " . $stmt_ext->error);
            }
            $stmt_ext->close();
            
        } else {
            // INSERT new extended details
            $insert_ext = "INSERT INTO tbl_4ps_extended_details 
                          (beneficiary_id, last_name, first_name, middle_name, ext_name,
                           permanent_address, street, barangay, town, province,
                           birthplace, mobile_phone, birthday, civil_status, gender,
                           father_full_name, father_address, father_education, father_income,
                           mother_full_name, mother_address, mother_education, mother_income,
                           secondary_school, degree_program, year_level,
                           reference_1, reference_2, reference_3,
                           id_picture, ctrl_no) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt_ext = $conn->prepare($insert_ext);
            $stmt_ext->bind_param("issssssssssssssssdsssdsssssssss",
                $beneficiary_id,
                $last_name,
                $first_name,
                $middle_name,
                $ext,
                $permanent_address,
                $street,
                $brgy,
                $town,
                $province,
                $birthplace,
                $mobile_phone,
                $birthday,
                $civil_status,
                $gender,
                $father_full_name,
                $father_address,
                $father_education,
                $father_income,
                $mother_full_name,
                $mother_address,
                $mother_education,
                $mother_income,
                $secondary_school,
                $degree_program,
                $year_level,
                $reference_1,
                $reference_2,
                $reference_3,
                $photo_filename,
                $ctrl_no
            );
            
            if (!$stmt_ext->execute()) {
                throw new Exception("Error inserting extended details: " . $stmt_ext->error);
            }
            $stmt_ext->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "Beneficiary updated successfully!";
        
        // Redirect back to beneficiaries page
        header("Location: beneficiaries-debug.php?success=" . urlencode($success_message));
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Update failed: " . $e->getMessage();
        error_log("ERROR during update: " . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-edit me-2"></i>Edit 4Ps Beneficiary</h2>
            <p class="text-muted">Update beneficiary information and extended details</p>
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

    <?php if (!$has_extended_details): ?>
    <div class="alert alert-warning">
        <h5><i class="fas fa-exclamation-triangle me-2"></i>Missing Extended Details</h5>
        <p class="mb-0">This beneficiary is missing personal information. Please complete the form below to add their details.</p>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <form method="POST" action="" id="editBeneficiaryForm" enctype="multipart/form-data">
                
                <!-- Personal Information -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>PERSONAL INFORMATION</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" 
                                       value="<?php echo htmlspecialchars($beneficiary['last_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" 
                                       value="<?php echo htmlspecialchars($beneficiary['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" 
                                       value="<?php echo htmlspecialchars($beneficiary['middle_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Ext. (Jr., Sr., III)</label>
                                <input type="text" class="form-control" name="ext" 
                                       value="<?php echo htmlspecialchars($beneficiary['ext_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Permanent Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="permanent_address" 
                                       value="<?php echo htmlspecialchars($beneficiary['permanent_address'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Street</label>
                                <input type="text" class="form-control" name="street" 
                                       value="<?php echo htmlspecialchars($beneficiary['street'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Barangay <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="brgy" 
                                       value="<?php echo htmlspecialchars($beneficiary['barangay'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Town/City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="town" 
                                       value="<?php echo htmlspecialchars($beneficiary['town'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Province <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="province" 
                                       value="<?php echo htmlspecialchars($beneficiary['province'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Birthplace <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="birthplace" 
                                       value="<?php echo htmlspecialchars($beneficiary['birthplace'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mobile/Phone No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="mobile_phone" 
                                       value="<?php echo htmlspecialchars($beneficiary['mobile_phone'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Birthday <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="birthday" 
                                       value="<?php echo htmlspecialchars($beneficiary['birthday'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Civil Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="civil_status" required>
                                    <option value="">-- Select --</option>
                                    <option value="Single" <?php echo ($beneficiary['civil_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married" <?php echo ($beneficiary['civil_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                    <option value="Widowed" <?php echo ($beneficiary['civil_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                    <option value="Separated" <?php echo ($beneficiary['civil_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select class="form-select" name="gender" required>
                                    <option value="">-- Select --</option>
                                    <option value="Male" <?php echo ($beneficiary['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($beneficiary['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
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
                                <input type="text" class="form-control" name="father_full_name" 
                                       value="<?php echo htmlspecialchars($beneficiary['father_full_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="father_address" 
                                       value="<?php echo htmlspecialchars($beneficiary['father_address'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Educational Attainment</label>
                                <select class="form-select" name="father_education">
                                    <option value="">-- Select --</option>
                                    <option value="Elementary" <?php echo ($beneficiary['father_education'] ?? '') == 'Elementary' ? 'selected' : ''; ?>>Elementary</option>
                                    <option value="High School" <?php echo ($beneficiary['father_education'] ?? '') == 'High School' ? 'selected' : ''; ?>>High School</option>
                                    <option value="College" <?php echo ($beneficiary['father_education'] ?? '') == 'College' ? 'selected' : ''; ?>>College</option>
                                    <option value="Vocational" <?php echo ($beneficiary['father_education'] ?? '') == 'Vocational' ? 'selected' : ''; ?>>Vocational</option>
                                    <option value="Post Graduate" <?php echo ($beneficiary['father_education'] ?? '') == 'Post Graduate' ? 'selected' : ''; ?>>Post Graduate</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monthly Income (₱)</label>
                                <input type="number" class="form-control" name="father_income" step="0.01" min="0" 
                                       value="<?php echo htmlspecialchars($beneficiary['father_income'] ?? '0'); ?>">
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
                                <input type="text" class="form-control" name="mother_full_name" 
                                       value="<?php echo htmlspecialchars($beneficiary['mother_full_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="mother_address" 
                                       value="<?php echo htmlspecialchars($beneficiary['mother_address'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Educational Attainment</label>
                                <select class="form-select" name="mother_education">
                                    <option value="">-- Select --</option>
                                    <option value="Elementary" <?php echo ($beneficiary['mother_education'] ?? '') == 'Elementary' ? 'selected' : ''; ?>>Elementary</option>
                                    <option value="High School" <?php echo ($beneficiary['mother_education'] ?? '') == 'High School' ? 'selected' : ''; ?>>High School</option>
                                    <option value="College" <?php echo ($beneficiary['mother_education'] ?? '') == 'College' ? 'selected' : ''; ?>>College</option>
                                    <option value="Vocational" <?php echo ($beneficiary['mother_education'] ?? '') == 'Vocational' ? 'selected' : ''; ?>>Vocational</option>
                                    <option value="Post Graduate" <?php echo ($beneficiary['mother_education'] ?? '') == 'Post Graduate' ? 'selected' : ''; ?>>Post Graduate</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monthly Income (₱)</label>
                                <input type="number" class="form-control" name="mother_income" step="0.01" min="0" 
                                       value="<?php echo htmlspecialchars($beneficiary['mother_income'] ?? '0'); ?>">
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
                                <input type="text" class="form-control" name="secondary_school" 
                                       value="<?php echo htmlspecialchars($beneficiary['secondary_school'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Degree Program/Course Taken <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="degree_program" 
                                       value="<?php echo htmlspecialchars($beneficiary['degree_program'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Year Level <span class="text-danger">*</span></label>
                                <select class="form-select" name="year_level" required>
                                    <option value="">-- Select --</option>
                                    <option value="1st Year" <?php echo ($beneficiary['year_level'] ?? '') == '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                                    <option value="2nd Year" <?php echo ($beneficiary['year_level'] ?? '') == '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                                    <option value="3rd Year" <?php echo ($beneficiary['year_level'] ?? '') == '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                                    <option value="4th Year" <?php echo ($beneficiary['year_level'] ?? '') == '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                                    <option value="5th Year" <?php echo ($beneficiary['year_level'] ?? '') == '5th Year' ? 'selected' : ''; ?>>5th Year</option>
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
                                <input type="text" class="form-control" name="reference_1" 
                                       value="<?php echo htmlspecialchars($beneficiary['reference_1'] ?? ''); ?>" 
                                       placeholder="Full Name, Contact Number">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">2. Name & Contact</label>
                                <input type="text" class="form-control" name="reference_2" 
                                       value="<?php echo htmlspecialchars($beneficiary['reference_2'] ?? ''); ?>" 
                                       placeholder="Full Name, Contact Number">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">3. Name & Contact</label>
                                <input type="text" class="form-control" name="reference_3" 
                                       value="<?php echo htmlspecialchars($beneficiary['reference_3'] ?? ''); ?>" 
                                       placeholder="Full Name, Contact Number">
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
                                <input type="text" class="form-control" name="household_id" 
                                       value="<?php echo htmlspecialchars($beneficiary['household_id']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grantee Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="grantee_name" 
                                       value="<?php echo htmlspecialchars($beneficiary['grantee_name']); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date Registered <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="date_registered" 
                                       value="<?php echo htmlspecialchars($beneficiary['date_registered']); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="">-- Select --</option>
                                    <option value="Active" <?php echo $beneficiary['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $beneficiary['status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Suspended" <?php echo $beneficiary['status'] == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="Graduated" <?php echo $beneficiary['status'] == 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Set Number</label>
                                <input type="text" class="form-control" name="set_number" 
                                       value="<?php echo htmlspecialchars($beneficiary['set_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Compliance Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="compliance_status" required>
                                    <option value="">-- Select --</option>
                                    <option value="Compliant" <?php echo $beneficiary['compliance_status'] == 'Compliant' ? 'selected' : ''; ?>>Compliant</option>
                                    <option value="Non-Compliant" <?php echo $beneficiary['compliance_status'] == 'Non-Compliant' ? 'selected' : ''; ?>>Non-Compliant</option>
                                    <option value="Partial" <?php echo $beneficiary['compliance_status'] == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Monthly Grant (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="monthly_grant" 
                                       value="<?php echo htmlspecialchars($beneficiary['monthly_grant']); ?>" 
                                       required step="0.01" min="0">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks" rows="3"><?php echo htmlspecialchars($beneficiary['remarks'] ?? ''); ?></textarea>
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
                                <label class="form-label">Upload New Photo (Optional)</label>
                                <input type="file" class="form-control" name="id_picture" accept="image/*" id="photoInput">
                                <small class="text-muted">Leave empty to keep current photo. Accepted formats: JPG, PNG, GIF (Max 5MB)</small>
                            </div>
                            <div class="col-md-6">
                                <div class="current-photo-container">
                                    <?php if (!empty($beneficiary['id_picture'])): ?>
                                        <label class="form-label">Current Photo:</label>
                                        <div class="current-photo">
                                            <img src="<?php echo BASE_URL; ?>/uploads/4ps/<?php echo htmlspecialchars($beneficiary['id_picture']); ?>" 
                                                 alt="Current Photo" 
                                                 id="currentPhoto"
                                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22150%22 height=%22150%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23ddd%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2218%22 fill=%22%23999%22%3ENo Photo%3C/text%3E%3C/svg%3E'">
                                        </div>
                                    <?php else: ?>
                                        <div class="no-photo">
                                            <i class="fas fa-user fa-3x text-muted"></i>
                                            <p class="text-muted mt-2">No photo uploaded</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="id-picture-preview" id="imagePreview" style="display: none;">
                                    <label class="form-label">New Photo Preview:</label>
                                    <img id="previewImage" src="" alt="Preview">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Control Number Display (if exists) -->
                <?php if (!empty($beneficiary['ctrl_no'])): ?>
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <label class="form-label"><strong>Control Number:</strong></label>
                                <p class="h5 text-primary"><?php echo htmlspecialchars($beneficiary['ctrl_no']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between mb-5">
                    <a href="beneficiaries-debug.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-arrow-left me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const photoInput = document.getElementById('photoInput');
    const imagePreview = document.getElementById('imagePreview');
    const previewImage = document.getElementById('previewImage');
    const currentPhoto = document.querySelector('.current-photo-container');
    
    if (photoInput) {
        photoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            
            if (file) {
                // Validate file size
                if (file.size > 5242880) { // 5MB
                    alert('File size must be less than 5MB');
                    this.value = '';
                    imagePreview.style.display = 'none';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPG, PNG, or GIF files are allowed');
                    this.value = '';
                    imagePreview.style.display = 'none';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    imagePreview.style.display = 'block';
                    if (currentPhoto) {
                        currentPhoto.style.opacity = '0.5';
                    }
                };
                reader.readAsDataURL(file);
            } else {
                imagePreview.style.display = 'none';
                if (currentPhoto) {
                    currentPhoto.style.opacity = '1';
                }
            }
        });
    }
    
    // Form submission confirmation
    const form = document.getElementById('editBeneficiaryForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            }
        });
    }
});
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

.current-photo, .no-photo {
    border: 2px dashed #cbd5e0;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    min-height: 180px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.current-photo img {
    max-width: 150px;
    max-height: 150px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.id-picture-preview {
    border: 2px solid #48bb78;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    background: #f0fff4;
}

.id-picture-preview img {
    max-width: 150px;
    max-height: 150px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.no-photo {
    background: #f7fafc;
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