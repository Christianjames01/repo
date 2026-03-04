<?php
require_once('../../../config/config.php');
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Recycling Management";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_center':
            $stmt = $conn->prepare("INSERT INTO tbl_recycling_centers (center_name, location, contact_person, contact_number, operating_hours, accepted_materials, services_offered, status, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
            $cn=$_POST['center_name']; $lo=$_POST['location']; $cp=$_POST['contact_person']; $cc=$_POST['contact_number']; $oh=$_POST['operating_hours']; $am=$_POST['accepted_materials']; $so=$_POST['services_offered']; $st=$_POST['status'];
            $stmt->bind_param("ssssssss",$cn,$lo,$cp,$cc,$oh,$am,$so,$st);
            $_SESSION['temp_success'] = $stmt->execute() ? "Recycling center added!" : "Failed to add center.";
            header('Location: recycling.php'); exit;
        case 'update_center':
            $id=(int)$_POST['center_id'];
            $stmt = $conn->prepare("UPDATE tbl_recycling_centers SET center_name=?,location=?,contact_person=?,contact_number=?,operating_hours=?,accepted_materials=?,services_offered=?,status=? WHERE center_id=?");
            $cn=$_POST['center_name']; $lo=$_POST['location']; $cp=$_POST['contact_person']; $cc=$_POST['contact_number']; $oh=$_POST['operating_hours']; $am=$_POST['accepted_materials']; $so=$_POST['services_offered']; $st=$_POST['status'];
            $stmt->bind_param("ssssssssi",$cn,$lo,$cp,$cc,$oh,$am,$so,$st,$id);
            $_SESSION['temp_success'] = $stmt->execute() ? "Center updated!" : "Failed to update center.";
            header('Location: recycling.php'); exit;
        case 'delete_center':
            $id=(int)$_POST['center_id'];
            $stmt=$conn->prepare("DELETE FROM tbl_recycling_centers WHERE center_id=?");
            $stmt->bind_param("i",$id);
            $_SESSION['temp_success'] = $stmt->execute() ? "Center deleted!" : "Failed to delete.";
            header('Location: recycling.php'); exit;
        case 'add_participant':
            $prog=(int)$_POST['program_id']; $res=(int)$_POST['resident_id']; $pts=(int)$_POST['points_earned']; $st=sanitizeInput($_POST['status']);
            $chk=$conn->prepare("SELECT participant_id FROM tbl_recycling_participants WHERE program_id=? AND resident_id=?");
            $chk->bind_param("ii",$prog,$res); $chk->execute(); $cr=$chk->get_result();
            if ($cr->num_rows>0) {
                $eid=$cr->fetch_assoc()['participant_id'];
                $stmt=$conn->prepare("UPDATE tbl_recycling_participants SET points_earned=?,status=? WHERE participant_id=?");
                $stmt->bind_param("isi",$pts,$st,$eid);
                $_SESSION['temp_success'] = $stmt->execute() ? "Participant updated (already enrolled)." : "Failed to update.";
            } else {
                $stmt=$conn->prepare("INSERT INTO tbl_recycling_participants (program_id,resident_id,points_earned,status) VALUES(?,?,?,?)");
                $stmt->bind_param("iiis",$prog,$res,$pts,$st);
                $_SESSION['temp_success'] = $stmt->execute() ? "Participant added!" : "Failed to add.";
            }
            header('Location: recycling.php?tab=participants'); exit;
        case 'update_participant':
            $id=(int)$_POST['participant_id']; $pts=(int)$_POST['points_earned']; $st=sanitizeInput($_POST['status']);
            $stmt=$conn->prepare("UPDATE tbl_recycling_participants SET points_earned=?,status=? WHERE participant_id=?");
            $stmt->bind_param("isi",$pts,$st,$id);
            $_SESSION['temp_success'] = $stmt->execute() ? "Participant updated!" : "Failed to update.";
            header('Location: recycling.php?tab=participants'); exit;
    }
}

$success_message = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }

$active_tab = $_GET['tab'] ?? 'centers';
$centers_result      = $conn->query("SELECT * FROM tbl_recycling_centers ORDER BY created_at DESC");
$participants_result = $conn->query("SELECT rp.*, CONCAT(r.first_name,' ',r.last_name) as resident_name, prog.program_name FROM tbl_recycling_participants rp LEFT JOIN tbl_residents r ON rp.resident_id=r.resident_id LEFT JOIN tbl_recycling_programs prog ON rp.program_id=prog.program_id ORDER BY rp.participant_id DESC");
$programs_dd         = $conn->query("SELECT program_id, program_name FROM tbl_recycling_programs WHERE status='active'");
$residents_dd        = $conn->query("SELECT resident_id, CONCAT(first_name,' ',last_name) as full_name FROM tbl_residents ORDER BY last_name, first_name");

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
require_once '../../../includes/header.php';
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#6366f1,#4f46e5);">
                <i class="fas fa-recycle" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Waste Management
                </div>
                <h1 class="db-hero__title">Recycling Management</h1>
                <p class="db-hero__sub">Manage recycling centers and program participants</p>
            </div>
        </div>
        <div class="db-hero__right">
            <?php if ($active_tab === 'centers'): ?>
            <button class="db-btn db-btn--primary" onclick="openModal('addCenterModal')">
                <i class="fas fa-plus"></i> Add Center
            </button>
            <?php else: ?>
            <button class="db-btn db-btn--primary" onclick="openModal('addParticipantModal')">
                <i class="fas fa-user-plus"></i> Add Participant
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($success_message): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($success_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ═══ TAB NAV ═══ -->
<div style="display:flex;gap:6px;margin-bottom:18px;">
    <a href="?tab=centers"
       class="db-btn <?php echo $active_tab==='centers' ? 'db-btn--primary' : 'db-btn--ghost'; ?>">
        <i class="fas fa-building"></i> Recycling Centers
    </a>
    <a href="?tab=participants"
       class="db-btn <?php echo $active_tab==='participants' ? 'db-btn--primary' : 'db-btn--ghost'; ?>">
        <i class="fas fa-users"></i> Program Participants
    </a>
</div>


<?php if ($active_tab === 'centers'): ?>
<!-- ═══ CENTERS TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-building"></i></span>
            <h2>Recycling Centers</h2>
        </div>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addCenterModal')">
            <i class="fas fa-plus"></i> Add Center
        </button>
    </div>

    <?php if ($centers_result && $centers_result->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>ID</th><th>Center Name</th><th>Location</th><th>Contact</th><th>Hours</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php while ($c = $centers_result->fetch_assoc()): ?>
            <tr>
                <td><span class="db-id">#<?php echo str_pad($c['center_id'],4,'0',STR_PAD_LEFT); ?></span></td>
                <td><strong><?php echo htmlspecialchars($c['center_name']); ?></strong></td>
                <td><span class="db-text-sm"><i class="fas fa-map-marker-alt me-1" style="color:var(--db-rose)"></i><?php echo htmlspecialchars($c['location']); ?></span></td>
                <td>
                    <strong><?php echo htmlspecialchars($c['contact_person']); ?></strong><br>
                    <span class="db-text-sm"><?php echo htmlspecialchars($c['contact_number']); ?></span>
                </td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($c['operating_hours']); ?></span></td>
                <td><span class="db-badge <?php echo $c['status']==='active' ? 'db-badge--success' : 'db-badge--muted'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                <td>
                    <div class="db-btn-group">
                        <button class="db-icon-btn db-icon-btn--info" onclick='viewCenter(<?php echo json_encode($c); ?>)' title="View"><i class="fas fa-eye"></i></button>
                        <button class="db-icon-btn db-icon-btn--primary" onclick='editCenter(<?php echo json_encode($c); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="db-icon-btn db-icon-btn--danger" onclick='openDeleteCenterModal(<?php echo $c["center_id"]; ?>,<?php echo json_encode($c["center_name"]); ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-building"></i><p>No recycling centers found.</p>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addCenterModal')"><i class="fas fa-plus"></i> Add Center</button>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'participants'): ?>
<!-- ═══ PARTICIPANTS TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-users"></i></span>
            <h2>Program Participants</h2>
        </div>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addParticipantModal')">
            <i class="fas fa-user-plus"></i> Add Participant
        </button>
    </div>

    <?php if ($participants_result && $participants_result->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>ID</th><th>Resident Name</th><th>Program</th><th>Points Earned</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php while ($part = $participants_result->fetch_assoc()): ?>
            <tr>
                <td><span class="db-id">#<?php echo str_pad($part['participant_id'],4,'0',STR_PAD_LEFT); ?></span></td>
                <td><strong><?php echo htmlspecialchars($part['resident_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($part['program_name'] ?? '—'); ?></td>
                <td>
                    <span class="db-badge db-badge--info" style="font-family:'DM Mono',monospace;">
                        <i class="fas fa-star me-1"></i><?php echo number_format($part['points_earned']); ?> pts
                    </span>
                </td>
                <td><span class="db-badge <?php echo $part['status']==='active' ? 'db-badge--success' : 'db-badge--muted'; ?>"><?php echo ucfirst($part['status']); ?></span></td>
                <td>
                    <button class="db-icon-btn db-icon-btn--primary" onclick='editParticipant(<?php echo json_encode($part); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-users"></i><p>No participants found.</p>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addParticipantModal')"><i class="fas fa-user-plus"></i> Add Participant</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ═══ ADD CENTER MODAL ═══ -->
<div id="addCenterModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-plus-circle"></i> Add Recycling Center</h3>
            <button class="db-modal__close" onclick="closeModal('addCenterModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="add_center">
            <div class="db-field"><label>Center Name <span class="req">*</span></label><input type="text" name="center_name" class="db-input" required></div>
            <div class="db-field"><label>Location <span class="req">*</span></label><input type="text" name="location" class="db-input" required></div>
            <div class="db-field-row">
                <div class="db-field"><label>Contact Person <span class="req">*</span></label><input type="text" name="contact_person" class="db-input" required></div>
                <div class="db-field"><label>Contact Number <span class="req">*</span></label><input type="text" name="contact_number" class="db-input" required></div>
            </div>
            <div class="db-field"><label>Operating Hours <span class="req">*</span></label><input type="text" name="operating_hours" class="db-input" placeholder="e.g., Mon–Sat 8:00 AM – 5:00 PM" required></div>
            <div class="db-field"><label>Accepted Materials <span class="req">*</span></label><textarea name="accepted_materials" class="db-input" rows="2" placeholder="e.g., Paper, Plastic, Metal, Glass" required></textarea></div>
            <div class="db-field"><label>Services Offered <span class="req">*</span></label><textarea name="services_offered" class="db-input" rows="2" placeholder="e.g., Buyback, Drop-off, Educational tours" required></textarea></div>
            <div class="db-field"><label>Status <span class="req">*</span></label>
                <select name="status" class="db-input" required><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Add Center</button>
        </form>
    </div>
</div>

<!-- ═══ VIEW CENTER MODAL ═══ -->
<div id="viewCenterModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-building"></i> Center Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewCenterModal')">×</button>
        </div>
        <div class="db-modal__body" id="centerDetailsContent"></div>
    </div>
</div>

<!-- ═══ EDIT CENTER MODAL ═══ -->
<div id="editCenterModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Recycling Center</h3>
            <button class="db-modal__close" onclick="closeModal('editCenterModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="update_center">
            <input type="hidden" name="center_id" id="edit_center_id">
            <div class="db-field"><label>Center Name <span class="req">*</span></label><input type="text" name="center_name" id="edit_center_name" class="db-input" required></div>
            <div class="db-field"><label>Location <span class="req">*</span></label><input type="text" name="location" id="edit_location" class="db-input" required></div>
            <div class="db-field-row">
                <div class="db-field"><label>Contact Person <span class="req">*</span></label><input type="text" name="contact_person" id="edit_contact_person" class="db-input" required></div>
                <div class="db-field"><label>Contact Number <span class="req">*</span></label><input type="text" name="contact_number" id="edit_contact_number" class="db-input" required></div>
            </div>
            <div class="db-field"><label>Operating Hours <span class="req">*</span></label><input type="text" name="operating_hours" id="edit_operating_hours" class="db-input" required></div>
            <div class="db-field"><label>Accepted Materials <span class="req">*</span></label><textarea name="accepted_materials" id="edit_accepted_materials" class="db-input" rows="2" required></textarea></div>
            <div class="db-field"><label>Services Offered <span class="req">*</span></label><textarea name="services_offered" id="edit_services_offered" class="db-input" rows="2" required></textarea></div>
            <div class="db-field"><label>Status <span class="req">*</span></label>
                <select name="status" id="edit_center_status" class="db-input" required><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Center</button>
        </form>
    </div>
</div>

<!-- ═══ DELETE CENTER CONFIRM ═══ -->
<div id="deleteCenterModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteCenterModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete:</p>
            <div class="db-delete-target" id="delete_center_name_display"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_center">
                <input type="hidden" name="center_id" id="delete_center_id">
                <div style="display:flex;gap:.75rem;margin-top:1.5rem">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteCenterModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ ADD PARTICIPANT MODAL ═══ -->
<div id="addParticipantModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-user-plus"></i> Add Participant</h3>
            <button class="db-modal__close" onclick="closeModal('addParticipantModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="add_participant">
            <div class="db-field"><label>Program <span class="req">*</span></label>
                <select name="program_id" class="db-input" required>
                    <option value="">Select Program</option>
                    <?php $programs_dd->data_seek(0); while ($pp=$programs_dd->fetch_assoc()): ?>
                    <option value="<?php echo $pp['program_id']; ?>"><?php echo htmlspecialchars($pp['program_name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="db-field"><label>Resident <span class="req">*</span></label>
                <select name="resident_id" class="db-input" required>
                    <option value="">Select Resident</option>
                    <?php while ($rr=$residents_dd->fetch_assoc()): ?>
                    <option value="<?php echo $rr['resident_id']; ?>"><?php echo htmlspecialchars($rr['full_name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="db-field"><label>Points Earned</label><input type="number" name="points_earned" class="db-input" value="0" min="0"></div>
            <div class="db-field"><label>Status <span class="req">*</span></label>
                <select name="status" class="db-input" required><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Add Participant</button>
        </form>
    </div>
</div>

<!-- ═══ EDIT PARTICIPANT MODAL ═══ -->
<div id="editParticipantModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Participant</h3>
            <button class="db-modal__close" onclick="closeModal('editParticipantModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="update_participant">
            <input type="hidden" name="participant_id" id="edit_participant_id">
            <div class="db-field"><label>Resident</label><input type="text" id="edit_participant_name" class="db-input" readonly style="opacity:.7;"></div>
            <div class="db-field"><label>Program</label><input type="text" id="edit_participant_program" class="db-input" readonly style="opacity:.7;"></div>
            <div class="db-field"><label>Points Earned <span class="req">*</span></label><input type="number" name="points_earned" id="edit_points_earned" class="db-input" min="0" required></div>
            <div class="db-field"><label>Status <span class="req">*</span></label>
                <select name="status" id="edit_participant_status" class="db-input" required><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Participant</button>
        </form>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function viewCenter(c) {
    document.getElementById('centerDetailsContent').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="db-field"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Center Name</label><div style="font-weight:700;font-size:15px;">${c.center_name}</div></div>
            <div class="db-field"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Status</label><div><span class="db-badge ${c.status==='active'?'db-badge--success':'db-badge--muted'}">${c.status.toUpperCase()}</span></div></div>
            <div class="db-field"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Location</label><div>${c.location}</div></div>
            <div class="db-field"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Operating Hours</label><div>${c.operating_hours}</div></div>
            <div class="db-field"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Contact Person</label><div>${c.contact_person}</div></div>
            <div class="db-field"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Contact Number</label><div>${c.contact_number}</div></div>
        </div>
        <div class="db-field" style="margin-top:4px;"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Accepted Materials</label><div style="margin-top:4px;">${c.accepted_materials}</div></div>
        <div class="db-field" style="margin-top:4px;"><label style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;">Services Offered</label><div style="margin-top:4px;">${c.services_offered}</div></div>
        <button class="db-btn db-btn--ghost db-btn--sm" style="margin-top:8px;" onclick="closeModal('viewCenterModal')">Close</button>
    `;
    openModal('viewCenterModal');
}

function editCenter(c) {
    document.getElementById('edit_center_id').value          = c.center_id;
    document.getElementById('edit_center_name').value        = c.center_name;
    document.getElementById('edit_location').value           = c.location;
    document.getElementById('edit_contact_person').value     = c.contact_person;
    document.getElementById('edit_contact_number').value     = c.contact_number;
    document.getElementById('edit_operating_hours').value    = c.operating_hours;
    document.getElementById('edit_accepted_materials').value = c.accepted_materials;
    document.getElementById('edit_services_offered').value   = c.services_offered;
    document.getElementById('edit_center_status').value      = c.status;
    openModal('editCenterModal');
}

function openDeleteCenterModal(id, name) {
    document.getElementById('delete_center_id').value             = id;
    document.getElementById('delete_center_name_display').textContent = name;
    openModal('deleteCenterModal');
}

function editParticipant(p) {
    document.getElementById('edit_participant_id').value      = p.participant_id;
    document.getElementById('edit_participant_name').value    = p.resident_name;
    document.getElementById('edit_participant_program').value = p.program_name;
    document.getElementById('edit_points_earned').value       = p.points_earned;
    document.getElementById('edit_participant_status').value  = p.status;
    openModal('editParticipantModal');
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php require_once '../../../includes/footer.php'; ?>