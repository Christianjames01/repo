<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Scholarship Programs';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $sql = "INSERT INTO tbl_education_scholarships (
                    scholarship_name, scholarship_type, description, amount, slots,
                    requirements, eligibility, application_start, application_end,
                    status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)";
                
                $params = [
                    $_POST['scholarship_name'],
                    $_POST['scholarship_type'],
                    $_POST['description'],
                    $_POST['amount'],
                    $_POST['slots'] ?? null,
                    $_POST['requirements'] ?? null,
                    $_POST['eligibility'] ?? null,
                    $_POST['application_start'] ?? null,
                    $_POST['application_end'] ?? null,
                    getCurrentUserId()
                ];
                
                if (executeQuery($conn, $sql, $params, 'sssdiisssi')) {
                    setMessage('Scholarship program added successfully', 'success');
                }
                break;
                
            case 'edit':
                $sql = "UPDATE tbl_education_scholarships SET
                    scholarship_name = ?, scholarship_type = ?, description = ?,
                    amount = ?, slots = ?, requirements = ?, eligibility = ?,
                    application_start = ?, application_end = ?
                    WHERE scholarship_id = ?";
                
                $params = [
                    $_POST['scholarship_name'],
                    $_POST['scholarship_type'],
                    $_POST['description'],
                    $_POST['amount'],
                    $_POST['slots'] ?? null,
                    $_POST['requirements'] ?? null,
                    $_POST['eligibility'] ?? null,
                    $_POST['application_start'] ?? null,
                    $_POST['application_end'] ?? null,
                    $_POST['scholarship_id']
                ];
                
                if (executeQuery($conn, $sql, $params, 'sssdiisssi')) {
                    setMessage('Scholarship program updated successfully', 'success');
                }
                break;
                
            case 'toggle_status':
                $new_status = $_POST['current_status'] == 'active' ? 'inactive' : 'active';
                $sql = "UPDATE tbl_education_scholarships SET status = ? WHERE scholarship_id = ?";
                executeQuery($conn, $sql, [$new_status, $_POST['scholarship_id']], 'si');
                setMessage('Status updated successfully', 'success');
                break;
                
            case 'delete':
                $sql = "DELETE FROM tbl_education_scholarships WHERE scholarship_id = ?";
                if (executeQuery($conn, $sql, [$_POST['scholarship_id']], 'i')) {
                    setMessage('Scholarship program deleted', 'success');
                }
                break;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get all scholarships
$scholarships = fetchAll($conn, "SELECT * FROM tbl_education_scholarships ORDER BY created_at DESC");

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="mb-0">
                    <i class="fas fa-award me-2 text-warning"></i>Scholarship Programs
                </h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i>Add Scholarship Program
                </button>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if (empty($scholarships)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-award fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No scholarship programs yet</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus me-1"></i>Add First Program
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
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
                            <?php foreach ($scholarships as $scholarship): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($scholarship['scholarship_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($scholarship['description'] ?? '', 0, 60)); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($scholarship['scholarship_type']); ?></td>
                                    <td><strong class="text-success">₱<?php echo number_format($scholarship['amount'], 2); ?></strong></td>
                                    <td><?php echo $scholarship['slots'] ?? 'Unlimited'; ?></td>
                                    <td>
                                        <?php if ($scholarship['application_start'] && $scholarship['application_end']): ?>
                                            <small>
                                                <?php echo formatDate($scholarship['application_start']); ?><br>
                                                to <?php echo formatDate($scholarship['application_end']); ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">Open</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($scholarship['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info" onclick='viewScholarship(<?php echo json_encode($scholarship); ?>)'>
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-primary" onclick='editScholarship(<?php echo json_encode($scholarship); ?>)'>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-warning" onclick="toggleStatus(<?php echo $scholarship['scholarship_id']; ?>, '<?php echo $scholarship['status']; ?>')">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                            <button class="btn btn-danger" onclick="deleteScholarship(<?php echo $scholarship['scholarship_id']; ?>, '<?php echo htmlspecialchars($scholarship['scholarship_name']); ?>')">
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
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="scholarshipForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Scholarship Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="scholarship_id" id="scholarshipId">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Scholarship Name *</label>
                            <input type="text" name="scholarship_name" id="scholarshipName" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Type *</label>
                            <input type="text" name="scholarship_type" id="scholarshipType" class="form-control" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount *</label>
                            <input type="number" step="0.01" name="amount" id="amount" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Available Slots</label>
                            <input type="number" name="slots" id="slots" class="form-control" placeholder="Leave blank for unlimited">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Requirements</label>
                            <textarea name="requirements" id="requirements" class="form-control" rows="3" placeholder="List requirements..."></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Eligibility Criteria</label>
                            <textarea name="eligibility" id="eligibility" class="form-control" rows="3" placeholder="Who can apply..."></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Application Start Date</label>
                            <input type="date" name="application_start" id="applicationStart" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Application End Date</label>
                            <input type="date" name="application_end" id="applicationEnd" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Forms -->
<form id="statusForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="scholarship_id" id="statusScholarshipId">
    <input type="hidden" name="current_status" id="currentStatus">
</form>

<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="scholarship_id" id="deleteScholarshipId">
</form>

<script>
function editScholarship(scholarship) {
    document.getElementById('modalTitle').textContent = 'Edit Scholarship Program';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('scholarshipId').value = scholarship.scholarship_id;
    document.getElementById('scholarshipName').value = scholarship.scholarship_name;
    document.getElementById('scholarshipType').value = scholarship.scholarship_type;
    document.getElementById('description').value = scholarship.description || '';
    document.getElementById('amount').value = scholarship.amount;
    document.getElementById('slots').value = scholarship.slots || '';
    document.getElementById('requirements').value = scholarship.requirements || '';
    document.getElementById('eligibility').value = scholarship.eligibility || '';
    document.getElementById('applicationStart').value = scholarship.application_start || '';
    document.getElementById('applicationEnd').value = scholarship.application_end || '';
    
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function viewScholarship(scholarship) {
    document.getElementById('viewTitle').textContent = scholarship.scholarship_name;
    
    let content = `
        <div class="row">
            <div class="col-md-12 mb-3">
                <h6>Type:</h6>
                <p>${scholarship.scholarship_type}</p>
            </div>
            <div class="col-md-12 mb-3">
                <h6>Description:</h6>
                <p>${scholarship.description || 'N/A'}</p>
            </div>
            <div class="col-md-6 mb-3">
                <h6>Amount:</h6>
                <p class="text-success fs-4">₱${parseFloat(scholarship.amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</p>
            </div>
            <div class="col-md-6 mb-3">
                <h6>Available Slots:</h6>
                <p>${scholarship.slots || 'Unlimited'}</p>
            </div>
            <div class="col-md-12 mb-3">
                <h6>Requirements:</h6>
                <p>${scholarship.requirements || 'N/A'}</p>
            </div>
            <div class="col-md-12 mb-3">
                <h6>Eligibility:</h6>
                <p>${scholarship.eligibility || 'N/A'}</p>
            </div>
            <div class="col-md-12 mb-3">
                <h6>Application Period:</h6>
                <p>${scholarship.application_start && scholarship.application_end ? 
                    scholarship.application_start + ' to ' + scholarship.application_end : 'Open'}</p>
            </div>
        </div>
    `;
    
    document.getElementById('viewContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

function toggleStatus(id, currentStatus) {
    if (confirm('Are you sure you want to change the status?')) {
        document.getElementById('statusScholarshipId').value = id;
        document.getElementById('currentStatus').value = currentStatus;
        document.getElementById('statusForm').submit();
    }
}

function deleteScholarship(id, name) {
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        document.getElementById('deleteScholarshipId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// Reset form when modal is closed
document.getElementById('addModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('modalTitle').textContent = 'Add Scholarship Program';
    document.getElementById('scholarshipForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('scholarshipId').value = '';
});
</script>

<?php
$conn->close();
include '../../includes/footer.php';
?>