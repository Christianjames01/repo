<?php
require_once '../../../config/config.php';
requireLogin();

$page_title = "Report Waste Issue";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report') {
    $errors = [];
    if (empty($_POST['issue_type']))  $errors[] = "Issue type is required";
    if (empty($_POST['description'])) $errors[] = "Description is required";
    if (empty($_POST['location']))    $errors[] = "Location is required";
    if (empty($_POST['urgency']))     $errors[] = "Urgency level is required";

    if (!empty($errors)) {
        setMessage(implode(', ', $errors), 'danger');
    } else {
        $issue_type   = sanitize($_POST['issue_type']);
        $description  = sanitize($_POST['description']);
        $location     = sanitize($_POST['location']);
        $urgency      = sanitize($_POST['urgency']);
        $reporter_id  = $_SESSION['user_id'];

        $user_data = fetchOne($conn,
            "SELECT u.username, r.first_name, r.last_name, r.contact_number
             FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
             WHERE u.user_id = ?", [$reporter_id], 'i'
        );

        $reporter_name    = ($user_data && !empty($user_data['first_name']))
            ? trim($user_data['first_name'] . ' ' . ($user_data['last_name'] ?? ''))
            : ($user_data['username'] ?? 'User #' . $reporter_id);
        $reporter_contact = $user_data['contact_number'] ?? 'N/A';

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg','image/png','image/jpg','image/gif'];
            $upload_result = uploadFile($_FILES['photo'], '../../../uploads/waste_issues/', $allowed_types, 5242880);
            if ($upload_result['success']) $photo_path = 'uploads/waste_issues/' . $upload_result['filename'];
            else setMessage('Photo upload failed: ' . $upload_result['message'], 'warning');
        }

        if ($photo_path) {
            $sql    = "INSERT INTO tbl_waste_issues (reporter_id,reporter_name,reporter_contact,issue_type,location,description,urgency,photo_path,status,created_at) VALUES (?,?,?,?,?,?,?,?,'pending',NOW())";
            $params = [$reporter_id,$reporter_name,$reporter_contact,$issue_type,$location,$description,$urgency,$photo_path];
            $types  = 'isssssss';
        } else {
            $sql    = "INSERT INTO tbl_waste_issues (reporter_id,reporter_name,reporter_contact,issue_type,location,description,urgency,status,created_at) VALUES (?,?,?,?,?,?,?,'pending',NOW())";
            $params = [$reporter_id,$reporter_name,$reporter_contact,$issue_type,$location,$description,$urgency];
            $types  = 'issssss';
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            setMessage('Failed to submit report. Database error.', 'danger');
        } else {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $issue_id = $conn->insert_id;
                $stmt->close();
                if ($issue_id > 0 && function_exists('logActivity'))
                    logActivity($conn, $reporter_id, 'Reported waste issue: ' . $issue_type, 'tbl_waste_issues', $issue_id);
                setMessage('Report submitted successfully! Reference ID: #' . $issue_id, 'success');
                header('Location: my-reports.php');
                exit();
            } else {
                setMessage('Failed to submit report. Please try again.', 'danger');
                $stmt->close();
            }
        }
    }
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/waste-pages.css?v=' . time() . '">';
require_once '../../../includes/header.php';
?>

<!-- ── PAGE HERO ── -->
<div class="wp-hero">
    <div class="wp-hero__ring wp-hero__ring--1"></div>
    <div class="wp-hero__ring wp-hero__ring--2"></div>
    <div class="wp-hero__ring wp-hero__ring--3"></div>
    <div class="wp-hero__inner">
        <div class="wp-hero__left">
            <div class="wp-hero__icon wp-hero__icon--rose">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <h1 class="wp-hero__title">Report Waste Issue</h1>
                <p class="wp-hero__sub">Help keep our barangay clean by reporting waste concerns</p>
            </div>
        </div>
        <div class="wp-hero__actions">
            <a href="my-reports.php" class="wp-btn wp-btn--ghost">
                <i class="fas fa-list"></i> My Reports
            </a>
        </div>
    </div>
</div>

<?php if ($msg = displayMessage()): ?>
<div style="margin-bottom:16px"><?php echo $msg; ?></div>
<?php endif; ?>

<div class="wp-grid">

    <!-- ── MAIN FORM ── -->
    <div>
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--rose"><i class="fas fa-file-alt"></i></span>
                    <h2>Issue Details</h2>
                </div>
                <span class="wp-badge wp-badge--muted"><i class="fas fa-info-circle"></i> All fields marked * are required</span>
            </div>

            <form method="POST" action="report-issue.php" enctype="multipart/form-data" id="reportForm">
                <input type="hidden" name="action" value="report">
                <div class="wp-panel__body" style="display:flex;flex-direction:column;gap:0">

                    <div class="wp-field-row" style="margin-bottom:16px">
                        <div class="wp-field" style="margin:0">
                            <label>Issue Type <span class="req">*</span></label>
                            <select name="issue_type" id="issue_type" class="wp-input" required>
                                <option value="">Select Issue Type</option>
                                <?php
                                $types_list = ['Missed Collection','Illegal Dumping','Overflowing Bin','Littering','Hazardous Waste','Damaged Bin','Blocked Access','Broken Collection Equipment','Unscheduled Collection','Other'];
                                foreach ($types_list as $t): ?>
                                <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wp-field" style="margin:0">
                            <label>Urgency Level <span class="req">*</span></label>
                            <select name="urgency" id="urgency" class="wp-input" required>
                                <option value="">Select Urgency</option>
                                <option value="low">Low — can wait a few days</option>
                                <option value="medium" selected>Medium — needs attention soon</option>
                                <option value="high">High — urgent attention required</option>
                                <option value="critical">Critical — immediate action needed</option>
                            </select>
                        </div>
                    </div>

                    <div class="wp-field">
                        <label>Location <span class="req">*</span></label>
                        <input type="text" name="location" id="location" class="wp-input"
                               placeholder="Street address, landmark, or specific area…" required>
                        <p style="font-size:11.5px;color:var(--db-muted);margin-top:4px"><i class="fas fa-info-circle" style="margin-right:4px"></i>Be as specific as possible to help us locate the issue quickly.</p>
                    </div>

                    <div class="wp-field">
                        <label>Description <span class="req">*</span></label>
                        <textarea name="description" id="description" class="wp-input" rows="5"
                                  placeholder="Describe the waste issue in detail…" required></textarea>
                        <p style="font-size:11.5px;color:var(--db-muted);margin-top:4px"><i class="fas fa-info-circle" style="margin-right:4px"></i>Include any relevant details that might help resolve the issue faster.</p>
                    </div>

                    <!-- Photo Upload -->
                    <div class="wp-field">
                        <label>Photo Evidence <span style="color:var(--db-muted);font-weight:400">(Optional)</span></label>
                        <div class="wp-upload-area" id="uploadArea">
                            <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/jpg,image/gif">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Click or drag & drop a photo here</p>
                            <span>JPG, PNG, GIF up to 5MB</span>
                        </div>
                        <div class="wp-image-preview" id="imagePreview">
                            <img id="previewImg" src="" alt="Preview">
                            <button type="button" class="wp-image-preview__remove" id="removeImg">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <!-- What happens next -->
                    <div class="wp-info-box" style="margin-bottom:20px">
                        <div class="wp-info-box__title"><i class="fas fa-info-circle"></i> What happens after you submit?</div>
                        <ul style="margin:0;padding-left:18px;font-size:12.5px">
                            <li>Your report will be reviewed by our waste management team</li>
                            <li>We aim to respond to all reports within 24–48 hours</li>
                            <li>You can track your report status anytime in "My Reports"</li>
                        </ul>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:flex-end">
                        <button type="reset" class="wp-btn wp-btn--ghost"><i class="fas fa-redo"></i> Reset</button>
                        <button type="submit" class="wp-btn wp-btn--primary"><i class="fas fa-paper-plane"></i> Submit Report</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ── SIDEBAR ── -->
    <div>
        <!-- Urgency Guide -->
        <div class="wp-panel" style="margin-bottom:18px">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--amber"><i class="fas fa-tachometer-alt"></i></span>
                    <h2>Urgency Guide</h2>
                </div>
            </div>
            <div class="wp-panel__body" style="display:flex;flex-direction:column;gap:8px">
                <?php
                $urgs = [
                    ['wp-badge--success', 'Low',      'fa-circle',            'Can wait a few days. Not an immediate concern.'],
                    ['wp-badge--warning', 'Medium',   'fa-exclamation-circle','Needs attention within the week.'],
                    ['wp-badge--danger',  'High',     'fa-exclamation-triangle','Requires urgent attention within 24 hrs.'],
                    ['wp-badge--dark',    'Critical', 'fa-skull-crossbones',  'Immediate action required. Health/safety risk.'],
                ];
                foreach ($urgs as [$cls, $lbl, $ico, $desc]):
                ?>
                <div style="display:flex;align-items:flex-start;gap:10px;padding:10px;background:var(--db-surf2);border-radius:8px;font-size:12.5px">
                    <span class="wp-badge <?php echo $cls; ?>" style="flex-shrink:0"><i class="fas <?php echo $ico; ?>"></i> <?php echo $lbl; ?></span>
                    <span style="color:var(--db-muted);line-height:1.5"><?php echo $desc; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Reporting Guidelines -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--success"><i class="fas fa-lightbulb"></i></span>
                    <h2>Reporting Guidelines</h2>
                </div>
            </div>
            <div class="wp-panel__body" style="display:flex;flex-direction:column;gap:8px">
                <?php
                $dos = [
                    'Provide specific location details',
                    'Include photos when possible',
                    'Describe the issue clearly and concisely',
                    'Select the correct urgency level',
                    'Report genuine concerns only',
                ];
                $donts = [
                    'Submit duplicate reports for the same issue',
                    'Provide false or misleading information',
                    'Use offensive or inappropriate language',
                    'Mark low-priority issues as critical',
                ];
                ?>
                <p style="font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">DO</p>
                <?php foreach ($dos as $d): ?>
                <div class="wp-guideline wp-guideline--do">
                    <i class="fas fa-check-circle"></i>
                    <span class="wp-guideline__text"><?php echo $d; ?></span>
                </div>
                <?php endforeach; ?>
                <p style="font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin:8px 0 4px">DON'T</p>
                <?php foreach ($donts as $d): ?>
                <div class="wp-guideline wp-guideline--dont">
                    <i class="fas fa-times-circle"></i>
                    <span class="wp-guideline__text"><?php echo $d; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="wp-panel__footer">
                <span style="font-size:12px;color:var(--db-muted)">
                    <i class="fas fa-phone-alt" style="color:var(--db-success);margin-right:6px"></i>
                    Unsure? Call us at <strong>(123) 456-7890</strong>
                </span>
            </div>
        </div>
    </div>
</div>

<script>
// ── Image upload preview ──
const photoInput  = document.getElementById('photo');
const uploadArea  = document.getElementById('uploadArea');
const previewWrap = document.getElementById('imagePreview');
const previewImg  = document.getElementById('previewImg');
const removeBtn   = document.getElementById('removeImg');

photoInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 5242880) { alert('File must be under 5MB'); this.value = ''; return; }
    const allowed = ['image/jpeg','image/png','image/jpg','image/gif'];
    if (!allowed.includes(file.type)) { alert('Only JPG, PNG, GIF allowed'); this.value = ''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        previewImg.src = e.target.result;
        previewWrap.style.display = 'block';
        uploadArea.style.display = 'none';
    };
    reader.readAsDataURL(file);
});

removeBtn.addEventListener('click', () => {
    photoInput.value = '';
    previewImg.src   = '';
    previewWrap.style.display = 'none';
    uploadArea.style.display  = 'block';
});

// Drag & drop
uploadArea.addEventListener('dragover',  e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
uploadArea.addEventListener('drop', e => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    if (e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        photoInput.files = dt.files;
        photoInput.dispatchEvent(new Event('change'));
    }
});

// ── Form validation ──
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const loc  = document.getElementById('location').value;
    const desc = document.getElementById('description').value;
    if (desc.length < 10) { e.preventDefault(); alert('Please provide a more detailed description (min 10 chars)'); return; }
    if (loc.length  < 5)  { e.preventDefault(); alert('Please provide a more specific location (min 5 chars)'); return; }
});

// ── Auto-save draft ──
const FIELDS = ['issue_type','location','description','urgency'];
window.addEventListener('load', () => FIELDS.forEach(f => {
    const el = document.getElementById(f);
    const sv = localStorage.getItem('wi_' + f);
    if (el && sv) el.value = sv;
}));
FIELDS.forEach(f => {
    const el = document.getElementById(f);
    if (el) el.addEventListener('change', () => localStorage.setItem('wi_' + f, el.value));
});
document.getElementById('reportForm').addEventListener('submit',  () => FIELDS.forEach(f => localStorage.removeItem('wi_' + f)));
document.getElementById('reportForm').addEventListener('reset',   () => FIELDS.forEach(f => localStorage.removeItem('wi_' + f)));
</script>

<?php require_once '../../../includes/footer.php'; ?>