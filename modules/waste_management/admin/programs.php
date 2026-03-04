<?php
// Path: modules/waste_management/admin/programs.php
require_once('../../../config/config.php');
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = 'Recycling Programs';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_program':
            $program_name     = sanitizeInput($_POST['program_name']);
            $program_type     = sanitizeInput($_POST['program_type']);
            $description      = sanitizeInput($_POST['description']);
            $accepted_mats    = sanitizeInput($_POST['accepted_materials']);
            $collection_pts   = sanitizeInput($_POST['collection_points']);
            $schedule         = sanitizeInput($_POST['schedule']);
            $contact_person   = sanitizeInput($_POST['contact_person']);
            $contact_number   = sanitizeInput($_POST['contact_number']);
            $incentives       = sanitizeInput($_POST['incentives']);
            $status           = sanitizeInput($_POST['status']);
            $stmt = $conn->prepare("INSERT INTO tbl_recycling_programs (program_name, program_type, description, recyclable_items, collection_points, schedule, contact_person, contact_number, incentive_type, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssssss", $program_name, $program_type, $description, $accepted_mats, $collection_pts, $schedule, $contact_person, $contact_number, $incentives, $status);
            $_SESSION['temp_success'] = $stmt->execute() ? "Program added successfully!" : "Failed to add program.";
            header('Location: programs.php'); exit;

        case 'update_program':
            $id               = (int)$_POST['program_id'];
            $program_name     = sanitizeInput($_POST['program_name']);
            $program_type     = sanitizeInput($_POST['program_type']);
            $description      = sanitizeInput($_POST['description']);
            $accepted_mats    = sanitizeInput($_POST['accepted_materials']);
            $collection_pts   = sanitizeInput($_POST['collection_points']);
            $schedule         = sanitizeInput($_POST['schedule']);
            $contact_person   = sanitizeInput($_POST['contact_person']);
            $contact_number   = sanitizeInput($_POST['contact_number']);
            $incentives       = sanitizeInput($_POST['incentives']);
            $status           = sanitizeInput($_POST['status']);
            $stmt = $conn->prepare("UPDATE tbl_recycling_programs SET program_name=?, program_type=?, description=?, recyclable_items=?, collection_points=?, schedule=?, contact_person=?, contact_number=?, incentive_type=?, status=? WHERE program_id=?");
            $stmt->bind_param("ssssssssssi", $program_name, $program_type, $description, $accepted_mats, $collection_pts, $schedule, $contact_person, $contact_number, $incentives, $status, $id);
            $_SESSION['temp_success'] = $stmt->execute() ? "Program updated successfully!" : "Failed to update program.";
            header('Location: programs.php'); exit;

        case 'delete_program':
            $id   = (int)$_POST['program_id'];
            $stmt = $conn->prepare("DELETE FROM tbl_recycling_programs WHERE program_id=?");
            $stmt->bind_param("i", $id);
            $_SESSION['temp_success'] = $stmt->execute() ? "Program deleted successfully!" : "Failed to delete program.";
            header('Location: programs.php'); exit;
    }
}

$success_message = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }

$programs_result = $conn->query("SELECT * FROM tbl_recycling_programs ORDER BY created_at DESC");

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include('../../../includes/header.php');
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#0d9488,#0f766e);">
                <i class="fas fa-recycle" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Waste Management
                </div>
                <h1 class="db-hero__title">Recycling Programs</h1>
                <p class="db-hero__sub">Manage environmental programs and community initiatives</p>
            </div>
        </div>
        <div class="db-hero__right">
            <button class="db-btn db-btn--primary" onclick="openModal('addProgramModal')">
                <i class="fas fa-plus"></i> Add New Program
            </button>
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

<!-- ═══ PROGRAMS TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-recycle"></i></span>
            <h2>All Programs</h2>
        </div>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addProgramModal')">
            <i class="fas fa-plus"></i> Add Program
        </button>
    </div>

    <?php if ($programs_result && $programs_result->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table" id="programsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Program Name</th>
                    <th>Type</th>
                    <th>Schedule</th>
                    <th>Contact Person</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($p = $programs_result->fetch_assoc()):
                $type_colors = [
                    'Buyback Program'     => 'db-badge--success',
                    'Collection Drive'    => 'db-badge--info',
                    'Community Program'   => 'db-badge--primary',
                    'Educational Program' => 'db-badge--warning',
                ];
                $tc = $type_colors[$p['program_type']] ?? 'db-badge--muted';
            ?>
            <tr>
                <td><span class="db-id">#<?php echo str_pad($p['program_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                <td>
                    <strong><?php echo htmlspecialchars($p['program_name']); ?></strong><br>
                    <span class="db-text-sm"><?php echo htmlspecialchars(substr($p['description'], 0, 55)) . (strlen($p['description']) > 55 ? '…' : ''); ?></span>
                </td>
                <td><span class="db-badge <?php echo $tc; ?>"><?php echo htmlspecialchars($p['program_type']); ?></span></td>
                <td><span class="db-text-sm"><i class="far fa-clock me-1"></i><?php echo htmlspecialchars($p['schedule'] ?? '—'); ?></span></td>
                <td>
                    <strong><?php echo htmlspecialchars($p['contact_person']); ?></strong><br>
                    <span class="db-text-sm"><?php echo htmlspecialchars($p['contact_number']); ?></span>
                </td>
                <td>
                    <span class="db-badge <?php echo $p['status'] === 'active' ? 'db-badge--success' : 'db-badge--muted'; ?>">
                        <?php echo ucfirst($p['status']); ?>
                    </span>
                </td>
                <td>
                    <div class="db-btn-group">
                        <button class="db-icon-btn db-icon-btn--primary" onclick='editProgram(<?php echo json_encode($p); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="db-icon-btn db-icon-btn--danger" onclick='openDeleteModal(<?php echo $p["program_id"]; ?>, <?php echo json_encode($p["program_name"]); ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-recycle"></i>
        <p>No programs found. Create your first recycling program.</p>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addProgramModal')"><i class="fas fa-plus"></i> Add Program</button>
    </div>
    <?php endif; ?>
</div>


<!-- ═══ ADD MODAL ═══ -->
<div id="addProgramModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-plus-circle"></i> Add New Program</h3>
            <button class="db-modal__close" onclick="closeModal('addProgramModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="add_program">
            <div class="db-field"><label>Program Name <span class="req">*</span></label><input type="text" name="program_name" class="db-input" required></div>
            <div class="db-field"><label>Program Type <span class="req">*</span></label>
                <select name="program_type" class="db-input" required>
                    <option value="">Select Type</option>
                    <option value="Buyback Program">Buyback Program</option>
                    <option value="Collection Drive">Collection Drive</option>
                    <option value="Community Program">Community Program</option>
                    <option value="Educational Program">Educational Program</option>
                </select>
            </div>
            <div class="db-field"><label>Description <span class="req">*</span></label><textarea name="description" class="db-input" rows="3" required></textarea></div>
            <div class="db-field"><label>Accepted Materials <span class="req">*</span></label><textarea name="accepted_materials" class="db-input" rows="2" placeholder="e.g., Plastic bottles, Paper, Metal cans" required></textarea></div>
            <div class="db-field"><label>Collection Points <span class="req">*</span></label><input type="text" name="collection_points" class="db-input" required></div>
            <div class="db-field"><label>Schedule <span class="req">*</span></label><input type="text" name="schedule" class="db-input" placeholder="e.g., Every Saturday, 8:00 AM – 12:00 PM" required></div>
            <div class="db-field-row">
                <div class="db-field"><label>Contact Person <span class="req">*</span></label><input type="text" name="contact_person" class="db-input" required></div>
                <div class="db-field"><label>Contact Number <span class="req">*</span></label><input type="text" name="contact_number" class="db-input" required></div>
            </div>
            <div class="db-field"><label>Incentives</label><input type="text" name="incentives" class="db-input" placeholder="e.g., PHP 5 per kilogram"></div>
            <div class="db-field"><label>Status <span class="req">*</span></label>
                <select name="status" class="db-input" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Add Program</button>
        </form>
    </div>
</div>

<!-- ═══ EDIT MODAL ═══ -->
<div id="editProgramModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Program</h3>
            <button class="db-modal__close" onclick="closeModal('editProgramModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="update_program">
            <input type="hidden" name="program_id" id="edit_program_id">
            <div class="db-field"><label>Program Name <span class="req">*</span></label><input type="text" name="program_name" id="edit_program_name" class="db-input" required></div>
            <div class="db-field"><label>Program Type <span class="req">*</span></label>
                <select name="program_type" id="edit_program_type" class="db-input" required>
                    <option value="Buyback Program">Buyback Program</option>
                    <option value="Collection Drive">Collection Drive</option>
                    <option value="Community Program">Community Program</option>
                    <option value="Educational Program">Educational Program</option>
                </select>
            </div>
            <div class="db-field"><label>Description <span class="req">*</span></label><textarea name="description" id="edit_description" class="db-input" rows="3" required></textarea></div>
            <div class="db-field"><label>Accepted Materials <span class="req">*</span></label><textarea name="accepted_materials" id="edit_accepted_materials" class="db-input" rows="2" required></textarea></div>
            <div class="db-field"><label>Collection Points <span class="req">*</span></label><input type="text" name="collection_points" id="edit_collection_points" class="db-input" required></div>
            <div class="db-field"><label>Schedule <span class="req">*</span></label><input type="text" name="schedule" id="edit_schedule" class="db-input" required></div>
            <div class="db-field-row">
                <div class="db-field"><label>Contact Person <span class="req">*</span></label><input type="text" name="contact_person" id="edit_contact_person" class="db-input" required></div>
                <div class="db-field"><label>Contact Number <span class="req">*</span></label><input type="text" name="contact_number" id="edit_contact_number" class="db-input" required></div>
            </div>
            <div class="db-field"><label>Incentives</label><input type="text" name="incentives" id="edit_incentives" class="db-input"></div>
            <div class="db-field"><label>Status <span class="req">*</span></label>
                <select name="status" id="edit_status" class="db-input" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Program</button>
        </form>
    </div>
</div>

<!-- ═══ DELETE CONFIRM MODAL ═══ -->
<div id="deleteProgramModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteProgramModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete:</p>
            <div class="db-delete-target" id="delete_program_name_display"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_program">
                <input type="hidden" name="program_id" id="delete_program_id">
                <div style="display:flex;gap:.75rem;margin-top:1.5rem">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteProgramModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function editProgram(p) {
    document.getElementById('edit_program_id').value          = p.program_id;
    document.getElementById('edit_program_name').value        = p.program_name;
    document.getElementById('edit_program_type').value        = p.program_type;
    document.getElementById('edit_description').value         = p.description;
    document.getElementById('edit_accepted_materials').value  = p.recyclable_items ?? '';
    document.getElementById('edit_collection_points').value   = p.collection_points;
    document.getElementById('edit_schedule').value            = p.schedule;
    document.getElementById('edit_contact_person').value      = p.contact_person;
    document.getElementById('edit_contact_number').value      = p.contact_number;
    document.getElementById('edit_incentives').value          = p.incentive_type ?? '';
    document.getElementById('edit_status').value              = p.status;
    openModal('editProgramModal');
}

function openDeleteModal(id, name) {
    document.getElementById('delete_program_id').value           = id;
    document.getElementById('delete_program_name_display').textContent = name;
    openModal('deleteProgramModal');
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php include('../../../includes/footer.php'); ?>