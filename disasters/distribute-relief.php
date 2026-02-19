<?php
/**
 * Distribute Relief Goods
 * Path: barangaylink/disasters/distribute-relief.php
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

$page_title = 'Distribute Relief Goods';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'distribute_relief') {
        $resident_id = sanitizeInput($_POST['resident_id']);
        
        // Verify that the resident is actually registered as an evacuee
        $evacuee_check = fetchOne($conn, 
            "SELECT evacuee_id FROM tbl_evacuees WHERE resident_id = ? AND status = 'Active'",
            [$resident_id], 'i');
        
        if (!$evacuee_check) {
            setMessage('Error: This resident is not registered as an active evacuee. Only registered evacuees can receive relief goods.', 'error');
            header('Location: distribute-relief.php');
            exit();
        }
        
        // Handle disaster_id - allow NULL if empty or not selected
        $disaster_id = null;
        if (!empty($_POST['disaster_id']) && $_POST['disaster_id'] !== '' && $_POST['disaster_id'] !== '0') {
            $disaster_id = (int)$_POST['disaster_id'];
        }
        
        $relief_type = sanitizeInput($_POST['relief_type']);
        $distribution_date = sanitizeInput($_POST['distribution_date']);
        $items_distributed = sanitizeInput($_POST['items']);
        $quantity = sanitizeInput($_POST['quantity']);
        $notes = sanitizeInput($_POST['notes'] ?? '');
        $distributed_by = $_SESSION['user_id'];
        
        // Insert with proper NULL handling
        $sql = "INSERT INTO tbl_relief_distributions 
                (resident_id, disaster_id, relief_type, distribution_date, items_distributed, quantity, distributed_by, notes, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Distributed')";
        
        $params = [$resident_id, $disaster_id, $relief_type, $distribution_date, $items_distributed, $quantity, $distributed_by, $notes];
        $types = 'iissssss';
        
        if (executeQuery($conn, $sql, $params, $types)) {
            logActivity($conn, getCurrentUserId(), "Distributed relief goods to resident ID: $resident_id");
            setMessage('Relief goods distributed successfully!', 'success');
            header('Location: distribute-relief.php');
            exit();
        } else {
            setMessage('Failed to distribute relief goods.', 'error');
        }
    } elseif ($action === 'delete_distribution') {
        $distribution_id = (int)$_POST['distribution_id'];
        
        if (executeQuery($conn, "DELETE FROM tbl_relief_distributions WHERE distribution_id = ?", [$distribution_id], 'i')) {
            logActivity($conn, getCurrentUserId(), "Deleted relief distribution ID: $distribution_id");
            setMessage('Distribution record deleted successfully!', 'success');
        } else {
            setMessage('Failed to delete distribution record.', 'error');
        }
        header('Location: distribute-relief.php');
        exit();
    }
}

// Fetch all distributions
$sql = "SELECT rd.*, 
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.address,
        u.username as distributed_by_name,
        ec.center_name,
        di.disaster_name
        FROM tbl_relief_distributions rd
        LEFT JOIN tbl_residents r ON rd.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON rd.distributed_by = u.user_id
        LEFT JOIN tbl_evacuees e ON rd.resident_id = e.resident_id AND e.status = 'Active'
        LEFT JOIN tbl_evacuation_centers ec ON e.center_id = ec.center_id
        LEFT JOIN tbl_disaster_incidents di ON rd.disaster_id = di.incident_id
        ORDER BY rd.distribution_date DESC, rd.created_at DESC";

$distributions = fetchAll($conn, $sql);

// Fetch ONLY residents who are registered as ACTIVE evacuees
$residents = fetchAll($conn, 
    "SELECT DISTINCT r.resident_id, 
            CONCAT(r.first_name, ' ', r.last_name) as full_name, 
            r.address,
            ec.center_name,
            e.family_members
     FROM tbl_residents r
     INNER JOIN tbl_evacuees e ON r.resident_id = e.resident_id
     INNER JOIN tbl_evacuation_centers ec ON e.center_id = ec.center_id
     WHERE e.status = 'Active'
     ORDER BY r.last_name, r.first_name");

// Fetch active disasters
$disasters = fetchAll($conn, "SELECT incident_id, disaster_name, disaster_type, incident_date 
                               FROM tbl_disaster_incidents
                               WHERE status IN ('Active', 'Ongoing') 
                               ORDER BY incident_date DESC");

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-hands-helping text-success me-2"></i>Distribute Relief Goods</h1>
            <p class="text-muted">Manage and track relief goods distribution to registered evacuees</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#distributeModal">
            <i class="fas fa-plus me-2"></i>Distribute Relief
        </button>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <?php
        $total_distributions = count($distributions);
        $today_count = count(array_filter($distributions, fn($d) => date('Y-m-d', strtotime($d['distribution_date'])) === date('Y-m-d')));
        $total_quantity = array_sum(array_map(fn($d) => is_numeric($d['quantity']) ? (int)$d['quantity'] : 0, $distributions));
        $unique_recipients = count(array_unique(array_column($distributions, 'resident_id')));
        ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Distributions</p>
                            <h3 class="mb-0"><?php echo $total_distributions; ?></h3>
                        </div>
                        <div class="fs-1 text-success"><i class="fas fa-box"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Today's Distributions</p>
                            <h3 class="mb-0"><?php echo $today_count; ?></h3>
                        </div>
                        <div class="fs-1 text-primary"><i class="fas fa-calendar-day"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Quantity</p>
                            <h3 class="mb-0"><?php echo $total_quantity; ?></h3>
                        </div>
                        <div class="fs-1 text-info"><i class="fas fa-boxes"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Unique Recipients</p>
                            <h3 class="mb-0"><?php echo $unique_recipients; ?></h3>
                        </div>
                        <div class="fs-1 text-warning"><i class="fas fa-users"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters & Export</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" id="filterDateFrom" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" id="filterDateTo" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Relief Type</label>
                    <select id="filterReliefType" class="form-select">
                        <option value="">All Types</option>
                        <option value="Food Pack">Food Pack</option>
                        <option value="Emergency Kit">Emergency Kit</option>
                        <option value="Medicine">Medicine</option>
                        <option value="Clothing">Clothing</option>
                        <option value="Hygiene Kit">Hygiene Kit</option>
                        <option value="Water">Water</option>
                        <option value="Cash Assistance">Cash Assistance</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Evacuee</label>
                    <select id="filterEvacuee" class="form-select">
                        <option value="">All Evacuees</option>
                        <?php foreach ($residents as $resident): ?>
                        <option value="<?php echo htmlspecialchars($resident['full_name']); ?>">
                            <?php echo htmlspecialchars($resident['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-filter me-1"></i>Apply Filters
                    </button>
                    <button class="btn btn-secondary" onclick="clearFilters()">
                        <i class="fas fa-redo me-1"></i>Reset
                    </button>
                    <div class="btn-group float-end">
                        <button class="btn btn-success" onclick="printAllDistributions()">
                            <i class="fas fa-print me-1"></i>Print All
                        </button>
                        <button class="btn btn-info" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-1"></i>Export to Excel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Distributions Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Relief Distribution Records</h5>
            <span class="badge bg-primary" id="recordCount"><?php echo count($distributions); ?> Records</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="distributionsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Recipient</th>
                            <th>Address</th>
                            <th>Evacuation Center</th>
                            <th>Relief Type</th>
                            <th>Items</th>
                            <th>Quantity</th>
                            <th>Distributed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($distributions)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No distribution records found</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($distributions as $dist): ?>
                            <tr>
                                <td><?php echo formatDate($dist['distribution_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($dist['resident_name']); ?></strong></td>
                                <td><small><?php echo htmlspecialchars($dist['address']); ?></small></td>
                                <td><small class="text-info"><?php echo htmlspecialchars($dist['center_name'] ?? 'N/A'); ?></small></td>
                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($dist['relief_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($dist['items_distributed']); ?></td>
                                <td><strong><?php echo htmlspecialchars($dist['quantity']); ?></strong></td>
                                <td><small><?php echo htmlspecialchars($dist['distributed_by_name'] ?? 'N/A'); ?></small></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick='viewDistribution(<?php echo json_encode($dist); ?>)' title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick='printDistribution(<?php echo json_encode($dist); ?>)' title="Print Receipt">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $dist['distribution_id']; ?>, '<?php echo htmlspecialchars($dist['resident_name'], ENT_QUOTES); ?>')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

<!-- Distribute Modal -->
<div class="modal fade" id="distributeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-hands-helping me-2"></i>Distribute Relief Goods</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="distribute_relief">
                <div class="modal-body">
                    <?php if (empty($residents)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>No Active Evacuees Available</strong>
                        <p class="mb-0 mt-2">There are currently no registered evacuees to distribute relief goods to. Please register evacuees first in the Evacuee Registration page.</p>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Only active registered evacuees are shown in the recipient list.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Recipient (Active Evacuees Only) *</label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">Select Recipient</option>
                                <?php foreach ($residents as $resident): ?>
                                <option value="<?php echo $resident['resident_id']; ?>">
                                    <?php echo htmlspecialchars($resident['full_name']); ?> - 
                                    <?php echo htmlspecialchars($resident['center_name']); ?>
                                    (<?php echo $resident['family_members']; ?> members)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Shows only residents currently registered in evacuation centers</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Related Disaster (Optional)</label>
                            <select name="disaster_id" class="form-select">
                                <option value="">No specific disaster</option>
                                <?php foreach ($disasters as $disaster): ?>
                                <option value="<?php echo $disaster['incident_id']; ?>">
                                    <?php echo htmlspecialchars($disaster['disaster_name']) . ' (' . formatDate($disaster['incident_date']) . ')'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Distribution Date *</label>
                            <input type="date" name="distribution_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Relief Type *</label>
                            <select name="relief_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Food Pack">Food Pack</option>
                                <option value="Emergency Kit">Emergency Kit</option>
                                <option value="Medicine">Medicine</option>
                                <option value="Clothing">Clothing</option>
                                <option value="Hygiene Kit">Hygiene Kit</option>
                                <option value="Water">Water</option>
                                <option value="Cash Assistance">Cash Assistance</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Quantity *</label>
                            <input type="text" name="quantity" class="form-control" placeholder="e.g., 1 pack, 5 pieces, 2 boxes" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Items Description *</label>
                            <textarea name="items" class="form-control" rows="3" placeholder="List of items included (e.g., 5kg rice, 1 pack noodles, 2 cans sardines, bottled water)" required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes or special instructions..."></textarea>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if (!empty($residents)): ?>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Distribute
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Distribution Modal -->
<div class="modal fade" id="viewDistributionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Distribution Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <!-- Recipient Information -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user me-2 text-primary"></i>Recipient Information</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Recipient Name</label>
                                <p class="mb-0 fw-bold" id="viewRecipientName"></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Evacuation Center</label>
                                <p class="mb-0" id="viewCenterName"></p>
                            </div>
                            <div class="col-12">
                                <label class="text-muted small mb-1">Address</label>
                                <p class="mb-0" id="viewAddress"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Distribution Details -->
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-box me-2 text-success"></i>Distribution Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Distribution Date</label>
                                <p class="mb-0" id="viewDistDate"></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Relief Type</label>
                                <p class="mb-0" id="viewReliefType"></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Quantity</label>
                                <p class="mb-0 fw-bold" id="viewQuantity"></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small mb-1">Distributed By</label>
                                <p class="mb-0" id="viewDistributedBy"></p>
                            </div>
                            <div class="col-12" id="viewDisasterSection" style="display:none;">
                                <label class="text-muted small mb-1">Related Disaster</label>
                                <p class="mb-0" id="viewDisasterName"></p>
                            </div>
                            <div class="col-12">
                                <label class="text-muted small mb-1">Items Distributed</label>
                                <div class="alert alert-light mb-0">
                                    <p class="mb-0" id="viewItems"></p>
                                </div>
                            </div>
                            <div class="col-12" id="viewNotesSection" style="display:none;">
                                <label class="text-muted small mb-1">Notes</label>
                                <div class="alert alert-light mb-0">
                                    <p class="mb-0" id="viewNotes"></p>
                                </div>
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

<!-- Print Modal -->
<div class="modal fade" id="printModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-print me-2"></i>Print Distribution Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="printContent" class="p-4">
                    <!-- Header -->
                    <div class="text-center mb-4 border-bottom pb-3">
                        <h4 class="mb-1">BARANGAY RELIEF DISTRIBUTION</h4>
                        <h5 class="text-muted mb-0">Official Receipt</h5>
                        <p class="text-muted small mb-0 mt-2">Barangay Disaster Risk Reduction and Management Office</p>
                    </div>

                    <!-- Distribution ID and Date -->
                    <div class="row mb-4">
                        <div class="col-6">
                            <p class="mb-1"><strong>Distribution ID:</strong> <span id="printDistId"></span></p>
                        </div>
                        <div class="col-6 text-end">
                            <p class="mb-1"><strong>Date:</strong> <span id="printDate"></span></p>
                        </div>
                    </div>

                    <!-- Recipient Information -->
                    <div class="mb-4">
                        <h6 class="bg-light p-2 mb-3"><i class="fas fa-user me-2"></i>Recipient Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="150"><strong>Name:</strong></td>
                                <td id="printRecipientName"></td>
                            </tr>
                            <tr>
                                <td><strong>Address:</strong></td>
                                <td id="printAddress"></td>
                            </tr>
                            <tr>
                                <td><strong>Evacuation Center:</strong></td>
                                <td id="printCenterName"></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Distribution Details -->
                    <div class="mb-4">
                        <h6 class="bg-light p-2 mb-3"><i class="fas fa-box me-2"></i>Relief Goods Details</h6>
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Relief Type</th>
                                    <th>Quantity</th>
                                    <th>Items Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td id="printReliefType"></td>
                                    <td id="printQuantity"></td>
                                    <td id="printItems"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4" id="printNotesSection" style="display:none;">
                        <h6 class="bg-light p-2 mb-3"><i class="fas fa-sticky-note me-2"></i>Additional Notes</h6>
                        <p id="printNotes" class="ps-3"></p>
                    </div>

                    <!-- Footer/Signature -->
                    <div class="row mt-5 pt-4">
                        <div class="col-6">
                            <div class="text-center">
                                <p class="mb-0"><strong>Received by:</strong></p>
                                <div class="border-bottom border-dark mt-5 mx-5"></div>
                                <p class="mt-2 mb-0 small">Signature over Printed Name</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <p class="mb-0"><strong>Distributed by:</strong></p>
                                <div class="mt-4">
                                    <p class="mb-0 fw-bold" id="printDistributedBy"></p>
                                    <div class="border-bottom border-dark mx-5"></div>
                                    <p class="mt-2 mb-0 small">Authorized Personnel</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Note -->
                    <div class="text-center mt-4 pt-4 border-top">
                        <p class="text-muted small mb-0">This is an official document. Keep this receipt for your records.</p>
                        <p class="text-muted small mb-0">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-trash-alt text-danger" style="font-size: 48px;"></i>
                </div>
                <h6 class="text-center mb-3">Are you sure you want to delete this distribution record?</h6>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Distribution Details:</strong>
                    <p class="mb-1 mt-2"><strong>Recipient:</strong> <span id="deleteRecipientName"></span></p>
                    <p class="mb-0"><strong>Distribution ID:</strong> <span id="deleteDistId"></span></p>
                </div>
                <p class="text-muted small text-center mb-0">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    This action cannot be undone. All information related to this distribution will be permanently removed.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" onclick="deleteDistribution()">
                    <i class="fas fa-trash me-1"></i>Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Print All Modal -->
<div class="modal fade" id="printAllModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-print me-2"></i>Print Distribution Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="printAllContent" class="p-4">
                    <!-- Header -->
                    <div class="text-center mb-4 border-bottom pb-3">
                        <h3 class="mb-1">BARANGAY RELIEF DISTRIBUTION REPORT</h3>
                        <h5 class="text-muted mb-0">Disaster Risk Reduction and Management Office</h5>
                        <p class="text-muted small mb-0 mt-2">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="row mb-4">
                        <div class="col-3 text-center">
                            <div class="border p-3">
                                <h4 class="mb-0" id="printTotalDist"><?php echo $total_distributions; ?></h4>
                                <small class="text-muted">Total Distributions</small>
                            </div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="border p-3">
                                <h4 class="mb-0" id="printTotalQty"><?php echo $total_quantity; ?></h4>
                                <small class="text-muted">Total Quantity</small>
                            </div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="border p-3">
                                <h4 class="mb-0" id="printUniqueRecip"><?php echo $unique_recipients; ?></h4>
                                <small class="text-muted">Unique Recipients</small>
                            </div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="border p-3">
                                <h4 class="mb-0" id="printDateRange">-</h4>
                                <small class="text-muted">Date Range</small>
                            </div>
                        </div>
                    </div>

                    <!-- Distributions Table -->
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Recipient</th>
                                <th>Address</th>
                                <th>Center</th>
                                <th>Relief Type</th>
                                <th>Items</th>
                                <th>Qty</th>
                                <th>Distributed By</th>
                            </tr>
                        </thead>
                        <tbody id="printTableBody">
                            <!-- Will be populated by JavaScript -->
                        </tbody>
                    </table>

                    <!-- Footer -->
                    <div class="row mt-5 pt-4">
                        <div class="col-6">
                            <div class="text-center">
                                <p class="mb-0"><strong>Prepared by:</strong></p>
                                <div class="border-bottom border-dark mt-5 mx-5"></div>
                                <p class="mt-2 mb-0 small">Signature over Printed Name</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <p class="mb-0"><strong>Noted by:</strong></p>
                                <div class="border-bottom border-dark mt-5 mx-5"></div>
                                <p class="mt-2 mb-0 small">DRRM Officer</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <button type="button" class="btn btn-success" onclick="printReport()">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style>
@media print {
    body * {
        visibility: hidden;
    }
    #printContent, #printContent * {
        visibility: visible;
    }
    #printAllContent, #printAllContent * {
        visibility: visible;
    }
    #printContent, #printAllContent {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .modal-header, .modal-footer, .btn {
        display: none !important;
    }
    table {
        page-break-inside: auto;
    }
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>

<script>
const distributionsData = <?php echo json_encode($distributions); ?>;
let currentDeleteId = null;
let filteredData = [...distributionsData];
let dataTable = null;

// View Distribution - Fixed to work with object parameter
function viewDistribution(dist) {
    // Populate modal fields
    document.getElementById('viewRecipientName').textContent = dist.resident_name;
    document.getElementById('viewCenterName').textContent = dist.center_name || 'N/A';
    document.getElementById('viewAddress').textContent = dist.address;
    document.getElementById('viewDistDate').textContent = dist.distribution_date;
    document.getElementById('viewReliefType').innerHTML = '<span class="badge bg-primary">' + dist.relief_type + '</span>';
    document.getElementById('viewQuantity').textContent = dist.quantity;
    document.getElementById('viewDistributedBy').textContent = dist.distributed_by_name || 'N/A';
    document.getElementById('viewItems').textContent = dist.items_distributed;
    
    // Handle optional disaster name
    if (dist.disaster_name) {
        document.getElementById('viewDisasterSection').style.display = 'block';
        document.getElementById('viewDisasterName').textContent = dist.disaster_name;
    } else {
        document.getElementById('viewDisasterSection').style.display = 'none';
    }
    
    // Handle optional notes
    if (dist.notes && dist.notes.trim()) {
        document.getElementById('viewNotesSection').style.display = 'block';
        document.getElementById('viewNotes').textContent = dist.notes;
    } else {
        document.getElementById('viewNotesSection').style.display = 'none';
    }
    
    // Show modal
    const viewModal = new bootstrap.Modal(document.getElementById('viewDistributionModal'));
    viewModal.show();
}

// Print Distribution - Fixed to work with object parameter
function printDistribution(dist) {
    // Populate print modal
    document.getElementById('printDistId').textContent = '#' + dist.distribution_id;
    document.getElementById('printDate').textContent = dist.distribution_date;
    document.getElementById('printRecipientName').textContent = dist.resident_name;
    document.getElementById('printAddress').textContent = dist.address;
    document.getElementById('printCenterName').textContent = dist.center_name || 'N/A';
    document.getElementById('printReliefType').textContent = dist.relief_type;
    document.getElementById('printQuantity').textContent = dist.quantity;
    document.getElementById('printItems').textContent = dist.items_distributed;
    document.getElementById('printDistributedBy').textContent = dist.distributed_by_name || 'N/A';
    
    // Handle optional notes
    if (dist.notes && dist.notes.trim()) {
        document.getElementById('printNotesSection').style.display = 'block';
        document.getElementById('printNotes').textContent = dist.notes;
    } else {
        document.getElementById('printNotesSection').style.display = 'none';
    }
    
    // Show modal
    const printModal = new bootstrap.Modal(document.getElementById('printModal'));
    printModal.show();
}

function confirmDelete(id, recipientName) {
    currentDeleteId = id;
    document.getElementById('deleteRecipientName').textContent = recipientName;
    document.getElementById('deleteDistId').textContent = '#' + id;
    
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

function deleteDistribution() {
    if (currentDeleteId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_distribution">' +
                        '<input type="hidden" name="distribution_id" value="' + currentDeleteId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function applyFilters() {
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const reliefType = document.getElementById('filterReliefType').value;
    const evacuee = document.getElementById('filterEvacuee').value;

    filteredData = distributionsData.filter(dist => {
        if (dateFrom && dist.distribution_date < dateFrom) return false;
        if (dateTo && dist.distribution_date > dateTo) return false;
        if (reliefType && dist.relief_type !== reliefType) return false;
        if (evacuee && dist.resident_name !== evacuee) return false;
        return true;
    });

    if (dataTable) {
        dataTable.clear();
        filteredData.forEach(dist => {
            dataTable.row.add([
                dist.distribution_date,
                '<strong>' + dist.resident_name + '</strong>',
                '<small>' + dist.address + '</small>',
                '<small class="text-info">' + (dist.center_name || 'N/A') + '</small>',
                '<span class="badge bg-primary">' + dist.relief_type + '</span>',
                dist.items_distributed,
                '<strong>' + dist.quantity + '</strong>',
                '<small>' + (dist.distributed_by_name || 'N/A') + '</small>',
                `<button class="btn btn-sm btn-info" onclick='viewDistribution(${JSON.stringify(dist).replace(/'/g, "&apos;")})' title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-success" onclick='printDistribution(${JSON.stringify(dist).replace(/'/g, "&apos;")})' title="Print Receipt">
                    <i class="fas fa-print"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="confirmDelete(${dist.distribution_id}, '${dist.resident_name.replace(/'/g, "\\'")}')" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>`
            ]);
        });
        dataTable.draw();
    }

    document.getElementById('recordCount').textContent = filteredData.length + ' Records';
}

function clearFilters() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterReliefType').value = '';
    document.getElementById('filterEvacuee').value = '';
    
    filteredData = [...distributionsData];
    
    if (dataTable) {
        dataTable.clear();
        distributionsData.forEach(dist => {
            dataTable.row.add([
                dist.distribution_date,
                '<strong>' + dist.resident_name + '</strong>',
                '<small>' + dist.address + '</small>',
                '<small class="text-info">' + (dist.center_name || 'N/A') + '</small>',
                '<span class="badge bg-primary">' + dist.relief_type + '</span>',
                dist.items_distributed,
                '<strong>' + dist.quantity + '</strong>',
                '<small>' + (dist.distributed_by_name || 'N/A') + '</small>',
                `<button class="btn btn-sm btn-info" onclick='viewDistribution(${JSON.stringify(dist).replace(/'/g, "&apos;")})' title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-success" onclick='printDistribution(${JSON.stringify(dist).replace(/'/g, "&apos;")})' title="Print Receipt">
                    <i class="fas fa-print"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="confirmDelete(${dist.distribution_id}, '${dist.resident_name.replace(/'/g, "\\'")}')" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>`
            ]);
        });
        dataTable.draw();
    }
    
    document.getElementById('recordCount').textContent = distributionsData.length + ' Records';
}

function printAllDistributions() {
    const totalDist = filteredData.length;
    const totalQty = filteredData.reduce((sum, d) => sum + (parseInt(d.quantity) || 0), 0);
    const uniqueRecip = new Set(filteredData.map(d => d.resident_id)).size;
    
    let dateRange = '-';
    if (filteredData.length > 0) {
        const dates = filteredData.map(d => d.distribution_date).sort();
        dateRange = dates[0] === dates[dates.length - 1] ? dates[0] : dates[0] + ' to ' + dates[dates.length - 1];
    }
    
    document.getElementById('printTotalDist').textContent = totalDist;
    document.getElementById('printTotalQty').textContent = totalQty;
    document.getElementById('printUniqueRecip').textContent = uniqueRecip;
    document.getElementById('printDateRange').textContent = dateRange;
    
    const tbody = document.getElementById('printTableBody');
    tbody.innerHTML = '';
    
    filteredData.forEach((dist, index) => {
        const row = tbody.insertRow();
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${dist.distribution_date}</td>
            <td>${dist.resident_name}</td>
            <td>${dist.address}</td>
            <td>${dist.center_name || 'N/A'}</td>
            <td>${dist.relief_type}</td>
            <td>${dist.items_distributed}</td>
            <td>${dist.quantity}</td>
            <td>${dist.distributed_by_name || 'N/A'}</td>
        `;
    });
    
    const printAllModal = new bootstrap.Modal(document.getElementById('printAllModal'));
    printAllModal.show();
}

function printReport() {
    window.print();
}

function exportToExcel() {
    const exportData = filteredData.map((dist, index) => ({
        'No': index + 1,
        'Distribution ID': dist.distribution_id,
        'Distribution Date': dist.distribution_date,
        'Recipient Name': dist.resident_name,
        'Address': dist.address,
        'Evacuation Center': dist.center_name || 'N/A',
        'Relief Type': dist.relief_type,
        'Items Distributed': dist.items_distributed,
        'Quantity': dist.quantity,
        'Distributed By': dist.distributed_by_name || 'N/A',
        'Notes': dist.notes || '',
        'Status': dist.status || 'Distributed'
    }));

    if (exportData.length === 0) {
        alert('No data to export!');
        return;
    }

    const headers = Object.keys(exportData[0]);
    let csv = headers.join(',') + '\n';
    
    exportData.forEach(row => {
        const values = headers.map(header => {
            const value = row[header] || '';
            const escaped = String(value).replace(/"/g, '""');
            return /[",\n]/.test(escaped) ? `"${escaped}"` : escaped;
        });
        csv += values.join(',') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    const dateStr = new Date().toISOString().split('T')[0];
    link.setAttribute('href', url);
    link.setAttribute('download', `Relief_Distribution_Report_${dateStr}.csv`);
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Initialize DataTable
$(document).ready(function() {
    if ($.fn.DataTable) {
        dataTable = $('#distributionsTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>