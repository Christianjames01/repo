<?php
/**
 * Disaster Incidents Management
 * Path: barangaylink/disasters/disaster-incidents.php
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

$page_title = 'Disaster Incidents';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_incident':
                $disaster_name = sanitizeInput($_POST['disaster_name']);
                $disaster_type = sanitizeInput($_POST['disaster_type']);
                $incident_date = sanitizeInput($_POST['incident_date']);
                $location = sanitizeInput($_POST['location']);
                $severity = sanitizeInput($_POST['severity']);
                $affected_families = sanitizeInput($_POST['affected_families']);
                $casualties = sanitizeInput($_POST['casualties']);
                $description = sanitizeInput($_POST['description']);
                $status = sanitizeInput($_POST['status']);
                
                $sql = "INSERT INTO tbl_disaster_incidents 
                        (disaster_name, disaster_type, incident_date, location, severity, affected_families, 
                         casualties, description, status, reported_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                if (executeQuery($conn, $sql, 
                    [$disaster_name, $disaster_type, $incident_date, $location, $severity, $affected_families, 
                     $casualties, $description, $status, $_SESSION['user_id']],
                    'sssssiissi')) {
                    
                    logActivity($conn, $_SESSION['user_id'], "Added disaster incident: $disaster_name at $location");
                    setMessage('Disaster incident added successfully', 'success');
                } else {
                    setMessage('Failed to add disaster incident', 'error');
                }
                break;
                
            case 'edit_incident':
                $incident_id = sanitizeInput($_POST['incident_id']);
                $disaster_name = sanitizeInput($_POST['disaster_name']);
                $disaster_type = sanitizeInput($_POST['disaster_type']);
                $incident_date = sanitizeInput($_POST['incident_date']);
                $location = sanitizeInput($_POST['location']);
                $severity = sanitizeInput($_POST['severity']);
                $affected_families = sanitizeInput($_POST['affected_families']);
                $casualties = sanitizeInput($_POST['casualties']);
                $description = sanitizeInput($_POST['description']);
                $status = sanitizeInput($_POST['status']);
                
                $sql = "UPDATE tbl_disaster_incidents 
                        SET disaster_name = ?, disaster_type = ?, incident_date = ?, location = ?, 
                            severity = ?, affected_families = ?, casualties = ?, description = ?, status = ?
                        WHERE incident_id = ?";
                
                if (executeQuery($conn, $sql, 
                    [$disaster_name, $disaster_type, $incident_date, $location, $severity, 
                     $affected_families, $casualties, $description, $status, $incident_id],
                    'sssssiissi')) {
                    
                    logActivity($conn, $_SESSION['user_id'], "Updated disaster incident: $disaster_name");
                    setMessage('Disaster incident updated successfully', 'success');
                } else {
                    setMessage('Failed to update disaster incident', 'error');
                }
                break;
                
            case 'update_status':
                $incident_id = sanitizeInput($_POST['incident_id']);
                $status = sanitizeInput($_POST['status']);
                
                $sql = "UPDATE tbl_disaster_incidents SET status = ? WHERE incident_id = ?";
                
                if (executeQuery($conn, $sql, [$status, $incident_id], 'si')) {
                    logActivity($conn, $_SESSION['user_id'], "Updated disaster incident status ID: $incident_id");
                    setMessage('Incident status updated successfully', 'success');
                } else {
                    setMessage('Failed to update incident status', 'error');
                }
                break;
                
            case 'delete_incident':
                $incident_id = sanitizeInput($_POST['incident_id']);
                
                $sql = "DELETE FROM tbl_disaster_incidents WHERE incident_id = ?";
                
                if (executeQuery($conn, $sql, [$incident_id], 'i')) {
                    logActivity($conn, $_SESSION['user_id'], "Deleted disaster incident ID: $incident_id");
                    setMessage('Disaster incident deleted successfully', 'success');
                } else {
                    setMessage('Failed to delete disaster incident', 'error');
                }
                break;
        }
        header('Location: disaster-incidents.php');
        exit();
    }
}

// Fetch incidents with reporter information
$sql = "SELECT di.*, 
        CASE 
            WHEN di.reported_by IS NOT NULL THEN u.username
            WHEN di.resident_reporter_id IS NOT NULL THEN CONCAT(r.first_name, ' ', r.last_name)
            ELSE 'N/A'
        END as reported_by_name
        FROM tbl_disaster_incidents di
        LEFT JOIN tbl_users u ON di.reported_by = u.user_id
        LEFT JOIN tbl_residents r ON di.resident_reporter_id = r.resident_id
        ORDER BY di.incident_date DESC, di.created_at DESC";

$incidents = fetchAll($conn, $sql);

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Enhanced Modal Styles */
.modal-content {
    border-radius: 15px;
    overflow: hidden;
}

.modal-header.bg-gradient {
    background: linear-gradient(135deg, var(--bs-primary) 0%, var(--bs-info) 100%);
}

.modal-header.bg-gradient.bg-info {
    background: linear-gradient(135deg, #0dcaf0 0%, #0d6efd 100%);
}

.modal-header.bg-gradient.bg-warning {
    background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
}

.modal-body {
    max-height: 70vh;
    overflow-y: auto;
}

/* View Modal Specific Styles */
#viewIncidentContent .info-card {
    background: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
}

#viewIncidentContent .stat-box {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    border: 2px solid #dee2e6;
}

#viewIncidentContent .stat-box .stat-value {
    font-size: 2rem;
    font-weight: bold;
    display: block;
    margin-bottom: 5px;
}

#viewIncidentContent .stat-box .stat-label {
    color: #6c757d;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Form Enhancements */
.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
}

.form-label.fw-bold {
    color: #495057;
    margin-bottom: 8px;
}

.form-label i {
    font-size: 0.9rem;
}

/* Delete Modal Animation */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

#deleteIncidentModal .display-1 {
    animation: shake 0.5s ease-in-out;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .modal-dialog-lg {
        margin: 0.5rem;
    }
    
    .modal-body {
        max-height: 60vh;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Disaster Incidents</h1>
            <p class="text-muted">Track and manage disaster incidents in the barangay</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addIncidentModal">
            <i class="fas fa-plus me-2"></i>Report Incident
        </button>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <?php
        $total_incidents = count($incidents);
        $active_count = count(array_filter($incidents, fn($i) => in_array($i['status'], ['Active', 'Ongoing'])));
        $total_families = array_sum(array_column($incidents, 'affected_families'));
        $total_casualties = array_sum(array_column($incidents, 'casualties'));
        ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Incidents</p>
                            <h3 class="mb-0"><?php echo $total_incidents; ?></h3>
                        </div>
                        <div class="fs-1 text-danger"><i class="fas fa-exclamation-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Active</p>
                            <h3 class="mb-0"><?php echo $active_count; ?></h3>
                        </div>
                        <div class="fs-1 text-warning"><i class="fas fa-fire"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Affected Families</p>
                            <h3 class="mb-0"><?php echo $total_families; ?></h3>
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
                            <p class="text-muted mb-1">Casualties</p>
                            <h3 class="mb-0"><?php echo $total_casualties; ?></h3>
                        </div>
                        <div class="fs-1 text-secondary"><i class="fas fa-user-injured"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Incidents Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">Disaster Incidents</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="incidentsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Severity</th>
                            <th>Affected Families</th>
                            <th>Casualties</th>
                            <th>Status</th>
                            <th>Reported By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incidents)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No disaster incidents found</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($incidents as $incident): ?>
                            <tr>
                                <td><?php echo formatDate($incident['incident_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($incident['disaster_name']); ?></strong></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($incident['disaster_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                <td><?php echo getSeverityBadge($incident['severity']); ?></td>
                                <td><strong><?php echo $incident['affected_families']; ?></strong></td>
                                <td><?php echo $incident['casualties']; ?></td>
                                <td><?php echo getStatusBadge($incident['status']); ?></td>
                                <td><small><?php echo htmlspecialchars($incident['reported_by_name'] ?? 'N/A'); ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" 
                                                onclick='viewIncident(<?php echo json_encode($incident); ?>)' 
                                                title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-warning" 
                                                onclick='editIncident(<?php echo json_encode($incident); ?>)' 
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger" 
                                                onclick="showDeleteModal(<?php echo $incident['incident_id']; ?>, '<?php echo addslashes(htmlspecialchars($incident['disaster_name'])); ?>')" 
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Incident Modal -->
<div class="modal fade" id="addIncidentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary bg-opacity-10">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Report Disaster Incident</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_incident">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Disaster Name *</label>
                            <input type="text" name="disaster_name" class="form-control" placeholder="e.g., Typhoon Kristine" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Disaster Type *</label>
                            <select name="disaster_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Typhoon">Typhoon</option>
                                <option value="Flood">Flood</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Fire">Fire</option>
                                <option value="Landslide">Landslide</option>
                                <option value="Storm Surge">Storm Surge</option>
                                <option value="Volcanic Eruption">Volcanic Eruption</option>
                                <option value="Drought">Drought</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Incident Date *</label>
                            <input type="date" name="incident_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Location *</label>
                            <input type="text" name="location" class="form-control" placeholder="Specific location" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Severity *</label>
                            <select name="severity" class="form-select" required>
                                <option value="">Select Severity</option>
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Affected Families *</label>
                            <input type="number" name="affected_families" class="form-control" min="0" value="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Casualties</label>
                            <input type="number" name="casualties" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Resolved">Resolved</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Detailed description of the incident..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Report Incident
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Incident Modal - Enhanced -->
<div class="modal fade" id="viewIncidentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gradient bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Incident Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="viewIncidentContent">
                    <!-- Content populated by JavaScript -->
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Close
                </button>
                <button type="button" class="btn btn-warning" onclick="editIncidentFromView()" id="editFromViewBtn">
                    <i class="fas fa-edit me-1"></i> Edit Incident
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Incident Modal - Enhanced -->
<div class="modal fade" id="editIncidentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gradient bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Disaster Incident
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit_incident">
                <input type="hidden" name="incident_id" id="edit_incident_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Disaster Name -->
                        <div class="col-md-12">
                            <label class="form-label fw-bold">
                                <i class="fas fa-heading text-primary me-1"></i>
                                Disaster Name *
                            </label>
                            <input type="text" name="disaster_name" id="edit_disaster_name" 
                                   class="form-control form-control-lg" 
                                   placeholder="e.g., Typhoon Kristine" required>
                        </div>

                        <!-- Type and Date Row -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-tag text-secondary me-1"></i>
                                Disaster Type *
                            </label>
                            <select name="disaster_type" id="edit_disaster_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Typhoon">🌀 Typhoon</option>
                                <option value="Flood">🌊 Flood</option>
                                <option value="Earthquake">🏚️ Earthquake</option>
                                <option value="Fire">🔥 Fire</option>
                                <option value="Landslide">⛰️ Landslide</option>
                                <option value="Storm Surge">🌊 Storm Surge</option>
                                <option value="Volcanic Eruption">🌋 Volcanic Eruption</option>
                                <option value="Drought">☀️ Drought</option>
                                <option value="Other">❓ Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-calendar text-danger me-1"></i>
                                Incident Date *
                            </label>
                            <input type="date" name="incident_date" id="edit_incident_date" 
                                   class="form-control" required>
                        </div>

                        <!-- Location and Severity Row -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-map-marker-alt text-success me-1"></i>
                                Location *
                            </label>
                            <input type="text" name="location" id="edit_location" 
                                   class="form-control" placeholder="Specific location" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-exclamation-circle text-warning me-1"></i>
                                Severity *
                            </label>
                            <select name="severity" id="edit_severity" class="form-select" required>
                                <option value="">Select Severity</option>
                                <option value="Low">🟢 Low</option>
                                <option value="Medium">🟡 Medium</option>
                                <option value="High">🟠 High</option>
                                <option value="Critical">🔴 Critical</option>
                            </select>
                        </div>

                        <!-- Statistics Row -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-users text-info me-1"></i>
                                Affected Families *
                            </label>
                            <input type="number" name="affected_families" id="edit_affected_families" 
                                   class="form-control" min="0" required>
                            <small class="text-muted">Number of families affected</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-user-injured text-danger me-1"></i>
                                Casualties
                            </label>
                            <input type="number" name="casualties" id="edit_casualties" 
                                   class="form-control" min="0" value="0">
                            <small class="text-muted">Number of casualties (if any)</small>
                        </div>

                        <!-- Status -->
                        <div class="col-md-12">
                            <label class="form-label fw-bold">
                                <i class="fas fa-flag text-primary me-1"></i>
                                Status *
                            </label>
                            <select name="status" id="edit_status" class="form-select form-select-lg" required>
                                <option value="Active">⚠️ Active - Currently happening</option>
                                <option value="Ongoing">🔄 Ongoing - In progress</option>
                                <option value="Resolved">✅ Resolved - Handled</option>
                                <option value="Closed">📁 Closed - Completed</option>
                            </select>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label fw-bold">
                                <i class="fas fa-align-left text-secondary me-1"></i>
                                Description
                            </label>
                            <textarea name="description" id="edit_description" 
                                      class="form-control" rows="4" 
                                      placeholder="Provide detailed information about the incident, damage assessment, and any other relevant details..."></textarea>
                            <small class="text-muted">Optional: Add more details about the incident</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i> Update Incident
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal - Enhanced -->
<div class="modal fade" id="deleteIncidentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Confirm Deletion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_incident">
                <input type="hidden" name="incident_id" id="delete_incident_id">
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="display-1 text-danger mb-3">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                        <h5 class="mb-3">Are you sure you want to delete this incident?</h5>
                    </div>
                    
                    <div class="alert alert-warning border-start border-4 border-warning">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle fs-3 me-3 text-warning"></i>
                            <div>
                                <strong class="d-block mb-1">Incident to be deleted:</strong>
                                <span id="deleteIncidentName" class="fs-5"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-danger border-start border-4 border-danger mb-0">
                        <div class="d-flex">
                            <i class="fas fa-info-circle me-2 mt-1"></i>
                            <div>
                                <strong>Warning:</strong> This action cannot be undone.
                                <ul class="mb-0 mt-2 ps-3">
                                    <li>All incident data will be permanently deleted</li>
                                    <li>This includes all related records and statistics</li>
                                    <li>Recovery will not be possible</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i> Yes, Delete Permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize Bootstrap modals
let viewIncidentModal;
let editIncidentModal;
let deleteIncidentModal;
let currentViewingIncident = null;

document.addEventListener('DOMContentLoaded', function() {
    viewIncidentModal = new bootstrap.Modal(document.getElementById('viewIncidentModal'));
    editIncidentModal = new bootstrap.Modal(document.getElementById('editIncidentModal'));
    deleteIncidentModal = new bootstrap.Modal(document.getElementById('deleteIncidentModal'));
});

function getSeverityBadgeHTML(severity) {
    const badges = {
        'Low': '<span class="badge bg-success">Low</span>',
        'Medium': '<span class="badge bg-warning">Medium</span>',
        'High': '<span class="badge bg-danger">High</span>',
        'Critical': '<span class="badge bg-dark">Critical</span>'
    };
    return badges[severity] || severity;
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Active': '<span class="badge bg-warning">Active</span>',
        'Ongoing': '<span class="badge bg-primary">Ongoing</span>',
        'Resolved': '<span class="badge bg-success">Resolved</span>',
        'Closed': '<span class="badge bg-secondary">Closed</span>'
    };
    return badges[status] || status;
}

// Helper function for date formatting
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

// Helper function for datetime formatting
function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Enhanced View Incident Function
function viewIncident(incident) {
    if (!incident) {
        console.error('No incident data provided');
        return;
    }
    
    // Store current incident for edit button
    currentViewingIncident = incident;
    
    const content = `
        <div class="row g-3">
            <!-- Header Section -->
            <div class="col-12">
                <div class="info-card">
                    <h4 class="mb-2">
                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                        ${escapeHtml(incident.disaster_name)}
                    </h4>
                    <span class="badge bg-secondary fs-6">${escapeHtml(incident.disaster_type)}</span>
                    ${getStatusBadgeHTML(incident.status)}
                    ${getSeverityBadgeHTML(incident.severity)}
                </div>
            </div>

            <!-- Date and Location -->
            <div class="col-md-6">
                <div class="info-card">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-calendar-alt text-danger fs-3 me-3"></i>
                        <div>
                            <strong class="text-muted d-block small">Incident Date</strong>
                            <span class="fs-5">${formatDate(incident.incident_date)}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-map-marker-alt text-success fs-3 me-3"></i>
                        <div>
                            <strong class="text-muted d-block small">Location</strong>
                            <span class="fs-5">${escapeHtml(incident.location)}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="col-md-6">
                <div class="stat-box border-primary">
                    <span class="stat-value text-primary">
                        <i class="fas fa-users"></i> ${incident.affected_families}
                    </span>
                    <span class="stat-label">Affected Families</span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-box border-danger">
                    <span class="stat-value text-danger">
                        <i class="fas fa-user-injured"></i> ${incident.casualties}
                    </span>
                    <span class="stat-label">Casualties</span>
                </div>
            </div>

            <!-- Reported By -->
            <div class="col-12">
                <div class="info-card">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-user text-info fs-4 me-3"></i>
                        <div>
                            <strong class="text-muted d-block small">Reported By</strong>
                            <span class="fs-6">${escapeHtml(incident.reported_by_name || 'N/A')}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            ${incident.description ? `
                <div class="col-12">
                    <div class="info-card">
                        <strong class="text-muted d-block mb-2">
                            <i class="fas fa-align-left me-1"></i> Description
                        </strong>
                        <div class="border rounded p-3 bg-white">
                            <p class="mb-0" style="white-space: pre-wrap; line-height: 1.6;">
                                ${escapeHtml(incident.description)}
                            </p>
                        </div>
                    </div>
                </div>
            ` : ''}

            <!-- Created At -->
            ${incident.created_at ? `
                <div class="col-12">
                    <div class="text-center text-muted small">
                        <i class="fas fa-clock me-1"></i>
                        Reported on ${formatDateTime(incident.created_at)}
                    </div>
                </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('viewIncidentContent').innerHTML = content;
    viewIncidentModal.show();
}

// Helper function to edit from view modal
function editIncidentFromView() {
    if (currentViewingIncident) {
        viewIncidentModal.hide();
        setTimeout(() => {
            editIncident(currentViewingIncident);
        }, 300);
    }
}

function editIncident(incident) {
    if (!incident) {
        console.error('No incident data provided');
        return;
    }
    
    console.log('Editing incident:', incident); // Debug log
    
    document.getElementById('edit_incident_id').value = incident.incident_id;
    document.getElementById('edit_disaster_name').value = incident.disaster_name;
    document.getElementById('edit_disaster_type').value = incident.disaster_type;
    document.getElementById('edit_incident_date').value = incident.incident_date;
    document.getElementById('edit_location').value = incident.location;
    document.getElementById('edit_severity').value = incident.severity;
    document.getElementById('edit_affected_families').value = incident.affected_families;
    document.getElementById('edit_casualties').value = incident.casualties;
    document.getElementById('edit_status').value = incident.status;
    document.getElementById('edit_description').value = incident.description || '';
    
    editIncidentModal.show();
}

function showDeleteModal(id, name) {
    document.getElementById('delete_incident_id').value = id;
    document.getElementById('deleteIncidentName').textContent = name;
    deleteIncidentModal.show();
}

// Initialize DataTable if available
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#incidentsTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "Search incidents:",
                lengthMenu: "Show _MENU_ incidents per page",
                info: "Showing _START_ to _END_ of _TOTAL_ incidents",
                infoEmpty: "No incidents found",
                infoFiltered: "(filtered from _MAX_ total incidents)",
                zeroRecords: "No matching incidents found"
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>