<?php
/**
 * Add Blotter Record — redesigned to match db design system
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
if ($user_role === 'Resident') { header('Location: my-blotter.php'); exit(); }

$page_title = 'Add Blotter Record';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complainant_id = intval($_POST['complainant_id']);
    $respondent_id  = !empty($_POST['respondent_id']) ? intval($_POST['respondent_id']) : null;
    $incident_date  = $_POST['incident_date'];
    $incident_time  = !empty($_POST['incident_time']) ? $_POST['incident_time'] : null;
    $incident_type  = trim($_POST['incident_type']);
    $description    = trim($_POST['description']);
    $location       = trim($_POST['location']);
    $status         = $_POST['status'];
    $remarks        = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;

    $year  = date('Y');
    $count = $conn->query("SELECT COUNT(*) as count FROM tbl_blotter WHERE YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc()['count'];
    $case_number = $year.'-'.str_pad($count+1,6,'0',STR_PAD_LEFT);

    if (empty($complainant_id)||empty($incident_date)||empty($incident_type)||empty($description)) {
        $_SESSION['error_message'] = 'Please fill in all required fields.';
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_blotter (case_number,complainant_id,respondent_id,incident_date,incident_time,incident_type,description,location,status,remarks) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("siisssssss",$case_number,$complainant_id,$respondent_id,$incident_date,$incident_time,$incident_type,$description,$location,$status,$remarks);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Blotter record added! Case #: $case_number";
            header('Location: add-blotter.php'); exit();
        } else {
            $_SESSION['error_message'] = 'Error adding record: '.$conn->error;
        }
        $stmt->close();
    }
}

$residents = $conn->query("SELECT resident_id, CONCAT(first_name,' ',last_name) as full_name FROM tbl_residents ORDER BY last_name,first_name")->fetch_all(MYSQLI_ASSOC);

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0;}
.rm-hero__icon--sky{background:linear-gradient(135deg,var(--db-sky),#0284c7);box-shadow:0 4px 16px rgba(14,165,233,.4);}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-panel__body{padding:20px 22px;}
.db-form-label{display:block;font-size:12px;font-weight:600;color:var(--db-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.db-form-control{width:100%;padding:10px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;}
.db-form-control:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
textarea.db-form-control{resize:vertical;}
select.db-form-control{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 13px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);color:var(--db-text);}
.db-btn--glass{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}
.db-btn--glass:hover{background:rgba(255,255,255,.22);color:#fff;}
@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon rm-hero__icon--sky"><i class="fas fa-plus-circle"></i></div>
            <div>
                <div class="rm-hero__title">Add Blotter Record</div>
                <div class="rm-hero__sub">Create a new blotter entry in the system</div>
            </div>
        </div>
        <div class="rm-hero__badges">
            <a href="manage-blotter.php" class="db-btn db-btn--glass db-btn--sm"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success">
    <i class="fas fa-check-circle"></i>
    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error">
    <i class="fas fa-exclamation-circle"></i>
    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<form method="POST" id="blotterForm">

<!-- Parties Involved -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-users"></i></div>
            <h2>Parties Involved</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="db-form-label">Complainant <span style="color:var(--db-rose)">*</span></label>
                <select name="complainant_id" id="complainant_id" class="db-form-control" required>
                    <option value="">Select Complainant</option>
                    <?php foreach($residents as $r): ?>
                    <option value="<?php echo $r['resident_id']; ?>" <?php echo(isset($_POST['complainant_id'])&&$_POST['complainant_id']==$r['resident_id'])?'selected':''; ?>><?php echo htmlspecialchars($r['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:11px;color:var(--db-muted);margin-top:6px;"><i class="fas fa-info-circle me-1"></i>Person filing the complaint</div>
            </div>
            <div class="col-md-6">
                <label class="db-form-label">Respondent <span style="color:var(--db-muted);font-weight:400;text-transform:none;">(optional)</span></label>
                <select name="respondent_id" id="respondent_id" class="db-form-control">
                    <option value="">Select Respondent (Optional)</option>
                    <?php foreach($residents as $r): ?>
                    <option value="<?php echo $r['resident_id']; ?>" <?php echo(isset($_POST['respondent_id'])&&$_POST['respondent_id']==$r['resident_id'])?'selected':''; ?>><?php echo htmlspecialchars($r['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:11px;color:var(--db-muted);margin-top:6px;"><i class="fas fa-info-circle me-1"></i>Person being complained about</div>
            </div>
        </div>
    </div>
</div>

<!-- Incident Details -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-exclamation-triangle"></i></div>
            <h2>Incident Details</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="db-form-label">Incident Date <span style="color:var(--db-rose)">*</span></label>
                <input type="date" name="incident_date" class="db-form-control"
                       value="<?php echo htmlspecialchars($_POST['incident_date']??''); ?>"
                       required max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-4">
                <label class="db-form-label">Incident Time <span style="color:var(--db-muted);font-weight:400;text-transform:none;">(optional)</span></label>
                <input type="time" name="incident_time" class="db-form-control"
                       value="<?php echo htmlspecialchars($_POST['incident_time']??''); ?>">
            </div>
            <div class="col-md-4">
                <label class="db-form-label">Status <span style="color:var(--db-rose)">*</span></label>
                <select name="status" class="db-form-control" required>
                    <?php foreach(['Pending','Under Investigation','Resolved'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo(isset($_POST['status'])&&$_POST['status']===$s)?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="db-form-label">Incident Type <span style="color:var(--db-rose)">*</span></label>
                <select name="incident_type" class="db-form-control" required>
                    <option value="">Select Type</option>
                    <?php foreach(['Noise Complaint','Physical Assault','Verbal Abuse','Theft','Property Damage','Boundary Dispute','Domestic Violence','Others'] as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo(isset($_POST['incident_type'])&&$_POST['incident_type']===$t)?'selected':''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="db-form-label">Location <span style="color:var(--db-rose)">*</span></label>
                <input type="text" name="location" class="db-form-control"
                       placeholder="Enter incident location"
                       value="<?php echo htmlspecialchars($_POST['location']??''); ?>" required>
                <div style="font-size:11px;color:var(--db-muted);margin-top:6px;"><i class="fas fa-map-marker-alt me-1"></i>Specific address or landmark</div>
            </div>
        </div>
    </div>
</div>

<!-- Description & Remarks -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-file-alt"></i></div>
            <h2>Description &amp; Remarks</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <div class="row g-3">
            <div class="col-12">
                <label class="db-form-label">Description <span style="color:var(--db-rose)">*</span></label>
                <textarea name="description" class="db-form-control" rows="6"
                          placeholder="Provide a detailed description of the incident…"
                          required><?php echo htmlspecialchars($_POST['description']??''); ?></textarea>
                <div style="font-size:11px;color:var(--db-muted);margin-top:6px;"><i class="fas fa-info-circle me-1"></i>Include all relevant details about what happened</div>
            </div>
            <div class="col-12">
                <label class="db-form-label">Remarks <span style="color:var(--db-muted);font-weight:400;text-transform:none;">(optional)</span></label>
                <textarea name="remarks" class="db-form-control" rows="3"
                          placeholder="Additional remarks or notes…"><?php echo htmlspecialchars($_POST['remarks']??''); ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- Action bar -->
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:var(--db-surf);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:16px 22px;box-shadow:var(--db-shadow);">
    <small style="color:var(--db-muted);font-size:12px;">
        <i class="fas fa-info-circle me-1"></i>Fields marked <span style="color:var(--db-rose);">*</span> are required
    </small>
    <div style="display:flex;gap:10px;">
        <a href="manage-blotter.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Cancel</a>
        <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Save Blotter Record</button>
    </div>
</div>

</form>
</div>

<script>
document.getElementById('complainant_id').addEventListener('change', function() {
    const r = document.getElementById('respondent_id');
    if (r.value === this.value) r.value = '';
});
document.getElementById('respondent_id').addEventListener('change', function() {
    const c = document.getElementById('complainant_id');
    if (this.value && this.value === c.value) {
        alert('Complainant and Respondent cannot be the same person!');
        this.value = '';
    }
});
document.getElementById('blotterForm').addEventListener('submit', function(e) {
    const c = document.getElementById('complainant_id').value;
    const r = document.getElementById('respondent_id').value;
    if (c && r && c === r) { e.preventDefault(); alert('Complainant and Respondent cannot be the same person!'); }
});
setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => {
    a.style.transition = 'opacity .4s';
    a.style.opacity = '0';
    setTimeout(() => a.remove(), 400);
}), 5000);
</script>
<?php include '../../includes/footer.php'; ?>