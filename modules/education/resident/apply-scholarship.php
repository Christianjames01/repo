<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = null;

if ($user_role === 'Resident') {
    $user_id = getCurrentUserId();
    $user_sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
    $user_data = fetchOne($conn, $user_sql, [$user_id], 'i');
    $resident_id = $user_data['resident_id'] ?? null;
}

$page_title = 'Apply for Scholarship';

$resident_info = null;
if ($resident_id) {
    $resident_sql = "SELECT * FROM tbl_residents WHERE resident_id = ?";
    $resident_info = fetchOne($conn, $resident_sql, [$resident_id], 'i');
}

$scholarships_sql = "SELECT * FROM tbl_education_scholarships 
                     WHERE status = 'active' 
                     AND (application_end IS NULL OR application_end >= CURDATE())";
$scholarships = fetchAll($conn, $scholarships_sql);

$success_message = '';
$error_message   = '';

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
        $sql = "INSERT INTO tbl_education_students (
            resident_id, first_name, last_name, middle_name, birth_date, gender,
            contact_number, email, address, school_name, school_address, grade_level,
            course, school_year, gwa_grade, parent_guardian_name, parent_contact,
            parent_occupation, monthly_income, scholarship_type, scholarship_status,
            application_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";

        $params = [
            $resident_id,
            $_POST['first_name'], $_POST['last_name'], $_POST['middle_name'] ?? null,
            $_POST['birth_date'], $_POST['gender'], $_POST['contact_number'],
            $_POST['email'] ?? null, $_POST['address'], $_POST['school_name'],
            $_POST['school_address'] ?? null, $_POST['grade_level'],
            $_POST['course'] ?? null, $_POST['school_year'], $_POST['gwa_grade'] ?? null,
            $_POST['parent_guardian_name'], $_POST['parent_contact'],
            $_POST['parent_occupation'] ?? null, $_POST['monthly_income'] ?? null,
            $_POST['scholarship_type'] ?? null
        ];

        if (executeQuery($conn, $sql, $params, "isssssssssssssdsssds")) {
            $_SESSION['temp_success'] = 'Scholarship application submitted successfully! Please wait for admin approval.';
            header('Location: student-portal.php');
            exit();
        } else {
            $error_message = "Failed to submit application. Please try again.";
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                <i class="fas fa-graduation-cap" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-resident">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Scholarship Application Form</h1>
                <p class="db-hero__sub">Complete all required fields to submit your application</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="student-portal.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back to Portal
            </a>
        </div>
    </div>
</div>

<?php if ($success_message): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo $success_message; ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo $error_message; ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>


<form method="POST" enctype="multipart/form-data">

<!-- ── Personal Information ── -->
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
                <input type="text" name="first_name" class="db-input"
                       value="<?php echo htmlspecialchars($resident_info['first_name'] ?? ''); ?>" required>
            </div>
            <div class="db-field">
                <label>Middle Name</label>
                <input type="text" name="middle_name" class="db-input"
                       value="<?php echo htmlspecialchars($resident_info['middle_name'] ?? ''); ?>">
            </div>
            <div class="db-field">
                <label>Last Name <span class="req">*</span></label>
                <input type="text" name="last_name" class="db-input"
                       value="<?php echo htmlspecialchars($resident_info['last_name'] ?? ''); ?>" required>
            </div>
        </div>
        <div class="db-field-row">
            <div class="db-field">
                <label>Birth Date <span class="req">*</span></label>
                <input type="date" name="birth_date" class="db-input"
                       value="<?php echo htmlspecialchars($resident_info['birth_date'] ?? ''); ?>" required>
            </div>
            <div class="db-field">
                <label>Gender <span class="req">*</span></label>
                <select name="gender" class="db-input" required>
                    <option value="">Select Gender</option>
                    <option value="Male"   <?php echo ($resident_info['gender'] ?? '') === 'Male'   ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo ($resident_info['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>
        </div>
        <div class="db-field-row">
            <div class="db-field">
                <label>Contact Number <span class="req">*</span></label>
                <input type="tel" name="contact_number" class="db-input"
                       value="<?php echo htmlspecialchars($resident_info['contact_number'] ?? ''); ?>" required>
            </div>
            <div class="db-field">
                <label>Email Address</label>
                <input type="email" name="email" class="db-input"
                       value="<?php echo htmlspecialchars($resident_info['email'] ?? ''); ?>">
            </div>
        </div>
        <div class="db-field">
            <label>Complete Address <span class="req">*</span></label>
            <textarea name="address" class="db-input" rows="2" required><?php echo htmlspecialchars($resident_info['address'] ?? ''); ?></textarea>
        </div>
    </div>
</div>


<!-- ── Educational Information ── -->
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
                    <?php
                    $levels = ['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12',
                               '1st Year College','2nd Year College','3rd Year College','4th Year College','5th Year College'];
                    foreach ($levels as $l) echo "<option value=\"$l\">$l</option>";
                    ?>
                </select>
            </div>
            <div class="db-field">
                <label>Course <span style="color:var(--db-muted);font-weight:400;">(College)</span></label>
                <input type="text" name="course" class="db-input" placeholder="e.g. BSCS">
            </div>
            <div class="db-field">
                <label>School Year <span class="req">*</span></label>
                <input type="text" name="school_year" class="db-input" placeholder="e.g. 2024-2025" required>
            </div>
        </div>
        <div class="db-field" style="max-width:240px;">
            <label>General Weighted Average (GWA)</label>
            <input type="number" step="0.01" name="gwa_grade" class="db-input" placeholder="e.g. 90.50">
            <small style="color:var(--db-muted);font-size:11.5px;">Enter your latest GWA or general average</small>
        </div>
    </div>
</div>


<!-- ── Parent / Guardian Information ── -->
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
                <input type="text" name="parent_occupation" class="db-input">
            </div>
            <div class="db-field">
                <label>Monthly Family Income</label>
                <div style="display:flex;align-items:center;">
                    <span style="padding:9px 13px;background:var(--db-surf2);border:1.5px solid var(--db-border);border-right:none;border-radius:var(--db-radius-sm) 0 0 var(--db-radius-sm);font-weight:700;color:var(--db-teal);font-size:14px;">₱</span>
                    <input type="number" step="0.01" name="monthly_income" class="db-input" placeholder="0.00"
                           style="border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;">
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ── Scholarship Selection ── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-award"></i></span>
            <h2>Scholarship Selection</h2>
        </div>
    </div>
    <div style="padding:22px;">
        <div class="db-field" style="max-width:480px;">
            <label>Select Scholarship Program <span style="color:var(--db-muted);font-weight:400;">(Optional)</span></label>
            <select name="scholarship_type" class="db-input">
                <option value="">— General Application —</option>
                <?php foreach ($scholarships as $s): ?>
                <option value="<?php echo htmlspecialchars($s['scholarship_name']); ?>">
                    <?php echo htmlspecialchars($s['scholarship_name']); ?> — ₱<?php echo number_format($s['amount'], 2); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small style="color:var(--db-muted);font-size:11.5px;">Leave blank for a general scholarship application</small>
        </div>
    </div>
</div>


<!-- Submit -->
<div style="display:flex;gap:10px;margin-bottom:24px;">
    <button type="submit" class="db-btn db-btn--primary" style="font-size:14px;padding:10px 28px;">
        <i class="fas fa-paper-plane"></i> Submit Application
    </button>
    <a href="student-portal.php" class="db-btn db-btn--ghost" style="font-size:14px;padding:10px 24px;">
        <i class="fas fa-times"></i> Cancel
    </a>
</div>

</form>

<script>
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../../includes/footer.php'; ?>