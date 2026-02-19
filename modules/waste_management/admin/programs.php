<?php
// Path: modules/waste_management/admin/programs.php
require_once('../../../config/config.php');

// Check if user is logged in and has appropriate role
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = 'Waste Management Programs';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_program':
                $program_name = sanitizeInput($_POST['program_name']);
                $program_type = sanitizeInput($_POST['program_type']);
                $description = sanitizeInput($_POST['description']);
                $accepted_materials = sanitizeInput($_POST['accepted_materials']);
                $collection_points = sanitizeInput($_POST['collection_points']);
                $schedule = sanitizeInput($_POST['schedule']);
                $contact_person = sanitizeInput($_POST['contact_person']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $incentives = sanitizeInput($_POST['incentives']);
                $status = sanitizeInput($_POST['status']);
             $stmt = $conn->prepare("INSERT INTO tbl_recycling_programs (program_name, program_type, description, recyclable_items, collection_points, schedule, contact_person, contact_number, incentive_type, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("ssssssssss", $program_name, $program_type, $description, $accepted_materials, $collection_points, $schedule, $contact_person, $contact_number, $incentives, $status);

                if ($stmt->execute()) {
                    $_SESSION['success'] = "Program added successfully!";
                } else {
                    $_SESSION['error'] = "Failed to add program.";
                }
                header('Location: programs.php');
                exit;
                break;
                
            case 'update_program':
                $program_id = (int)$_POST['program_id'];
                $program_name = sanitizeInput($_POST['program_name']);
                $program_type = sanitizeInput($_POST['program_type']);
                $description = sanitizeInput($_POST['description']);
                $accepted_materials = sanitizeInput($_POST['accepted_materials']);
                $collection_points = sanitizeInput($_POST['collection_points']);
                $schedule = sanitizeInput($_POST['schedule']);
                $contact_person = sanitizeInput($_POST['contact_person']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $incentives = sanitizeInput($_POST['incentives']);
                $status = sanitizeInput($_POST['status']);
                
              $stmt = $conn->prepare("UPDATE tbl_recycling_programs SET program_name = ?, program_type = ?, description = ?, recyclable_items = ?, collection_points = ?, schedule = ?, contact_person = ?, contact_number = ?, incentive_type = ?, status = ? WHERE program_id = ?");
                $stmt->bind_param("ssssssssssi", $program_name, $program_type, $description, $accepted_materials, $collection_points, $schedule, $contact_person, $contact_number, $incentives, $status, $program_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Program updated successfully!";
                } else {
                    $_SESSION['error'] = "Failed to update program.";
                }
                header('Location: programs.php');
                exit;
                break;
                
            case 'delete_program':
                $program_id = (int)$_POST['program_id'];
                $stmt = $conn->prepare("DELETE FROM tbl_recycling_programs WHERE program_id = ?");
                $stmt->bind_param("i", $program_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Program deleted successfully!";
                } else {
                    $_SESSION['error'] = "Failed to delete program.";
                }
                header('Location: programs.php');
                exit;
                break;
        }
    }
}

// Fetch all programs
$programs_query = "SELECT * FROM tbl_recycling_programs ORDER BY created_at DESC";
$programs_result = $conn->query($programs_query);

include('../../../includes/header.php');
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-recycle text-success me-2"></i>
                        Waste Management Programs
                    </h1>
                    <p class="text-muted">Manage environmental programs and initiatives</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                    <i class="fas fa-plus me-2"></i>Add New Program
                </button>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Programs Table -->
    <div class="card shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="programsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Program Name</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Contact Person</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($programs_result && $programs_result->num_rows > 0): ?>
                            <?php while ($program = $programs_result->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $program['program_id']; ?></td>
                                    <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                    <td><?php echo htmlspecialchars($program['program_type']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($program['description'], 0, 50)) . '...'; ?></td>
                                    <td><?php echo htmlspecialchars($program['contact_person']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $program['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($program['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="editProgram(<?php echo htmlspecialchars(json_encode($program)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteProgram(<?php echo $program['program_id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">No programs found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_program">
                    
                    <div class="mb-3">
                        <label class="form-label">Program Name *</label>
                        <input type="text" name="program_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Program Type *</label>
                        <select name="program_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="Buyback Program">Buyback Program</option>
                            <option value="Collection Drive">Collection Drive</option>
                            <option value="Community Program">Community Program</option>
                            <option value="Educational Program">Educational Program</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Accepted Materials *</label>
                        <textarea name="accepted_materials" class="form-control" rows="2" required></textarea>
                        <small class="text-muted">e.g., Plastic bottles, Paper, Metal cans</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Collection Points *</label>
                        <input type="text" name="collection_points" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Schedule *</label>
                        <input type="text" name="schedule" class="form-control" required placeholder="e.g., Every Saturday, 8:00 AM - 12:00 PM">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Incentives</label>
                        <input type="text" name="incentives" class="form-control" placeholder="e.g., PHP 5 per kilogram">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Program Modal -->
<div class="modal fade" id="editProgramModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editProgramForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_program">
                    <input type="hidden" name="program_id" id="edit_program_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Program Name *</label>
                        <input type="text" name="program_name" id="edit_program_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Program Type *</label>
                        <select name="program_type" id="edit_program_type" class="form-control" required>
                            <option value="Buyback Program">Buyback Program</option>
                            <option value="Collection Drive">Collection Drive</option>
                            <option value="Community Program">Community Program</option>
                            <option value="Educational Program">Educational Program</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Accepted Materials *</label>
                        <textarea name="accepted_materials" id="edit_accepted_materials" class="form-control" rows="2" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Collection Points *</label>
                        <input type="text" name="collection_points" id="edit_collection_points" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Schedule *</label>
                        <input type="text" name="schedule" id="edit_schedule" class="form-control" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" id="edit_contact_person" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" id="edit_contact_number" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Incentives</label>
                        <input type="text" name="incentives" id="edit_incentives" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status *</label>
                        <select name="status" id="edit_status" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editProgram(program) {
    document.getElementById('edit_program_id').value = program.program_id;
    document.getElementById('edit_program_name').value = program.program_name;
    document.getElementById('edit_program_type').value = program.program_type;
    document.getElementById('edit_description').value = program.description;
    document.getElementById('edit_accepted_materials').value = program.accepted_materials;
    document.getElementById('edit_collection_points').value = program.collection_points;
    document.getElementById('edit_schedule').value = program.schedule;
    document.getElementById('edit_contact_person').value = program.contact_person;
    document.getElementById('edit_contact_number').value = program.contact_number;
    document.getElementById('edit_incentives').value = program.incentives;
    document.getElementById('edit_status').value = program.status;
    
    var modal = new bootstrap.Modal(document.getElementById('editProgramModal'));
    modal.show();
}

function deleteProgram(programId) {
    if (confirm('Are you sure you want to delete this program?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_program">' +
                        '<input type="hidden" name="program_id" value="' + programId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include('../../../includes/footer.php'); ?>