<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'My Vehicle Details';

// Get vehicle ID
$vehicle_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$vehicle_id) {
    $_SESSION['error_message'] = 'Invalid vehicle ID';
    header('Location: index.php');
    exit();
}

// Fetch vehicle details - make sure it belongs to this driver
$sql = "SELECT v.*, 
        CONCAT(r.first_name, ' ', r.last_name) as driver_name,
        r.contact_number as driver_contact
        FROM tbl_vehicles v
        LEFT JOIN tbl_users u ON v.assigned_driver_id = u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE v.vehicle_id = ? AND v.assigned_driver_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $vehicle_id, $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = 'Vehicle not found or access denied';
    header('Location: index.php');
    exit();
}

$vehicle = $result->fetch_assoc();
$stmt->close();

// Get vehicle logs (replacing maintenance/fuel/trip tables)
$logs_sql = "SELECT * FROM tbl_vehicle_logs 
              WHERE vehicle_id = ? 
              ORDER BY log_date DESC 
              LIMIT 20";
$logs_stmt = $conn->prepare($logs_sql);
$logs_stmt->bind_param("i", $vehicle_id);
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();
$vehicle_logs = [];
if ($logs_result && $logs_result->num_rows > 0) {
    while ($row = $logs_result->fetch_assoc()) {
        $vehicle_logs[] = $row;
    }
}
$logs_stmt->close();

// Separate logs by type
$maintenance_logs = array_filter($vehicle_logs, fn($log) => stripos($log['log_type'], 'maintenance') !== false);
$fuel_logs = array_filter($vehicle_logs, fn($log) => stripos($log['log_type'], 'fuel') !== false);
$trip_logs = array_filter($vehicle_logs, fn($log) => stripos($log['log_type'], 'trip') !== false || stripos($log['log_type'], 'travel') !== false);
$other_logs = array_filter($vehicle_logs, fn($log) => 
    stripos($log['log_type'], 'maintenance') === false && 
    stripos($log['log_type'], 'fuel') === false && 
    stripos($log['log_type'], 'trip') === false && 
    stripos($log['log_type'], 'travel') === false
);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <a href="index.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
            <h2 class="mb-0">
                <i class="fas fa-car me-2"></i>Vehicle Details
            </h2>
            <p class="text-muted">View your assigned vehicle information</p>
        </div>
    </div>

    <div class="row">
        <!-- Vehicle Information -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>Vehicle Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <i class="fas <?php 
                            echo $vehicle['vehicle_type'] === 'Car' ? 'fa-car' : 
                                ($vehicle['vehicle_type'] === 'Motorcycle' ? 'fa-motorcycle' : 
                                ($vehicle['vehicle_type'] === 'Truck' ? 'fa-truck' : 'fa-bus'));
                        ?> fa-5x text-primary"></i>
                        <h3 class="mt-3 mb-1"><?php echo htmlspecialchars($vehicle['plate_number']); ?></h3>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($vehicle['vehicle_type']); ?></p>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="text-muted small">Brand & Model</label>
                        <p class="mb-0 fw-bold">
                            <?php echo htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']); ?>
                        </p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Capacity</label>
                        <p class="mb-0"><?php echo htmlspecialchars($vehicle['capacity']); ?> seats</p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Status</label>
                        <p class="mb-0">
                            <?php
                            $status_badges = [
                                'Active' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
                                'Inactive' => '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Inactive</span>',
                                'Maintenance' => '<span class="badge bg-warning text-dark"><i class="fas fa-wrench me-1"></i>Maintenance</span>'
                            ];
                            echo $status_badges[$vehicle['status']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($vehicle['status']) . '</span>';
                            ?>
                        </p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Assigned To</label>
                        <p class="mb-0">
                            <i class="fas fa-user me-1"></i>
                            <?php echo htmlspecialchars($vehicle['driver_name'] ?: 'Not assigned'); ?>
                        </p>
                    </div>

                    <?php if ($vehicle['driver_contact']): ?>
                    <div class="mb-0">
                        <label class="text-muted small">Contact</label>
                        <p class="mb-0">
                            <i class="fas fa-phone me-1"></i>
                            <?php echo htmlspecialchars($vehicle['driver_contact']); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tabs Content -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#all-logs" role="tab">
                                <i class="fas fa-list me-1"></i>All Activity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#maintenance" role="tab">
                                <i class="fas fa-wrench me-1"></i>Maintenance
                                <span class="badge bg-secondary ms-1"><?php echo count($maintenance_logs); ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#trips" role="tab">
                                <i class="fas fa-route me-1"></i>Trips
                                <span class="badge bg-secondary ms-1"><?php echo count($trip_logs); ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <!-- All Logs Tab -->
                        <div class="tab-pane fade show active" id="all-logs" role="tabpanel">
                            <?php if (empty($vehicle_logs)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-list fa-3x mb-3 d-block"></i>
                                    <p class="mb-0">No activity logs yet</p>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($vehicle_logs as $log): ?>
                                    <div class="timeline-item mb-3">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0">
                                                <div class="timeline-icon bg-primary text-white">
                                                    <i class="fas fa-circle"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1">
                                                            <span class="badge bg-info"><?php echo htmlspecialchars($log['log_type']); ?></span>
                                                        </h6>
                                                        <p class="mb-1"><?php echo htmlspecialchars($log['description']); ?></p>
                                                        <small class="text-muted">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?php echo date('M d, Y h:i A', strtotime($log['log_date'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <hr class="my-2">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Maintenance Tab -->
                        <div class="tab-pane fade" id="maintenance" role="tabpanel">
                            <?php if (empty($maintenance_logs)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-wrench fa-3x mb-3 d-block"></i>
                                    <p class="mb-0">No maintenance records</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($maintenance_logs as $log): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($log['log_type']); ?></span>
                                                </h6>
                                                <p class="mb-1"><?php echo htmlspecialchars($log['description']); ?></p>
                                                <small class="text-muted">
                                                    <?php echo date('F d, Y', strtotime($log['log_date'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Trip Logs Tab -->
                        <div class="tab-pane fade" id="trips" role="tabpanel">
                            <?php if (empty($trip_logs)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-route fa-3x mb-3 d-block"></i>
                                    <p class="mb-0">No trip logs</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($trip_logs as $log): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <span class="badge bg-success"><?php echo htmlspecialchars($log['log_type']); ?></span>
                                                </h6>
                                                <p class="mb-1"><?php echo htmlspecialchars($log['description']); ?></p>
                                                <small class="text-muted">
                                                    <?php echo date('F d, Y g:i A', strtotime($log['log_date'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    font-weight: 600;
}

.nav-tabs .nav-link:hover {
    color: #0d6efd;
}

.timeline-item {
    position: relative;
}

.timeline-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6rem;
}

.list-group-item {
    border-left: none;
    border-right: none;
}

.list-group-item:first-child {
    border-top: none;
}
</style>

<?php include '../../includes/footer.php'; ?>