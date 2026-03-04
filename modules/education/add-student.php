<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Add Student';

$residents_sql = "SELECT resident_id, first_name, last_name, middle_name, birth_date, gender, contact_number, email, address 
                  FROM tbl_residents ORDER BY last_name, first_name";
$residents = fetchAll($conn, $residents_sql);

$scholarships_sql = "SELECT * FROM tbl_education_scholarships WHERE status = 'active' ORDER BY scholarship_name";
$scholarships = fetchAll($conn, $scholarships_sql);

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (empty($_POST['first_name']))           $errors[] = "First name is required";
    if (empty($_POST['last_name']))            $errors[] = "Last name is required";
    if (empty($_POST['birth_date']))           $errors[] = "Birth date is required";
    if (empty($_POST['gender']))               $errors[] = "Gender is required";
    if (empty($_POST['contact_number']))       $errors[] = "Contact number is required";
    if (empty($_POST['address']))              $errors[] = "Address is required";
    if (empty($_POST['school_name']))          $errors[] = "School name is required";
    if (empty($_POST['grade_level']))          $errors[] = "Grade level is required";
    if (empty($_POST['school_year']))          $errors[] = "School year is required";
    if (empty($_POST['parent_guardian_name'])) $errors[] = "Parent/Guardian name is required";
    if (empty($_POST['parent_contact']))       $errors[] = "Parent/Guardian contact is required";

    if (empty($errors)) {
        $scholarship_status = 'pending';
        $scholarship_amount = 0;
        $approval_date = null;
        $approved_by   = null;

        if (isset($_POST['approve_now']) && $_POST['approve_now'] == '1') {
            $scholarship_status = 'active';
            $approval_date      = date('Y-m-d H:i:s');
            $approved_by        = getCurrentUserId();
            if (!empty($_POST['scholarship_type'])) {
                $sd = fetchOne($conn, "SELECT amount FROM tbl_education_scholarships WHERE scholarship_name = ?", [$_POST['scholarship_type']], 's');
                if ($sd) $scholarship_amount = $sd['amount'];
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
            $_POST['first_name'], $_POST['last_name'], $_POST['middle_name'] ?? null,
            $_POST['birth_date'], $_POST['gender'], $_POST['contact_number'],
            $_POST['email'] ?? null, $_POST['address'], $_POST['school_name'],
            $_POST['school_address'] ?? null, $_POST['grade_level'],
            $_POST['course'] ?? null, $_POST['school_year'], $_POST['gwa_grade'] ?? null,
            $_POST['parent_guardian_name'], $_POST['parent_contact'],
            $_POST['parent_occupation'] ?? null, $_POST['monthly_income'] ?? null,
            $_POST['scholarship_type'] ?? null, $scholarship_status, $scholarship_amount,
            $_POST['scholarship_start_date'] ?? null, $_POST['scholarship_end_date'] ?? null,
            $approval_date, $approved_by, $_POST['status'] ?? 'active', $_POST['remarks'] ?? null
        ];

        if (executeQuery($conn, $sql, $params, "isssssssssssssdsssdsdssssiss")) {
            $_SESSION['temp_success'] = 'Student record added successfully!';
            header('Location: manage-students.php');
            exit();
        } else {
            $error_message = "Failed to add student record. Please try again.";
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                <i class="fas fa-user-plus" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Add Student Record</h1>
                <p class="db-hero__sub">Create a new student record in the education system</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="manage-students.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>
        </div>
    </div>
</div>

<?php if ($error_message): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo $error_message; ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>


<!-- Quick Fill -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bolt"></i></span>
            <h2>Quick Fill from Resident Database</h2>
        </div>
    </div>
    <div style="padding:18px 22px;">
        <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
            <div class="db-field" style="margin:0;">
                <label>Select Resident <span style="color:var(--db-muted);font-weight:400;">(Optional)</span></label>
                <select class="db-input" id="residentSelect" onchange="fillFromResident()">
                    <option value="">Choose a resident to auto-fill information...</option>
                    <?php foreach ($residents as $r): ?>
                    <option value="<?php echo $r['resident_id']; ?>"
                            data-firstname="<?php echo htmlspecialchars($r['first_name']); ?>"
                            data-middlename="<?php echo htmlspecialchars($r['middle_name'] ?? ''); ?>"
                            data-lastname="<?php echo htmlspecialchars($r['last_name']); ?>"
                            data-birthdate="<?php echo $r['birth_date']; ?>"
                            data-gender="<?php echo $r['gender']; ?>"
                            data-contact="<?php echo htmlspecialchars($r['contact_number']); ?>"
                            data-email="<?php echo htmlspecialchars($r['email'] ?? ''); ?>"
                            data-address="<?php echo htmlspecialchars($r['address']); ?>">
                        <?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? '')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--db-muted);font-size:11.5px;">Selecting a resident will auto-fill personal information below</small>
            </div>
            <button type="button" class="db-btn db-btn--ghost" onclick="clearStudentForm()" style="flex-shrink:0;">
                <i class="fas fa-eraser"></i> Clear Form
            </button>
        </div>
    </div>
</div>


<form method="POST" id="studentForm">
<input type="hidden" name="resident_id" id="residentId">

<!-- Personal Information -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-user"></i></span>
            <h2>Personal Information</h2>
        </div>
        <span class="db-badge db-badge--muted"><i class="fas fa-asterisk" style="font-size:8px;"></i> Required fields</span>
    </div>
    <div style="padding:22px;">
        <div class="db-field-row" style="grid-template-columns:1fr 1fr 1fr;">
            <div class="db-field">
                <label>First Name <span class="req">*</span></label>
                <input type="text" name="first_name" id="firstName" class="db-input" required>
            </div>
            <div class="db-field">
                <label>Middle Name</label>
                <input type="text" name="middle_name" id="middleName" class="db-input">
            </div>
            <div class="db-field">
                <label>Last Name <span class="req">*</span></label>
                <input type="text" name="last_name" id="lastName" class="db-input" required>
            </div>
        </div>
        <div class="db-field-row">
            <div class="db-field">
                <label>Birth Date <span class="req">*</span></label>
                <input type="date" name="birth_date" id="birthDate" class="db-input" required>
            </div>
            <div class="db-field">
                <label>Gender <span class="req">*</span></label>
                <select name="gender" id="gender" class="db-input" required>
                    <option value="">Select Gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
        </div>
        <div class="db-field-row">
            <div class="db-field">
                <label>Contact Number <span class="req">*</span></label>
                <input type="tel" name="contact_number" id="contactNumber" class="db-input" required>
            </div>
            <div class="db-field">
                <label>Email Address</label>
                <input type="email" name="email" id="email" class="db-input">
            </div>
        </div>
        <div class="db-field">
            <label>Complete Address <span class="req">*</span></label>
            <textarea name="address" id="address" class="db-input" rows="2" required></textarea>
        </div>
    </div>
</div>

<!-- Educational Information -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-school"></i></span>
            <h2>Educational Information</h2>
        </div>
    </div>
    <div style="padding:22px;">
        <div class="db-field-row">
            <div class="db-field">
                <label>School Name <span class="req">*</span></label>
                <input type="text" name="school_name" class="db-input" required>
            </div>
            <div class="db-field">
                <label>School Address</label>
                <input type="text" name="school_address" class="db-input">
            </div>
        </div>
        <div class="db-field-row" style="grid-template-columns:1fr 1fr 1fr;">
            <div class="db-field">
                <label>Grade Level / Year <span class="req">*</span></label>
                <select name="grade_level" class="db-input" required>
                    <option value="">Select Level</option>
                    <?php foreach (['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12','1st Year College','2nd Year College','3rd Year College','4th Year College','5th Year College'] as $l): ?>
                    <option value="<?php echo $l; ?>"><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="db-field">
                <label>Course <span style="color:var(--db-muted);font-weight:400;">(College)</span></label>
                <input type="text" name="course" class="db-input" placeholder="e.g. BS Computer Science">
            </div>
            <div class="db-field">
                <label>School Year <span class="req">*</span></label>
                <input type="text" name="school_year" class="db-input"
                       placeholder="e.g. 2024-2025"
                       value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" required>
            </div>
        </div>
        <div class="db-field" style="max-width:260px;">
            <label>General Weighted Average (GWA)</label>
            <input type="number" step="0.01" min="60" max="100" name="gwa_grade" class="db-input" placeholder="e.g. 90.50">
        </div>
    </div>
</div>

<!-- Parent / Guardian Information -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-users"></i></span>
            <h2>Parent / Guardian Information</h2>
        </div>
    </div>
    <div style="padding:22px;">
        <div class="db-field-row">
            <div class="db-field">
                <label>Parent/Guardian Name <span class="req">*</span></label>
                <input type="text" name="parent_guardian_name" class="db-input" required>
            </div>
            <div class="db-field">
                <label>Contact Number <span class="req">*</span></label>
                <input type="tel" name="parent_contact" class="db-input" required>
            </div>
        </div>
        <div class="db-field-row">
            <div class="db-field">
                <label>Occupation</label>
                <input type="text" name="parent_occupation" class="db-input" placeholder="e.g. Teacher, Driver">
            </div>
            <div class="db-field">
                <label>Monthly Family Income</label>
                <div style="display:flex;">
                    <span style="padding:9px 13px;background:var(--db-surf2);border:1.5px solid var(--db-border);border-right:none;border-radius:var(--db-radius-sm) 0 0 var(--db-radius-sm);font-weight:700;color:var(--db-teal);font-size:14px;">₱</span>
                    <input type="number" step="0.01" name="monthly_income" class="db-input" placeholder="0.00"
                           style="border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scholarship Information -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-award"></i></span>
            <h2>Scholarship Information</h2>
        </div>
    </div>
    <div style="padding:22px;">
        <div class="db-field-row">
            <div class="db-field">
                <label>Scholarship Program</label>
                <select name="scholarship_type" id="scholarshipType" class="db-input" onchange="updateScholarshipAmount()">
                    <option value="">None / General Application</option>
                    <?php foreach ($scholarships as $s): ?>
                    <option value="<?php echo htmlspecialchars($s['scholarship_name']); ?>"
                            data-amount="<?php echo $s['amount']; ?>">
                        <?php echo htmlspecialchars($s['scholarship_name']); ?> — ₱<?php echo number_format($s['amount'], 2); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="db-field">
                <label>Scholarship Amount</label>
                <div style="display:flex;">
                    <span style="padding:9px 13px;background:var(--db-surf2);border:1.5px solid var(--db-border);border-right:none;border-radius:var(--db-radius-sm) 0 0 var(--db-radius-sm);font-weight:700;color:var(--db-teal);font-size:14px;">₱</span>
                    <input type="number" step="0.01" name="scholarship_amount" id="scholarshipAmount" class="db-input" placeholder="0.00"
                           style="border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;">
                </div>
                <small style="color:var(--db-muted);font-size:11.5px;">Auto-filled when scholarship is selected</small>
            </div>
        </div>
        <div class="db-field-row">
            <div class="db-field">
                <label>Scholarship Start Date</label>
                <input type="date" name="scholarship_start_date" class="db-input">
            </div>
            <div class="db-field">
                <label>Scholarship End Date</label>
                <input type="date" name="scholarship_end_date" class="db-input">
            </div>
        </div>
        <div style="display:flex;align-items:flex-start;gap:10px;padding:14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1.5px solid var(--db-border);cursor:pointer;"
             onclick="document.getElementById('approveNow').click()">
            <input type="checkbox" name="approve_now" value="1" id="approveNow"
                   style="width:16px;height:16px;accent-color:var(--db-navy-light);flex-shrink:0;margin-top:2px;">
            <div>
                <strong style="font-size:13px;">Approve scholarship immediately</strong>
                <p style="margin:3px 0 0;font-size:12px;color:var(--db-muted);">Check this to set scholarship status as "Active" instead of "Pending"</p>
            </div>
        </div>
    </div>
</div>

<!-- Additional Information -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-info-circle"></i></span>
            <h2>Additional Information</h2>
        </div>
    </div>
    <div style="padding:22px;">
        <div class="db-field" style="max-width:260px;">
            <label>Student Status</label>
            <select name="status" class="db-input">
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
                <option value="graduated">Graduated</option>
            </select>
        </div>
        <div class="db-field">
            <label>Remarks / Notes</label>
            <textarea name="remarks" class="db-input" rows="3" placeholder="Any additional notes about this student…"></textarea>
        </div>
    </div>
</div>

<!-- Submit -->
<div style="display:flex;gap:10px;margin-bottom:24px;">
    <button type="submit" class="db-btn db-btn--primary" style="font-size:14px;padding:10px 28px;">
        <i class="fas fa-save"></i> Add Student Record
    </button>
    <a href="manage-students.php" class="db-btn db-btn--ghost" style="font-size:14px;padding:10px 24px;">
        <i class="fas fa-times"></i> Cancel
    </a>
</div>
</form>


<script>
function fillFromResident() {
    const sel = document.getElementById('residentSelect');
    const opt = sel.options[sel.selectedIndex];
    if (sel.value) {
        document.getElementById('residentId').value      = sel.value;
        document.getElementById('firstName').value       = opt.dataset.firstname   || '';
        document.getElementById('middleName').value      = opt.dataset.middlename  || '';
        document.getElementById('lastName').value        = opt.dataset.lastname    || '';
        document.getElementById('birthDate').value       = opt.dataset.birthdate   || '';
        document.getElementById('gender').value          = opt.dataset.gender      || '';
        document.getElementById('contactNumber').value   = opt.dataset.contact     || '';
        document.getElementById('email').value           = opt.dataset.email       || '';
        document.getElementById('address').value         = opt.dataset.address     || '';
    } else { clearStudentForm(); }
}
function clearStudentForm() {
    document.getElementById('studentForm').reset();
    document.getElementById('residentSelect').value = '';
    document.getElementById('residentId').value     = '';
}
function updateScholarshipAmount() {
    const sel = document.getElementById('scholarshipType');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('scholarshipAmount').value = sel.value && opt.dataset.amount ? opt.dataset.amount : '';
}
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../includes/footer.php'; ?>