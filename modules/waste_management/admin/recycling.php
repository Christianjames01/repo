<?php
require_once('../../../config/config.php');

// Check if user is logged in and has appropriate role
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Recycling Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_center':
                $center_name = sanitizeInput($_POST['center_name']);
                $location = sanitizeInput($_POST['location']);
                $contact_person = sanitizeInput($_POST['contact_person']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $operating_hours = sanitizeInput($_POST['operating_hours']);
                $accepted_materials = sanitizeInput($_POST['accepted_materials']);
                $services_offered = sanitizeInput($_POST['services_offered']);
                $status = sanitizeInput($_POST['status']);
                
                $stmt = $conn->prepare("INSERT INTO tbl_recycling_centers (center_name, location, contact_person, contact_number, operating_hours, accepted_materials, services_offered, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssssssss", $center_name, $location, $contact_person, $contact_number, $operating_hours, $accepted_materials, $services_offered, $status);
                
                if ($stmt->execute()) {
                    setMessage("Recycling center added successfully!", "success");
                } else {
                    setMessage("Failed to add recycling center.", "error");
                }
                header('Location: recycling.php');
                exit;
                break;
                
            case 'update_center':
                $center_id = (int)$_POST['center_id'];
                $center_name = sanitizeInput($_POST['center_name']);
                $location = sanitizeInput($_POST['location']);
                $contact_person = sanitizeInput($_POST['contact_person']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $operating_hours = sanitizeInput($_POST['operating_hours']);
                $accepted_materials = sanitizeInput($_POST['accepted_materials']);
                $services_offered = sanitizeInput($_POST['services_offered']);
                $status = sanitizeInput($_POST['status']);
                
                $stmt = $conn->prepare("UPDATE tbl_recycling_centers SET center_name = ?, location = ?, contact_person = ?, contact_number = ?, operating_hours = ?, accepted_materials = ?, services_offered = ?, status = ? WHERE center_id = ?");
                $stmt->bind_param("ssssssssi", $center_name, $location, $contact_person, $contact_number, $operating_hours, $accepted_materials, $services_offered, $status, $center_id);
                
                if ($stmt->execute()) {
                    setMessage("Recycling center updated successfully!", "success");
                } else {
                    setMessage("Failed to update recycling center.", "error");
                }
                header('Location: recycling.php');
                exit;
                break;
                
            case 'delete_center':
                $center_id = (int)$_POST['center_id'];
                $stmt = $conn->prepare("DELETE FROM tbl_recycling_centers WHERE center_id = ?");
                $stmt->bind_param("i", $center_id);
                
                if ($stmt->execute()) {
                    setMessage("Recycling center deleted successfully!", "success");
                } else {
                    setMessage("Failed to delete recycling center.", "error");
                }
                header('Location: recycling.php');
                exit;
                break;
                
            case 'add_participant':
                $program_id = (int)$_POST['program_id'];
                $resident_id = (int)$_POST['resident_id'];
                $points_earned = (int)$_POST['points_earned'];
                $status = sanitizeInput($_POST['status']);

                // Check if resident is already enrolled in this program
                $check_existing = $conn->prepare("SELECT participant_id FROM tbl_recycling_participants WHERE program_id = ? AND resident_id = ?");
                $check_existing->bind_param("ii", $program_id, $resident_id);
                $check_existing->execute();
                $existing_result = $check_existing->get_result();

                if ($existing_result->num_rows > 0) {
                    // Already enrolled — update points and status instead of inserting a duplicate
                    $existing_row = $existing_result->fetch_assoc();
                    $existing_id = $existing_row['participant_id'];

                    $stmt = $conn->prepare("UPDATE tbl_recycling_participants SET points_earned = ?, status = ? WHERE participant_id = ?");
                    $stmt->bind_param("isi", $points_earned, $status, $existing_id);

                    if ($stmt->execute()) {
                        setMessage("Resident is already enrolled. Points and status have been updated instead.", "warning");
                    } else {
                        setMessage("Failed to update existing participant.", "error");
                    }
                } else {
                    // Not enrolled yet — insert new row
                    $stmt = $conn->prepare("INSERT INTO tbl_recycling_participants (program_id, resident_id, points_earned, status) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("iiis", $program_id, $resident_id, $points_earned, $status);

                    if ($stmt->execute()) {
                        setMessage("Participant added successfully!", "success");
                    } else {
                        setMessage("Failed to add participant.", "error");
                    }
                }
                header('Location: recycling.php?tab=participants');
                exit;
                break;
                
            case 'update_participant':
                $participant_id = (int)$_POST['participant_id'];
                $points_earned = (int)$_POST['points_earned'];
                $status = sanitizeInput($_POST['status']);
                
                $stmt = $conn->prepare("UPDATE tbl_recycling_participants SET points_earned = ?, status = ? WHERE participant_id = ?");
                $stmt->bind_param("isi", $points_earned, $status, $participant_id);
                
                if ($stmt->execute()) {
                    setMessage("Participant updated successfully!", "success");
                } else {
                    setMessage("Failed to update participant.", "error");
                }
                header('Location: recycling.php?tab=participants');
                exit;
                break;
        }
    }
}

// Get active tab
$active_tab = $_GET['tab'] ?? 'centers';

// Fetch recycling centers
$centers_query = "SELECT * FROM tbl_recycling_centers ORDER BY created_at DESC";
$centers_result = $conn->query($centers_query);

// Fetch participants with resident and program details
$participants_query = "
    SELECT rp.*, 
           CONCAT(r.first_name, ' ', r.last_name) as resident_name,
           prog.program_name
    FROM tbl_recycling_participants rp
    LEFT JOIN tbl_residents r ON rp.resident_id = r.resident_id
    LEFT JOIN tbl_recycling_programs prog ON rp.program_id = prog.program_id
    ORDER BY rp.participant_id DESC
";
$participants_result = $conn->query($participants_query);

// Fetch programs for dropdown
$programs_result = $conn->query("SELECT program_id, program_name FROM tbl_recycling_programs WHERE status = 'active'");

// Fetch residents for dropdown
$residents_result = $conn->query("SELECT resident_id, CONCAT(first_name, ' ', last_name) as full_name FROM tbl_residents ORDER BY last_name, first_name");

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-recycle me-2"></i><?php echo $page_title; ?></h1>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'centers' ? 'active' : ''; ?>" href="?tab=centers">
                <i class="fas fa-building me-1"></i>Recycling Centers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'participants' ? 'active' : ''; ?>" href="?tab=participants">
                <i class="fas fa-users me-1"></i>Program Participants
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'centers'): ?>
        <!-- Recycling Centers Tab -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Recycling Centers</h6>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCenterModal">
                    <i class="fas fa-plus me-1"></i>Add Center
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="centersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Center Name</th>
                                <th>Location</th>
                                <th>Contact Person</th>
                                <th>Contact Number</th>
                                <th>Operating Hours</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($centers_result && $centers_result->num_rows > 0): ?>
                                <?php while ($center = $centers_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $center['center_id']; ?></td>
                                        <td><?php echo htmlspecialchars($center['center_name']); ?></td>
                                        <td><?php echo htmlspecialchars($center['location']); ?></td>
                                        <td><?php echo htmlspecialchars($center['contact_person']); ?></td>
                                        <td><?php echo htmlspecialchars($center['contact_number']); ?></td>
                                        <td><?php echo htmlspecialchars($center['operating_hours']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $center['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($center['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="viewCenter(<?php echo htmlspecialchars(json_encode($center)); ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick="editCenter(<?php echo htmlspecialchars(json_encode($center)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteCenter(<?php echo $center['center_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center">No recycling centers found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($active_tab === 'participants'): ?>
        <!-- Participants Tab -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-success">Program Participants</h6>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addParticipantModal">
                    <i class="fas fa-user-plus me-1"></i>Add Participant
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="participantsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Resident Name</th>
                                <th>Program</th>
                                <th>Points Earned</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($participants_result && $participants_result->num_rows > 0): ?>
                                <?php while ($participant = $participants_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $participant['participant_id']; ?></td>
                                        <td><?php echo htmlspecialchars($participant['resident_name']); ?></td>
                                        <td><?php echo htmlspecialchars($participant['program_name']); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo number_format($participant['points_earned']); ?> pts</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $participant['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($participant['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" onclick="editParticipant(<?php echo htmlspecialchars(json_encode($participant)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No participants found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Add Center Modal -->
<div class="modal fade" id="addCenterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Recycling Center</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_center">
                    
                    <div class="mb-3">
                        <label class="form-label">Center Name *</label>
                        <input type="text" name="center_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Location *</label>
                        <input type="text" name="location" class="form-control" required>
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
                        <label class="form-label">Operating Hours *</label>
                        <input type="text" name="operating_hours" class="form-control" placeholder="e.g., Mon-Sat 8:00 AM - 5:00 PM" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Accepted Materials *</label>
                        <textarea name="accepted_materials" class="form-control" rows="2" required></textarea>
                        <small class="text-muted">e.g., Paper, Plastic, Metal, Glass</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Services Offered *</label>
                        <textarea name="services_offered" class="form-control" rows="2" required></textarea>
                        <small class="text-muted">e.g., Buyback, Drop-off, Educational tours</small>
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
                    <button type="submit" class="btn btn-primary">Add Center</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Center Modal -->
<div class="modal fade" id="editCenterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Recycling Center</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_center">
                    <input type="hidden" name="center_id" id="edit_center_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Center Name *</label>
                        <input type="text" name="center_name" id="edit_center_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Location *</label>
                        <input type="text" name="location" id="edit_location" class="form-control" required>
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
                        <label class="form-label">Operating Hours *</label>
                        <input type="text" name="operating_hours" id="edit_operating_hours" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Accepted Materials *</label>
                        <textarea name="accepted_materials" id="edit_accepted_materials" class="form-control" rows="2" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Services Offered *</label>
                        <textarea name="services_offered" id="edit_services_offered" class="form-control" rows="2" required></textarea>
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
                    <button type="submit" class="btn btn-primary">Update Center</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Center Modal -->
<div class="modal fade" id="viewCenterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Recycling Center Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="centerDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Participant Modal -->
<div class="modal fade" id="addParticipantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Program Participant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_participant">
                    
                    <div class="mb-3">
                        <label class="form-label">Program *</label>
                        <select name="program_id" class="form-control" required>
                            <option value="">Select Program</option>
                            <?php 
                            $programs_result->data_seek(0);
                            while ($program = $programs_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $program['program_id']; ?>">
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Resident *</label>
                        <select name="resident_id" class="form-control" required>
                            <option value="">Select Resident</option>
                            <?php while ($resident = $residents_result->fetch_assoc()): ?>
                                <option value="<?php echo $resident['resident_id']; ?>">
                                    <?php echo htmlspecialchars($resident['full_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Points Earned</label>
                        <input type="number" name="points_earned" class="form-control" value="0" min="0">
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
                    <button type="submit" class="btn btn-success">Add Participant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Participant Modal -->
<div class="modal fade" id="editParticipantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Participant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_participant">
                    <input type="hidden" name="participant_id" id="edit_participant_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Resident</label>
                        <input type="text" id="edit_participant_name" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Program</label>
                        <input type="text" id="edit_participant_program" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Points Earned *</label>
                        <input type="number" name="points_earned" id="edit_points_earned" class="form-control" min="0" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status *</label>
                        <select name="status" id="edit_participant_status" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Participant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Center functions
function viewCenter(center) {
    const content = `
        <div class="row">
            <div class="col-md-12 mb-3">
                <h5>${center.center_name}</h5>
            </div>
            <div class="col-md-6 mb-3">
                <strong><i class="fas fa-map-marker-alt me-2"></i>Location:</strong><br>
                ${center.location}
            </div>
            <div class="col-md-6 mb-3">
                <strong><i class="fas fa-clock me-2"></i>Operating Hours:</strong><br>
                ${center.operating_hours}
            </div>
            <div class="col-md-6 mb-3">
                <strong><i class="fas fa-user me-2"></i>Contact Person:</strong><br>
                ${center.contact_person}
            </div>
            <div class="col-md-6 mb-3">
                <strong><i class="fas fa-phone me-2"></i>Contact Number:</strong><br>
                ${center.contact_number}
            </div>
            <div class="col-md-12 mb-3">
                <strong><i class="fas fa-recycle me-2"></i>Accepted Materials:</strong><br>
                ${center.accepted_materials}
            </div>
            <div class="col-md-12 mb-3">
                <strong><i class="fas fa-hands-helping me-2"></i>Services Offered:</strong><br>
                ${center.services_offered}
            </div>
            <div class="col-md-6 mb-3">
                <strong><i class="fas fa-info-circle me-2"></i>Status:</strong><br>
                <span class="badge bg-${center.status == 'active' ? 'success' : 'secondary'}">
                    ${center.status.toUpperCase()}
                </span>
            </div>
        </div>
    `;
    
    document.getElementById('centerDetailsContent').innerHTML = content;
    var modal = new bootstrap.Modal(document.getElementById('viewCenterModal'));
    modal.show();
}

function editCenter(center) {
    document.getElementById('edit_center_id').value = center.center_id;
    document.getElementById('edit_center_name').value = center.center_name;
    document.getElementById('edit_location').value = center.location;
    document.getElementById('edit_contact_person').value = center.contact_person;
    document.getElementById('edit_contact_number').value = center.contact_number;
    document.getElementById('edit_operating_hours').value = center.operating_hours;
    document.getElementById('edit_accepted_materials').value = center.accepted_materials;
    document.getElementById('edit_services_offered').value = center.services_offered;
    document.getElementById('edit_status').value = center.status;
    
    var modal = new bootstrap.Modal(document.getElementById('editCenterModal'));
    modal.show();
}

function deleteCenter(centerId) {
    if (confirm('Are you sure you want to delete this recycling center?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_center">' +
                        '<input type="hidden" name="center_id" value="' + centerId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Participant functions
function editParticipant(participant) {
    document.getElementById('edit_participant_id').value = participant.participant_id;
    document.getElementById('edit_participant_name').value = participant.resident_name;
    document.getElementById('edit_participant_program').value = participant.program_name;
    document.getElementById('edit_points_earned').value = participant.points_earned;
    document.getElementById('edit_participant_status').value = participant.status;
    
    var modal = new bootstrap.Modal(document.getElementById('editParticipantModal'));
    modal.show();
}
</script>

<?php require_once '../../../includes/footer.php'; ?>