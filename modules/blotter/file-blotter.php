<?php
/**
 * Resident File Blotter Page
 * Path: modules/blotter/file-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role   = getCurrentUserRole();
$resident_id = getCurrentResidentId();
$user_id     = getCurrentUserId();

if ($user_role !== 'Resident') {
    header('Location: ../dashboard/index.php'); exit();
}

$verify_stmt = $conn->prepare("SELECT is_verified FROM tbl_residents WHERE resident_id = ?");
$verify_stmt->bind_param("i", $resident_id);
$verify_stmt->execute();
$verify_data = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$verify_data || $verify_data['is_verified'] != 1) {
    header('Location: not-verified-blotter.php'); exit();
}

$page_title      = 'File Blotter Complaint';
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complainant_id  = $resident_id;
    $respondent_id   = !empty($_POST['respondent_id'])   ? intval($_POST['respondent_id'])     : null;
    $respondent_name = !empty($_POST['respondent_name']) ? trim($_POST['respondent_name'])      : null;
    $incident_date   = $_POST['incident_date']   ?? '';
    $incident_time   = !empty($_POST['incident_time']) ? $_POST['incident_time'] : null;
    $incident_type   = trim($_POST['incident_type']   ?? '');
    $description     = trim($_POST['description']     ?? '');
    $location        = trim($_POST['location']        ?? '');
    $remarks         = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    $status          = 'Pending';

    $year        = date('Y');
    $count_row   = $conn->query("SELECT COUNT(*) as count FROM tbl_blotter WHERE YEAR(created_at)=YEAR(CURDATE())")->fetch_assoc();
    $case_number = $year . '-' . str_pad($count_row['count'] + 1, 6, '0', STR_PAD_LEFT);

    if (empty($incident_date) || empty($incident_type) || empty($description) || empty($location)) {
        $error_message = 'Please fill in all required fields.';
    } elseif (empty($respondent_id) && empty($respondent_name)) {
        $error_message = 'Please select a respondent or enter their name manually.';
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_blotter (case_number,complainant_id,respondent_id,respondent_name,incident_date,incident_time,incident_type,description,location,status,remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("siissssssss", $case_number, $complainant_id, $respondent_id, $respondent_name, $incident_date, $incident_time, $incident_type, $description, $location, $status, $remarks);

        if ($stmt->execute()) {
            $blotter_id = $conn->insert_id;
            $stmt->close();

            // Notify admins
            $admin_result = $conn->query("SELECT user_id FROM tbl_users WHERE (role LIKE '%Admin%' OR role LIKE '%Staff%') AND is_active=1");
            if ($admin_result && $admin_result->num_rows > 0) {
                $an = $conn->prepare("INSERT INTO tbl_notifications (user_id,title,message,type,reference_type,reference_id,is_read,created_at) VALUES (?,?,?,'blotter_filed','blotter',?,0,NOW())");
                $at = "New Blotter Complaint Filed";
                $am = "New blotter complaint (Case #$case_number) filed. Type: $incident_type.";
                while ($ar = $admin_result->fetch_assoc()) { $an->bind_param("issi",$ar['user_id'],$at,$am,$blotter_id); $an->execute(); }
                $an->close();
            }
            // Notify resident
            $rn = $conn->prepare("INSERT INTO tbl_notifications (user_id,title,message,type,reference_type,reference_id,is_read,created_at) VALUES (?,?,?,'blotter_filed','blotter',?,0,NOW())");
            $rt = "Blotter Complaint Filed Successfully";
            $rm = "Your blotter complaint (Case #$case_number) is now pending review.";
            $rn->bind_param("issi",$user_id,$rt,$rm,$blotter_id);
            $rn->execute(); $rn->close();

            $success_message = "Complaint filed successfully! Case Number: <strong>$case_number</strong>. Barangay officials will be in touch.";
            $_POST = [];
        } else {
            $error_message = 'Error filing complaint: ' . $conn->error;
            $stmt->close();
        }
    }
}

$res_stmt = $conn->prepare("SELECT resident_id, CONCAT(first_name,' ',last_name) as full_name FROM tbl_residents WHERE resident_id != ? ORDER BY last_name,first_name");
$res_stmt->bind_param("i", $resident_id);
$res_stmt->execute();
$residents = $res_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$res_stmt->close();

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a3a4a 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(14,165,233,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(16,185,129,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert--info{background:var(--db-sky-light);color:#0369a1;border-color:var(--db-sky);}
.db-alert--warning{background:var(--db-amber-light);color:#92400e;border-color:var(--db-amber);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;flex-shrink:0;}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--db-border);gap:10px;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:14px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__body{padding:22px;}

/* ── Form Controls ── */
.db-form-group{margin-bottom:18px;}
.db-form-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);display:block;margin-bottom:7px;}
.db-form-label span{color:var(--db-rose);margin-left:2px;}
.db-form-control{width:100%;border:2px solid var(--db-border);border-radius:var(--db-radius-sm);padding:10px 14px;font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);transition:border-color .18s,box-shadow .18s;outline:none;}
.db-form-control:focus{border-color:var(--db-sky);box-shadow:0 0 0 4px rgba(14,165,233,.1);}
.db-form-control::placeholder{color:#b0bec5;}
textarea.db-form-control{resize:vertical;min-height:120px;line-height:1.6;}
.db-form-hint{font-size:11.5px;color:var(--db-muted);margin-top:5px;}

/* ── Respondent Section ── */
.db-respondent-box{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:18px;}
.db-or-divider{text-align:center;font-size:11px;color:var(--db-muted);font-weight:600;letter-spacing:.5px;margin:14px 0;position:relative;}
.db-or-divider::before,.db-or-divider::after{content:'';position:absolute;top:50%;width:calc(50% - 24px);height:1px;background:var(--db-border);}
.db-or-divider::before{left:0;}
.db-or-divider::after{right:0;}

/* ── Grid ── */
.db-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.db-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;}

/* ── Checkbox ── */
.db-check{display:flex;align-items:flex-start;gap:10px;padding:14px 16px;background:var(--db-surf2);border:2px solid var(--db-border);border-radius:var(--db-radius-sm);margin-bottom:20px;transition:border-color .18s;}
.db-check:has(input:checked){border-color:var(--db-sky);background:var(--db-sky-light);}
.db-check input{width:18px;height:18px;margin-top:1px;accent-color:var(--db-sky);flex-shrink:0;cursor:pointer;}
.db-check label{font-size:12.5px;line-height:1.6;color:var(--db-text);cursor:pointer;}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--lg{padding:12px 28px;font-size:14px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn-group{display:flex;flex-direction:column;gap:10px;}

/* ── Sidebar Info Blocks ── */
.db-info-block{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:16px;margin-bottom:14px;}
.db-info-block__title{font-size:12px;font-weight:700;color:var(--db-text);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.db-info-block__title i{color:var(--db-sky);}
.db-info-block ul{margin:0;padding-left:16px;}
.db-info-block ul li{font-size:12px;color:var(--db-muted);margin-bottom:5px;line-height:1.5;}
.db-warning-block{background:var(--db-rose-light);border:1px solid #fecdd3;border-radius:var(--db-radius);padding:16px;}
.db-warning-block__title{font-size:12px;font-weight:700;color:#9f1239;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.db-warning-block ul{margin:0;padding-left:16px;}
.db-warning-block ul li{font-size:12px;color:#be123c;margin-bottom:5px;line-height:1.5;}

/* ── Modal ── */
.db-modal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(13,27,54,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;}
.db-modal.open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);box-shadow:var(--db-shadow-lg);width:100%;max-width:500px;overflow:hidden;animation:dbFadeUp .2s ease;}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header h3{font-size:15px;font-weight:700;color:#fff;margin:0;}
.db-modal__close{background:none;border:none;color:rgba(255,255,255,.7);font-size:20px;cursor:pointer;line-height:1;padding:0;}
.db-modal__body{padding:22px;}
.db-modal__footer{padding:16px 22px;border-top:1px solid var(--db-border);display:flex;justify-content:flex-end;gap:10px;}
.db-summary-row{display:flex;flex-direction:column;gap:2px;margin-bottom:14px;}
.db-summary-row:last-child{margin-bottom:0;}
.db-summary-label{font-family:'DM Mono',monospace;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);}
.db-summary-value{font-size:13px;font-weight:600;color:var(--db-text);line-height:1.5;}
.db-summary-value.scrollable{max-height:100px;overflow-y:auto;background:var(--db-surf2);padding:8px 10px;border-radius:var(--db-radius-sm);border:1px solid var(--db-border);}
.db-modal-warning{background:var(--db-amber-light);border:1px solid #fde68a;border-radius:var(--db-radius-sm);padding:12px 14px;font-size:12px;color:var(--db-amber-dark);margin-bottom:16px;}

@media(max-width:768px){
    .rm-hero{padding:20px;border-radius:0;}
    .db-grid-2,.db-grid-3{grid-template-columns:1fr;}
}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">File Blotter Complaint</div>
                <div class="rm-hero__sub">Submit a complaint to the barangay office</div>
            </div>
        </div>
        <a href="my-blotter.php" class="db-btn db-btn--ghost"><i class="fas fa-arrow-left"></i> Back to My Blotter</a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($success_message): ?>
<div class="db-alert db-alert--success">
    <i class="fas fa-check-circle" style="flex-shrink:0;font-size:16px;"></i>
    <div><?= $success_message ?> <a href="my-blotter.php" style="color:#065f46;font-weight:700;margin-left:8px;"><i class="fas fa-eye"></i> View Records</a></div>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i> <?= htmlspecialchars($error_message) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Info notice -->
<div class="db-alert db-alert--info" id="infoAlert">
    <i class="fas fa-info-circle" style="flex-shrink:0;font-size:16px;"></i>
    <div>
        <strong>Before Filing:</strong> Provide accurate and truthful information. Filing false complaints may result in legal consequences.
    </div>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:18px;align-items:start;">

    <!-- Main Form -->
    <div>
        <form method="POST" id="blotterForm">

            <!-- Respondent -->
            <div class="db-panel" style="animation-delay:.05s">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-user-shield"></i></div>
                        <h2>Who are you filing against?</h2>
                    </div>
                </div>
                <div class="db-panel__body">
                    <div class="db-respondent-box">
                        <div class="db-form-group" style="margin-bottom:0;">
                            <label class="db-form-label">Select from Registered Residents</label>
                            <select name="respondent_id" id="respondent_id" class="db-form-control">
                                <option value="">— Select Respondent —</option>
                                <?php foreach ($residents as $res): ?>
                                <option value="<?= $res['resident_id'] ?>" <?= (isset($_POST['respondent_id']) && $_POST['respondent_id'] == $res['resident_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($res['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="db-or-divider">OR</div>
                        <div class="db-form-group" style="margin-bottom:0;">
                            <label class="db-form-label">Enter Name Manually <span style="font-family:'Sora';text-transform:none;letter-spacing:0;font-size:11px;color:var(--db-muted);">(if not in list)</span></label>
                            <input type="text" name="respondent_name" id="respondent_name" class="db-form-control" placeholder="Enter respondent's full name" value="<?= htmlspecialchars($_POST['respondent_name'] ?? '') ?>">
                            <div class="db-form-hint">Use this only if the person is not a registered resident.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Incident Details -->
            <div class="db-panel" style="animation-delay:.1s">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-calendar-alt"></i></div>
                        <h2>Incident Details</h2>
                    </div>
                </div>
                <div class="db-panel__body">
                    <div class="db-grid-2">
                        <div class="db-form-group">
                            <label class="db-form-label">Incident Date <span>*</span></label>
                            <input type="date" name="incident_date" id="incident_date" class="db-form-control" value="<?= $_POST['incident_date'] ?? '' ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Incident Time <span style="color:var(--db-muted);font-size:9px;">(Optional)</span></label>
                            <input type="time" name="incident_time" id="incident_time" class="db-form-control" value="<?= $_POST['incident_time'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="db-grid-2">
                        <div class="db-form-group">
                            <label class="db-form-label">Incident Type <span>*</span></label>
                            <select name="incident_type" id="incident_type" class="db-form-control" required>
                                <option value="">Select Type</option>
                                <?php
                                $types = ['Noise Complaint','Physical Assault','Verbal Abuse','Theft','Property Damage','Boundary Dispute','Domestic Violence','Harassment','Others'];
                                foreach ($types as $t):
                                ?>
                                <option value="<?= $t ?>" <?= (isset($_POST['incident_type']) && $_POST['incident_type']===$t)?'selected':'' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Location <span>*</span></label>
                            <input type="text" name="location" id="location" class="db-form-control" placeholder="Exact location of incident" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="db-form-group">
                        <label class="db-form-label">Detailed Description <span>*</span></label>
                        <textarea name="description" id="description" class="db-form-control" rows="6" placeholder="Describe what happened in detail. Include all relevant facts, witnesses, and other important information…" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        <div class="db-form-hint"><i class="fas fa-info-circle" style="margin-right:4px;"></i>Be as detailed as possible to help barangay officials understand and resolve your complaint.</div>
                    </div>
                    <div class="db-form-group" style="margin-bottom:0;">
                        <label class="db-form-label">Additional Information <span style="color:var(--db-muted);font-size:9px;">(Optional)</span></label>
                        <textarea name="remarks" id="remarks" class="db-form-control" rows="3" placeholder="Evidence, witnesses, or any other relevant information…"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Agreement & Submit -->
            <div class="db-panel" style="animation-delay:.15s">
                <div class="db-panel__body">
                    <div class="db-check">
                        <input type="checkbox" id="agreement" required>
                        <label for="agreement">I hereby declare that all information provided above is true and correct to the best of my knowledge. I understand that filing false complaints may result in legal consequences.</label>
                    </div>
                    <div class="db-btn-group">
                        <button type="button" class="db-btn db-btn--primary db-btn--lg" id="submitBtn" style="justify-content:center;">
                            <i class="fas fa-paper-plane"></i> Submit Complaint
                        </button>
                        <a href="my-blotter.php" class="db-btn db-btn--ghost" style="justify-content:center;">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </div>

        </form>
    </div>

    <!-- Sidebar -->
    <div style="position:sticky;top:20px;">
        <div class="db-info-block">
            <div class="db-info-block__title"><i class="fas fa-info-circle"></i> Guidelines</div>
            <ul>
                <li>Provide accurate and complete information</li>
                <li>Include all relevant facts and details</li>
                <li>Mention any witnesses if applicable</li>
                <li>Attach supporting information if available</li>
                <li>For emergencies, call <strong>911</strong> immediately</li>
                <li>You will be given a case number for tracking</li>
            </ul>
        </div>
        <div class="db-warning-block">
            <div class="db-warning-block__title"><i class="fas fa-exclamation-triangle"></i> Important Warning</div>
            <ul>
                <li>All complaints are recorded and reviewed</li>
                <li>Filing a false complaint is a punishable offense</li>
                <li>Barangay officials may call you for follow-up</li>
                <li>Cases are handled with confidentiality</li>
            </ul>
        </div>
    </div>

</div>
</div>

<!-- Confirm Modal -->
<div class="db-modal" id="confirmModal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i> Confirm Submission</h3>
            <button class="db-modal__close" onclick="document.getElementById('confirmModal').classList.remove('open')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-modal-warning"><i class="fas fa-shield-alt" style="margin-right:6px;"></i> Please review your complaint carefully. Once submitted, it will be forwarded to barangay officials.</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px;">
                <div class="db-summary-row">
                    <div class="db-summary-label">Respondent</div>
                    <div class="db-summary-value" id="s_respondent">—</div>
                </div>
                <div class="db-summary-row">
                    <div class="db-summary-label">Incident Type</div>
                    <div class="db-summary-value" id="s_type">—</div>
                </div>
                <div class="db-summary-row">
                    <div class="db-summary-label">Date</div>
                    <div class="db-summary-value" id="s_date">—</div>
                </div>
                <div class="db-summary-row">
                    <div class="db-summary-label">Time</div>
                    <div class="db-summary-value" id="s_time">—</div>
                </div>
            </div>
            <div class="db-summary-row">
                <div class="db-summary-label">Location</div>
                <div class="db-summary-value" id="s_location">—</div>
            </div>
            <div class="db-summary-row" style="margin-bottom:0;">
                <div class="db-summary-label">Description</div>
                <div class="db-summary-value scrollable" id="s_description">—</div>
            </div>
        </div>
        <div class="db-modal__footer">
            <button type="button" class="db-btn db-btn--ghost" onclick="document.getElementById('confirmModal').classList.remove('open')"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" class="db-btn db-btn--primary" id="confirmSubmitBtn"><i class="fas fa-check"></i> Confirm & Submit</button>
        </div>
    </div>
</div>

<script>
// Mutual exclusivity: dropdown ↔ manual name
document.getElementById('respondent_id').addEventListener('change', function() {
    const mn = document.getElementById('respondent_name');
    if (this.value) { mn.value = ''; mn.disabled = true; } else { mn.disabled = false; }
});
document.getElementById('respondent_name').addEventListener('input', function() {
    const dd = document.getElementById('respondent_id');
    if (this.value.trim()) { dd.value = ''; dd.disabled = true; } else { dd.disabled = false; }
});

// Open confirm modal
document.getElementById('submitBtn').addEventListener('click', function() {
    const form = document.getElementById('blotterForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    if (!document.getElementById('agreement').checked) { alert('Please agree to the declaration before submitting.'); return; }

    const rid  = document.getElementById('respondent_id').value;
    const rnam = document.getElementById('respondent_name').value.trim();
    if (!rid && !rnam) { alert('Please select a respondent or enter their name manually.'); return; }

    let respondentDisplay = rnam;
    if (rid) {
        const opt = document.querySelector(`#respondent_id option[value="${rid}"]`);
        respondentDisplay = opt ? opt.textContent.trim() : '—';
    }

    const d = document.getElementById('incident_date').value;
    document.getElementById('s_respondent').textContent  = respondentDisplay || '—';
    document.getElementById('s_type').textContent        = document.getElementById('incident_type').value || '—';
    document.getElementById('s_date').textContent        = d ? new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : '—';
    document.getElementById('s_time').textContent        = document.getElementById('incident_time').value || 'Not specified';
    document.getElementById('s_location').textContent    = document.getElementById('location').value || '—';
    document.getElementById('s_description').textContent = document.getElementById('description').value || '—';

    document.getElementById('confirmModal').classList.add('open');
});

document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
    document.getElementById('blotterForm').submit();
});

document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});

setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => { a.style.opacity='0'; setTimeout(()=>a.remove(),400); }), 6000);
</script>
<?php include '../../includes/footer.php'; ?>