<?php
/**
 * Evacuee Registration with Filters
 * Path: barangaylink/disasters/evacuee-registration.php
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

$page_title = 'Evacuee Registration';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'register_evacuee':
                $center_id = sanitizeInput($_POST['center_id']);
                $resident_id = sanitizeInput($_POST['resident_id']);
                $family_head_name = sanitizeInput($_POST['family_head_name']);
                $family_members = sanitizeInput($_POST['family_members']);
                $check_in_date = sanitizeInput($_POST['check_in_date']);
                $contact_number = sanitizeInput($_POST['contact_number']);
                $special_needs = sanitizeInput($_POST['special_needs']);
                $notes = sanitizeInput($_POST['notes']);
                
                // Check if center exists and get its details
                $center = fetchOne($conn, "SELECT * FROM tbl_evacuation_centers WHERE center_id = ?", [$center_id], 'i');
                
                if (!$center) {
                    setMessage('Evacuation center not found', 'error');
                    break;
                }
                
                // Check if center status allows new evacuees (only if status column exists)
                if (isset($center['status']) && in_array($center['status'], ['Inactive', 'Full', 'Under Maintenance'])) {
                    $status_messages = [
                        'Inactive' => 'This evacuation center is currently inactive and cannot accept evacuees.',
                        'Full' => 'This evacuation center is full and cannot accept more evacuees.',
                        'Under Maintenance' => 'This evacuation center is under maintenance and cannot accept evacuees.'
                    ];
                    setMessage($status_messages[$center['status']], 'error');
                    break;
                }
                
                // Get current occupancy
                $current_occupancy_result = fetchOne($conn, 
                    "SELECT COALESCE(SUM(family_members), 0) as total_members 
                     FROM tbl_evacuees WHERE center_id = ? AND status = 'Active'", 
                    [$center_id], 'i');
                
                $current_occupancy = $current_occupancy_result['total_members'] ?? 0;
                
                // Check if adding this family would exceed capacity
                if (($current_occupancy + (int)$family_members) > $center['capacity']) {
                    $available = $center['capacity'] - $current_occupancy;
                    setMessage("Center capacity exceeded! Only {$available} space(s) available.", 'error');
                    break;
                }
                
                // Register evacuee
                $sql = "INSERT INTO tbl_evacuees 
                        (center_id, resident_id, family_head_name, family_members, check_in_date, 
                         contact_number, special_needs, notes, status, registered_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW())";
                
                if (executeQuery($conn, $sql, 
                    [$center_id, $resident_id, $family_head_name, $family_members, $check_in_date, 
                     $contact_number, $special_needs, $notes, getCurrentUserId()],
                    'iisissssi')) {
                    
                    // Update evacuation center occupancy
                    $new_occupancy = $current_occupancy + (int)$family_members;
                    
                    // Check if current_occupancy column exists
                    if (columnExists($conn, 'tbl_evacuation_centers', 'current_occupancy')) {
                        executeQuery($conn, 
                            "UPDATE tbl_evacuation_centers SET current_occupancy = ? WHERE center_id = ?",
                            [$new_occupancy, $center_id], 'ii');
                    }
                    
                    // Update status if full (only if status column exists)
                    if (columnExists($conn, 'tbl_evacuation_centers', 'status') && $new_occupancy >= $center['capacity']) {
                        executeQuery($conn, 
                            "UPDATE tbl_evacuation_centers SET status = 'Full' WHERE center_id = ?",
                            [$center_id], 'i');
                    }
                    
                    logActivity($conn, getCurrentUserId(), "Registered evacuee: $family_head_name at {$center['center_name']}");
                    setMessage('Evacuee registered successfully', 'success');
                } else {
                    setMessage('Failed to register evacuee', 'error');
                }
                break;
                
         // Replace your checkout_evacuee case with this SINGLE version (remove duplicates!)
case 'checkout_evacuee':
    $evacuee_id = sanitizeInput($_POST['evacuee_id']);
    
    // Get evacuee info before checkout
    $evacuee = fetchOne($conn, "SELECT * FROM tbl_evacuees WHERE evacuee_id = ?", [$evacuee_id], 'i');
    
    if (!$evacuee) {
        setMessage('Evacuee not found', 'error');
        break;
    }
    
    // Update checkout date and status - MAKE SURE TO USE STRING 'Inactive'
    $sql = "UPDATE tbl_evacuees SET check_out_date = CURDATE(), status = 'Inactive' WHERE evacuee_id = ?";
    
    if (executeQuery($conn, $sql, [$evacuee_id], 'i')) {
        // Update evacuation center occupancy
        $center = fetchOne($conn, "SELECT * FROM tbl_evacuation_centers WHERE center_id = ?", 
                          [$evacuee['center_id']], 'i');
        
        if ($center) {
            // Get current occupancy
            $current_occupancy = fetchOne($conn, 
                "SELECT COALESCE(SUM(family_members), 0) as total_members 
                 FROM tbl_evacuees WHERE center_id = ? AND status = 'Active'", 
                [$evacuee['center_id']], 'i');
            
            $new_occupancy = $current_occupancy['total_members'];
            
            // Update occupancy if column exists
            if (columnExists($conn, 'tbl_evacuation_centers', 'current_occupancy')) {
                executeQuery($conn, 
                    "UPDATE tbl_evacuation_centers SET current_occupancy = ? WHERE center_id = ?",
                    [$new_occupancy, $evacuee['center_id']], 'ii');
            }
            
            // Update status back to Active if no longer full
            if (columnExists($conn, 'tbl_evacuation_centers', 'status') && 
                isset($center['status']) && 
                $new_occupancy < $center['capacity'] && 
                $center['status'] === 'Full') {
                executeQuery($conn, 
                    "UPDATE tbl_evacuation_centers SET status = 'Active' WHERE center_id = ?",
                    [$evacuee['center_id']], 'i');
            }
        }
        
        logActivity($conn, getCurrentUserId(), "Checked out evacuee ID: $evacuee_id");
        setMessage('Evacuee checked out successfully', 'success');
    } else {
        setMessage('Failed to check out evacuee', 'error');
    }
    break;

$sql = "SELECT 
    e.evacuee_id,
    e.center_id,
    e.resident_id,
    e.family_head_name,
    e.family_members,
    e.check_in_date,
    e.check_out_date,  
    e.contact_number,
    e.special_needs,
    e.notes,
    e.status,
    u.username AS registered_by_name,
    CONCAT(r.first_name, ' ', r.last_name) AS full_name,
    r.address,
    r.gender,
    TIMESTAMPDIFF(YEAR, COALESCE(r.date_of_birth, r.birthdate), CURDATE()) AS age,
    c.center_name
FROM tbl_evacuees e
LEFT JOIN tbl_users u ON e.registered_by = u.user_id
LEFT JOIN tbl_residents r ON e.resident_id = r.resident_id
LEFT JOIN tbl_evacuation_centers c ON e.center_id = c.center_id
ORDER BY e.check_in_date DESC";

                
               $sql = "UPDATE tbl_evacuees SET check_out_date = CURDATE(), status = 'Inactive' WHERE evacuee_id = ?";
                executeQuery($conn, $sql, [$evacuee_id], 'i');
                
               if (executeQuery($conn, $sql, [$evacuee_id], 'i')) {
                    // Update evacuation center occupancy
                    $center = fetchOne($conn, "SELECT * FROM tbl_evacuation_centers WHERE center_id = ?", 
                                      [$evacuee['center_id']], 'i');
                    
                    if ($center) {
                        // Get current occupancy
                        $current_occupancy = fetchOne($conn, 
                            "SELECT COALESCE(SUM(family_members), 0) as total_members 
                             FROM tbl_evacuees WHERE center_id = ? AND status = 'Active'", 
                            [$evacuee['center_id']], 'i');
                        
                        $new_occupancy = $current_occupancy['total_members'];
                        
                        // Update occupancy if column exists
                        if (columnExists($conn, 'tbl_evacuation_centers', 'current_occupancy')) {
                            executeQuery($conn, 
                                "UPDATE tbl_evacuation_centers SET current_occupancy = ? WHERE center_id = ?",
                                [$new_occupancy, $evacuee['center_id']], 'ii');
                        }
                        
                        // Update status back to Active if no longer full (only if status column exists)
                        if (columnExists($conn, 'tbl_evacuation_centers', 'status') && 
                            isset($center['status']) && 
                            $new_occupancy < $center['capacity'] && 
                            $center['status'] === 'Full') {
                            executeQuery($conn, 
                                "UPDATE tbl_evacuation_centers SET status = 'Active' WHERE center_id = ?",
                                [$evacuee['center_id']], 'i');
                        }
                    }
                    
                    logActivity($conn, getCurrentUserId(), "Checked out evacuee ID: $evacuee_id");
                    setMessage('Evacuee checked out successfully', 'success');
                } else {
                    setMessage('Failed to check out evacuee', 'error');
                }
                break;
                
            case 'delete_evacuee':
                $evacuee_id = sanitizeInput($_POST['evacuee_id']);
                
                // Get evacuee info before deletion
                $evacuee = fetchOne($conn, "SELECT * FROM tbl_evacuees WHERE evacuee_id = ?", [$evacuee_id], 'i');
                
                if (!$evacuee) {
                    setMessage('Evacuee not found', 'error');
                    break;
                }
                
                $sql = "DELETE FROM tbl_evacuees WHERE evacuee_id = ?";
                
                if (executeQuery($conn, $sql, [$evacuee_id], 'i')) {
                    // Update occupancy if the evacuee was active
                    if ($evacuee['status'] === 'Active') {
                        $center = fetchOne($conn, "SELECT * FROM tbl_evacuation_centers WHERE center_id = ?", 
                                          [$evacuee['center_id']], 'i');
                        
                        if ($center) {
                            // Recalculate occupancy
                            $current_occupancy = fetchOne($conn, 
                                "SELECT COALESCE(SUM(family_members), 0) as total_members 
                                 FROM tbl_evacuees WHERE center_id = ? AND status = 'Active'", 
                                [$evacuee['center_id']], 'i');
                            
                            $new_occupancy = $current_occupancy['total_members'];
                            
                            if (columnExists($conn, 'tbl_evacuation_centers', 'current_occupancy')) {
                                executeQuery($conn, 
                                    "UPDATE tbl_evacuation_centers SET current_occupancy = ? WHERE center_id = ?",
                                    [$new_occupancy, $evacuee['center_id']], 'ii');
                            }
                            
                            // Update status if no longer full (only if status column exists)
                            if (columnExists($conn, 'tbl_evacuation_centers', 'status') && 
                                isset($center['status']) && 
                                $new_occupancy < $center['capacity'] && 
                                $center['status'] === 'Full') {
                                executeQuery($conn, 
                                    "UPDATE tbl_evacuation_centers SET status = 'Active' WHERE center_id = ?",
                                    [$evacuee['center_id']], 'i');
                            }
                        }
                    }
                    
                    logActivity($conn, getCurrentUserId(), "Deleted evacuee ID: $evacuee_id");
                    setMessage('Evacuee deleted successfully', 'success');
                } else {
                    setMessage('Failed to delete evacuee', 'error');
                }
                break;
        }
        header('Location: evacuee-registration.php');
        exit();
    }
}

$sql = "SELECT 
    e.evacuee_id,
    e.center_id,
    e.resident_id,
    e.family_head_name,
    e.family_members,
    e.check_in_date,
    e.check_out_date, 
    e.contact_number,
    e.special_needs,
    e.notes,
    e.status,
    u.username AS registered_by_name,
    CONCAT(r.first_name, ' ', r.last_name) AS full_name,
    r.address,
    r.gender,
    TIMESTAMPDIFF(YEAR, COALESCE(r.date_of_birth, r.birthdate), CURDATE()) AS age,
    c.center_name
FROM tbl_evacuees e
LEFT JOIN tbl_users u ON e.registered_by = u.user_id
LEFT JOIN tbl_residents r ON e.resident_id = r.resident_id
LEFT JOIN tbl_evacuation_centers c ON e.center_id = c.center_id
ORDER BY e.check_in_date DESC";

$evacuees = fetchAll($conn, $sql);

// Fetch all residents for dropdown
$residents = fetchAll($conn, "SELECT resident_id, CONCAT(first_name, ' ', last_name) as full_name, address FROM tbl_residents ORDER BY last_name, first_name");

// Fetch centers with real-time occupancy count
// Check if status column exists before filtering
$has_status_column = columnExists($conn, 'tbl_evacuation_centers', 'status');

$centers_sql = "SELECT ec.*, 
                COALESCE(SUM(CASE WHEN e.status = 'Active' THEN e.family_members ELSE 0 END), 0) as current_evacuees,
                COUNT(CASE WHEN e.status = 'Active' THEN 1 END) as evacuee_families
                FROM tbl_evacuation_centers ec
                LEFT JOIN tbl_evacuees e ON ec.center_id = e.center_id";

if ($has_status_column) {
    $centers_sql .= " WHERE ec.status = 'Active'";
}

$centers_sql .= " GROUP BY ec.center_id
                 ORDER BY ec.center_name";

$centers = fetchAll($conn, $centers_sql);

// Fetch all centers for filter dropdown
$all_centers = fetchAll($conn, "SELECT DISTINCT ec.center_id, ec.center_name 
                                FROM tbl_evacuation_centers ec
                                INNER JOIN tbl_evacuees e ON ec.center_id = e.center_id
                                ORDER BY ec.center_name");

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-user-plus text-warning me-2"></i>Evacuee Registration</h1>
            <p class="text-muted">Register and manage evacuees during disasters</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#registerModal">
            <i class="fas fa-plus me-2"></i>Register Evacuee
        </button>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Filter Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Evacuees</h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="resetFilters()">
                    <i class="fas fa-redo me-1"></i>Reset Filters
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <!-- Status Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted">Status</label>
                    <select class="form-select form-select-sm" id="filterStatus" onchange="applyFilters()">
                        <option value="">All Statuses</option>
                        <option value="Active">Active</option>
                        <option value="Checked Out">Checked Out</option>
                    </select>
                </div>

                <!-- Evacuation Center Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted">Evacuation Center</label>
                    <select class="form-select form-select-sm" id="filterCenter" onchange="applyFilters()">
                        <option value="">All Centers</option>
                        <?php foreach ($all_centers as $center): ?>
                        <option value="<?php echo $center['center_id']; ?>">
                            <?php echo htmlspecialchars($center['center_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted">Check-in From</label>
                    <input type="date" class="form-control form-control-sm" id="filterDateFrom" onchange="applyFilters()">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Check-in To</label>
                    <input type="date" class="form-control form-control-sm" id="filterDateTo" onchange="applyFilters()">
                </div>

                <!-- Family Size Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted">Family Size</label>
                    <select class="form-select form-select-sm" id="filterFamilySize" onchange="applyFilters()">
                        <option value="">All Sizes</option>
                        <option value="1-2">1-2 members</option>
                        <option value="3-4">3-4 members</option>
                        <option value="5-7">5-7 members</option>
                        <option value="8+">8+ members</option>
                    </select>
                </div>

                <!-- Special Needs Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted">Special Needs</label>
                    <select class="form-select form-select-sm" id="filterSpecialNeeds" onchange="applyFilters()">
                        <option value="">All</option>
                        <option value="yes">With Special Needs</option>
                        <option value="no">No Special Needs</option>
                    </select>
                </div>

                <!-- Search Filter -->
                <div class="col-md-6">
                    <label class="form-label small text-muted">Search Name/Address</label>
                    <input type="text" class="form-control form-control-sm" id="filterSearch" 
                           placeholder="Type to search..." onkeyup="applyFilters()">
                </div>
            </div>

            <!-- Filter Results Summary -->
            <div class="mt-3 pt-3 border-top">
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        <span id="filterResultsCount">Showing all evacuees</span>
                    </small>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-2" onclick="exportFilteredData()">
                            <i class="fas fa-download me-1"></i>Export Filtered
                        </button>
                        <button class="btn btn-sm btn-outline-success" onclick="printFilteredData()">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <?php
        $total_evacuees = count($evacuees);
        $active_count = count(array_filter($evacuees, fn($e) => $e['status'] === 'Active'));
      $returned_count = count(array_filter($evacuees, fn($e) => $e['status'] === 'Inactive'));
        $total_families = count(array_unique(array_column(array_filter($evacuees, fn($e) => $e['status'] === 'Active'), 'family_head_name')));
        ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Evacuees</p>
                            <h3 class="mb-0"><?php echo $total_evacuees; ?></h3>
                        </div>
                        <div class="fs-1 text-primary"><i class="fas fa-users"></i></div>
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
                        <div class="fs-1 text-success"><i class="fas fa-user-check"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Checked Out</p>
                            <h3 class="mb-0"><?php echo $returned_count; ?></h3>
                        </div>
                        <div class="fs-1 text-info"><i class="fas fa-sign-out-alt"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Active Families</p>
                            <h3 class="mb-0"><?php echo $total_families; ?></h3>
                        </div>
                        <div class="fs-1 text-warning"><i class="fas fa-home"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Evacuees Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">Registered Evacuees</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="evacueesTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Family Members</th>
                            <th>Evacuation Center</th>
                            <th>Check-in Date</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($evacuees as $evacuee): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($evacuee['full_name']); ?></strong><br>
                                <?php if (!empty($evacuee['address'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($evacuee['address']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $evacuee['family_members']; ?></td>
                            <td><?php echo htmlspecialchars($evacuee['center_name']); ?></td>
                            <td><?php echo formatDateTime($evacuee['check_in_date']); ?></td>
                            <td><?php echo htmlspecialchars($evacuee['contact_number'] ?? 'N/A'); ?></td>
                            <td><?php echo getStatusBadge($evacuee['status']); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-info" onclick="viewEvacuee(<?php echo $evacuee['evacuee_id']; ?>)" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($evacuee['status'] === 'Active'): ?>
                                    <button class="btn btn-success" onclick="showCheckoutModal(<?php echo $evacuee['evacuee_id']; ?>, '<?php echo htmlspecialchars($evacuee['full_name']); ?>')" title="Check Out">
                                        <i class="fas fa-sign-out-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-danger" onclick="showDeleteModal(<?php echo $evacuee['evacuee_id']; ?>, '<?php echo htmlspecialchars($evacuee['full_name']); ?>')" title="Delete">
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

<!-- Register Evacuee Modal -->
<div class="modal fade" id="registerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Register Evacuee</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="evacueeForm">
                <input type="hidden" name="action" value="register_evacuee">
                <div class="modal-body">
                    <?php if (empty($centers)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>No Centers Available</strong>
                        <p class="mb-0 mt-2">There are currently no evacuation centers available to register evacuees. Please add evacuation centers first.</p>
                    </div>
                    <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Resident *</label>
                            <select name="resident_id" class="form-select" required onchange="populateResidentInfo(this)">
                                <option value="">Select Resident</option>
                                <?php foreach ($residents as $resident): ?>
                                <option value="<?php echo $resident['resident_id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($resident['full_name']); ?>"
                                        data-address="<?php echo htmlspecialchars($resident['address']); ?>">
                                    <?php echo htmlspecialchars($resident['full_name'] . ' - ' . $resident['address']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Evacuation Center *</label>
                            <select name="center_id" class="form-select" required id="centerSelect" onchange="updateCenterInfo()">
                                <option value="">Select Center</option>
                                <?php foreach ($centers as $center): ?>
                                <?php
                                $available = $center['capacity'] - $center['current_evacuees'];
                                $is_full = $available <= 0;
                                ?>
                                <option value="<?php echo $center['center_id']; ?>" 
                                        data-capacity="<?php echo $center['capacity']; ?>"
                                        data-current="<?php echo $center['current_evacuees']; ?>"
                                        data-available="<?php echo $available; ?>"
                                        <?php echo $is_full ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($center['center_name']); ?> 
                                    (<?php echo $center['current_evacuees']; ?>/<?php echo $center['capacity']; ?>)
                                    <?php echo $is_full ? ' - FULL' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="centerCapacityInfo"></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Family Head Name *</label>
                            <input type="text" name="family_head_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Number of Family Members *</label>
                            <input type="number" name="family_members" class="form-control" min="1" required id="familyMembers" onchange="checkCapacity()">
                            <small class="text-danger" id="capacityWarning" style="display: none;"></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Check-in Date & Time *</label>
                            <input type="datetime-local" name="check_in_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" placeholder="09XX-XXX-XXXX">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Special Needs/Medical Conditions</label>
                            <textarea name="special_needs" class="form-control" rows="3" placeholder="List any medical conditions, disabilities, or special requirements..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional information..."></textarea>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if (!empty($centers)): ?>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save me-1"></i>Register Evacuee
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Evacuee Modal -->
<div class="modal fade" id="viewEvacueeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>Evacuee Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <!-- Personal Information -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user me-2 text-primary"></i>Personal Information</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
<label class="text-muted small mb-1">Full Name</label>
<p class="mb-0 fw-bold" id="viewEvacueeName"></p>
</div>
<div class="col-md-6">
<label class="text-muted small mb-1">Status</label>
<p class="mb-0" id="viewEvacueeStatus"></p>
</div>
<div class="col-md-12">
<label class="text-muted small mb-1">Address</label>
<p class="mb-0" id="viewEvacueeAddress"></p>
</div>
<div class="col-md-6">
<label class="text-muted small mb-1">Age</label>
<p class="mb-0" id="viewEvacueeAge"></p>
</div>
<div class="col-md-6">
<label class="text-muted small mb-1">Gender</label>
<p class="mb-0" id="viewEvacueeGender"></p>
</div>
<div class="col-md-6">
<label class="text-muted small mb-1">Contact Number</label>
<p class="mb-0" id="viewEvacueeContact"></p>
</div>
<div class="col-md-6">
<label class="text-muted small mb-1">Family Members</label>
<p class="mb-0" id="viewEvacueeFamily"></p>
</div>
</div>
</div>
                <!-- Evacuation Details -->
                <div class="col-12">
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-home me-2 text-success"></i>Evacuation Details</h6>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="text-muted small mb-1">Evacuation Center</label>
                            <p class="mb-0 fw-bold" id="viewEvacueeCenter"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Check-in Date & Time</label>
                            <p class="mb-0" id="viewEvacueeCheckin"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-m4:54 PMuted small mb-1">Check-out Date & Time</label>
<p class="mb-0" id="viewEvacueeCheckout"></p>
</div>
</div>
</div>
                <!-- Special Needs & Notes -->
                <div class="col-12">
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-notes-medical me-2 text-warning"></i>Special Needs & Notes</h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="text-muted small mb-1">Special Needs / Medical Conditions</label>
                            <div class="alert alert-light mb-0">
                                <p class="mb-0" id="viewEvacueeNeeds"></p>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small mb-1">Additional Notes</label>
                            <div class="alert alert-light mb-0">
                                <p class="mb-0" id="viewEvacueeNotes"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Registration Info -->
                <div class="col-12">
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2 text-secondary"></i>Registration Information</h6>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="text-muted small mb-1">Registered By</label>
                            <p class="mb-0" id="viewEvacueeRegisteredBy"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
    </div>
</div>
</div>
<!-- Checkout Confirmation Modal -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i>Check Out Evacuee</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-sign-out-alt text-success" style="font-size: 48px;"></i>
                </div>
                <p class="text-center mb-3">Are you sure you want to check out this evacuee?</p>
                <div class="alert alert-info">
                    <strong>Evacuee:</strong> <span id="checkoutEvacueeName"></span>
                </div>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    This will update their status to "Checked Out" and free up space in the evacuation center.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="checkoutForm" class="d-inline">
                    <input type="hidden" name="action" value="checkout_evacuee">
                    <input type="hidden" name="evacuee_id" id="checkoutEvacueeId">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Yes, Check Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete Evacuee</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-danger" style="font-size: 48px;"></i>
                </div>
                <p class="text-center mb-3"><strong>Warning:</strong> This action cannot be undone!</p>
                <div class="alert alert-danger">
                    <strong>Evacuee:</strong> <span id="deleteEvacueeName"></span>
                </div>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Are you sure you want to permanently delete this evacuee record?
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="deleteForm" class="d-inline">
                    <input type="hidden" name="action" value="delete_evacuee">
                    <input type="hidden" name="evacuee_id" id="deleteEvacueeId">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Yes, Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
const evacueesData = <?php echo json_encode($evacuees); ?>;
const centersData = <?php echo json_encode($centers); ?>;

// Filter functions
function applyFilters() {
    const status = document.getElementById('filterStatus').value;
    const center = document.getElementById('filterCenter').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const familySize = document.getElementById('filterFamilySize').value;
    const specialNeeds = document.getElementById('filterSpecialNeeds').value;
    const search = document.getElementById('filterSearch').value.toLowerCase();

    let filteredCount = 0;
    const table = document.getElementById('evacueesTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const evacueeId = parseInt(row.querySelector('.btn-info').getAttribute('onclick').match(/\d+/)[0]);
        const evacuee = evacueesData.find(e => e.evacuee_id == evacueeId);
        
        if (!evacuee) continue;

        let showRow = true;

        // Status filter - FIXED: Map "Checked Out" to "Inactive"
     if (status) {
    let statusToCheck = status;
    if (status === 'Checked Out') {
        statusToCheck = 'Inactive';  // Map the display name to DB name
    }
    
    if (evacuee.status !== statusToCheck) {
        showRow = false;
    }
}

        // Center filter
        if (center && evacuee.center_id != center) {
            showRow = false;
        }

        // Date range filter
        if (dateFrom) {
            const checkinDate = new Date(evacuee.check_in_date);
            const fromDate = new Date(dateFrom);
            if (checkinDate < fromDate) {
                showRow = false;
            }
        }

        if (dateTo) {
            const checkinDate = new Date(evacuee.check_in_date);
            const toDate = new Date(dateTo);
            toDate.setHours(23, 59, 59);
            if (checkinDate > toDate) {
                showRow = false;
            }
        }

        // Family size filter
        if (familySize) {
            const members = parseInt(evacuee.family_members);
            switch(familySize) {
                case '1-2':
                    if (members < 1 || members > 2) showRow = false;
                    break;
                case '3-4':
                    if (members < 3 || members > 4) showRow = false;
                    break;
                case '5-7':
                    if (members < 5 || members > 7) showRow = false;
                    break;
                case '8+':
                    if (members < 8) showRow = false;
                    break;
            }
        }

        // Special needs filter
        if (specialNeeds) {
            const hasSpecialNeeds = evacuee.special_needs && evacuee.special_needs.trim() !== '';
            if (specialNeeds === 'yes' && !hasSpecialNeeds) {
                showRow = false;
            } else if (specialNeeds === 'no' && hasSpecialNeeds) {
                showRow = false;
            }
        }

        // Search filter
        if (search) {
            const searchText = (
                evacuee.full_name + ' ' + 
                (evacuee.address || '') + ' ' + 
                (evacuee.contact_number || '')
            ).toLowerCase();
            
            if (!searchText.includes(search)) {
                showRow = false;
            }
        }

        // Show/hide row
        row.style.display = showRow ? '' : 'none';
        if (showRow) filteredCount++;
    }

    // Update filter results count
    const totalCount = evacueesData.length;
    const resultsText = filteredCount === totalCount 
        ? `Showing all ${totalCount} evacuees`
        : `Showing ${filteredCount} of ${totalCount} evacuees`;
    
    document.getElementById('filterResultsCount').textContent = resultsText;
}

function resetFilters() {
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterCenter').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterFamilySize').value = '';
    document.getElementById('filterSpecialNeeds').value = '';
    document.getElementById('filterSearch').value = '';
    applyFilters();
}

function exportFilteredData() {
    const table = document.getElementById('evacueesTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Name,Family Members,Evacuation Center,Check-in Date,Contact,Status\n";

    for (let i = 0; i < rows.length; i++) {
        if (rows[i].style.display !== 'none') {
            const evacueeId = parseInt(rows[i].querySelector('.btn-info').getAttribute('onclick').match(/\d+/)[0]);
            const evacuee = evacueesData.find(e => e.evacuee_id == evacueeId);
            
            if (evacuee) {
                csvContent += `"${evacuee.full_name}",`;
                csvContent += `"${evacuee.family_members}",`;
                csvContent += `"${evacuee.center_name}",`;
                csvContent += `"${evacuee.check_in_date}",`;
                csvContent += `"${evacuee.contact_number || 'N/A'}",`;
                csvContent += `"${evacuee.status}"\n`;
            }
        }
    }

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `evacuees_filtered_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function printFilteredData() {
    const table = document.getElementById('evacueesTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    let printContent = `
        <html>
        <head>
            <title>Evacuees Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { text-align: center; color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #4CAF50; color: white; }
                tr:nth-child(even) { background-color: #f2f2f2; }
                .header-info { margin-bottom: 20px; }
                .print-date { text-align: right; color: #666; font-size: 12px; }
            </style>
    </head>
    <body>
        <div class="header-info">
            <h1>Evacuees Report</h1>
            <div class="print-date">Generated: ${new Date().toLocaleString()}</div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Family Members</th>
                    <th>Evacuation Center</th>
                    <th>Check-in Date</th>
                    <th>Contact</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
`;

for (let i = 0; i < rows.length; i++) {
    if (rows[i].style.display !== 'none') {
        const evacueeId = parseInt(rows[i].querySelector('.btn-info').getAttribute('onclick').match(/\d+/)[0]);
        const evacuee = evacueesData.find(e => e.evacuee_id == evacueeId);
        
        if (evacuee) {
            printContent += `
                <tr>
                    <td>${evacuee.full_name}</td>
                    <td>${evacuee.family_members}</td>
                    <td>${evacuee.center_name}</td>
                    <td>${evacuee.check_in_date}</td>
                    <td>${evacuee.contact_number || 'N/A'}</td>
                    <td>${evacuee.status}</td>
                </tr>
            `;
        }
    }
}

printContent += `
            </tbody>
        </table>
    </body>
    </html>
`;

const printWindow = window.open('', '', 'height=600,width=800');
printWindow.document.write(printContent);
printWindow.document.close();
printWindow.print();
}
function populateResidentInfo(select) {
const option = select.options[select.selectedIndex];
if (option.value) {
const familyHeadInput = document.querySelector('input[name="family_head_name"]');
if (familyHeadInput) {
familyHeadInput.value = option.dataset.name;
}
}
}
function updateCenterInfo() {
const select = document.getElementById('centerSelect');
const option = select.options[select.selectedIndex];
const infoDiv = document.getElementById('centerCapacityInfo');
if (option.value) {
    const available = option.dataset.available;
    infoDiv.textContent = `Available spaces: ${available}`;
    infoDiv.className = parseInt(available) > 0 ? 'text-success' : 'text-danger';
} else {
    infoDiv.textContent = '';
}

checkCapacity();
}
function checkCapacity() {
const select = document.getElementById('centerSelect');
const familyInput = document.getElementById('familyMembers');
const warning = document.getElementById('capacityWarning');
const submitBtn = document.getElementById('submitBtn');
const option = select.options[select.selectedIndex];

if (option.value && familyInput.value) {
    const available = parseInt(option.dataset.available);
    const requested = parseInt(familyInput.value);
    
    if (requested > available) {
        warning.textContent = `Cannot accommodate ${requested} members. Only ${available} space(s) available.`;
        warning.style.display = 'block';
        submitBtn.disabled = true;
    } else {
        warning.style.display = 'none';
        submitBtn.disabled = false;
    }
} else {
    warning.style.display = 'none';
    submitBtn.disabled = false;
}
}
function viewEvacuee(id) {
    const evacuee = evacueesData.find(e => e.evacuee_id == id);
    if (!evacuee) return;
    
    document.getElementById('viewEvacueeName').textContent = evacuee.full_name;
    document.getElementById('viewEvacueeAddress').textContent = evacuee.address || 'N/A';
    document.getElementById('viewEvacueeAge').textContent = evacuee.age ? evacuee.age + ' years old' : 'N/A';
    document.getElementById('viewEvacueeGender').textContent = evacuee.gender || 'N/A';
    document.getElementById('viewEvacueeFamily').textContent = evacuee.family_members + ' members';
    document.getElementById('viewEvacueeCenter').textContent = evacuee.center_name;
    document.getElementById('viewEvacueeCheckin').textContent = evacuee.check_in_date;
    
    // FIXED: Properly handle checkout display for Inactive status
    if (evacuee.status === 'Inactive') {
        document.getElementById('viewEvacueeCheckout').textContent = evacuee.check_out_date || 'Checked Out';
    } else {
        document.getElementById('viewEvacueeCheckout').textContent = 'Still in center';
    }
    
    document.getElementById('viewEvacueeContact').textContent = evacuee.contact_number || 'N/A';
    document.getElementById('viewEvacueeNeeds').textContent = evacuee.special_needs || 'None specified';
    document.getElementById('viewEvacueeNotes').textContent = evacuee.notes || 'No additional notes';
    document.getElementById('viewEvacueeStatus').innerHTML = getStatusBadgeHTML(evacuee.status);
    document.getElementById('viewEvacueeRegisteredBy').textContent = evacuee.registered_by_name || 'N/A';

    const viewModal = new bootstrap.Modal(document.getElementById('viewEvacueeModal'));
    viewModal.show();
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Active': '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
        'Inactive': '<span class="badge bg-secondary"><i class="fas fa-sign-out-alt me-1"></i>Checked Out</span>',
        'Checked Out': '<span class="badge bg-secondary"><i class="fas fa-sign-out-alt me-1"></i>Checked Out</span>'
    };
    return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
}
function showCheckoutModal(id, name) {
document.getElementById('checkoutEvacueeId').value = id;
document.getElementById('checkoutEvacueeName').textContent = name;
const checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
checkoutModal.show();
}
function showDeleteModal(id, name) {
document.getElementById('deleteEvacueeId').value = id;
document.getElementById('deleteEvacueeName').textContent = name;
const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
deleteModal.show();
}
// Optional: Initialize DataTable if you have it included
$(document).ready(function() {
if ($.fn.DataTable) {
$('#evacueesTable').DataTable({
order: [[3, 'desc']],
pageLength: 25,
responsive: true
});
}
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>