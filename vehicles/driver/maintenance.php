<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'Maintenance Records';

// Check if driver has assigned vehicles
$check_vehicle = $conn->prepare("SELECT COUNT(*) as count FROM tbl_vehicles WHERE assigned_driver_id = ?");
$check_vehicle->bind_param("i", $current_user_id);
$check_vehicle->execute();
$result = $check_vehicle->get_result();
$vehicle_count = $result->fetch_assoc()['count'];
$check_vehicle->close();

$has_assigned_vehicle = $vehicle_count > 0;

$success_message = '';
$error_message = '';

// Get driver's vehicles
$my_vehicles = [];
if ($has_assigned_vehicle) {
    $vehicles_sql = "SELECT vehicle_id, plate_number, brand, model, vehicle_type
                     FROM tbl_vehicles 
                     WHERE assigned_driver_id = ?
                     ORDER BY plate_number";
    $stmt = $conn->prepare($vehicles_sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $my_vehicles[] = $row;
    }
    $stmt->close();
}

// Handle form submission - Report maintenance issue
if ($has_assigned_vehicle && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'report_maintenance') {
        $vehicle_id = intval($_POST['vehicle_id']);
        $maintenance_type = trim($_POST['maintenance_type']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        
        // Verify vehicle belongs to driver
        $check = $conn->prepare("SELECT vehicle_id FROM tbl_vehicles WHERE vehicle_id = ? AND assigned_driver_id = ?");
        $check->bind_param("ii", $vehicle_id, $current_user_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $log_desc = "Maintenance Report [$priority Priority] - $maintenance_type: $description";
            $stmt = $conn->prepare("INSERT INTO tbl_vehicle_logs (vehicle_id, driver_id, log_type, description) VALUES (?, ?, 'Maintenance', ?)");
            $stmt->bind_param("iis", $vehicle_id, $current_user_id, $log_desc);
            
            if ($stmt->execute()) {
                $success_message = "Maintenance report submitted successfully!";
            } else {
                $error_message = "Error submitting report: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Invalid vehicle selected";
        }
        $check->close();
        
        $_SESSION['temp_success'] = $success_message;
        $_SESSION['temp_error'] = $error_message;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get OFFICIAL maintenance records from tbl_maintenance (added by admin/staff)
$official_maintenance = [];
if ($has_assigned_vehicle) {
    $official_sql = "SELECT m.*, v.plate_number, v.brand, v.model, v.vehicle_type
                     FROM tbl_maintenance m
                     INNER JOIN tbl_vehicles v ON m.vehicle_id = v.vehicle_id
                     WHERE v.assigned_driver_id = ?";
    
    $params = [$current_user_id];
    $types = "i";
    
    if ($filter_status) {
        $official_sql .= " AND m.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    
    if ($filter_date_from) {
        $official_sql .= " AND m.maintenance_date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }
    
    if ($filter_date_to) {
        $official_sql .= " AND m.maintenance_date <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }
    
    $official_sql .= " ORDER BY m.maintenance_date DESC LIMIT 50";
    
    $stmt = $conn->prepare($official_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $official_maintenance[] = $row;
    }
    $stmt->close();
}

// Get driver's reported issues from tbl_vehicle_logs
$reported_issues = [];
if ($has_assigned_vehicle) {
    $logs_sql = "SELECT l.*, v.plate_number, v.brand, v.vehicle_type
                 FROM tbl_vehicle_logs l
                 INNER JOIN tbl_vehicles v ON l.vehicle_id = v.vehicle_id
                 WHERE v.assigned_driver_id = ? AND l.log_type = 'Maintenance'
                 ORDER BY l.log_date DESC
                 LIMIT 50";
    $stmt = $conn->prepare($logs_sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reported_issues[] = $row;
    }
    $stmt->close();
}

// Calculate statistics
$total_maintenance = count($official_maintenance);
$completed = count(array_filter($official_maintenance, fn($m) => $m['status'] === 'Completed'));
$pending = count(array_filter($official_maintenance, fn($m) => $m['status'] === 'Pending'));
$scheduled = count(array_filter($official_maintenance, fn($m) => $m['status'] === 'Scheduled'));

include '../../includes/header.php';
?>

<style>
.stat-card {
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}
</style>

<div class="container-fluid py-4">
    <?php if (!$has_assigned_vehicle): ?>
    <!-- No Vehicle Assigned -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-wrench me-2"></i>Maintenance Records</h2>
            <p class="text-muted">View maintenance history and report issues</p>
        </div>
    </div>

    <div class="alert alert-warning">
        <div class="d-flex align-items-start gap-3">
            <i class="fas fa-exclamation-triangle" style="font-size: 2.5rem;"></i>
            <div>
                <h4 class="mb-2">No Vehicle Assigned</h4>
                <p class="mb-2">You currently don't have any vehicle assigned to you. Maintenance reporting is not available until a vehicle is assigned by the administrator.</p>
                <hr class="my-3">
                <p class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Please contact the administrator to request a vehicle assignment.
                </p>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <div style="width: 100px; height: 100px; background: #6c757d; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: white; font-size: 3rem; margin-bottom: 2rem;">
                <i class="fas fa-tools"></i>
            </div>
            <h5 class="mb-3">Waiting for Vehicle Assignment</h5>
            <p class="text-muted">Once a vehicle is assigned to you, you'll be able to view maintenance records and report issues.</p>
        </div>
    </div>

    <?php else: ?>
    
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-wrench me-2"></i>Maintenance Records</h2>
                    <p class="text-muted">View maintenance history and report issues</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportMaintenanceModal">
                    <i class="fas fa-plus me-2"></i>Report Issue
                </button>
            </div>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?php echo $total_maintenance; ?></h3>
                        <p class="text-muted mb-0">Total Services</p>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?php echo $completed; ?></h3>
                        <p class="text-muted mb-0">Completed</p>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?php echo $pending; ?></h3>
                        <p class="text-muted mb-0">Pending</p>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0"><?php echo $scheduled; ?></h3>
                        <p class="text-muted mb-0">Scheduled</p>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#official-maintenance" type="button">
                <i class="fas fa-clipboard-check me-2"></i>Official Maintenance (<?php echo count($official_maintenance); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#reported-issues" type="button">
                <i class="fas fa-flag me-2"></i>My Reported Issues (<?php echo count($reported_issues); ?>)
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Official Maintenance Tab -->
        <div class="tab-pane fade show active" id="official-maintenance">
            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filters</h5>
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Scheduled" <?php echo $filter_status === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $filter_date_from; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $filter_date_to; ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Apply Filters
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Official Maintenance Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-list me-2"></i>Maintenance Records</h5>
                    <?php if (empty($official_maintenance)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No maintenance records yet</h5>
                            <p class="text-muted">Official maintenance performed by admin/staff will appear here</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Vehicle</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Performed By</th>
                                        <th>Cost</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($official_maintenance as $record): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($record['maintenance_date'])); ?></strong>
                                            <?php if (!empty($record['next_maintenance_date'])): ?>
                                                <br><small class="text-muted">
                                                    <i class="fas fa-calendar-plus"></i> Next: <?php echo date('M d, Y', strtotime($record['next_maintenance_date'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($record['plate_number']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($record['brand'] . ' ' . $record['model']); ?></small>
                                        </td>
                                        <td>
                                            <i class="fas fa-wrench text-primary me-1"></i>
                                            <?php echo htmlspecialchars($record['maintenance_type']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['description']); ?></td>
                                        <td><?php echo htmlspecialchars($record['performed_by']); ?></td>
                                        <td>
                                            <strong>₱<?php echo number_format($record['cost'], 2); ?></strong>
                                            <?php if (!empty($record['odometer_reading'])): ?>
                                                <br><small class="text-muted">
                                                    <i class="fas fa-tachometer-alt"></i> <?php echo number_format($record['odometer_reading'], 1); ?> km
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = 'secondary';
                                            if ($record['status'] === 'Completed') $badge_class = 'success';
                                            elseif ($record['status'] === 'Pending') $badge_class = 'warning';
                                            elseif ($record['status'] === 'Scheduled') $badge_class = 'info';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($record['status']); ?>
                                            </span>
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

        <!-- Reported Issues Tab -->
        <div class="tab-pane fade" id="reported-issues">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-list me-2"></i>My Reported Issues</h5>
                    <?php if (empty($reported_issues)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-flag fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No reported issues yet</h5>
                            <p class="text-muted">Click "Report Issue" to submit maintenance concerns</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Reported On</th>
                                        <th>Vehicle</th>
                                        <th>Priority</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reported_issues as $log): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($log['log_date'])); ?></strong><br>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($log['log_date'])); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($log['plate_number']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($log['vehicle_type']); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = 'info';
                                            $priority_text = 'Low Priority';
                                            if (stripos($log['description'], 'High Priority') !== false) {
                                                $badge_class = 'danger';
                                                $priority_text = 'High Priority';
                                            } elseif (stripos($log['description'], 'Medium Priority') !== false) {
                                                $badge_class = 'warning';
                                                $priority_text = 'Medium Priority';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $priority_text; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($has_assigned_vehicle): ?>
<!-- Report Maintenance Modal -->
<div class="modal fade" id="reportMaintenanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Report Maintenance Issue</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="report_maintenance">
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-car me-2"></i>Vehicle *</label>
                        <select name="vehicle_id" class="form-select" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($my_vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                    <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-tools me-2"></i>Maintenance Type *</label>
                        <select name="maintenance_type" class="form-select" required>
                            <option value="">Select Type</option>
                            <option value="Oil Change">🛢️ Oil Change</option>
                            <option value="Tire Replacement">🛞 Tire Replacement</option>
                            <option value="Brake Service">🛑 Brake Service</option>
                            <option value="Engine Problem">⚙️ Engine Problem</option>
                            <option value="Electrical Issue">⚡ Electrical Issue</option>
                            <option value="Air Conditioning">❄️ Air Conditioning</option>
                            <option value="Battery">🔋 Battery</option>
                            <option value="Transmission">🔧 Transmission</option>
                            <option value="Other">📋 Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-flag me-2"></i>Priority Level *</label>
                        <select name="priority" class="form-select" required>
                            <option value="Low">🟢 Low - Can wait</option>
                            <option value="Medium">🟡 Medium - Soon</option>
                            <option value="High">🔴 High - Urgent</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-file-alt me-2"></i>Description *</label>
                        <textarea name="description" class="form-control" rows="5" 
                                  placeholder="Please describe the issue in detail. Include:&#10;• What happened?&#10;• When did you notice it?&#10;• Any unusual sounds or behaviors?&#10;• Current vehicle condition" required></textarea>
                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-info-circle"></i> The more details you provide, the faster we can address the issue
                        </small>
                    </div>

                    <div class="alert alert-warning">
                        <strong><i class="fas fa-bell me-2"></i>Important Notice</strong>
                        <ul class="mb-0 mt-2">
                            <li>This report will be sent to the vehicle administrator immediately</li>
                            <li>You will receive a notification when action is taken</li>
                            <li>For urgent safety issues, contact admin directly</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Submit Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>