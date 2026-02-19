<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'Vehicle Reports';

// Get driver's vehicles
$vehicles_sql = "SELECT vehicle_id, plate_number, brand, model, vehicle_type, status
                 FROM tbl_vehicles 
                 WHERE assigned_driver_id = ?
                 ORDER BY plate_number";
$stmt = $conn->prepare($vehicles_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
$my_vehicles = [];
while ($row = $result->fetch_assoc()) {
    $my_vehicles[] = $row;
}
$stmt->close();

// Get selected vehicle or default to first
$selected_vehicle_id = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : ($my_vehicles[0]['vehicle_id'] ?? 0);
$selected_vehicle = null;
foreach ($my_vehicles as $vehicle) {
    if ($vehicle['vehicle_id'] == $selected_vehicle_id) {
        $selected_vehicle = $vehicle;
        break;
    }
}

// Get statistics for selected vehicle
$stats = [
    'total_logs' => 0,
    'trips' => 0,
    'fuel_records' => 0,
    'maintenance' => 0,
    'assignments' => 0
];

if ($selected_vehicle_id) {
    $stats_sql = "SELECT 
                    COUNT(*) as total_logs,
                    SUM(CASE WHEN log_type IN ('Trip', 'Travel') THEN 1 ELSE 0 END) as trips,
                    SUM(CASE WHEN log_type = 'Fuel' THEN 1 ELSE 0 END) as fuel_records,
                    SUM(CASE WHEN log_type = 'Maintenance' THEN 1 ELSE 0 END) as maintenance,
                    SUM(CASE WHEN log_type = 'Assignment' THEN 1 ELSE 0 END) as assignments
                  FROM tbl_vehicle_logs
                  WHERE vehicle_id = ?";
    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("i", $selected_vehicle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
}

// Get recent activity
$recent_logs = [];
if ($selected_vehicle_id) {
    $logs_sql = "SELECT * FROM tbl_vehicle_logs 
                 WHERE vehicle_id = ? 
                 ORDER BY log_date DESC 
                 LIMIT 10";
    $stmt = $conn->prepare($logs_sql);
    $stmt->bind_param("i", $selected_vehicle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_logs[] = $row;
    }
    $stmt->close();
}

// Get activity by month for chart
$monthly_activity = [];
if ($selected_vehicle_id) {
    $monthly_sql = "SELECT 
                        DATE_FORMAT(log_date, '%Y-%m') as month,
                        COUNT(*) as count
                    FROM tbl_vehicle_logs
                    WHERE vehicle_id = ?
                    AND log_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                    GROUP BY month
                    ORDER BY month";
    $stmt = $conn->prepare($monthly_sql);
    $stmt->bind_param("i", $selected_vehicle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthly_activity[] = $row;
    }
    $stmt->close();
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <a href="index.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
            <h2 class="mb-0">
                <i class="fas fa-chart-bar me-2"></i>Vehicle Reports
            </h2>
            <p class="text-muted">View vehicle statistics and activity reports</p>
        </div>
    </div>

    <!-- Vehicle Selector -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <label class="form-label mb-2">Select Vehicle</label>
                            <select class="form-control" onchange="window.location.href='?vehicle_id=' + this.value">
                                <?php foreach ($my_vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['vehicle_id']; ?>" 
                                            <?php echo $vehicle['vehicle_id'] == $selected_vehicle_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($selected_vehicle): ?>
                        <div class="col-md-6 text-end">
                            <h4 class="mb-0"><?php echo htmlspecialchars($selected_vehicle['plate_number']); ?></h4>
                            <p class="text-muted mb-0">
                                <?php echo htmlspecialchars($selected_vehicle['brand'] . ' ' . $selected_vehicle['model']); ?>
                            </p>
                            <span class="badge bg-<?php echo $selected_vehicle['status'] === 'Active' ? 'success' : 'warning'; ?>">
                                <?php echo htmlspecialchars($selected_vehicle['status']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$selected_vehicle): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No vehicles assigned to you yet.
        </div>
    <?php else: ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="fas fa-list fa-2x text-primary mb-2"></i>
                    <h3 class="mb-0"><?php echo $stats['total_logs']; ?></h3>
                    <p class="text-muted mb-0 small">Total Activities</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="fas fa-route fa-2x text-success mb-2"></i>
                    <h3 class="mb-0"><?php echo $stats['trips']; ?></h3>
                    <p class="text-muted mb-0 small">Total Trips</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="fas fa-gas-pump fa-2x text-info mb-2"></i>
                    <h3 class="mb-0"><?php echo $stats['fuel_records']; ?></h3>
                    <p class="text-muted mb-0 small">Fuel Records</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="fas fa-wrench fa-2x text-warning mb-2"></i>
                    <h3 class="mb-0"><?php echo $stats['maintenance']; ?></h3>
                    <p class="text-muted mb-0 small">Maintenance</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Activity Chart -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>Activity Overview (Last 6 Months)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="activityChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Activity Breakdown -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-pie-chart me-2"></i>Activity Breakdown
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="fas fa-route text-success me-2"></i>Trips</span>
                            <strong><?php echo $stats['trips']; ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $stats['total_logs'] > 0 ? ($stats['trips'] / $stats['total_logs'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="fas fa-gas-pump text-info me-2"></i>Fuel</span>
                            <strong><?php echo $stats['fuel_records']; ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-info" style="width: <?php echo $stats['total_logs'] > 0 ? ($stats['fuel_records'] / $stats['total_logs'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="fas fa-wrench text-warning me-2"></i>Maintenance</span>
                            <strong><?php echo $stats['maintenance']; ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-warning" style="width: <?php echo $stats['total_logs'] > 0 ? ($stats['maintenance'] / $stats['total_logs'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="fas fa-user-check text-primary me-2"></i>Assignments</span>
                            <strong><?php echo $stats['assignments']; ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?php echo $stats['total_logs'] > 0 ? ($stats['assignments'] / $stats['total_logs'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>Recent Activity
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_logs)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <p class="mb-0">No activity recorded yet</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($log['log_date'])); ?></strong><br>
                                            <small class="text-muted"><?php echo date('g:i A', strtotime($log['log_date'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($log['log_type']); ?></span>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
<?php if (!empty($monthly_activity)): ?>
const ctx = document.getElementById('activityChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($monthly_activity, 'month')); ?>,
        datasets: [{
            label: 'Activities',
            data: <?php echo json_encode(array_column($monthly_activity, 'count')); ?>,
            borderColor: 'rgb(13, 110, 253)',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>