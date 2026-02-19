<?php
/**
 * Resident Disaster Dashboard
 * Path: barangaylink/disasters/resident/index.php
 */

require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and is a resident
if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if ($user_role !== 'Resident') {
    setMessage('Access Denied: This page is for residents only.', 'error');
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit();
}

// Get resident ID
$resident_id = $_SESSION['resident_id'] ?? null;
$current_user_id = getCurrentUserId();

// FETCH USER DATA FIRST before checking verification
$user_data = null;
if ($resident_id) {
    $stmt = $conn->prepare("
        SELECT r.is_verified, r.first_name, r.last_name, r.id_photo
        FROM tbl_residents r
        WHERE r.resident_id = ?
    ");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $stmt->close();
}

// NOW check verification status AFTER fetching user data
if (!$user_data || $user_data['is_verified'] != 1) {
    header("Location: not-verified.php");
    exit();
}

$page_title = 'Disaster Information';

// Initialize all variables BEFORE using them
$disaster_columns = [];
$active_disasters = [];
$my_damage_reports = [];
$my_relief = [];
$evacuation_centers = [];
$my_evacuee_status = null; // Initialize this FIRST before it's used in HTML

// Check which columns exist in tbl_disaster_incidents
if (tableExists($conn, 'tbl_disaster_incidents')) {
    $result = $conn->query("SHOW COLUMNS FROM tbl_disaster_incidents");
    while ($row = $result->fetch_assoc()) {
        $disaster_columns[] = $row['Field'];
    }
}

// Build appropriate SQL based on available columns
if (tableExists($conn, 'tbl_disaster_incidents')) {
    $name_col = in_array('disaster_name', $disaster_columns) ? 'disaster_name' : 
                (in_array('name', $disaster_columns) ? 'name' : 
                (in_array('incident_name', $disaster_columns) ? 'incident_name' : 'disaster_type'));
    
    $active_disasters = fetchAll($conn, "
        SELECT *, {$name_col} as disaster_name
        FROM tbl_disaster_incidents 
        WHERE status IN ('Active', 'Ongoing') 
        ORDER BY incident_date DESC 
        LIMIT 5
    ");
}

// Fetch my damage reports
if ($resident_id && tableExists($conn, 'tbl_damage_assessments')) {
    $my_damage_reports = fetchAll($conn, "
        SELECT da.* 
        FROM tbl_damage_assessments da
        WHERE da.resident_id = ?
        ORDER BY da.assessment_date DESC
    ", [$resident_id], 'i');
}

// Fetch my relief distributions with evacuation center info
if ($resident_id && tableExists($conn, 'tbl_relief_distributions')) {
    // Check if relief_distributions table columns exist
    $relief_columns = [];
    $result = $conn->query("SHOW COLUMNS FROM tbl_relief_distributions");
    while ($row = $result->fetch_assoc()) {
        $relief_columns[] = $row['Field'];
    }
    
    // Check if evacuation centers table exists
    $has_evacuation_centers = tableExists($conn, 'tbl_evacuation_centers');
    
    // Build query based on available columns and tables
    $relief_sql = "SELECT rd.*";
    
    // Add disaster name if disaster_id column exists
    if (in_array('disaster_id', $relief_columns) && !empty($disaster_columns)) {
        $name_col = in_array('disaster_name', $disaster_columns) ? 'disaster_name' : 
                    (in_array('name', $disaster_columns) ? 'name' : 'disaster_type');
        $relief_sql .= ", di.{$name_col} as disaster_name, di.disaster_type";
    }
    
    // Add evacuation center name if center_id column exists
    if (in_array('center_id', $relief_columns) && $has_evacuation_centers) {
        $relief_sql .= ", ec.center_name, ec.location as center_location";
    }
    
    $relief_sql .= " FROM tbl_relief_distributions rd";
    
    // Add JOINs based on available columns
    if (in_array('disaster_id', $relief_columns) && !empty($disaster_columns)) {
        $relief_sql .= " LEFT JOIN tbl_disaster_incidents di ON rd.disaster_id = di.incident_id";
    }
    
    if (in_array('center_id', $relief_columns) && $has_evacuation_centers) {
        $relief_sql .= " LEFT JOIN tbl_evacuation_centers ec ON rd.center_id = ec.center_id";
    }
    
    $relief_sql .= " WHERE rd.resident_id = ? ORDER BY rd.distribution_date DESC";
    
    $my_relief = fetchAll($conn, $relief_sql, [$resident_id], 'i');
}

// Fetch evacuation centers with real-time occupancy
if (tableExists($conn, 'tbl_evacuation_centers')) {
    $evacuation_centers = fetchAll($conn, "
        SELECT ec.*, 
        COALESCE(SUM(CASE WHEN e.status = 'Active' THEN e.family_members ELSE 0 END), 0) as current_evacuees,
        COUNT(CASE WHEN e.status = 'Active' THEN 1 END) as active_families
        FROM tbl_evacuation_centers ec
        LEFT JOIN tbl_evacuees e ON ec.center_id = e.center_id
        WHERE ec.status IN ('Active', 'Full')
        GROUP BY ec.center_id
        ORDER BY ec.center_name
    ");
}

// Check if I'm registered as evacuee - This must come BEFORE the HTML section uses it
if ($resident_id && tableExists($conn, 'tbl_evacuee_registrations')) {
    $evacuee_sql = "
        SELECT er.*, ec.center_name
        FROM tbl_evacuee_registrations er
        LEFT JOIN tbl_evacuation_centers ec ON er.center_id = ec.center_id
        WHERE er.resident_id = ? AND er.status = 'Active'
        ORDER BY er.registration_date DESC
        LIMIT 1
    ";
    
    // Add disaster info if available
    if (!empty($disaster_columns)) {
        $name_col = in_array('disaster_name', $disaster_columns) ? 'disaster_name' : 
                    (in_array('name', $disaster_columns) ? 'name' : 'disaster_type');
        
        $evacuee_sql = "
            SELECT er.*, ec.center_name, di.{$name_col} as disaster_name
            FROM tbl_evacuee_registrations er
            LEFT JOIN tbl_evacuation_centers ec ON er.center_id = ec.center_id
            LEFT JOIN tbl_disaster_incidents di ON er.disaster_id = di.incident_id
            WHERE er.resident_id = ? AND er.status = 'Active'
            ORDER BY er.registration_date DESC
            LIMIT 1
        ";
    }
    
    $my_evacuee_status = fetchOne($conn, $evacuee_sql, [$resident_id], 'i');
}

// NOW include the header - all variables are initialized
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="mb-4">
        <h1 class="h3 mb-1"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Disaster Information & Assistance</h1>
        <p class="text-muted">View active disasters, report damage, and check relief status</p>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Alert if evacuee -->
    <?php if ($my_evacuee_status): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Evacuee Status:</strong> You are currently registered at <strong><?php echo htmlspecialchars($my_evacuee_status['center_name']); ?></strong>
        <?php if (!empty($my_evacuee_status['disaster_name'])): ?>
            for <?php echo htmlspecialchars($my_evacuee_status['disaster_name']); ?>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="fas fa-house-damage text-danger fs-1 mb-3"></i>
                    <h6>Report Damage</h6>
                    <p class="text-muted small mb-3">Report property damage caused by disasters</p>
                    <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#reportDamageModal">
                        <i class="fas fa-plus me-1"></i>Report Now
                    </button>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="fas fa-hands-helping text-primary fs-1 mb-3"></i>
                    <h6>Relief Status</h6>
                    <p class="text-muted small mb-3">Check your relief assistance status</p>
                    <a href="#relief-section" class="btn btn-primary btn-sm">
                        <i class="fas fa-eye me-1"></i>View Status
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="fas fa-home text-success fs-1 mb-3"></i>
                    <h6>Evacuation Centers</h6>
                    <p class="text-muted small mb-3">View available evacuation centers</p>
                    <a href="#evacuation-section" class="btn btn-success btn-sm">
                        <i class="fas fa-map-marker-alt me-1"></i>View Centers
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="fas fa-phone-alt text-warning fs-1 mb-3"></i>
                    <h6>Emergency Hotline</h6>
                    <p class="text-muted small mb-3">Contact barangay for emergencies</p>
                    <a href="tel:<?php echo BARANGAY_CONTACT; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-phone me-1"></i><?php echo BARANGAY_CONTACT; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Active Disasters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Active Disasters</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($active_disasters)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-check-circle fs-1 mb-3"></i>
                            <p>No active disasters at the moment</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Disaster</th>
                                        <th>Type</th>
                                        <th>Severity</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_disasters as $disaster): ?>
                                    <tr>
                                        <td><?php echo formatDate($disaster['incident_date']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($disaster['disaster_name']); ?></strong></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($disaster['disaster_type']); ?></span></td>
                                        <td><?php echo getSeverityBadge($disaster['severity']); ?></td>
                                        <td><?php echo getStatusBadge($disaster['status']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Damage Reports -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list text-primary me-2"></i>My Damage Reports</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#reportDamageModal">
                        <i class="fas fa-plus me-1"></i>New Report
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($my_damage_reports)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-file-alt fs-1 mb-3"></i>
                            <p>No damage reports yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Disaster Type</th>
                                        <th>Damage Type</th>
                                        <th>Severity</th>
                                        <th>Est. Cost</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_damage_reports as $report): ?>
                                    <tr>
                                        <td><?php echo formatDate($report['assessment_date']); ?></td>
                                        <td><?php echo htmlspecialchars($report['disaster_type']); ?></td>
                                        <td><?php echo htmlspecialchars($report['damage_type']); ?></td>
                                        <td><?php echo getSeverityBadge($report['severity']); ?></td>
                                        <td>₱<?php echo number_format($report['estimated_cost'], 2); ?></td>
                                        <td><?php echo getStatusBadge($report['status']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Relief Distributions -->
            <div class="card border-0 shadow-sm" id="relief-section">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-hands-helping text-success me-2"></i>My Relief Assistance</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($my_relief)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-box-open fs-1 mb-3"></i>
                            <p>No relief assistance received yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <?php if (!empty($my_relief[0]['disaster_name'])): ?>
                                        <th>Disaster</th>
                                        <?php endif; ?>
                                        <?php if (!empty($my_relief[0]['center_name'])): ?>
                                        <th>Evacuation Center</th>
                                        <?php endif; ?>
                                        <th>Items Received</th>
                                        <th>Quantity</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_relief as $relief): ?>
                                    <tr>
                                        <td><?php echo formatDate($relief['distribution_date']); ?></td>
                                        <?php if (!empty($relief['disaster_name'])): ?>
                                        <td><?php echo htmlspecialchars($relief['disaster_name']); ?></td>
                                        <?php endif; ?>
                                        <?php if (!empty($relief['center_name'])): ?>
                                        <td>
                                            <strong><?php echo htmlspecialchars($relief['center_name']); ?></strong>
                                            <?php if (!empty($relief['center_location'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($relief['center_location']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($relief['items_distributed']); ?></td>
                                        <td><?php echo htmlspecialchars($relief['quantity']); ?></td>
                                        <td><?php echo getStatusBadge($relief['status']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-lg-4">
            <!-- Evacuation Centers -->
            <div class="card border-0 shadow-sm" id="evacuation-section">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-home text-info me-2"></i>Evacuation Centers</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($evacuation_centers)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-home fs-1 mb-3"></i>
                            <p>No active evacuation centers</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($evacuation_centers as $center): ?>
                        <?php
                        $occupancy = $center['capacity'] > 0 ? ($center['current_evacuees'] / $center['capacity']) * 100 : 0;
                        $available = $center['capacity'] - $center['current_evacuees'];
                        $occupancy_class = $occupancy >= 90 ? 'danger' : ($occupancy >= 70 ? 'warning' : 'success');
                        $is_full = $available <= 0 || $center['status'] === 'Full';
                        ?>
                        <div class="border rounded p-3 mb-3 <?php echo $is_full ? 'bg-light' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0"><?php echo htmlspecialchars($center['center_name']); ?></h6>
                                <?php if ($is_full): ?>
                                <span class="badge bg-danger">FULL</span>
                                <?php else: ?>
                                <span class="badge bg-success">Available</span>
                                <?php endif; ?>
                            </div>
                            
                            <p class="text-muted small mb-2">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <?php echo htmlspecialchars($center['location']); ?>
                            </p>
                            
                            <!-- Occupancy Progress Bar -->
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Occupancy</small>
                                    <small class="text-muted"><?php echo $center['current_evacuees']; ?>/<?php echo $center['capacity']; ?></small>
                                </div>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-<?php echo $occupancy_class; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo min($occupancy, 100); ?>%"
                                         aria-valuenow="<?php echo $center['current_evacuees']; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="<?php echo $center['capacity']; ?>">
                                        <?php echo round($occupancy, 1); ?>%
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-2 small mb-2">
                                <div class="col-6">
                                    <i class="fas fa-users me-1 text-primary"></i>
                                    <strong><?php echo $center['active_families']; ?></strong> families
                                </div>
                                <div class="col-6">
                                    <i class="fas fa-chair me-1 <?php echo $is_full ? 'text-danger' : 'text-success'; ?>"></i>
                                    <strong><?php echo $available; ?></strong> spaces left
                                </div>
                            </div>
                            
                            <?php if (!empty($center['contact_person'])): ?>
                            <p class="text-muted small mb-0 mt-2">
                                <i class="fas fa-phone me-1"></i>
                                <?php echo htmlspecialchars($center['contact_person']); ?>
                                <?php if (!empty($center['contact_number'])): ?>
                                    - <?php echo htmlspecialchars($center['contact_number']); ?>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Emergency Contacts -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-phone-alt text-danger me-2"></i>Emergency Contacts</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Barangay Hall</strong>
                        <p class="mb-0"><i class="fas fa-phone me-2"></i><?php echo BARANGAY_CONTACT; ?></p>
                    </div>
                    <div class="mb-3">
                        <strong>NDRRMC Hotline</strong>
                        <p class="mb-0"><i class="fas fa-phone me-2"></i>911</p>
                    </div>
                    <div class="mb-3">
                        <strong>Local Police</strong>
                        <p class="mb-0"><i class="fas fa-phone me-2"></i>117</p>
                    </div>
                    <div>
                        <strong>Fire Department</strong>
                        <p class="mb-0"><i class="fas fa-phone me-2"></i>160</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Report Damage Modal -->
<div class="modal fade" id="reportDamageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-house-damage me-2"></i>Report Property Damage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="submit-damage-report.php">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Please provide accurate information about the damage to your property.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Disaster Type *</label>
                            <select name="disaster_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Typhoon">Typhoon</option>
                                <option value="Flood">Flood</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Fire">Fire</option>
                                <option value="Landslide">Landslide</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Damage *</label>
                            <input type="date" name="assessment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Specific Location *</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g., Purok 1, Street name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Damage Type *</label>
                            <select name="damage_type" class="form-select" required>
                                <option value="">Select Damage Type</option>
                                <option value="Structural">Structural (Walls, Roof)</option>
                                <option value="Partial">Partial Damage</option>
                                <option value="Total">Total Loss</option>
                                <option value="Infrastructure">Infrastructure</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Severity *</label>
                            <select name="severity" class="form-select" required>
                                <option value="">Select Severity</option>
                                <option value="Low">Low (Minor repairs needed)</option>
                                <option value="Medium">Medium (Significant repairs)</option>
                                <option value="High">High (Major reconstruction)</option>
                                <option value="Critical">Critical (Uninhabitable)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Estimated Cost (₱)</label>
                            <input type="number" name="estimated_cost" class="form-control" step="0.01" min="0" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description of Damage *</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Please describe the damage in detail..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-paper-plane me-1"></i>Submit Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>