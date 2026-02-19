<?php
/**
 * Evacuation Centers Management
 * Path: barangaylink/disasters/evacuation-centers.php
 */

// Don't call session_start() here - config.php handles it
require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff', 'Secretary'])) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit();
}

$page_title = 'Evacuation Centers';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_center':
                $center_name = sanitizeInput($_POST['center_name']);
                $location = sanitizeInput($_POST['location']);
                $capacity = sanitizeInput($_POST['capacity']);
                $facilities = sanitizeInput($_POST['facilities']);
                $contact_person = sanitizeInput($_POST['contact_person']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $status = sanitizeInput($_POST['status']);
                
                $sql = "INSERT INTO tbl_evacuation_centers 
                        (center_name, location, capacity, facilities, contact_person, contact_number, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                
                if (executeQuery($conn, $sql, 
                    [$center_name, $location, $capacity, $facilities, $contact_person, $contact_number, $status],
                    'ssissss')) {
                    
                    logActivity($conn, getCurrentUserId(), "Added evacuation center: $center_name");
                    setMessage('Evacuation center added successfully', 'success');
                } else {
                    setMessage('Failed to add evacuation center', 'error');
                }
                break;
                
            case 'update_center':
                $center_id = sanitizeInput($_POST['center_id']);
                $center_name = sanitizeInput($_POST['center_name']);
                $location = sanitizeInput($_POST['location']);
                $capacity = sanitizeInput($_POST['capacity']);
                $facilities = sanitizeInput($_POST['facilities']);
                $contact_person = sanitizeInput($_POST['contact_person']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $status = sanitizeInput($_POST['status']);
                
                $sql = "UPDATE tbl_evacuation_centers 
                        SET center_name = ?, location = ?, capacity = ?, facilities = ?,
                            contact_person = ?, contact_number = ?, status = ?
                        WHERE center_id = ?";
                
                if (executeQuery($conn, $sql, 
                    [$center_name, $location, $capacity, $facilities, $contact_person, $contact_number, $status, $center_id],
                    'ssissssi')) {
                    
                    logActivity($conn, getCurrentUserId(), "Updated evacuation center ID: $center_id");
                    setMessage('Evacuation center updated successfully', 'success');
                } else {
                    setMessage('Failed to update evacuation center', 'error');
                }
                break;
                
            case 'delete_center':
                $center_id = sanitizeInput($_POST['center_id']);
                
                // Check if there are active evacuees
                $check = fetchOne($conn, 
                    "SELECT COUNT(*) as count FROM tbl_evacuees WHERE center_id = ? AND status = 'Active'",
                    [$center_id], 'i');
                
                if ($check && $check['count'] > 0) {
                    setMessage('Cannot delete center with active evacuees. Please check out all evacuees first.', 'error');
                } else {
                    $sql = "DELETE FROM tbl_evacuation_centers WHERE center_id = ?";
                    
                    if (executeQuery($conn, $sql, [$center_id], 'i')) {
                        logActivity($conn, getCurrentUserId(), "Deleted evacuation center ID: $center_id");
                        setMessage('Evacuation center deleted successfully', 'success');
                    } else {
                        setMessage('Failed to delete evacuation center', 'error');
                    }
                }
                break;
        }
        header('Location: evacuation-centers.php');
        exit();
    }
}

// Fetch all centers with evacuee count
$sql = "SELECT ec.*, 
        COUNT(e.evacuee_id) as evacuee_count,
        COALESCE(SUM(CASE WHEN e.status = 'Active' THEN e.family_members ELSE 0 END), 0) as total_evacuees
        FROM tbl_evacuation_centers ec
        LEFT JOIN tbl_evacuees e ON ec.center_id = e.center_id AND e.status = 'Active'
        GROUP BY ec.center_id
        ORDER BY ec.center_name";

$centers = fetchAll($conn, $sql);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-home text-primary me-2"></i>Evacuation Centers</h1>
            <p class="text-muted">Manage evacuation center information and capacity</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCenterModal">
            <i class="fas fa-plus me-2"></i>Add Center
        </button>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <?php
        $total_centers = count($centers);
        $active_centers = count(array_filter($centers, fn($c) => $c['status'] === 'Active'));
        $total_capacity = array_sum(array_column($centers, 'capacity'));
        $total_evacuees = array_sum(array_column($centers, 'total_evacuees'));
        $occupancy_rate = $total_capacity > 0 ? ($total_evacuees / $total_capacity) * 100 : 0;
        ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Centers</p>
                            <h3 class="mb-0"><?php echo $total_centers; ?></h3>
                        </div>
                        <div class="fs-1 text-primary"><i class="fas fa-building"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Active Centers</p>
                            <h3 class="mb-0"><?php echo $active_centers; ?></h3>
                        </div>
                        <div class="fs-1 text-success"><i class="fas fa-check-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Capacity</p>
                            <h3 class="mb-0"><?php echo $total_capacity; ?></h3>
                        </div>
                        <div class="fs-1 text-info"><i class="fas fa-users"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Occupancy Rate</p>
                            <h3 class="mb-0"><?php echo round($occupancy_rate, 1); ?>%</h3>
                        </div>
                        <div class="fs-1 text-warning"><i class="fas fa-chart-pie"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Centers Grid -->
    <div class="row g-3">
        <?php foreach ($centers as $center): ?>
        <?php
        $evacuee_count = $center['total_evacuees'];
        $capacity = $center['capacity'];
        $occupancy = $capacity > 0 ? ($evacuee_count / $capacity) * 100 : 0;
        $occupancy_class = $occupancy >= 90 ? 'danger' : ($occupancy >= 70 ? 'warning' : 'success');
        $available_space = $capacity - $evacuee_count;
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo htmlspecialchars($center['center_name']); ?></h5>
                    <?php echo getStatusBadge($center['status']); ?>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i>Location:</small>
                        <p class="mb-0"><?php echo htmlspecialchars($center['location']); ?></p>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">Capacity:</small>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar bg-<?php echo $occupancy_class; ?>" 
                                 style="width: <?php echo min($occupancy, 100); ?>%">
                                <?php echo $evacuee_count; ?> / <?php echo $capacity; ?> (<?php echo round($occupancy, 1); ?>%)
                            </div>
                        </div>
                        <small class="text-muted">Available: <?php echo $available_space; ?> spaces</small>
                    </div>
                    
                    <?php if (!empty($center['facilities'])): ?>
                    <div class="mb-3">
                        <small class="text-muted"><i class="fas fa-list me-1"></i>Facilities:</small>
                        <p class="mb-0 small"><?php echo htmlspecialchars(truncateText($center['facilities'], 80)); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-2">
                        <small class="text-muted"><i class="fas fa-user me-1"></i>Contact Person:</small>
                        <p class="mb-0"><?php echo htmlspecialchars($center['contact_person']); ?></p>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted"><i class="fas fa-phone me-1"></i>Contact Number:</small>
                        <p class="mb-0"><?php echo htmlspecialchars($center['contact_number']); ?></p>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary flex-fill" onclick="viewCenter(<?php echo $center['center_id']; ?>)">
                            <i class="fas fa-eye me-1"></i>View
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="editCenter(<?php echo $center['center_id']; ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteCenter(<?php echo $center['center_id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Center Modal -->
<div class="modal fade" id="addCenterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Evacuation Center</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_center">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Center Name *</label>
                            <input type="text" name="center_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" class="form-control" min="1" required>
                            <small class="text-muted">Maximum number of people</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required placeholder="Complete address">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Facilities</label>
                            <textarea name="facilities" class="form-control" rows="3" placeholder="e.g., Restrooms, Shower, Kitchen, Medical Tent, Sleeping Area"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" class="form-control" required placeholder="09XX-XXX-XXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Add Center
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Center Modal -->
<div class="modal fade" id="viewCenterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Evacuation Center Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <!-- Basic Information -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-building me-2 text-primary"></i>Basic Information</h6>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="text-muted small mb-1">Center Name</label>
                                <p class="mb-0 fw-bold" id="viewCenterName"></p>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small mb-1">Status</label>
                                <p class="mb-0" id="viewCenterStatus"></p>
                            </div>
                            <div class="col-12">
                                <label class="text-muted small mb-1">Location</label>
                                <p class="mb-0" id="viewCenterLocation"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Capacity Information -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-users me-2 text-success"></i>Capacity Information</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="text-muted small mb-1">Maximum Capacity</label>
                                <p class="mb-0 fs-4 fw-bold text-primary" id="viewCenterCapacity"></p>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small mb-1">Current Evacuees</label>
                                <p class="mb-0 fs-4 fw-bold text-success" id="viewCenterEvacuees"></p>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small mb-1">Available Space</label>
                                <p class="mb-0 fs-4 fw-bold text-info" id="viewCenterAvailable"></p>
                            </div>
                            <div class="col-12">
                                <label class="text-muted small mb-1">Occupancy Rate</label>
                                <div class="progress" style="height: 30px;">
                                    <div class="progress-bar" id="viewCenterProgress" style="width: 0%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Facilities -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-list me-2 text-warning"></i>Facilities</h6>
                        <div class="alert alert-light mb-0">
                            <p class="mb-0" id="viewCenterFacilities"></p>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-address-book me-2 text-secondary"></i>Contact Information</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Contact Person</label>
                                <p class="mb-0 fw-bold" id="viewCenterContactPerson"></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Contact Number</label>
                                <p class="mb-0 fw-bold" id="viewCenterContactNumber"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" onclick="editCenterFromView()">
                    <i class="fas fa-edit me-1"></i>Edit Center
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Center Modal -->
<div class="modal fade" id="editCenterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Evacuation Center</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_center">
                <input type="hidden" name="center_id" id="editCenterId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Center Name *</label>
                            <input type="text" name="center_name" id="editCenterName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" id="editCenterCapacity" class="form-control" min="1" required>
                            <small class="text-muted">Maximum number of people</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" id="editCenterLocation" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Facilities</label>
                            <textarea name="facilities" id="editCenterFacilities" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" id="editCenterContactPerson" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" id="editCenterContactNumber" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" id="editCenterStatus" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Full">Full</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Update Center
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Center Modal -->
<div class="modal fade" id="deleteCenterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_center">
                <input type="hidden" name="center_id" id="deleteCenterId">
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Warning!</strong> This action cannot be undone.
                    </div>
                    <p>Are you sure you want to delete this evacuation center?</p>
                    <div class="card bg-light">
                        <div class="card-body">
                            <p class="mb-1"><strong>Center Name:</strong> <span id="deleteCenterName"></span></p>
                            <p class="mb-1"><strong>Location:</strong> <span id="deleteCenterLocation"></span></p>
                            <p class="mb-0"><strong>Active Evacuees:</strong> <span id="deleteCenterEvacuees" class="badge bg-warning"></span></p>
                        </div>
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Note: You cannot delete a center with active evacuees. Please check out all evacuees first.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>Delete Center
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const centersData = <?php echo json_encode($centers); ?>;
let currentCenterId = null;

function viewCenter(id) {
    const center = centersData.find(c => c.center_id == id);
    if (!center) return;
    
    const occupancy = center.capacity > 0 ? ((center.total_evacuees / center.capacity) * 100).toFixed(1) : 0;
    const available = center.capacity - center.total_evacuees;
    
    // Determine progress bar color
    let progressClass = 'bg-success';
    if (occupancy >= 90) progressClass = 'bg-danger';
    else if (occupancy >= 70) progressClass = 'bg-warning';
    
    // Populate modal
    document.getElementById('viewCenterName').textContent = center.center_name;
    document.getElementById('viewCenterLocation').textContent = center.location;
    document.getElementById('viewCenterCapacity').textContent = center.capacity;
    document.getElementById('viewCenterEvacuees').textContent = center.total_evacuees;
    document.getElementById('viewCenterAvailable').textContent = available;
    document.getElementById('viewCenterFacilities').textContent = center.facilities || 'No facilities listed';
    document.getElementById('viewCenterContactPerson').textContent = center.contact_person;
    document.getElementById('viewCenterContactNumber').textContent = center.contact_number;
    document.getElementById('viewCenterStatus').innerHTML = getStatusBadgeHTML(center.status);
    
    // Update progress bar
    const progressBar = document.getElementById('viewCenterProgress');
    progressBar.style.width = Math.min(occupancy, 100) + '%';
    progressBar.className = 'progress-bar ' + progressClass;
    progressBar.textContent = center.total_evacuees + ' / ' + center.capacity + ' (' + occupancy + '%)';
    
    currentCenterId = id;
    
    // Show modal
    const viewModal = new bootstrap.Modal(document.getElementById('viewCenterModal'));
    viewModal.show();
}

function editCenterFromView() {
    // Close view modal
    const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewCenterModal'));
    viewModal.hide();
    
    // Open edit modal
    setTimeout(() => {
        editCenter(currentCenterId);
    }, 300);
}

function editCenter(id) {
    const center = centersData.find(c => c.center_id == id);
    if (!center) return;
    
    // Populate edit form
    document.getElementById('editCenterId').value = center.center_id;
    document.getElementById('editCenterName').value = center.center_name;
    document.getElementById('editCenterLocation').value = center.location;
    document.getElementById('editCenterCapacity').value = center.capacity;
    document.getElementById('editCenterFacilities').value = center.facilities || '';
    document.getElementById('editCenterContactPerson').value = center.contact_person;
    document.getElementById('editCenterContactNumber').value = center.contact_number;
    document.getElementById('editCenterStatus').value = center.status;
    
    // Show modal
    const editModal = new bootstrap.Modal(document.getElementById('editCenterModal'));
    editModal.show();
}

function deleteCenter(id) {
    const center = centersData.find(c => c.center_id == id);
    if (!center) return;
    
    // Populate delete modal
    document.getElementById('deleteCenterId').value = center.center_id;
    document.getElementById('deleteCenterName').textContent = center.center_name;
    document.getElementById('deleteCenterLocation').textContent = center.location;
    document.getElementById('deleteCenterEvacuees').textContent = center.total_evacuees;
    
    // Disable delete button if there are active evacuees
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    if (center.total_evacuees > 0) {
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fas fa-ban me-1"></i>Cannot Delete';
    } else {
        deleteBtn.disabled = false;
        deleteBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Delete Center';
    }
    
    // Show modal
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteCenterModal'));
    deleteModal.show();
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Active': '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
        'Inactive': '<span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>Inactive</span>',
        'Full': '<span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i>Full</span>',
        'Under Maintenance': '<span class="badge bg-warning"><i class="fas fa-tools me-1"></i>Under Maintenance</span>'
    };
    return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>