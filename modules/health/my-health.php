<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Resident']);

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT r.is_verified,r.id_photo,r.first_name,r.last_name,r.resident_id FROM tbl_users u JOIN tbl_residents r ON u.resident_id=r.resident_id WHERE u.user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data || $user_data['is_verified'] != 1) { header("Location: health-verification.php"); exit(); }

$page_title  = 'My Health Profile';
$resident_id = $user_data['resident_id'];

$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id=?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id=?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$res = $stmt->get_result();
$health_profile = $res->num_rows > 0 ? $res->fetch_assoc() : null;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_vaccination_records WHERE resident_id=?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$vaccinations_count = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$modal_id = $health_profile ? 'editProfileModal' : 'createProfileModal';

include '../../includes/header.php';
?>

<style>
:root { --rs-blue:#4299e1; --rs-green:#48bb78; --rs-orange:#ed8936; --rs-purple:#9f7aea; --rs-red:#f56565; --rs-radius:12px; --rs-shadow:0 2px 8px rgba(0,0,0,.08); --rs-shadow-md:0 4px 16px rgba(0,0,0,.12); }
.rs-page { padding:1.5rem; }

/* Header */
.rs-page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; }
.rs-page-header__left h2 { font-size:1.4rem; font-weight:700; color:#2d3748; margin:0 0 4px; display:flex; align-items:center; gap:10px; }
.rs-page-header__left p  { color:#718096; margin:0; font-size:.9rem; }

.rs-alert { border:none; border-radius:var(--rs-radius); padding:1rem 1.25rem; box-shadow:var(--rs-shadow); border-left:4px solid; display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem; font-size:.9rem; }
.rs-alert--success { background:linear-gradient(135deg,#d1f4e0,#e7f9ee); border-left-color:#198754; color:#0f5132; }
.rs-alert--danger  { background:linear-gradient(135deg,#ffd6d6,#ffe5e5); border-left-color:#dc3545; color:#842029; }
.rs-alert__close   { margin-left:auto; background:none; border:none; cursor:pointer; opacity:.6; font-size:1.1rem; }

/* Stats */
.rs-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
.rs-stat { background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); padding:1.25rem; display:flex; align-items:center; gap:.9rem; transition:box-shadow .2s,transform .2s; }
.rs-stat:hover { box-shadow:var(--rs-shadow-md); transform:translateY(-3px); }
.rs-stat__icon { width:52px; height:52px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#fff; flex-shrink:0; }
.rs-stat__val  { font-size:1.4rem; font-weight:700; color:#2d3748; line-height:1.2; }
.rs-stat__lbl  { font-size:.8rem; color:#718096; font-weight:500; margin-top:2px; }

/* Profile sections */
.rs-profile-section { background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); padding:1.5rem; margin-bottom:1.25rem; transition:box-shadow .2s,transform .2s; }
.rs-profile-section:hover { box-shadow:var(--rs-shadow-md); transform:translateY(-2px); }
.rs-section-head { display:flex; align-items:center; gap:.6rem; font-size:1rem; font-weight:700; color:#2d3748; padding-bottom:.75rem; border-bottom:2px solid #e9ecef; margin-bottom:1.1rem; }
.rs-section-head i { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:.85rem; color:#fff; flex-shrink:0; }

.rs-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(185px,1fr)); gap:1.1rem; }
.rs-info-item label { font-size:.75rem; color:#718096; text-transform:uppercase; letter-spacing:.5px; font-weight:600; display:block; margin-bottom:.3rem; }
.rs-info-item span  { font-size:.95rem; color:#2d3748; font-weight:500; }
.rs-blood-type { color:#c53030; font-weight:700; font-size:1.05rem; }
.rs-bmi-ok   { color:var(--rs-green); }
.rs-bmi-warn { color:var(--rs-orange); }
.rs-bmi-bad  { color:var(--rs-red); }

.rs-tags { display:flex; flex-wrap:wrap; gap:.5rem; }
.rs-tag--condition { background:#fed7d7; color:#742a2a; padding:.3rem .75rem; border-radius:20px; font-size:.8rem; font-weight:600; }
.rs-tag--allergy   { background:#feebc8; color:#7c2d12; padding:.6rem .9rem; border-radius:8px; font-size:.85rem; font-weight:500; display:flex; align-items:center; gap:.4rem; }
.rs-medications    { background:#f7fafc; padding:.9rem 1rem; border-radius:8px; color:#2d3748; line-height:1.6; font-size:.9rem; }
.rs-muted          { color:#a0aec0; font-style:italic; font-size:.9rem; }

/* Empty */
.rs-empty { text-align:center; padding:3.5rem 2rem; color:#a0aec0; background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); }
.rs-empty i { font-size:3.5rem; margin-bottom:1rem; display:block; opacity:.3; }
.rs-empty h5 { color:#4a5568; margin-bottom:.5rem; }
.rs-empty p  { font-size:.9rem; margin-bottom:1.5rem; }

/* Buttons */
.rs-btn { border-radius:8px; padding:.55rem 1.25rem; font-weight:600; font-size:.9rem; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; transition:all .2s; box-shadow:0 2px 6px rgba(0,0,0,.1); }
.rs-btn:hover { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,0,0,.15); }
.rs-btn--primary   { background:linear-gradient(135deg,#4299e1,#3182ce); color:#fff; }
.rs-btn--secondary { background:#e2e8f0; color:#4a5568; }

/* Modal */
.rs-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(3px); z-index:9999; align-items:center; justify-content:center; }
.rs-modal-overlay.show { display:flex; }
.rs-modal { background:#fff; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.18); width:90%; max-width:820px; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; }
.rs-modal__header { padding:1.25rem 1.5rem; border-bottom:2px solid #e9ecef; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#f8f9fa,#fff); }
.rs-modal__title  { margin:0; font-size:1.05rem; font-weight:700; color:#2d3748; display:flex; align-items:center; gap:.5rem; }
.rs-modal__close  { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#718096; width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:50%; }
.rs-modal__close:hover { background:#e9ecef; }
.rs-modal__body   { padding:1.5rem; overflow-y:auto; }
.rs-modal__footer { padding:1rem 1.5rem; border-top:2px solid #e9ecef; display:flex; justify-content:flex-end; gap:.6rem; background:#f8f9fa; }

.rs-form-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:1.1rem; }
.rs-form-group { display:flex; flex-direction:column; }
.rs-form-group.full { grid-column:1/-1; }
.rs-form-label { font-weight:600; color:#495057; font-size:.875rem; margin-bottom:.4rem; }
.rs-form-control { border:2px solid #e9ecef; border-radius:8px; padding:.6rem .9rem; font-size:.95rem; transition:border-color .2s; width:100%; }
.rs-form-control:focus { border-color:var(--rs-blue); box-shadow:0 0 0 .2rem rgba(66,153,225,.15); outline:none; }

@media(max-width:768px){ .rs-stats { grid-template-columns:1fr 1fr; } .rs-form-grid { grid-template-columns:1fr; } .rs-page-header { flex-direction:column; gap:1rem; } }
</style>

<div class="rs-page">
    <div class="rs-page-header">
        <div class="rs-page-header__left">
            <h2><i class="fas fa-user-md" style="color:var(--rs-blue);"></i> My Health Profile</h2>
            <p>Manage your personal health information</p>
        </div>
        <button class="rs-btn rs-btn--primary" onclick="openModal('<?php echo $modal_id; ?>')">
            <i class="fas fa-<?php echo $health_profile ? 'edit' : 'plus'; ?>"></i>
            <?php echo $health_profile ? 'Update Profile' : 'Create Profile'; ?>
        </button>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="rs-alert rs-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="rs-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="rs-alert rs-alert--danger"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="rs-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="rs-stats">
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-red);"><i class="fas fa-tint"></i></div>
            <div><div class="rs-stat__val"><?php echo $health_profile && $health_profile['blood_type'] ? htmlspecialchars($health_profile['blood_type']) : '—'; ?></div><div class="rs-stat__lbl">Blood Type</div></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-green);"><i class="fas fa-syringe"></i></div>
            <div><div class="rs-stat__val"><?php echo $vaccinations_count; ?></div><div class="rs-stat__lbl">Vaccinations</div></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-purple);"><i class="fas fa-calendar-check"></i></div>
            <div><div class="rs-stat__val" style="font-size:1rem;"><?php echo ($health_profile && $health_profile['last_checkup_date']) ? date('M d, Y', strtotime($health_profile['last_checkup_date'])) : 'N/A'; ?></div><div class="rs-stat__lbl">Last Check-up</div></div>
        </div>
    </div>

    <?php if ($health_profile): ?>

    <!-- Basic Info -->
    <div class="rs-profile-section">
        <div class="rs-section-head"><i class="fas fa-heartbeat" style="background:var(--rs-red);"></i> Basic Health Information</div>
        <div class="rs-info-grid">
            <div class="rs-info-item"><label>Blood Type</label><span class="rs-blood-type"><?php echo htmlspecialchars($health_profile['blood_type']?:'Not Set'); ?></span></div>
            <div class="rs-info-item"><label>Height</label><span><?php echo $health_profile['height'] ? $health_profile['height'].' cm' : 'Not Set'; ?></span></div>
            <div class="rs-info-item"><label>Weight</label><span><?php echo $health_profile['weight'] ? $health_profile['weight'].' kg' : 'Not Set'; ?></span></div>
            <div class="rs-info-item"><label>BMI</label><span>
                <?php if ($health_profile['height'] && $health_profile['weight']):
                    $bmi = $health_profile['weight'] / (($health_profile['height']/100)**2);
                    $bmi_class = $bmi < 18.5 ? 'rs-bmi-warn' : ($bmi < 25 ? 'rs-bmi-ok' : ($bmi < 30 ? 'rs-bmi-warn' : 'rs-bmi-bad'));
                    $bmi_label = $bmi < 18.5 ? 'Underweight' : ($bmi < 25 ? 'Normal' : ($bmi < 30 ? 'Overweight' : 'Obese'));
                    echo number_format($bmi,1).' <span class="'.$bmi_class.'" style="font-size:.8rem;">('.$bmi_label.')</span>';
                else: echo 'N/A'; endif; ?>
            </span></div>
            <div class="rs-info-item"><label>Last Check-up</label><span><?php echo $health_profile['last_checkup_date'] ? date('M d, Y', strtotime($health_profile['last_checkup_date'])) : 'Not Set'; ?></span></div>
        </div>
    </div>

    <!-- Medical Conditions -->
    <div class="rs-profile-section">
        <div class="rs-section-head"><i class="fas fa-notes-medical" style="background:var(--rs-orange);"></i> Medical Conditions</div>
        <?php if ($health_profile['medical_conditions']): ?>
        <div class="rs-tags">
            <?php foreach(explode(',', $health_profile['medical_conditions']) as $c): ?>
            <span class="rs-tag--condition"><?php echo htmlspecialchars(trim($c)); ?></span>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p class="rs-muted">No medical conditions recorded</p><?php endif; ?>
    </div>

    <!-- Allergies -->
    <div class="rs-profile-section">
        <div class="rs-section-head"><i class="fas fa-exclamation-triangle" style="background:var(--rs-red);"></i> Allergies</div>
        <?php if ($health_profile['allergies']): ?>
        <div class="rs-tags">
            <?php foreach(explode(',', $health_profile['allergies']) as $a): ?>
            <span class="rs-tag--allergy"><i class="fas fa-allergies"></i><?php echo htmlspecialchars(trim($a)); ?></span>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p class="rs-muted">No known allergies</p><?php endif; ?>
    </div>

    <!-- Current Medications -->
    <div class="rs-profile-section">
        <div class="rs-section-head"><i class="fas fa-pills" style="background:var(--rs-blue);"></i> Current Medications</div>
        <?php if ($health_profile['current_medications']): ?>
        <div class="rs-medications"><?php echo nl2br(htmlspecialchars($health_profile['current_medications'])); ?></div>
        <?php else: ?><p class="rs-muted">No current medications</p><?php endif; ?>
    </div>

    <!-- Emergency Contact -->
    <div class="rs-profile-section">
        <div class="rs-section-head"><i class="fas fa-phone-alt" style="background:var(--rs-green);"></i> Emergency Contact</div>
        <div class="rs-info-grid">
            <div class="rs-info-item"><label>Contact Name</label><span><?php echo htmlspecialchars($health_profile['emergency_contact_name']?:'Not Set'); ?></span></div>
            <div class="rs-info-item"><label>Contact Number</label><span><?php echo htmlspecialchars($health_profile['emergency_contact_number']?:'Not Set'); ?></span></div>
        </div>
    </div>

    <!-- Government IDs -->
    <div class="rs-profile-section">
        <div class="rs-section-head"><i class="fas fa-id-card" style="background:var(--rs-purple);"></i> Government IDs</div>
        <div class="rs-info-grid">
            <div class="rs-info-item"><label>PhilHealth Number</label><span><?php echo htmlspecialchars($health_profile['philhealth_number']?:'Not Set'); ?></span></div>
            <div class="rs-info-item"><label>PWD ID</label><span><?php echo htmlspecialchars($health_profile['pwd_id']?:'Not Set'); ?></span></div>
            <div class="rs-info-item"><label>Senior Citizen ID</label><span><?php echo htmlspecialchars($health_profile['senior_citizen_id']?:'Not Set'); ?></span></div>
        </div>
    </div>

    <?php else: ?>
    <div class="rs-empty">
        <i class="fas fa-user-md"></i>
        <h5>No Health Profile Yet</h5>
        <p>Create your health profile to keep track of your medical information</p>
        <button class="rs-btn rs-btn--primary" onclick="openModal('createProfileModal')"><i class="fas fa-plus"></i> Create Health Profile</button>
    </div>
    <?php endif; ?>
</div>

<!-- Create / Edit Modal -->
<div id="<?php echo $modal_id; ?>" class="rs-modal-overlay">
    <div class="rs-modal">
        <div class="rs-modal__header">
            <div class="rs-modal__title">
                <i class="fas fa-user-md" style="color:var(--rs-blue);"></i>
                <?php echo $health_profile ? 'Update' : 'Create'; ?> Health Profile
            </div>
            <button class="rs-modal__close" onclick="closeModal('<?php echo $modal_id; ?>')">×</button>
        </div>
        <form action="actions/<?php echo $health_profile ? 'update' : 'create'; ?>-health-profile.php" method="POST" class="rs-modal__body">
            <div class="rs-form-grid">
                <div class="rs-form-group">
                    <label class="rs-form-label">Blood Type <span style="color:var(--rs-red);">*</span></label>
                    <select name="blood_type" class="rs-form-control" required>
                        <option value="">Select Blood Type</option>
                        <?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                        <option value="<?php echo $bt; ?>" <?php echo ($health_profile && $health_profile['blood_type']===$bt)?'selected':''; ?>><?php echo $bt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">Height (cm)</label>
                    <input type="number" name="height" class="rs-form-control" step="0.1" value="<?php echo $health_profile['height']??''; ?>">
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">Weight (kg)</label>
                    <input type="number" name="weight" class="rs-form-control" step="0.1" value="<?php echo $health_profile['weight']??''; ?>">
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">Last Check-up Date</label>
                    <input type="date" name="last_checkup_date" class="rs-form-control" value="<?php echo $health_profile['last_checkup_date']??''; ?>">
                </div>
                <div class="rs-form-group full">
                    <label class="rs-form-label">Medical Conditions (comma separated)</label>
                    <input type="text" name="medical_conditions" class="rs-form-control" placeholder="e.g., Diabetes, Hypertension" value="<?php echo htmlspecialchars($health_profile['medical_conditions']??''); ?>">
                </div>
                <div class="rs-form-group full">
                    <label class="rs-form-label">Allergies (comma separated)</label>
                    <input type="text" name="allergies" class="rs-form-control" placeholder="e.g., Penicillin, Peanuts" value="<?php echo htmlspecialchars($health_profile['allergies']??''); ?>">
                </div>
                <div class="rs-form-group full">
                    <label class="rs-form-label">Current Medications</label>
                    <textarea name="current_medications" class="rs-form-control" rows="3"><?php echo htmlspecialchars($health_profile['current_medications']??''); ?></textarea>
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name" class="rs-form-control" value="<?php echo htmlspecialchars($health_profile['emergency_contact_name']??''); ?>">
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">Emergency Contact Number</label>
                    <input type="text" name="emergency_contact_number" class="rs-form-control" value="<?php echo htmlspecialchars($health_profile['emergency_contact_number']??''); ?>">
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">PhilHealth Number</label>
                    <input type="text" name="philhealth_number" class="rs-form-control" value="<?php echo htmlspecialchars($health_profile['philhealth_number']??''); ?>">
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">PWD ID</label>
                    <input type="text" name="pwd_id" class="rs-form-control" value="<?php echo htmlspecialchars($health_profile['pwd_id']??''); ?>">
                </div>
                <div class="rs-form-group">
                    <label class="rs-form-label">Senior Citizen ID</label>
                    <input type="text" name="senior_citizen_id" class="rs-form-control" value="<?php echo htmlspecialchars($health_profile['senior_citizen_id']??''); ?>">
                </div>
            </div>
            <div class="rs-modal__footer">
                <button type="button" class="rs-btn rs-btn--secondary" onclick="closeModal('<?php echo $modal_id; ?>')"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="rs-btn rs-btn--primary"><i class="fas fa-save"></i> Save Profile</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
window.onclick = e => { if (e.target.classList.contains('rs-modal-overlay')) e.target.classList.remove('show'); };
setTimeout(() => { document.querySelectorAll('.rs-alert').forEach(a => { a.style.transition='opacity .4s'; a.style.opacity='0'; setTimeout(()=>a.remove(),400); }); }, 5000);
</script>

<?php include '../../includes/footer.php'; ?>