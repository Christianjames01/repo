<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = null;

// Get resident ID
if ($user_role === 'Resident') {
    $user_id = getCurrentUserId();
    $user_sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
    $user_data = fetchOne($conn, $user_sql, [$user_id], 'i');
    $resident_id = $user_data['resident_id'] ?? null;
}

$page_title = 'Apply for Scholarship';

// Get resident info
$resident_info = null;
if ($resident_id) {
    $resident_sql = "SELECT * FROM tbl_residents WHERE resident_id = ?";
    $resident_info = fetchOne($conn, $resident_sql, [$resident_id], 'i');
}

// Get available scholarships
$scholarships_sql = "SELECT * FROM tbl_education_scholarships 
                     WHERE status = 'active' 
                     AND (application_end IS NULL OR application_end >= CURDATE())";
$scholarships = fetchAll($conn, $scholarships_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    if (empty($_POST['first_name'])) $errors[] = "First name is required";
    if (empty($_POST['last_name'])) $errors[] = "Last name is required";
    if (empty($_POST['birth_date'])) $errors[] = "Birth date is required";
    if (empty($_POST['gender'])) $errors[] = "Gender is required";
    if (empty($_POST['contact_number'])) $errors[] = "Contact number is required";
    if (empty($_POST['address'])) $errors[] = "Address is required";
    if (empty($_POST['school_name'])) $errors[] = "School name is required";
    if (empty($_POST['grade_level'])) $errors[] = "Grade level is required";
    if (empty($_POST['school_year'])) $errors[] = "School year is required";
    if (empty($_POST['parent_guardian_name'])) $errors[] = "Parent/Guardian name is required";
    if (empty($_POST['parent_contact'])) $errors[] = "Parent/Guardian contact is required";
    
    if (empty($errors)) {
        $sql = "INSERT INTO tbl_education_students (
            resident_id, first_name, last_name, middle_name, birth_date, gender,
            contact_number, email, address, school_name, school_address, grade_level,
            course, school_year, gwa_grade, parent_guardian_name, parent_contact,
            parent_occupation, monthly_income, scholarship_type, scholarship_status,
            application_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $params = [
            $resident_id,
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['middle_name'] ?? null,
            $_POST['birth_date'],
            $_POST['gender'],
            $_POST['contact_number'],
            $_POST['email'] ?? null,
            $_POST['address'],
            $_POST['school_name'],
            $_POST['school_address'] ?? null,
            $_POST['grade_level'],
            $_POST['course'] ?? null,
            $_POST['school_year'],
            $_POST['gwa_grade'] ?? null,
            $_POST['parent_guardian_name'],
            $_POST['parent_contact'],
            $_POST['parent_occupation'] ?? null,
            $_POST['monthly_income'] ?? null,
            $_POST['scholarship_type'] ?? null
        ];
        
        $types = "isssssssssssssdsssds";
        
        if (executeQuery($conn, $sql, $params, $types)) {
            setMessage('Scholarship application submitted successfully! Please wait for admin approval.', 'success');
            header('Location: student-portal.php');
            exit();
        } else {
            $errors[] = "Failed to submit application. Please try again.";
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            setMessage($error, 'error');
        }
    }
}

include '../../../includes/header.php';
?>

<style>
.form-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
}
.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #007bff;
}
.required-field::after {
    content: " *";
    color: red;
}
</style>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <!-- Header -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1">
                                <i class="fas fa-graduation-cap me-2 text-primary"></i>Scholarship Application Form
                            </h3>
                            <p class="text-muted mb-0">Complete all required fields marked with *</p>
                        </div>
                        <a href="student-portal.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    </div>
                </div>
            </div>

            <?php echo displayMessage(); ?>

            <!-- Application Form -->
            <form method="POST" enctype="multipart/form-data">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        
                        <!-- Personal Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-user me-2"></i>Personal Information
                            </h5>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">First Name</label>
                                    <input type="text" name="first_name" class="form-control" 
                                           value="<?php echo $resident_info['first_name'] ?? ''; ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" name="middle_name" class="form-control"
                                           value="<?php echo $resident_info['middle_name'] ?? ''; ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Last Name</label>
                                    <input type="text" name="last_name" class="form-control"
                                           value="<?php echo $resident_info['last_name'] ?? ''; ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Birth Date</label>
                                    <input type="date" name="birth_date" class="form-control"
                                           value="<?php echo $resident_info['birth_date'] ?? ''; ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Gender</label>
                                    <select name="gender" class="form-select" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($resident_info['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($resident_info['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Contact Number</label>
                                    <input type="tel" name="contact_number" class="form-control"
                                           value="<?php echo $resident_info['contact_number'] ?? ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo $resident_info['email'] ?? ''; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Complete Address</label>
                                    <textarea name="address" class="form-control" rows="2" required><?php echo $resident_info['address'] ?? ''; ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Educational Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-school me-2"></i>Educational Information
                            </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">School Name</label>
                                    <input type="text" name="school_name" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">School Address</label>
                                    <input type="text" name="school_address" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Grade Level / Year</label>
                                    <select name="grade_level" class="form-select" required>
                                        <option value="">Select Level</option>
                                        <option value="Grade 7">Grade 7</option>
                                        <option value="Grade 8">Grade 8</option>
                                        <option value="Grade 9">Grade 9</option>
                                        <option value="Grade 10">Grade 10</option>
                                        <option value="Grade 11">Grade 11</option>
                                        <option value="Grade 12">Grade 12</option>
                                        <option value="1st Year College">1st Year College</option>
                                        <option value="2nd Year College">2nd Year College</option>
                                        <option value="3rd Year College">3rd Year College</option>
                                        <option value="4th Year College">4th Year College</option>
                                        <option value="5th Year College">5th Year College</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Course (For College)</label>
                                    <input type="text" name="course" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">School Year</label>
                                    <input type="text" name="school_year" class="form-control" 
                                           placeholder="e.g., 2024-2025" required>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">General Weighted Average (GWA)</label>
                                    <input type="number" step="0.01" name="gwa_grade" class="form-control"
                                           placeholder="e.g., 90.50">
                                    <small class="text-muted">Enter your latest GWA or general average</small>
                                </div>
                            </div>
                        </div>

                        <!-- Parent/Guardian Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-users me-2"></i>Parent/Guardian Information
                            </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Parent/Guardian Name</label>
                                    <input type="text" name="parent_guardian_name" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Parent/Guardian Contact</label>
                                    <input type="tel" name="parent_contact" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Occupation</label>
                                    <input type="text" name="parent_occupation" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Monthly Family Income</label>
                                    <input type="number" step="0.01" name="monthly_income" class="form-control"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>

                        <!-- Scholarship Selection -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-award me-2"></i>Scholarship Selection
                            </h5>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Select Scholarship Program (Optional)</label>
                                    <select name="scholarship_type" class="form-select">
                                        <option value="">General Application</option>
                                        <?php foreach ($scholarships as $scholarship): ?>
                                            <option value="<?php echo htmlspecialchars($scholarship['scholarship_name']); ?>">
                                                <?php echo htmlspecialchars($scholarship['scholarship_name']); ?>
                                                - ₱<?php echo number_format($scholarship['amount'], 2); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Leave blank for general scholarship application</small>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="text-center pt-3">
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="fas fa-paper-plane me-2"></i>Submit Application
                            </button>
                            <a href="student-portal.php" class="btn btn-outline-secondary btn-lg px-5 ms-2">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$conn->close();
include '../../../includes/footer.php';
?>