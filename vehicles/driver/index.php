<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

// CRITICAL: Use requireRole from session.php which now includes account status check
requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'Driver Dashboard';

// Helper function to check if table exists
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

// Get driver's assigned vehicles
$vehicles_sql = "SELECT v.*, 
                 CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, '')) as driver_name,
                 r.contact_number as driver_contact
                 FROM tbl_vehicles v
                 LEFT JOIN tbl_users u ON v.assigned_driver_id = u.user_id
                 LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                 WHERE v.assigned_driver_id = ?
                 ORDER BY v.plate_number";

$stmt = $conn->prepare($vehicles_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
$my_vehicles = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $my_vehicles[] = $row;
    }
}
$stmt->close();

// Get recent trip logs for this driver (if table exists)
$recent_trips = [];
if (tableExists($conn, 'tbl_vehicle_logs')) {
    $trips_sql = "SELECT t.*, v.plate_number, v.vehicle_type
                  FROM tbl_vehicle_logs t
                  INNER JOIN tbl_vehicles v ON t.vehicle_id = v.vehicle_id
                  WHERE v.assigned_driver_id = ?
                  ORDER BY t.log_date DESC, t.created_at DESC
                  LIMIT 10";

    $trips_stmt = $conn->prepare($trips_sql);
    $trips_stmt->bind_param("i", $current_user_id);
    $trips_stmt->execute();
    $trips_result = $trips_stmt->get_result();
    if ($trips_result && $trips_result->num_rows > 0) {
        while ($row = $trips_result->fetch_assoc()) {
            $recent_trips[] = $row;
        }
    }
    $trips_stmt->close();
}

// Get upcoming maintenance (if table exists)
$upcoming_maintenance = [];
if (tableExists($conn, 'tbl_maintenance_records')) {
    $maintenance_sql = "SELECT m.*, v.plate_number, v.vehicle_type
                        FROM tbl_maintenance_records m
                        INNER JOIN tbl_vehicles v ON m.vehicle_id = v.vehicle_id
                        WHERE v.assigned_driver_id = ? 
                        AND m.next_maintenance_date IS NOT NULL
                        AND m.next_maintenance_date >= CURDATE()
                        ORDER BY m.next_maintenance_date ASC
                        LIMIT 5";

    $maint_stmt = $conn->prepare($maintenance_sql);
    $maint_stmt->bind_param("i", $current_user_id);
    $maint_stmt->execute();
    $maint_result = $maint_stmt->get_result();
    if ($maint_result && $maint_result->num_rows > 0) {
        while ($row = $maint_result->fetch_assoc()) {
            $upcoming_maintenance[] = $row;
        }
    }
    $maint_stmt->close();
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Welcome Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-0">
                <i class="fas fa-id-card me-2"></i>Driver Dashboard
            </h2>
            <p class="text-muted">Welcome back! Here's your vehicle information</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">My Vehicles</p>
                            <h3 class="mb-0"><?php echo count($my_vehicles); ?></h3>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-car fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Recent Trips</p>
                            <h3 class="mb-0"><?php echo count($recent_trips); ?></h3>
                        </div>
                        <div class="text-info">
                            <i class="fas fa-route fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Active Vehicles</p>
                            <h3 class="mb-0">
                                <?php echo count(array_filter($my_vehicles, function($v) { return $v['status'] === 'Active'; })); ?>
                            </h3>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Maintenance Due</p>
                            <h3 class="mb-0"><?php echo count($upcoming_maintenance); ?></h3>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-wrench fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- My Assigned Vehicles -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-car me-2"></i>My Assigned Vehicles
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($my_vehicles)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-car fa-4x text-muted mb-3"></i>
                            <p class="text-muted">No vehicles assigned to you yet</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($my_vehicles as $vehicle): ?>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6 class="mb-1">
                                            <i class="fas <?php 
                                                echo $vehicle['vehicle_type'] === 'Car' ? 'fa-car' : 
                                                    ($vehicle['vehicle_type'] === 'Motorcycle' ? 'fa-motorcycle' : 
                                                    ($vehicle['vehicle_type'] === 'Truck' ? 'fa-truck' : 'fa-bus'));
                                            ?> me-2"></i>
                                            <strong><?php echo htmlspecialchars($vehicle['plate_number']); ?></strong>
                                        </h6>
                                        <p class="mb-0 text-muted">
                                            <?php echo htmlspecialchars($vehicle['brand'] . ' - ' . $vehicle['vehicle_type']); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted">Vehicle Type</small>
                                        <p class="mb-0"><?php echo htmlspecialchars($vehicle['vehicle_type']); ?></p>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <?php
                                        $status_badges = [
                                            'Active' => '<span class="badge bg-success">Active</span>',
                                            'Inactive' => '<span class="badge bg-danger">Inactive</span>',
                                            'Maintenance' => '<span class="badge bg-warning text-dark">Maintenance</span>'
                                        ];
                                        echo $status_badges[$vehicle['status']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($vehicle['status']) . '</span>';
                                        ?>
                                        <div class="mt-2">
                                            <a href="my-vehicle.php?id=<?php echo $vehicle['vehicle_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye me-1"></i>View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Upcoming Maintenance -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0">
                        <i class="fas fa-wrench me-2"></i>Upcoming Maintenance
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcoming_maintenance)): ?>
                        <p class="text-muted text-center py-3 mb-0">No upcoming maintenance</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_maintenance as $maint): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($maint['plate_number']); ?></h6>
                                        <p class="mb-1 text-muted small"><?php echo htmlspecialchars($maint['maintenance_type']); ?></p>
                                    </div>
                                    <span class="badge bg-warning text-dark">
                                        <?php echo date('M d', strtotime($maint['next_maintenance_date'])); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Trips -->
    <?php if (!empty($recent_trips)): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>Recent Activity Logs
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th>Description</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_trips as $trip): ?>
                                <tr>
                                    <td><?php echo date('M d, Y h:i A', strtotime($trip['log_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($trip['plate_number']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($trip['vehicle_type']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($trip['description'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($trip['log_type']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.card {
    transition: transform 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
}

.list-group-item {
    transition: background-color 0.2s ease;
}

.list-group-item:hover {
    background-color: rgba(0, 123, 255, 0.05);
}
</style>

<?php include '../../includes/footer.php'; ?>