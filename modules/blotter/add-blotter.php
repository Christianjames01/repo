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
<?php include '/home/claude/_db_shared.css'; ?>
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
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<form method="POST" id="blotterForm">
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
                <div style="font-size:11px;color:var(--db-muted);margin-top:5px;"><i class="fas fa-info-circle me-1"></i>Person filing the complaint</div>
            </div>
            <div class="col-md-6">
                <label class="db-form-label">Respondent <span style="color:var(--db-muted);font-weight:400;">(optional)</span></label>
                <select name="respondent_id" id="respondent_id" class="db-form-control">
                    <option value="">Select Respondent (Optional)</option>
                    <?php foreach($residents as $r): ?>
                    <option value="<?php echo $r['resident_id']; ?>" <?php echo(isset($_POST['respondent_id'])&&$_POST['respondent_id']==$r['resident_id'])?'selected':''; ?>><?php echo htmlspecialchars($r['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:11px;color:var(--db-muted);margin-top:5px;"><i class="fas fa-info-circle me-1"></i>Person being complained about</div>
            </div>
        </div>
    </div>
</div>

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
                <input type="date" name="incident_date" class="db-form-control" value="<?php echo $_POST['incident_date']??''; ?>" required max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-4">
                <label class="db-form-label">Incident Time <span style="color:var(--db-muted);font-weight:400;">(optional)</span></label>
                <input type="time" name="incident_time" class="db-form-control" value="<?php echo $_POST['incident_time']??''; ?>">
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
                <input type="text" name="location" class="db-form-control" placeholder="Enter incident location" value="<?php echo htmlspecialchars($_POST['location']??''); ?>" required>
                <div style="font-size:11px;color:var(--db-muted);margin-top:5px;"><i class="fas fa-info-circle me-1"></i>Specific address or landmark</div>
            </div>
        </div>
    </div>
</div>

<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-file-alt"></i></div>
            <h2>Description & Remarks</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <div class="row g-3">
            <div class="col-12">
                <label class="db-form-label">Description <span style="color:var(--db-rose)">*</span></label>
                <textarea name="description" class="db-form-control" rows="6" placeholder="Provide detailed description of the incident…" required><?php echo htmlspecialchars($_POST['description']??''); ?></textarea>
                <div style="font-size:11px;color:var(--db-muted);margin-top:5px;"><i class="fas fa-info-circle me-1"></i>Include all relevant details</div>
            </div>
            <div class="col-12">
                <label class="db-form-label">Remarks <span style="color:var(--db-muted);font-weight:400;">(optional)</span></label>
                <textarea name="remarks" class="db-form-control" rows="3" placeholder="Additional remarks…"><?php echo htmlspecialchars($_POST['remarks']??''); ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- Action bar -->
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:var(--db-surf);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:16px 20px;box-shadow:var(--db-shadow);">
    <small style="color:var(--db-muted);font-size:12px;"><i class="fas fa-info-circle me-1"></i>Fields marked <span style="color:var(--db-rose);">*</span> are required</small>
    <div style="display:flex;gap:10px;">
        <a href="manage-blotter.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Cancel</a>
        <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Save Blotter Record</button>
    </div>
</div>
</form>
</div>

<script>
document.getElementById('complainant_id').addEventListener('change',function(){
    const r=document.getElementById('respondent_id');
    if(r.value===this.value) r.value='';
});
document.getElementById('respondent_id').addEventListener('change',function(){
    const c=document.getElementById('complainant_id');
    if(this.value&&this.value===c.value){alert('Complainant and Respondent cannot be the same person!');this.value='';}
});
document.getElementById('blotterForm').addEventListener('submit',function(e){
    const c=document.getElementById('complainant_id').value;
    const r=document.getElementById('respondent_id').value;
    if(c&&r&&c===r){e.preventDefault();alert('Complainant and Respondent cannot be the same person!');}
});
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include '../../includes/footer.php'; ?>