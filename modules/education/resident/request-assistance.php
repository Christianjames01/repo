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

$page_title = 'Request Educational Assistance';

// Get student records for this resident
$students_sql = "SELECT * FROM tbl_education_students WHERE resident_id = ? ORDER BY created_at DESC";
$students = $resident_id ? fetchAll($conn, $students_sql, [$resident_id], 'i') : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    if (empty($_POST['student_id']))      $errors[] = "Please select a student";
    if (empty($_POST['assistance_type'])) $errors[] = "Assistance type is required";
    if (empty($_POST['requested_amount'])) $errors[] = "Amount is required";
    if (empty($_POST['purpose']))         $errors[] = "Purpose is required";

    if (empty($errors)) {
        $sql = "INSERT INTO tbl_education_assistance_requests (
            student_id, assistance_type, requested_amount, purpose,
            supporting_documents, request_date, status
        ) VALUES (?, ?, ?, ?, ?, NOW(), 'pending')";

        $params = [
            $_POST['student_id'],
            $_POST['assistance_type'],
            $_POST['requested_amount'],
            $_POST['purpose'],
            $_POST['supporting_documents'] ?? null
        ];

        if (executeQuery($conn, $sql, $params, 'isdss')) {
            $_SESSION['temp_success'] = 'Assistance request submitted successfully! Please wait for approval.';
            header('Location: student-portal.php');
            exit();
        } else {
            $_SESSION['temp_error'] = 'Failed to submit request. Please try again.';
        }
    } else {
        $_SESSION['temp_error'] = implode('<br>', $errors);
    }
}

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

// Get previous requests
$requests_sql = "SELECT ear.*, es.first_name, es.last_name, es.school_name
                 FROM tbl_education_assistance_requests ear
                 JOIN tbl_education_students es ON ear.student_id = es.student_id
                 WHERE es.resident_id = ?
                 ORDER BY ear.request_date DESC";
$previous_requests = $resident_id ? fetchAll($conn, $requests_sql, [$resident_id], 'i') : [];

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- ═══════════════════════════════════════════
     HERO
═══════════════════════════════════════════ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>

    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #10b981, #0d9488);">
                <i class="fas fa-hand-holding-usd" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-resident">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Request Educational Assistance</h1>
                <p class="db-hero__sub">Apply for financial assistance to support your education</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="student-portal.php" class="db-btn db-btn--ghost db-btn--sm" style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back to Portal
            </a>
        </div>
    </div>
</div>

<!-- Alerts -->
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


<?php if (empty($students)): ?>
<!-- ── No Student Records ── -->
<div class="db-panel">
    <div class="db-empty" style="padding: 64px 24px;">
        <i class="fas fa-user-graduate" style="font-size:48px; color:var(--db-border);"></i>
        <p style="font-size:15px; font-weight:700; color:var(--db-text); margin-bottom:4px;">No Student Records Found</p>
        <p>You need to submit a scholarship application first before requesting assistance.</p>
        <a href="apply-scholarship.php" class="db-btn db-btn--primary" style="margin-top:8px;">
            <i class="fas fa-plus"></i> Apply for Scholarship
        </a>
    </div>
</div>

<?php else: ?>

<!-- ── Main Grid ── -->
<div class="db-grid">

    <!-- ── LEFT / FORM COLUMN ── -->
    <div class="db-grid__main">

        <!-- Assistance Type Cards (visual selector) -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-hand-holding-usd"></i></span>
                    <h2>Type of Assistance</h2>
                </div>
                <span class="db-badge db-badge--info"><i class="fas fa-mouse-pointer"></i> Click to select</span>
            </div>
            <div style="padding: 18px 22px;">
                <div class="db-assist-grid">
                    <?php
                    $assist_types = [
                        ['type' => 'Tuition Fee',    'icon' => 'fa-graduation-cap', 'color' => 'blue'],
                        ['type' => 'School Supplies', 'icon' => 'fa-book',           'color' => 'amber'],
                        ['type' => 'Uniform',         'icon' => 'fa-tshirt',         'color' => 'teal'],
                        ['type' => 'Books',           'icon' => 'fa-book-open',      'color' => 'indigo'],
                        ['type' => 'Transportation',  'icon' => 'fa-bus',            'color' => 'rose'],
                        ['type' => 'Other',           'icon' => 'fa-ellipsis-h',     'color' => 'blue'],
                    ];
                    foreach ($assist_types as $at): ?>
                    <div class="db-assist-card" data-type="<?php echo $at['type']; ?>" onclick="selectAssistanceType('<?php echo $at['type']; ?>', this)">
                        <div class="db-stat-card__icon db-stat-card__icon--<?php echo $at['color']; ?>" style="width:46px;height:46px;font-size:20px;margin:0 auto 10px;">
                            <i class="fas <?php echo $at['icon']; ?>"></i>
                        </div>
                        <div style="font-size:12.5px;font-weight:700;text-align:center;"><?php echo $at['type']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="assistance_type" id="assistanceType">
                <div id="assistanceTypeDisplay" style="display:none;margin-top:14px;" class="db-alert db-alert--success" style="margin-bottom:0;">
                    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
                    <span id="assistanceTypeText"></span>
                </div>
            </div>
        </div>

        <!-- Request Form -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-file-alt"></i></span>
                    <h2>Request Details</h2>
                </div>
            </div>
            <form method="POST" class="db-modal__body" style="padding:22px;">

                <!-- Select Student -->
                <div class="db-field">
                    <label>Select Student <span class="req">*</span></label>
                    <select name="student_id" class="db-input" required>
                        <option value="">Choose student...</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>">
                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> —
                                <?php echo htmlspecialchars($student['school_name']); ?>
                                (<?php echo htmlspecialchars($student['grade_level']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Amount -->
                <div class="db-field">
                    <label>Amount Requested <span class="req">*</span></label>
                    <div style="display:flex;align-items:center;gap:0;">
                        <span style="padding:9px 13px;background:var(--db-surf2);border:1.5px solid var(--db-border);border-right:none;border-radius:var(--db-radius-sm) 0 0 var(--db-radius-sm);font-weight:700;color:var(--db-teal);font-size:14px;">₱</span>
                        <input type="number" step="0.01" name="requested_amount" class="db-input"
                               placeholder="0.00" required
                               style="border-radius: 0 var(--db-radius-sm) var(--db-radius-sm) 0;">
                    </div>
                    <small style="color:var(--db-muted);font-size:11.5px;">Enter the amount you need for this assistance</small>
                </div>

                <!-- Purpose -->
                <div class="db-field">
                    <label>Purpose / Reason <span class="req">*</span></label>
                    <textarea name="purpose" class="db-input" rows="4"
                              placeholder="Please explain in detail why you need this assistance and how it will be used…" required></textarea>
                </div>

                <!-- Supporting Documents -->
                <div class="db-field">
                    <label>Supporting Documents <span style="color:var(--db-muted);font-weight:400;">(Optional)</span></label>
                    <textarea name="supporting_documents" class="db-input" rows="3"
                              placeholder="List any documents you have (e.g., bills, receipts, quotations)"></textarea>
                    <small style="color:var(--db-muted);font-size:11.5px;">You can upload actual documents later in the "My Documents" section</small>
                </div>

                <!-- Submit -->
                <div style="display:flex;gap:10px;margin-top:8px;">
                    <button type="submit" class="db-btn db-btn--primary db-btn--full" style="flex:1;">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                    <a href="student-portal.php" class="db-btn db-btn--ghost" style="flex-shrink:0;">
                        Cancel
                    </a>
                </div>
            </form>
        </div>


        <!-- ── Previous Requests ── -->
        <?php if (!empty($previous_requests)): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-history"></i></span>
                    <h2>My Previous Requests</h2>
                </div>
                <span class="db-badge db-badge--muted"><?php echo count($previous_requests); ?> total</span>
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Student</th>
                            <th>Amount Requested</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previous_requests as $req):
                        $status = $req['status'];
                        $badge_map = [
                            'pending'   => 'db-badge--warning',
                            'approved'  => 'db-badge--success',
                            'rejected'  => 'db-badge--danger',
                            'completed' => 'db-badge--info',
                        ];
                        $badge_cls = $badge_map[$status] ?? 'db-badge--muted';
                        $status_labels = [
                            'pending'   => 'Pending Review',
                            'approved'  => 'Approved',
                            'rejected'  => 'Rejected',
                            'completed' => 'Completed',
                        ];
                        $status_label = $status_labels[$status] ?? ucfirst($status);
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($req['assistance_type']); ?></strong></td>
                        <td>
                            <strong><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong><br>
                            <span class="db-text-sm"><?php echo htmlspecialchars($req['school_name']); ?></span>
                        </td>
                        <td><strong style="color:var(--db-teal);">₱<?php echo number_format($req['requested_amount'], 2); ?></strong>
                            <?php if ($status === 'approved' && !empty($req['approved_amount'])): ?>
                            <br><span class="db-text-sm">Approved: ₱<?php echo number_format($req['approved_amount'], 2); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($req['request_date'])); ?></span></td>
                        <td>
                            <span class="db-badge <?php echo $badge_cls; ?>"><?php echo $status_label; ?></span>
                            <?php if (!empty($req['rejection_reason'])): ?>
                            <br><span style="font-size:10.5px;color:var(--db-danger);margin-top:4px;display:block;">
                                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($req['rejection_reason']); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /db-grid__main -->


    <!-- ── RIGHT SIDEBAR ── -->
    <div class="db-grid__side">

        <!-- Guidelines -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-info-circle"></i></span>
                    <h2>Assistance Guidelines</h2>
                </div>
            </div>
            <div style="padding: 18px 22px; display:flex; flex-direction:column; gap:18px;">

                <div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span class="db-panel__icon db-panel__icon--teal" style="width:28px;height:28px;font-size:12px;flex-shrink:0;"><i class="fas fa-user-check"></i></span>
                        <strong style="font-size:13px;">Eligibility</strong>
                    </div>
                    <ul style="margin:0;padding-left:18px;color:var(--db-muted);font-size:12.5px;line-height:2;">
                        <li>Must be a registered student</li>
                        <li>Resident of the barangay</li>
                        <li>Financial need must be demonstrated</li>
                    </ul>
                </div>

                <div style="border-top:1px solid var(--db-border);padding-top:14px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span class="db-panel__icon db-panel__icon--amber" style="width:28px;height:28px;font-size:12px;flex-shrink:0;"><i class="fas fa-file-alt"></i></span>
                        <strong style="font-size:13px;">Required Documents</strong>
                    </div>
                    <ul style="margin:0;padding-left:18px;color:var(--db-muted);font-size:12.5px;line-height:2;">
                        <li>Certificate of Enrollment</li>
                        <li>Statement of Account (for tuition)</li>
                        <li>Quotation / Bills (for supplies)</li>
                        <li>Barangay Clearance</li>
                    </ul>
                </div>

                <div style="border-top:1px solid var(--db-border);padding-top:14px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span class="db-panel__icon db-panel__icon--indigo" style="width:28px;height:28px;font-size:12px;flex-shrink:0;"><i class="fas fa-clock"></i></span>
                        <strong style="font-size:13px;">Processing Time</strong>
                    </div>
                    <p style="margin:0;color:var(--db-muted);font-size:12.5px;line-height:1.75;">
                        Requests are typically processed within <strong style="color:var(--db-text);">5–10 working days</strong> after submission.
                    </p>
                </div>
            </div>
        </div>

        <!-- Contact Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-phone-alt"></i></span>
                    <h2>Need Help?</h2>
                </div>
            </div>
            <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px;">
                <p style="margin:0;font-size:12.5px;color:var(--db-muted);">Contact the Education Office:</p>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                    <span class="db-panel__icon db-panel__icon--blue" style="width:30px;height:30px;font-size:12px;flex-shrink:0;"><i class="fas fa-phone"></i></span>
                    <div>
                        <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;font-family:'DM Mono',monospace;">Phone</div>
                        <strong style="font-size:13px;"><?php echo BARANGAY_CONTACT; ?></strong>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                    <span class="db-panel__icon db-panel__icon--rose" style="width:30px;height:30px;font-size:12px;flex-shrink:0;"><i class="fas fa-envelope"></i></span>
                    <div>
                        <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;font-family:'DM Mono',monospace;">Email</div>
                        <strong style="font-size:13px;"><?php echo BARANGAY_EMAIL; ?></strong>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /db-grid__side -->
</div><!-- /db-grid -->

<?php endif; ?>


<style>
/* ── Assistance Type Selector Grid ── */
.db-assist-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.db-assist-card {
    padding: 18px 12px 14px;
    border-radius: var(--db-radius);
    border: 2px solid var(--db-border);
    background: var(--db-surf2);
    cursor: pointer;
    transition: all .2s ease;
}

.db-assist-card:hover {
    border-color: var(--db-navy-light);
    background: #f0f4ff;
    transform: translateY(-2px);
    box-shadow: var(--db-shadow);
}

.db-assist-card.selected {
    border-color: var(--db-navy-light);
    background: #eef3ff;
    box-shadow: 0 0 0 3px rgba(28,52,97,.12), var(--db-shadow);
}

.db-assist-card.selected .db-stat-card__icon {
    box-shadow: 0 4px 14px rgba(13,27,54,.15);
}

@media (max-width: 600px) {
    .db-assist-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>


<script>
// ── Assistance Type Selector ──
function selectAssistanceType(type, el) {
    document.querySelectorAll('.db-assist-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('assistanceType').value = type;
    const display = document.getElementById('assistanceTypeDisplay');
    document.getElementById('assistanceTypeText').textContent = 'Selected: ' + type;
    display.style.display = 'flex';
}

// ── Auto-dismiss alerts ──
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0';
        a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php
$conn->close();
include '../../../includes/footer.php';
?>