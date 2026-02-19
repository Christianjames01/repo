<?php
/**
 * Damage Assessment Management - COMPLETE VERSION WITH PRINT
 * Path: barangaylink/disasters/damage-assessment.php
 */

require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin','Staff', 'Secretary'])) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit();
}

$page_title = 'Damage Assessment';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_assessment':
                $resident_id = sanitizeInput($_POST['resident_id']);
                $disaster_type = sanitizeInput($_POST['disaster_type']);
                $assessment_date = sanitizeInput($_POST['assessment_date']);
                $location = sanitizeInput($_POST['location']);
                $damage_type = sanitizeInput($_POST['damage_type']);
                $severity = sanitizeInput($_POST['severity']);
                $estimated_cost = sanitizeInput($_POST['estimated_cost']);
                $description = sanitizeInput($_POST['description']);
                $status = sanitizeInput($_POST['status']);
                
                // Validate resident exists
                $resident_check = fetchOne($conn, "SELECT resident_id FROM tbl_residents WHERE resident_id = ?", [$resident_id], 'i');
                if (!$resident_check) {
                    setMessage('Invalid resident selected', 'error');
                    header('Location: damage-assessment.php');
                    exit();
                }
                
                $sql = "INSERT INTO tbl_damage_assessments 
                        (resident_id, disaster_type, assessment_date, location, damage_type, 
                         severity, estimated_cost, description, status, assessed_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                if (executeQuery($conn, $sql, 
                    [$resident_id, $disaster_type, $assessment_date, $location, $damage_type, 
                     $severity, $estimated_cost, $description, $status, getCurrentUserId()],
                    'isssssdssi')) {
                    
                    logActivity($conn, getCurrentUserId(), "Added damage assessment for resident ID: $resident_id");
                    setMessage('Damage assessment added successfully', 'success');
                } else {
                    setMessage('Failed to add damage assessment', 'error');
                }
                break;
                
            case 'update_assessment':
                $assessment_id = sanitizeInput($_POST['assessment_id']);
                $disaster_type = sanitizeInput($_POST['disaster_type']);
                $assessment_date = sanitizeInput($_POST['assessment_date']);
                $location = sanitizeInput($_POST['location']);
                $damage_type = sanitizeInput($_POST['damage_type']);
                $severity = sanitizeInput($_POST['severity']);
                $estimated_cost = sanitizeInput($_POST['estimated_cost']);
                $description = sanitizeInput($_POST['description']);
                $status = sanitizeInput($_POST['status']);
                
                if ($user_role === 'Super Admin' && isset($_POST['assessed_by']) && !empty($_POST['assessed_by'])) {
                    $assessed_by = sanitizeInput($_POST['assessed_by']);
                    
                    $user_check = null;
                    $test_query = "SELECT user_id FROM tbl_users WHERE user_id = ? AND status = 'Active' LIMIT 1";
                    $test_result = fetchOne($conn, $test_query, [$assessed_by], 'i');
                    
                    if ($test_result) {
                        $role_check_queries = [
                            "SELECT u.user_id FROM tbl_users u LEFT JOIN tbl_roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.status = 'Active' AND (r.role_name = 'Tanod' OR r.role_name = 'Staff')",
                            "SELECT user_id FROM tbl_users WHERE user_id = ? AND status = 'Active' AND (role = 'Tanod' OR role = 'Staff')",
                            "SELECT user_id FROM tbl_users WHERE user_id = ? AND status = 'Active' AND (role_name = 'Tanod' OR role_name = 'Staff')"
                        ];
                        
                        foreach ($role_check_queries as $query) {
                            try {
                                $user_check = fetchOne($conn, $query, [$assessed_by], 'i');
                                if ($user_check) break;
                            } catch (Exception $e) {
                                continue;
                            }
                        }
                    }
                    
                    if (!$user_check) {
                        $assessed_by = getCurrentUserId();
                        setMessage('Invalid user selected. Only Tanod or Staff can be assigned. Assessment updated with your account.', 'warning');
                    }
                    
                    $sql = "UPDATE tbl_damage_assessments 
                            SET disaster_type = ?, assessment_date = ?, location = ?, damage_type = ?,
                                severity = ?, estimated_cost = ?, description = ?, status = ?, assessed_by = ?
                            WHERE assessment_id = ?";
                    
                    if (executeQuery($conn, $sql, 
                        [$disaster_type, $assessment_date, $location, $damage_type, 
                         $severity, $estimated_cost, $description, $status, $assessed_by, $assessment_id],
                        'sssssdssii')) {
                        
                        logActivity($conn, getCurrentUserId(), "Updated damage assessment ID: $assessment_id");
                        if (!isset($user_check)) {
                            // Don't override the warning message
                        } else {
                            setMessage('Damage assessment updated successfully', 'success');
                        }
                    } else {
                        setMessage('Failed to update damage assessment', 'error');
                    }
                } else {
                    $sql = "UPDATE tbl_damage_assessments 
                            SET disaster_type = ?, assessment_date = ?, location = ?, damage_type = ?,
                                severity = ?, estimated_cost = ?, description = ?, status = ?
                            WHERE assessment_id = ?";
                    
                    if (executeQuery($conn, $sql, 
                        [$disaster_type, $assessment_date, $location, $damage_type, 
                         $severity, $estimated_cost, $description, $status, $assessment_id],
                        'sssssdssi')) {
                        
                        logActivity($conn, getCurrentUserId(), "Updated damage assessment ID: $assessment_id");
                        setMessage('Damage assessment updated successfully', 'success');
                    } else {
                        setMessage('Failed to update damage assessment', 'error');
                    }
                }
                break;
                
            case 'delete_assessment':
                $assessment_id = sanitizeInput($_POST['assessment_id']);
                $sql = "DELETE FROM tbl_damage_assessments WHERE assessment_id = ?";
                
                if (executeQuery($conn, $sql, [$assessment_id], 'i')) {
                    logActivity($conn, getCurrentUserId(), "Deleted damage assessment ID: $assessment_id");
                    setMessage('Damage assessment deleted successfully', 'success');
                } else {
                    setMessage('Failed to delete damage assessment', 'error');
                }
                break;
        }
        header('Location: damage-assessment.php');
        exit();
    }
}

// Fetch all assessments
$sql = "SELECT da.*, 
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.address,
        r.contact_number,
        r.email,
        u.username as assessed_by_name
        FROM tbl_damage_assessments da
        LEFT JOIN tbl_residents r ON da.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON da.assessed_by = u.user_id
        ORDER BY da.assessment_date DESC, da.created_at DESC";

$assessments = fetchAll($conn, $sql);

// Fetch users for Super Admin - Only Tanod and Staff
$users = [];
if ($user_role === 'Super Admin') {
    $user_columns_result = $conn->query("SHOW COLUMNS FROM tbl_users");
    $user_columns = [];
    while ($row = $user_columns_result->fetch_assoc()) {
        $user_columns[] = $row['Field'];
    }
    
    $role_column = null;
    $use_role_join = false;
    
    if (in_array('role_name', $user_columns)) {
        $role_column = 'role_name';
    } elseif (in_array('role', $user_columns)) {
        $role_column = 'role';
    } elseif (in_array('role_id', $user_columns)) {
        $use_role_join = true;
    }
    
    if ($use_role_join) {
        if (in_array('first_name', $user_columns) && in_array('last_name', $user_columns)) {
            $users = fetchAll($conn, "SELECT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name, r.role_name 
                FROM tbl_users u 
                LEFT JOIN tbl_roles r ON u.role_id = r.role_id 
                WHERE u.status = 'Active' AND (r.role_name = 'Tanod' OR r.role_name = 'Staff') 
                ORDER BY u.last_name, u.first_name");
        } else {
            $users = fetchAll($conn, "SELECT u.user_id, u.username as full_name, r.role_name 
                FROM tbl_users u 
                LEFT JOIN tbl_roles r ON u.role_id = r.role_id 
                WHERE u.status = 'Active' AND (r.role_name = 'Tanod' OR r.role_name = 'Staff') 
                ORDER BY u.username");
        }
    } elseif ($role_column) {
        if (in_array('first_name', $user_columns) && in_array('last_name', $user_columns)) {
            $users = fetchAll($conn, "SELECT user_id, CONCAT(first_name, ' ', last_name) as full_name, $role_column as role_name 
                FROM tbl_users 
                WHERE status = 'Active' AND ($role_column = 'Tanod' OR $role_column = 'Staff') 
                ORDER BY last_name, first_name");
        } elseif (in_array('username', $user_columns)) {
            $users = fetchAll($conn, "SELECT user_id, username as full_name, $role_column as role_name 
                FROM tbl_users 
                WHERE status = 'Active' AND ($role_column = 'Tanod' OR $role_column = 'Staff') 
                ORDER BY username");
        }
    }
}

// Fetch all residents
$residents = fetchAll($conn, "SELECT resident_id, CONCAT(first_name, ' ', last_name) as full_name, address FROM tbl_residents ORDER BY last_name, first_name");

// Calculate statistics
$total_assessments = count($assessments);
$pending_count = count(array_filter($assessments, fn($a) => $a['status'] === 'Pending'));
$completed_count = count(array_filter($assessments, fn($a) => $a['status'] === 'Completed'));
$total_cost = array_sum(array_column($assessments, 'estimated_cost'));

include __DIR__ . '/../includes/header.php';
?>

<style>
.info-row {
    padding: 0.75rem 0;
    border-bottom: 1px solid #e9ecef;
}
.info-row:last-child {
    border-bottom: none;
}
.info-label {
    font-weight: 600;
    color: #6c757d;
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}
.info-value {
    color: #2d3748;
    font-size: 1rem;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-house-damage text-danger me-2"></i>Damage Assessment</h1>
            <p class="text-muted">Manage and track property damage assessments</p>
        </div>
        <div>
            <button class="btn btn-success me-2" onclick="showPrintAllModal()">
                <i class="fas fa-print me-2"></i>Print All
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssessmentModal">
                <i class="fas fa-plus me-2"></i>Add Assessment
            </button>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Assessments</p>
                            <h3 class="mb-0"><?php echo $total_assessments; ?></h3>
                        </div>
                        <div class="fs-1 text-primary"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Pending</p>
                            <h3 class="mb-0"><?php echo $pending_count; ?></h3>
                        </div>
                        <div class="fs-1 text-warning"><i class="fas fa-clock"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Completed</p>
                            <h3 class="mb-0"><?php echo $completed_count; ?></h3>
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
                            <p class="text-muted mb-1">Total Est. Cost</p>
                            <h3 class="mb-0">₱<?php echo number_format($total_cost, 2); ?></h3>
                        </div>
                        <div class="fs-1 text-info"><i class="fas fa-peso-sign"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assessments Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">Damage Assessments</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="assessmentsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Resident</th>
                            <th>Location</th>
                            <th>Disaster Type</th>
                            <th>Damage Type</th>
                            <th>Severity</th>
                            <th>Est. Cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessments as $assessment): ?>
                        <tr>
                            <td><?php echo formatDate($assessment['assessment_date']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($assessment['resident_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($assessment['address']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($assessment['location']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($assessment['disaster_type']); ?></span></td>
                            <td><?php echo htmlspecialchars($assessment['damage_type']); ?></td>
                            <td>
                                <?php 
                                $severity = trim($assessment['severity'] ?? '');
                                if (!empty($severity)) {
                                    echo getSeverityBadge($severity);
                                } else {
                                    echo '<span class="badge bg-secondary">Not Set</span>';
                                }
                                ?>
                            </td>
                            <td>₱<?php echo number_format($assessment['estimated_cost'], 2); ?></td>
                            <td><?php echo getStatusBadge($assessment['status']); ?></td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-info" onclick="viewAssessment(<?php echo $assessment['assessment_id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="printAssessmentDirect(<?php echo $assessment['assessment_id']; ?>)" title="Print">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="editAssessment(<?php echo $assessment['assessment_id']; ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteAssessment(<?php echo $assessment['assessment_id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Assessment Modal -->
<div class="modal fade" id="viewAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-eye me-2"></i>
                    Assessment Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body">
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Resident Information</h6>
                        <div class="info-row">
                            <div class="info-label">Name</div>
                            <div class="info-value" id="view_resident_name"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Address</div>
                            <div class="info-value" id="view_resident_address"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Contact Number</div>
                            <div class="info-value" id="view_resident_contact"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Email</div>
                            <div class="info-value" id="view_resident_email"></div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3"><i class="fas fa-house-damage me-2"></i>Assessment Information</h6>
                        <div class="info-row">
                            <div class="info-label">Assessment Date</div>
                            <div class="info-value" id="view_assessment_date"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Disaster Type</div>
                            <div class="info-value" id="view_disaster_type"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Location</div>
                            <div class="info-value" id="view_location"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Damage Type</div>
                            <div class="info-value" id="view_damage_type"></div>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <div class="row">
                    <div class="col-md-4">
                        <div class="info-row">
                            <div class="info-label"><i class="fas fa-exclamation-triangle text-warning me-1"></i>Severity</div>
                            <div class="info-value" id="view_severity"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-row">
                            <div class="info-label"><i class="fas fa-peso-sign text-success me-1"></i>Estimated Cost</div>
                            <div class="info-value" id="view_estimated_cost"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-row">
                            <div class="info-label"><i class="fas fa-info-circle text-info me-1"></i>Status</div>
                            <div class="info-value" id="view_status"></div>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <div class="row">
                    <div class="col-12">
                        <h6 class="text-primary mb-3"><i class="fas fa-file-alt me-2"></i>Description</h6>
                        <div class="alert alert-light" id="view_description">
                            No description provided
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label"><i class="fas fa-user-check text-primary me-1"></i>Assessed By</div>
                            <div class="info-value" id="view_assessed_by"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label"><i class="fas fa-calendar-plus text-muted me-1"></i>Created At</div>
                            <div class="info-value" id="view_created_at"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Close
                </button>
                <button type="button" class="btn btn-success" onclick="printFromView()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <button type="button" class="btn btn-warning" onclick="editFromView()">
                    <i class="fas fa-edit me-1"></i> Edit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Assessment Modal -->
<div class="modal fade" id="addAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="damage-assessment.php">
                <input type="hidden" name="action" value="add_assessment">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle text-primary me-2"></i>
                        Add Damage Assessment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="resident_id" class="form-label">Resident *</label>
                            <select class="form-select" id="resident_id" name="resident_id" required>
                                <option value="">Select Resident</option>
                                <?php foreach ($residents as $resident): ?>
                                <option value="<?php echo $resident['resident_id']; ?>">
                                    <?php echo htmlspecialchars($resident['full_name']); ?> - 
                                    <?php echo htmlspecialchars($resident['address']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="assessment_date" class="form-label">Assessment Date *</label>
                            <input type="date" class="form-control" id="assessment_date" 
                                   name="assessment_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="disaster_type" class="form-label">Disaster Type *</label>
                            <select class="form-select" id="disaster_type" name="disaster_type" required>
                                <option value="">Select Type</option>
                                <option value="Flood">Flood</option>
                                <option value="Fire">Fire</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Typhoon">Typhoon</option>
                                <option value="Landslide">Landslide</option>
                                <option value="Storm">Storm</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="location" class="form-label">Specific Location *</label>
                            <input type="text" class="form-control" id="location" 
                                   name="location" required placeholder="e.g., Zone 1, Street Name">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="damage_type" class="form-label">Damage Type *</label>
                            <select class="form-select" id="damage_type" name="damage_type" required>
                                <option value="">Select Damage Type</option>
                                <option value="Structural">Structural</option>
                                <option value="Property">Property</option>
                                <option value="Agricultural">Agricultural</option>
                                <option value="Infrastructure">Infrastructure</option>
                                <option value="Personal Belongings">Personal Belongings</option>
                                <option value="Livelihood">Livelihood</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="severity" class="form-label">Severity *</label>
                            <select class="form-select" id="severity" name="severity" required>
                                <option value="">Select Severity</option>
                                <option value="Minor">Minor - Low impact</option>
                                <option value="Moderate">Moderate - Significant damage</option>
                                <option value="Severe">Severe - Major damage</option>
                                <option value="Critical">Critical - Catastrophic</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="estimated_cost" class="form-label">Estimated Cost (₱) *</label>
                            <input type="number" class="form-control" id="estimated_cost" 
                                   name="estimated_cost" required min="0" step="0.01" value="0">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3" placeholder="Detailed description of the damage..."></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Assessment Modal -->
<div class="modal fade" id="editAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="damage-assessment.php" id="editAssessmentForm">
                <input type="hidden" name="action" value="update_assessment">
                <input type="hidden" name="assessment_id" id="edit_assessment_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit text-warning me-2"></i>
                        Edit Damage Assessment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_disaster_type" class="form-label">Disaster Type *</label>
                            <select class="form-select" id="edit_disaster_type" name="disaster_type" required>
                                <option value="Flood">Flood</option>
                                <option value="Fire">Fire</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Typhoon">Typhoon</option>
                                <option value="Landslide">Landslide</option>
                                <option value="Storm">Storm</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="edit_assessment_date" class="form-label">Assessment Date *</label>
                            <input type="date" class="form-control" id="edit_assessment_date" 
                                   name="assessment_date" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="edit_location" class="form-label">Location *</label>
                            <input type="text" class="form-control" id="edit_location" 
                                   name="location" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="edit_damage_type" class="form-label">Damage Type *</label>
                            <select class="form-select" id="edit_damage_type" name="damage_type" required>
                                <option value="Structural">Structural</option>
                                <option value="Property">Property</option>
                                <option value="Agricultural">Agricultural</option>
                                <option value="Infrastructure">Infrastructure</option>
                                <option value="Personal Belongings">Personal Belongings</option>
                                <option value="Livelihood">Livelihood</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="edit_severity" class="form-label">Severity *</label>
                            <select class="form-select" id="edit_severity" name="severity" required>
                                <option value="Minor">Minor</option>
                                <option value="Moderate">Moderate</option>
                                <option value="Severe">Severe</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="edit_estimated_cost" class="form-label">Estimated Cost (₱) *</label>
                            <input type="number" class="form-control" id="edit_estimated_cost" 
                                   name="estimated_cost" required min="0" step="0.01">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label">Status *</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <?php if ($user_role === 'Super Admin'): ?>
                        <div class="col-md-6">
                            <label for="edit_assessed_by" class="form-label">Assessed By</label>
                            <select class="form-select" id="edit_assessed_by" name="assessed_by">
                                <option value="">-- Keep Current --</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                    <?php if (isset($user['role_name'])): ?>
                                        (<?php echo htmlspecialchars($user['role_name']); ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-12">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i> Update Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Confirm Deletion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST" action="damage-assessment.php" id="deleteAssessmentForm">
                <input type="hidden" name="action" value="delete_assessment">
                <input type="hidden" name="assessment_id" id="delete_assessment_id">
                
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-trash-alt text-danger" style="font-size: 3rem;"></i>
                    </div>
                    
                    <h6 class="text-center mb-3">Are you sure you want to delete this damage assessment?</h6>
                    
                    <div class="alert alert-danger">
                        <h6 class="mb-2"><strong>Assessment Details:</strong></h6>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted">Resident:</small><br>
                                <strong id="delete_resident_name"></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Location:</small><br>
                                <strong id="delete_location"></strong>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted">Disaster Type:</small><br>
                                <strong id="delete_disaster_type"></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Estimated Cost:</small><br>
                                <strong id="delete_estimated_cost"></strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. All data associated with this assessment will be permanently deleted.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i> Yes, Delete Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Print All Modal -->
<div class="modal fade" id="printAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-print text-success me-2"></i>
                    Print Assessment Reports
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="printAllForm" action="print-all-assessments.php" method="POST" target="_blank">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Filter Options</label>
                        
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="filter_type" id="filter_all" value="all" checked>
                            <label class="form-check-label" for="filter_all">
                                Print All Assessments
                            </label>
                        </div>
                        
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="filter_type" id="filter_status" value="status">
                            <label class="form-check-label" for="filter_status">
                                Filter by Status
                            </label>
                        </div>
                        
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="filter_type" id="filter_severity" value="severity">
                            <label class="form-check-label" for="filter_severity">
                                Filter by Severity
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="filter_type" id="filter_date" value="date">
                            <label class="form-check-label" for="filter_date">
                                Filter by Date Range
                            </label>
                        </div>
                    </div>

                    <div id="status_filter" class="filter-option" style="display: none;">
                        <label class="form-label">Select Status</label>
                        <select class="form-select" name="status">
                            <option value="Pending">Pending</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div id="severity_filter" class="filter-option" style="display: none;">
                        <label class="form-label">Select Severity</label>
                        <select class="form-select" name="severity">
                            <option value="Minor">Minor</option>
                            <option value="Moderate">Moderate</option>
                            <option value="Severe">Severe</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>

                    <div id="date_filter" class="filter-option" style="display: none;">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" name="date_from">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" name="date_to">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-print me-1"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const assessmentsData = <?php echo json_encode($assessments); ?>;
let currentViewingId = null;

function viewAssessment(assessmentId) {
    const assessment = assessmentsData.find(a => a.assessment_id == assessmentId);
    if (!assessment) return;
    
    currentViewingId = assessmentId;
    
    // Populate resident information
    document.getElementById('view_resident_name').textContent = assessment.resident_name || 'N/A';
    document.getElementById('view_resident_address').textContent = assessment.address || 'N/A';
    document.getElementById('view_resident_contact').textContent = assessment.contact_number || 'N/A';
    document.getElementById('view_resident_email').textContent = assessment.email || 'N/A';
    
    // Populate assessment information
    document.getElementById('view_assessment_date').textContent = formatDateDisplay(assessment.assessment_date);
    document.getElementById('view_disaster_type').innerHTML = '<span class="badge bg-secondary">' + assessment.disaster_type + '</span>';
    document.getElementById('view_location').textContent = assessment.location;
    document.getElementById('view_damage_type').textContent = assessment.damage_type;
    
    // Populate severity, cost, and status
    document.getElementById('view_severity').innerHTML = getSeverityBadgeHTML(assessment.severity);
    document.getElementById('view_estimated_cost').innerHTML = '<strong>₱' + Number(assessment.estimated_cost).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</strong>';
    document.getElementById('view_status').innerHTML = getStatusBadgeHTML(assessment.status);
    
    // Populate description
    const descDiv = document.getElementById('view_description');
    if (assessment.description && assessment.description.trim() !== '') {
        descDiv.textContent = assessment.description;
        descDiv.className = 'alert alert-light';
    } else {
        descDiv.textContent = 'No description provided';
        descDiv.className = 'alert alert-light text-muted fst-italic';
    }
    
    // Populate assessed by and created at
    document.getElementById('view_assessed_by').textContent = assessment.assessed_by_name || 'N/A';
    document.getElementById('view_created_at').textContent = formatDateTimeDisplay(assessment.created_at);
    
    // Show modal
    new bootstrap.Modal(document.getElementById('viewAssessmentModal')).show();
}

function editAssessment(assessmentId) {
    const assessment = assessmentsData.find(a => a.assessment_id == assessmentId);
    if (!assessment) return;
    
    document.getElementById('edit_assessment_id').value = assessment.assessment_id;
    document.getElementById('edit_disaster_type').value = assessment.disaster_type;
    document.getElementById('edit_assessment_date').value = assessment.assessment_date;
    document.getElementById('edit_location').value = assessment.location;
    document.getElementById('edit_damage_type').value = assessment.damage_type;
    document.getElementById('edit_severity').value = assessment.severity || 'Minor';
    document.getElementById('edit_estimated_cost').value = assessment.estimated_cost;
    document.getElementById('edit_status').value = assessment.status;
    document.getElementById('edit_description').value = assessment.description || '';
    
    <?php if ($user_role === 'Super Admin'): ?>
    if (document.getElementById('edit_assessed_by')) {
        document.getElementById('edit_assessed_by').value = assessment.assessed_by || '';
    }
    <?php endif; ?>
    
    new bootstrap.Modal(document.getElementById('editAssessmentModal')).show();
}

function deleteAssessment(assessmentId) {
    const assessment = assessmentsData.find(a => a.assessment_id == assessmentId);
    if (!assessment) return;
    
    // Populate delete confirmation modal
    document.getElementById('delete_assessment_id').value = assessmentId;
    document.getElementById('delete_resident_name').textContent = assessment.resident_name || 'N/A';
    document.getElementById('delete_location').textContent = assessment.location || 'N/A';
    document.getElementById('delete_disaster_type').textContent = assessment.disaster_type || 'N/A';
    document.getElementById('delete_estimated_cost').textContent = '₱' + Number(assessment.estimated_cost).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Show delete modal
    new bootstrap.Modal(document.getElementById('deleteAssessmentModal')).show();
}

function printAssessmentDirect(assessmentId) {
    window.open('print-assessment.php?id=' + assessmentId, '_blank');
}

function printFromView() {
    if (currentViewingId) {
        printAssessmentDirect(currentViewingId);
    }
}

function editFromView() {
    if (currentViewingId) {
        // Close view modal
        bootstrap.Modal.getInstance(document.getElementById('viewAssessmentModal')).hide();
        // Open edit modal
        setTimeout(() => editAssessment(currentViewingId), 300);
    }
}

function showPrintAllModal() {
    new bootstrap.Modal(document.getElementById('printAllModal')).show();
}

function formatDateDisplay(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

function formatDateTimeDisplay(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + 
           ' at ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function getSeverityBadgeHTML(severity) {
    const badges = {
        'Minor': '<span class="badge bg-info">Minor</span>',
        'Moderate': '<span class="badge bg-warning">Moderate</span>',
        'Severe': '<span class="badge bg-danger">Severe</span>',
        'Critical': '<span class="badge bg-dark">Critical</span>'
    };
    return badges[severity] || '<span class="badge bg-secondary">Not Set</span>';
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Pending': '<span class="badge bg-warning">Pending</span>',
        'In Progress': '<span class="badge bg-info">In Progress</span>',
        'Completed': '<span class="badge bg-success">Completed</span>',
        'Cancelled': '<span class="badge bg-secondary">Cancelled</span>'
    };
    return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
}

// Handle filter option display
document.querySelectorAll('input[name="filter_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.filter-option').forEach(option => {
            option.style.display = 'none';
        });
        
        if (this.value === 'status') {
            document.getElementById('status_filter').style.display = 'block';
        } else if (this.value === 'severity') {
            document.getElementById('severity_filter').style.display = 'block';
        } else if (this.value === 'date') {
            document.getElementById('date_filter').style.display = 'block';
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>