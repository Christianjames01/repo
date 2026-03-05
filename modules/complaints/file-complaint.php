<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Only residents can file complaints
requireRole('Resident');

$current_user_id = getCurrentUserId();
$page_title = 'File a Complaint';

// Get resident info
$resident_id = null;
$resident_info = [];

$stmt = $conn->prepare("SELECT u.resident_id, r.first_name, r.last_name, r.email, r.contact_number, r.address 
                        FROM tbl_users u 
                        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id 
                        WHERE u.user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $resident_info = $result->fetch_assoc();
    $resident_id = $resident_info['resident_id'];
}
$stmt->close();

if (!$resident_id) {
    $_SESSION['error_message'] = 'Resident information not found';
    header('Location: view-complaints.php');
    exit;
}

include '../../includes/header.php';
?>

<link rel="stylesheet" href="/barangaylink1/assets/css/_db_shared.css">

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon rm-hero__icon--rose"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">File a Complaint</div>
                <div class="rm-hero__sub">Submit your concern or complaint to the barangay office</div>
            </div>
        </div>
        <a href="view-complaints.php" class="db-btn db-btn--glass db-btn--sm">
            <i class="fas fa-arrow-left"></i> Back to Complaints
        </a>
    </div>
</div>

<div style="padding: 0 24px 32px;">

<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error">
    <i class="fas fa-exclamation-circle"></i>
    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<form action="process-complaint.php" method="POST" enctype="multipart/form-data" id="complaintForm">
    <input type="hidden" name="action" value="file_complaint">
    <input type="hidden" name="resident_id" value="<?php echo $resident_id; ?>">

    <div style="display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start;">

        <!-- ── Left Column ── -->
        <div>

            <!-- Complaint Information -->
            <div class="db-panel" style="animation-delay:.05s">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-info-circle"></i></div>
                        <h2>Complaint Information</h2>
                    </div>
                </div>
                <div class="db-panel__body">

                    <div class="db-form-group">
                        <label class="db-form-label">Subject <span class="req" style="color:var(--db-rose);">*</span></label>
                        <input type="text" class="db-form-control" id="subject" name="subject"
                               placeholder="Brief description of your complaint" required maxlength="255">
                        <div style="font-size:11px; color:var(--db-muted); margin-top:5px;">
                            <i class="fas fa-info-circle me-1"></i>Keep it short and descriptive
                        </div>
                    </div>

                    <div class="db-form-row db-form-row--2">
                        <div class="db-form-group">
                            <label class="db-form-label">Category <span style="color:var(--db-rose);">*</span></label>
                            <select class="db-form-select" id="category" name="category" required>
                                <option value="">Select category</option>
                                <option value="Noise">Noise Disturbance</option>
                                <option value="Garbage">Garbage / Waste Management</option>
                                <option value="Property">Property Dispute</option>
                                <option value="Infrastructure">Infrastructure / Road Issues</option>
                                <option value="Public Safety">Public Safety Concern</option>
                                <option value="Services">Barangay Services</option>
                                <option value="Utilities">Utilities (Water / Electric)</option>
                                <option value="Animals">Stray Animals</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="db-form-group">
                            <label class="db-form-label">Priority Level <span style="color:var(--db-rose);">*</span></label>
                            <select class="db-form-select" id="priority" name="priority" required>
                                <option value="Low">Low — Not urgent, can wait</option>
                                <option value="Medium" selected>Medium — Normal concern</option>
                                <option value="High">High — Needs attention soon</option>
                                <option value="Urgent">Urgent — Immediate attention required</option>
                            </select>
                            <!-- priority indicator pill -->
                            <div id="priorityPill" style="margin-top:8px; display:none;">
                                <span id="priorityBadge" class="db-badge"></span>
                            </div>
                        </div>
                    </div>

                    <div class="db-form-group">
                        <label class="db-form-label">Detailed Description <span style="color:var(--db-rose);">*</span></label>
                        <textarea class="db-form-textarea" id="description" name="description" rows="6"
                                  placeholder="Please provide as much detail as possible about your complaint…" required></textarea>
                        <div style="font-size:11px; color:var(--db-muted); margin-top:5px;">
                            <i class="fas fa-info-circle me-1"></i>Include: What happened? When? Where? Who was involved?
                        </div>
                    </div>

                    <div class="db-form-group">
                        <label class="db-form-label">Location / Address</label>
                        <input type="text" class="db-form-control" id="location" name="location"
                               placeholder="Specific location where the issue occurred"
                               value="<?php echo htmlspecialchars($resident_info['address']); ?>">
                    </div>

                    <!-- File Upload -->
                    <div class="db-form-group">
                        <label class="db-form-label">Attachments <span style="font-weight:400; text-transform:none; letter-spacing:0; color:var(--db-muted);">(Optional)</span></label>
                        <label for="attachments" class="db-upload-zone" id="uploadZone">
                            <div class="db-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="db-upload-zone__text" id="uploadText">Click to upload or drag files here</div>
                            <div class="db-upload-zone__hint">Images, PDF, DOC — Max 5MB each · Up to 5 files</div>
                        </label>
                        <input type="file" id="attachments" name="attachments[]"
                               accept="image/*,.pdf,.doc,.docx" multiple style="display:none;"
                               onchange="handleAttachments(this)">
                        <div id="fileList" style="margin-top:8px;"></div>
                    </div>

                    <!-- Terms -->
                    <div style="display:flex; align-items:flex-start; gap:10px; padding:14px; background:var(--db-surf2); border:1px solid var(--db-border); border-radius:var(--db-radius-sm); margin-bottom:18px;">
                        <input type="checkbox" id="terms" name="terms" required
                               style="width:16px; height:16px; margin-top:2px; accent-color:var(--db-navy); flex-shrink:0; cursor:pointer;">
                        <label for="terms" style="font-size:12.5px; color:var(--db-text); cursor:pointer; line-height:1.5;">
                            I certify that the information provided is true and accurate to the best of my knowledge.
                        </label>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" class="db-btn db-btn--danger" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Submit Complaint
                        </button>
                        <a href="view-complaints.php" class="db-btn db-btn--ghost">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>

                </div>
            </div>

        </div><!-- /left -->

        <!-- ── Right Column ── -->
        <div>

            <!-- Resident Info -->
            <div class="db-side-card">
                <div class="db-side-card__header">
                    <div class="db-panel__icon db-panel__icon--navy" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-user"></i></div>
                    <span>Your Information</span>
                    <span class="db-badge db-badge--muted" style="margin-left:auto;"><i class="fas fa-lock"></i> Auto-filled</span>
                </div>
                <div class="db-side-card__body">
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <div>
                            <div style="font-size:10.5px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:3px;">Name</div>
                            <div style="font-size:13px; font-weight:700;"><?php echo htmlspecialchars($resident_info['first_name'] . ' ' . $resident_info['last_name']); ?></div>
                        </div>
                        <div>
                            <div style="font-size:10.5px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:3px;">Contact Number</div>
                            <div style="font-size:13px;"><?php echo htmlspecialchars($resident_info['contact_number']); ?></div>
                        </div>
                        <div>
                            <div style="font-size:10.5px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:3px;">Email</div>
                            <div style="font-size:13px;"><?php echo htmlspecialchars($resident_info['email']); ?></div>
                        </div>
                        <div>
                            <div style="font-size:10.5px; color:var(--db-muted); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:3px;">Address</div>
                            <div style="font-size:12.5px; color:var(--db-muted);"><?php echo htmlspecialchars($resident_info['address']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Important Notes -->
            <div class="db-side-card">
                <div class="db-side-card__header">
                    <div class="db-panel__icon db-panel__icon--amber" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-lightbulb"></i></div>
                    <span>Important Notes</span>
                </div>
                <div class="db-side-card__body" style="font-size:12px; color:var(--db-muted); line-height:1.6;">
                    <p style="margin:0 0 7px;">• Provide accurate and honest information</p>
                    <p style="margin:0 0 7px;">• You will receive a complaint number for tracking</p>
                    <p style="margin:0 0 7px;">• Response time depends on priority level</p>
                    <p style="margin:0 0 7px;">• You can track your complaint status online</p>
                    <p style="margin:0;">• For emergencies, call the barangay hotline</p>
                </div>
            </div>

            <!-- Priority Guide -->
            <div class="db-side-card">
                <div class="db-side-card__header">
                    <div class="db-panel__icon db-panel__icon--purple" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-layer-group"></i></div>
                    <span>Priority Guide</span>
                </div>
                <div class="db-side-card__body" style="display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="db-badge db-badge--success"><i class="fas fa-circle"></i> Low</span>
                        <span style="font-size:11.5px; color:var(--db-muted);">Not urgent, can wait</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="db-badge db-badge--amber"><i class="fas fa-circle"></i> Medium</span>
                        <span style="font-size:11.5px; color:var(--db-muted);">Normal concern</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="db-badge db-badge--rose"><i class="fas fa-exclamation-circle"></i> High</span>
                        <span style="font-size:11.5px; color:var(--db-muted);">Needs attention soon</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="db-badge db-badge--danger" style="background:#fee2e2; color:#7f1d1d; border:none;"><i class="fas fa-fire"></i> Urgent</span>
                        <span style="font-size:11.5px; color:var(--db-muted);">Immediate attention</span>
                    </div>
                </div>
            </div>

        </div><!-- /right -->

    </div><!-- /grid -->
</form>

</div><!-- /padding wrapper -->

<script>
// Priority badge colours
const priorityMap = {
    Low:    { cls: 'db-badge--success', icon: 'fa-circle',            label: 'Low Priority' },
    Medium: { cls: 'db-badge--amber',   icon: 'fa-exclamation-circle', label: 'Medium Priority' },
    High:   { cls: 'db-badge--rose',    icon: 'fa-exclamation-triangle',label: 'High Priority' },
    Urgent: { cls: 'db-badge--danger',  icon: 'fa-fire',               label: 'Urgent' },
};

document.getElementById('priority').addEventListener('change', function () {
    const pill   = document.getElementById('priorityPill');
    const badge  = document.getElementById('priorityBadge');
    const config = priorityMap[this.value];
    if (!config) { pill.style.display = 'none'; return; }
    badge.className = 'db-badge ' + config.cls;
    badge.innerHTML = `<i class="fas ${config.icon}"></i> ${config.label}`;
    pill.style.display = 'block';
});

// Trigger on load for default selected value
document.getElementById('priority').dispatchEvent(new Event('change'));

// File upload handling
function handleAttachments(input) {
    const zone     = document.getElementById('uploadZone');
    const text     = document.getElementById('uploadText');
    const fileList = document.getElementById('fileList');
    const files    = input.files;

    if (files.length > 5) {
        alert('Maximum 5 files allowed.');
        input.value = '';
        zone.classList.remove('has-file');
        text.textContent = 'Click to upload or drag files here';
        fileList.innerHTML = '';
        return;
    }

    let totalSize = 0;
    for (let f of files) {
        if (f.size > 5 * 1024 * 1024) {
            alert(`File "${f.name}" exceeds 5MB limit.`);
            input.value = '';
            zone.classList.remove('has-file');
            text.textContent = 'Click to upload or drag files here';
            fileList.innerHTML = '';
            return;
        }
        totalSize += f.size;
    }

    if (files.length > 0) {
        zone.classList.add('has-file');
        text.innerHTML = `<i class="fas fa-check-circle" style="color:var(--db-success);"></i> ${files.length} file${files.length > 1 ? 's' : ''} selected`;

        let html = '';
        for (let f of files) {
            const kb   = (f.size / 1024).toFixed(1);
            const isPdf = f.type === 'application/pdf';
            const icon = isPdf ? 'fa-file-pdf' : f.type.startsWith('image/') ? 'fa-image' : 'fa-file';
            const iconColor = isPdf ? 'var(--db-rose)' : f.type.startsWith('image/') ? 'var(--db-sky)' : 'var(--db-muted)';
            html += `
                <div style="display:flex; align-items:center; gap:10px; padding:8px 12px;
                            background:var(--db-surf2); border:1px solid var(--db-border);
                            border-radius:var(--db-radius-sm); margin-bottom:6px;">
                    <i class="fas ${icon}" style="color:${iconColor}; font-size:16px; flex-shrink:0;"></i>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:12.5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${f.name}</div>
                        <div style="font-size:11px; color:var(--db-muted);">${kb} KB</div>
                    </div>
                    <span class="db-badge db-badge--success"><i class="fas fa-check"></i></span>
                </div>`;
        }
        fileList.innerHTML = html;
    } else {
        zone.classList.remove('has-file');
        text.textContent = 'Click to upload or drag files here';
        fileList.innerHTML = '';
    }
}

// Form submit loading state
document.getElementById('complaintForm').addEventListener('submit', function (e) {
    if (!this.checkValidity()) {
        e.preventDefault();
        this.reportValidity();
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
});

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.transition = 'opacity .4s';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>