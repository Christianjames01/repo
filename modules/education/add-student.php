<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Add Student';

// Get all residents for selection
$residents_sql = "SELECT resident_id, first_name, last_name, middle_name, birth_date, gender, contact_number, email, address 
                  FROM tbl_residents 
                  ORDER BY last_name, first_name";
$residents = fetchAll($conn, $residents_sql);

// Get available scholarships
$scholarships_sql = "SELECT * FROM tbl_education_scholarships 
                     WHERE status = 'active' 
                     ORDER BY scholarship_name";
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
        // Determine initial scholarship status
        $scholarship_status = 'pending';
        $scholarship_amount = 0;
        $approval_date = null;
        $approved_by = null;
        
        // If admin is directly approving
        if (isset($_POST['approve_now']) && $_POST['approve_now'] == '1') {
            $scholarship_status = 'active';
            $approval_date = date('Y-m-d H:i:s');
            $approved_by = getCurrentUserId();
            
            // Get scholarship amount if scholarship type is selected
            if (!empty($_POST['scholarship_type'])) {
                $scholarship_sql = "SELECT amount FROM tbl_education_scholarships WHERE scholarship_name = ?";
                $scholarship_data = fetchOne($conn, $scholarship_sql, [$_POST['scholarship_type']], 's');
                if ($scholarship_data) {
                    $scholarship_amount = $scholarship_data['amount'];
                }
            } elseif (!empty($_POST['scholarship_amount'])) {
                $scholarship_amount = $_POST['scholarship_amount'];
            }
        }
        
        $sql = "INSERT INTO tbl_education_students (
            resident_id, first_name, last_name, middle_name, birth_date, gender,
            contact_number, email, address, school_name, school_address, grade_level,
            course, school_year, gwa_grade, parent_guardian_name, parent_contact,
            parent_occupation, monthly_income, scholarship_type, scholarship_status,
            scholarship_amount, scholarship_start_date, scholarship_end_date,
            application_date, approval_date, approved_by, status, remarks
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";
        
        $params = [
            !empty($_POST['resident_id']) ? $_POST['resident_id'] : null,
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
            $_POST['scholarship_type'] ?? null,
            $scholarship_status,
            $scholarship_amount,
            $_POST['scholarship_start_date'] ?? null,
            $_POST['scholarship_end_date'] ?? null,
            $approval_date,
            $approved_by,
            $_POST['status'] ?? 'active',
            $_POST['remarks'] ?? null
        ];
        
        $types = "isssssssssssssdsssdsdssssiss";
        
        if (executeQuery($conn, $sql, $params, $types)) {
            setMessage('Student record added successfully!', 'success');
            header('Location: manage-students.php');
            exit();
        } else {
            $errors[] = "Failed to add student record. Please try again.";
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            setMessage($error, 'error');
        }
    }
}

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
}

.card-body {
    padding: 1.75rem;
}

/* Form Sections */
.form-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
    border: 2px solid #e9ecef;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #495057;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid #0d6efd;
    display: flex;
    align-items: center;
}

/* Form Enhancements */
.form-label {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
}

.required-field::after {
    content: " *";
    color: #dc3545;
    font-weight: 700;
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

/* Enhanced Buttons */
.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-lg {
    padding: 0.875rem 2.5rem;
    font-size: 1.1rem;
}

/* Alert Enhancements */
.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-success {
    background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
    border-left-color: #198754;
}

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

/* Quick Fill Section */
.quick-fill-section {
    background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
    border: 2px solid #ffc107;
    border-radius: var(--border-radius);
    padding: 1.5rem;
}
</style>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <!-- Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold">
                                <i class="fas fa-user-plus me-2 text-primary"></i>Add Student Record
                            </h2>
                            <p class="text-muted mb-0">Create a new student record in the education system</p>
                        </div>
                        <a href="manage-students.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Students
                        </a>
                    </div>
                </div>
            </div>

            <?php echo displayMessage(); ?>

            <!-- Quick Fill from Resident -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-bolt me-2 text-warning"></i>Quick Fill from Resident Database</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Select Resident (Optional)</label>
                            <select class="form-select" id="residentSelect" onchange="fillFromResident()">
                                <option value="">Choose a resident to auto-fill information...</option>
                                <?php foreach ($residents as $resident): ?>
                                    <option value="<?php echo $resident['resident_id']; ?>" 
                                            data-firstname="<?php echo htmlspecialchars($resident['first_name']); ?>"
                                            data-middlename="<?php echo htmlspecialchars($resident['middle_name'] ?? ''); ?>"
                                            data-lastname="<?php echo htmlspecialchars($resident['last_name']); ?>"
                                            data-birthdate="<?php echo $resident['birth_date']; ?>"
                                            data-gender="<?php echo $resident['gender']; ?>"
                                            data-contact="<?php echo htmlspecialchars($resident['contact_number']); ?>"
                                            data-email="<?php echo htmlspecialchars($resident['email'] ?? ''); ?>"
                                            data-address="<?php echo htmlspecialchars($resident['address']); ?>">
                                        <?php echo htmlspecialchars($resident['last_name'] . ', ' . $resident['first_name'] . ' ' . ($resident['middle_name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Selecting a resident will auto-fill personal information below</small>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary w-100" onclick="clearForm()">
                                <i class="fas fa-eraser me-1"></i>Clear Form
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Application Form -->
            <form method="POST" id="studentForm">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        
                        <!-- Hidden resident_id field -->
                        <input type="hidden" name="resident_id" id="residentId">
                        
                        <!-- Personal Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-user me-2"></i>Personal Information
                            </h5>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">First Name</label>
                                    <input type="text" name="first_name" id="firstName" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" name="middle_name" id="middleName" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Last Name</label>
                                    <input type="text" name="last_name" id="lastName" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Birth Date</label>
                                    <input type="date" name="birth_date" id="birthDate" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Gender</label>
                                    <select name="gender" id="gender" class="form-select" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Contact Number</label>
                                    <input type="tel" name="contact_number" id="contactNumber" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" id="email" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Complete Address</label>
                                    <textarea name="address" id="address" class="form-control" rows="2" required></textarea>
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
                                    <input type="text" name="course" class="form-control" placeholder="e.g., BS Computer Science">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">School Year</label>
                                    <input type="text" name="school_year" class="form-control" 
                                           placeholder="e.g., 2024-2025" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" required>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">General Weighted Average (GWA)</label>
                                    <input type="number" step="0.01" name="gwa_grade" class="form-control"
                                           placeholder="e.g., 90.50" min="60" max="100">
                                    <small class="text-muted">Enter the student's latest GWA or general average</small>
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
                                    <input type="text" name="parent_occupation" class="form-control" placeholder="e.g., Teacher, Driver">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Monthly Family Income</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" name="monthly_income" class="form-control" placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Scholarship Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-award me-2"></i>Scholarship Information
                            </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Scholarship Program</label>
                                    <select name="scholarship_type" id="scholarshipType" class="form-select" onchange="updateScholarshipAmount()">
                                        <option value="">None / General Application</option>
                                        <?php foreach ($scholarships as $scholarship): ?>
                                            <option value="<?php echo htmlspecialchars($scholarship['scholarship_name']); ?>" 
                                                    data-amount="<?php echo $scholarship['amount']; ?>">
                                                <?php echo htmlspecialchars($scholarship['scholarship_name']); ?>
                                                - ₱<?php echo number_format($scholarship['amount'], 2); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Scholarship Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" name="scholarship_amount" id="scholarshipAmount" 
                                               class="form-control" placeholder="0.00">
                                    </div>
                                    <small class="text-muted">Auto-filled when scholarship is selected</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Scholarship Start Date</label>
                                    <input type="date" name="scholarship_start_date" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Scholarship End Date</label>
                                    <input type="date" name="scholarship_end_date" class="form-control">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="approve_now" value="1" id="approveNow">
                                        <label class="form-check-label" for="approveNow">
                                            <strong>Approve scholarship immediately</strong>
                                            <small class="d-block text-muted">Check this to set scholarship status as "Active" instead of "Pending"</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-info-circle me-2"></i>Additional Information
                            </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Student Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="graduated">Graduated</option>
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Remarks / Notes</label>
                                    <textarea name="remarks" class="form-control" rows="3" 
                                              placeholder="Any additional notes or comments about this student..."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="text-center pt-3">
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="fas fa-save me-2"></i>Add Student Record
                            </button>
                            <a href="manage-students.php" class="btn btn-outline-secondary btn-lg px-5 ms-2">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Fill form from selected resident
function fillFromResident() {
    const select = document.getElementById('residentSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (select.value) {
        document.getElementById('residentId').value = select.value;
        document.getElementById('firstName').value = selectedOption.dataset.firstname || '';
        document.getElementById('middleName').value = selectedOption.dataset.middlename || '';
        document.getElementById('lastName').value = selectedOption.dataset.lastname || '';
        document.getElementById('birthDate').value = selectedOption.dataset.birthdate || '';
        document.getElementById('gender').value = selectedOption.dataset.gender || '';
        document.getElementById('contactNumber').value = selectedOption.dataset.contact || '';
        document.getElementById('email').value = selectedOption.dataset.email || '';
        document.getElementById('address').value = selectedOption.dataset.address || '';
    } else {
        clearForm();
    }
}

// Clear the form
function clearForm() {
    document.getElementById('studentForm').reset();
    document.getElementById('residentSelect').value = '';
    document.getElementById('residentId').value = '';
}

// Update scholarship amount when scholarship is selected
function updateScholarshipAmount() {
    const select = document.getElementById('scholarshipType');
    const selectedOption = select.options[select.selectedIndex];
    const amountInput = document.getElementById('scholarshipAmount');
    
    if (select.value && selectedOption.dataset.amount) {
        amountInput.value = selectedOption.dataset.amount;
    } else {
        amountInput.value = '';
    }
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php
$conn->close();
include '../../includes/footer.php';
?>