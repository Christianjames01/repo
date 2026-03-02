<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Scholarship Programs';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add':
            $sql = "INSERT INTO tbl_education_scholarships (
                scholarship_name, scholarship_type, description, amount, slots,
                requirements, eligibility, application_start, application_end,
                status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)";
            $params = [
                $_POST['scholarship_name'], $_POST['scholarship_type'], $_POST['description'],
                $_POST['amount'], $_POST['slots'] ?? null, $_POST['requirements'] ?? null,
                $_POST['eligibility'] ?? null, $_POST['application_start'] ?? null,
                $_POST['application_end'] ?? null, getCurrentUserId()
            ];
            if (executeQuery($conn, $sql, $params, 'sssdiisssi'))
                $_SESSION['temp_success'] = 'Scholarship program added successfully';
            break;

        case 'edit':
            $sql = "UPDATE tbl_education_scholarships SET
                scholarship_name=?, scholarship_type=?, description=?,
                amount=?, slots=?, requirements=?, eligibility=?,
                application_start=?, application_end=?
                WHERE scholarship_id=?";
            $params = [
                $_POST['scholarship_name'], $_POST['scholarship_type'], $_POST['description'],
                $_POST['amount'], $_POST['slots'] ?? null, $_POST['requirements'] ?? null,
                $_POST['eligibility'] ?? null, $_POST['application_start'] ?? null,
                $_POST['application_end'] ?? null, $_POST['scholarship_id']
            ];
            if (executeQuery($conn, $sql, $params, 'sssdiisssi'))
                $_SESSION['temp_success'] = 'Scholarship program updated successfully';
            break;

        case 'toggle_status':
            $new_status = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
            executeQuery($conn, "UPDATE tbl_education_scholarships SET status=? WHERE scholarship_id=?",
                [$new_status, $_POST['scholarship_id']], 'si');
            $_SESSION['temp_success'] = 'Status updated successfully';
            break;

        case 'delete':
            if (executeQuery($conn, "DELETE FROM tbl_education_scholarships WHERE scholarship_id=?",
                [$_POST['scholarship_id']], 'i'))
                $_SESSION['temp_success'] = 'Scholarship program deleted';
            break;
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit();
}

$scholarships = fetchAll($conn, "SELECT * FROM tbl_education_scholarships ORDER BY created_at DESC");

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <i class="fas fa-award" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Scholarship Programs</h1>
                <p class="db-hero__sub">Create and manage scholarship programs — <?php echo count($scholarships); ?> total</p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;">
            <button onclick="openModal('scholarshipModal')" class="db-btn db-btn--primary db-btn--sm">
                <i class="fas fa-plus"></i> Add Program
            </button>
            <a href="index.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back
            </a>
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
<?php if ($error_message): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>


<!-- STATS ROW -->
<?php
$active_count   = count(array_filter($scholarships, fn($s) => $s['status'] === 'active'));
$inactive_count = count($scholarships) - $active_count;
$total_amount   = array_sum(array_column(array_filter($scholarships, fn($s) => $s['status'] === 'active'), 'amount'));
$total_slots    = array_sum(array_filter(array_column($scholarships, 'slots')));
?>
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-award"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo count($scholarships); ?></div>
            <div class="db-stat-card__label">Total Programs</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-check-circle"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $active_count; ?></div>
            <div class="db-stat-card__label">Active Programs</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $total_slots ?: '∞'; ?></div>
            <div class="db-stat-card__label">Available Slots</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-hand-holding-usd"></i></div>
        <div>
            <div class="db-stat-card__num">₱<?php echo number_format($total_amount, 0); ?></div>
            <div class="db-stat-card__label">Total Active Value</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
</div>


<!-- PROGRAMS TABLE -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-award"></i></span>
            <h2>All Scholarship Programs</h2>
        </div>
        <button onclick="openModal('scholarshipModal')" class="db-btn db-btn--primary db-btn--sm">
            <i class="fas fa-plus"></i> Add Program
        </button>
    </div>

    <?php if (empty($scholarships)): ?>
    <div class="db-empty">
        <i class="fas fa-award"></i>
        <p>No scholarship programs yet. Create the first one!</p>
        <button onclick="openModal('scholarshipModal')" class="db-btn db-btn--primary db-btn--sm">
            <i class="fas fa-plus"></i> Add First Program
        </button>
    </div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Program Name</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Slots</th>
                    <th>Application Period</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scholarships as $s): ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($s['scholarship_name']); ?></strong>
                    <?php if (!empty($s['description'])): ?>
                    <br><span class="db-text-sm"><?php echo htmlspecialchars(substr($s['description'], 0, 60)) . (strlen($s['description']) > 60 ? '…' : ''); ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="db-badge db-badge--primary"><?php echo htmlspecialchars($s['scholarship_type']); ?></span></td>
                <td><strong style="color:var(--db-teal);">₱<?php echo number_format($s['amount'], 2); ?></strong></td>
                <td><?php echo $s['slots'] ?? '<span class="db-text-muted">Unlimited</span>'; ?></td>
                <td>
                    <?php if ($s['application_start'] && $s['application_end']): ?>
                    <span class="db-text-sm">
                        <?php echo date('M d, Y', strtotime($s['application_start'])); ?><br>
                        to <?php echo date('M d, Y', strtotime($s['application_end'])); ?>
                    </span>
                    <?php else: ?>
                    <span class="db-badge db-badge--success">Open</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="db-badge <?php echo $s['status'] === 'active' ? 'db-badge--success' : 'db-badge--muted'; ?>">
                        <?php echo ucfirst($s['status']); ?>
                    </span>
                </td>
                <td>
                    <div class="db-btn-group">
                        <button onclick='viewScholarship(<?php echo json_encode($s); ?>)'
                                class="db-icon-btn db-icon-btn--info" title="View">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick='editScholarship(<?php echo json_encode($s); ?>)'
                                class="db-icon-btn db-icon-btn--primary" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="toggleStatus(<?php echo $s['scholarship_id']; ?>, '<?php echo $s['status']; ?>')"
                                class="db-icon-btn" style="background:rgba(245,158,11,.1);color:#d97706;border-color:#f59e0b;" title="Toggle Status">
                            <i class="fas fa-power-off"></i>
                        </button>
                        <button onclick="deleteScholarship(<?php echo $s['scholarship_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['scholarship_name'])); ?>')"
                                class="db-icon-btn db-icon-btn--danger" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>


<!-- ── Add / Edit Modal ── -->
<div id="scholarshipModal" class="db-modal">
    <div class="db-modal__box" style="max-width:680px;">
        <div class="db-modal__header">
            <h3 id="modalTitle"><i class="fas fa-award"></i> Add Scholarship Program</h3>
            <button class="db-modal__close" onclick="closeModal('scholarshipModal')">×</button>
        </div>
        <form method="POST" id="scholarshipForm" class="db-modal__body" style="padding:22px;">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="scholarship_id" id="scholarshipId">

            <div class="db-field-row">
                <div class="db-field">
                    <label>Scholarship Name <span class="req">*</span></label>
                    <input type="text" name="scholarship_name" id="sName" class="db-input" required>
                </div>
                <div class="db-field">
                    <label>Type <span class="req">*</span></label>
                    <input type="text" name="scholarship_type" id="sType" class="db-input" required placeholder="e.g. Academic, Merit">
                </div>
            </div>
            <div class="db-field">
                <label>Description</label>
                <textarea name="description" id="sDesc" class="db-input" rows="3" placeholder="Brief description of this program…"></textarea>
            </div>
            <div class="db-field-row">
                <div class="db-field">
                    <label>Amount <span class="req">*</span></label>
                    <div style="display:flex;">
                        <span style="padding:9px 13px;background:var(--db-surf2);border:1.5px solid var(--db-border);border-right:none;border-radius:var(--db-radius-sm) 0 0 var(--db-radius-sm);font-weight:700;color:var(--db-teal);">₱</span>
                        <input type="number" step="0.01" name="amount" id="sAmount" class="db-input" placeholder="0.00" required
                               style="border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;">
                    </div>
                </div>
                <div class="db-field">
                    <label>Available Slots</label>
                    <input type="number" name="slots" id="sSlots" class="db-input" placeholder="Leave blank for unlimited">
                </div>
            </div>
            <div class="db-field">
                <label>Requirements</label>
                <textarea name="requirements" id="sReqs" class="db-input" rows="3" placeholder="List document requirements…"></textarea>
            </div>
            <div class="db-field">
                <label>Eligibility Criteria</label>
                <textarea name="eligibility" id="sElig" class="db-input" rows="3" placeholder="Who can apply…"></textarea>
            </div>
            <div class="db-field-row">
                <div class="db-field">
                    <label>Application Start Date</label>
                    <input type="date" name="application_start" id="sStart" class="db-input">
                </div>
                <div class="db-field">
                    <label>Application End Date</label>
                    <input type="date" name="application_end" id="sEnd" class="db-input">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="db-btn db-btn--primary db-btn--full">
                    <i class="fas fa-save"></i> <span id="submitLabel">Save Program</span>
                </button>
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('scholarshipModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>


<!-- ── View Modal ── -->
<div id="viewModal" class="db-modal">
    <div class="db-modal__box" style="max-width:600px;">
        <div class="db-modal__header">
            <h3 id="viewTitle"><i class="fas fa-award"></i> Scholarship Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewModal')">×</button>
        </div>
        <div class="db-modal__body" id="viewContent" style="padding:22px;"></div>
    </div>
</div>


<!-- ── Toggle / Delete hidden forms ── -->
<form id="statusForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="scholarship_id" id="statusId">
    <input type="hidden" name="current_status" id="statusCurrent">
</form>

<!-- Delete confirm modal -->
<div id="deleteModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteModal')">×</button>
        </div>
        <div class="db-modal__body" style="padding:22px;">
            <p>Are you sure you want to delete:</p>
            <div class="db-delete-target" id="deleteName"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="scholarship_id" id="deleteId">
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteModal')">Cancel</button>
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

function editScholarship(s) {
    document.getElementById('modalTitle').innerHTML   = '<i class="fas fa-edit"></i> Edit Scholarship Program';
    document.getElementById('submitLabel').textContent = 'Update Program';
    document.getElementById('formAction').value       = 'edit';
    document.getElementById('scholarshipId').value    = s.scholarship_id;
    document.getElementById('sName').value            = s.scholarship_name;
    document.getElementById('sType').value            = s.scholarship_type;
    document.getElementById('sDesc').value            = s.description   || '';
    document.getElementById('sAmount').value          = s.amount;
    document.getElementById('sSlots').value           = s.slots         || '';
    document.getElementById('sReqs').value            = s.requirements  || '';
    document.getElementById('sElig').value            = s.eligibility   || '';
    document.getElementById('sStart').value           = s.application_start || '';
    document.getElementById('sEnd').value             = s.application_end   || '';
    openModal('scholarshipModal');
}

function viewScholarship(s) {
    document.getElementById('viewTitle').innerHTML = '<i class="fas fa-award"></i> ' + s.scholarship_name;
    const fmt = n => parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('viewContent').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <span class="db-badge db-badge--primary">${s.scholarship_type}</span>
                <span class="db-badge ${s.status==='active'?'db-badge--success':'db-badge--muted'}">${s.status}</span>
            </div>
            ${s.description ? `<p style="font-size:13.5px;color:var(--db-muted);margin:0;">${s.description}</p>` : ''}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="padding:12px 16px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                    <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;">Amount</div>
                    <strong style="font-size:20px;color:var(--db-teal);">₱${fmt(s.amount)}</strong>
                </div>
                <div style="padding:12px 16px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                    <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;">Slots</div>
                    <strong style="font-size:20px;">${s.slots || 'Unlimited'}</strong>
                </div>
            </div>
            ${s.requirements ? `<div><div style="font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Requirements</div><p style="font-size:13px;margin:0;">${s.requirements}</p></div>` : ''}
            ${s.eligibility  ? `<div><div style="font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Eligibility</div><p style="font-size:13px;margin:0;">${s.eligibility}</p></div>` : ''}
            ${s.application_start && s.application_end ? `<div style="padding:10px 16px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);font-size:12.5px;">
                <i class="far fa-calendar" style="color:var(--db-sky);margin-right:6px;"></i>
                <strong>Application Period:</strong> ${s.application_start} — ${s.application_end}
            </div>` : ''}
        </div>
        <div style="margin-top:18px;">
            <button class="db-btn db-btn--ghost" onclick="closeModal('viewModal')">Close</button>
        </div>`;
    openModal('viewModal');
}

function toggleStatus(id, current) {
    const action = current === 'active' ? 'deactivate' : 'activate';
    if (confirm(`Are you sure you want to ${action} this scholarship program?`)) {
        document.getElementById('statusId').value      = id;
        document.getElementById('statusCurrent').value = current;
        document.getElementById('statusForm').submit();
    }
}

function deleteScholarship(id, name) {
    document.getElementById('deleteId').value   = id;
    document.getElementById('deleteName').textContent = name;
    openModal('deleteModal');
}

// Reset modal on close
document.getElementById('scholarshipModal').addEventListener('click', function(){});
document.querySelectorAll('.db-modal__close').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalTitle').innerHTML    = '<i class="fas fa-award"></i> Add Scholarship Program';
        document.getElementById('submitLabel').textContent = 'Save Program';
        document.getElementById('scholarshipForm').reset();
        document.getElementById('formAction').value       = 'add';
        document.getElementById('scholarshipId').value    = '';
    });
});

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../includes/footer.php'; ?>