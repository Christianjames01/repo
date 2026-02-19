<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Staff', 'Super Admin'])) {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$page_title = 'Trip Logs Management';

$success_message = '';
$error_message = '';

// Get all vehicles with driver info
$vehicles_sql = "SELECT v.vehicle_id, v.plate_number, v.brand, v.model, v.vehicle_type,
                        u.user_id, 
                        u.username as driver_name
                 FROM tbl_vehicles v
                 LEFT JOIN tbl_users u ON v.assigned_driver_id = u.user_id
                 ORDER BY v.plate_number";
$vehicles_result = $conn->query($vehicles_sql);
$all_vehicles = [];
while ($row = $vehicles_result->fetch_assoc()) {
    $all_vehicles[] = $row;
}

// Get all drivers
$drivers_sql = "SELECT user_id, username as driver_name
                FROM tbl_users
                WHERE role = 'Driver' 
                ORDER BY username";
$drivers_result = $conn->query($drivers_sql);
$all_drivers = [];
while ($row = $drivers_result->fetch_assoc()) {
    $all_drivers[] = $row;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'add_trip':
            $vehicle_id = intval($_POST['vehicle_id']);
            $driver_id = intval($_POST['driver_id']);
            $trip_date = $_POST['trip_date'];
            $departure_time = $_POST['departure_time'];
            $trip_status = $_POST['trip_status'];
            
            // Handle arrival time based on status
            $arrival_time = null;
            if ($trip_status === 'completed' && !empty($_POST['arrival_time'])) {
                $arrival_time = $_POST['arrival_time'];
            }
            
            $origin = trim($_POST['origin']);
            $destination = trim($_POST['destination']);
            $purpose = trim($_POST['purpose']);
            $odometer_start = floatval($_POST['odometer_start']);
            $odometer_end = !empty($_POST['odometer_end']) ? floatval($_POST['odometer_end']) : null;
            $passengers = intval($_POST['passengers']);
            $notes = trim($_POST['notes']);
            
            // Calculate distance
            $distance = null;
            if ($odometer_end !== null && $odometer_end >= $odometer_start) {
                $distance = $odometer_end - $odometer_start;
            }
            
            $stmt = $conn->prepare("INSERT INTO tbl_trip_logs (vehicle_id, driver_id, trip_date, departure_time, arrival_time, origin, destination, purpose, odometer_start, odometer_end, distance_km, passengers, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissssssdddis", 
                $vehicle_id, $driver_id, $trip_date, $departure_time, $arrival_time, 
                $origin, $destination, $purpose, $odometer_start, $odometer_end, 
                $distance, $passengers, $notes
            );
            
            if ($stmt->execute()) {
                $success_message = "Trip log added successfully!";
                logActivity($conn, $current_user_id, "Added trip log", "tbl_trip_logs", $stmt->insert_id, "Trip from $origin to $destination");
            } else {
                $error_message = "Error adding trip log: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'edit_trip':
            $trip_id = intval($_POST['trip_id']);
            $vehicle_id = intval($_POST['vehicle_id']);
            $driver_id = intval($_POST['driver_id']);
            $trip_date = $_POST['trip_date'];
            $departure_time = $_POST['departure_time'];
            $trip_status = $_POST['trip_status'];
            
            // Handle arrival time based on status
            $arrival_time = null;
            if ($trip_status === 'completed' && !empty($_POST['arrival_time'])) {
                $arrival_time = $_POST['arrival_time'];
            }
            
            $origin = trim($_POST['origin']);
            $destination = trim($_POST['destination']);
            $purpose = trim($_POST['purpose']);
            $odometer_start = floatval($_POST['odometer_start']);
            $odometer_end = !empty($_POST['odometer_end']) ? floatval($_POST['odometer_end']) : null;
            $passengers = intval($_POST['passengers']);
            $notes = trim($_POST['notes']);
            
            // Calculate distance
            $distance = null;
            if ($odometer_end !== null && $odometer_end >= $odometer_start) {
                $distance = $odometer_end - $odometer_start;
            }
            
            $stmt = $conn->prepare("UPDATE tbl_trip_logs SET vehicle_id=?, driver_id=?, trip_date=?, departure_time=?, arrival_time=?, origin=?, destination=?, purpose=?, odometer_start=?, odometer_end=?, distance_km=?, passengers=?, notes=? WHERE trip_id=?");
            $stmt->bind_param("iissssssdddisi", 
                $vehicle_id, $driver_id, $trip_date, $departure_time, $arrival_time, 
                $origin, $destination, $purpose, $odometer_start, $odometer_end, 
                $distance, $passengers, $notes, $trip_id
            );
            
            if ($stmt->execute()) {
                $success_message = "Trip log updated successfully!";
                logActivity($conn, $current_user_id, "Updated trip log", "tbl_trip_logs", $trip_id, "Trip from $origin to $destination");
            } else {
                $error_message = "Error updating trip log: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'delete_trip':
            $trip_id = intval($_POST['trip_id']);
            
            $stmt = $conn->prepare("DELETE FROM tbl_trip_logs WHERE trip_id = ?");
            $stmt->bind_param("i", $trip_id);
            
            if ($stmt->execute()) {
                $success_message = "Trip log deleted successfully!";
                logActivity($conn, $current_user_id, "Deleted trip log", "tbl_trip_logs", $trip_id, "");
            } else {
                $error_message = "Error deleting trip log: " . $stmt->error;
            }
            $stmt->close();
            break;
    }
    
    $_SESSION['temp_success'] = $success_message;
    $_SESSION['temp_error'] = $error_message;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get messages from session
if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

// Get filter parameters
$filter_driver = isset($_GET['driver']) ? intval($_GET['driver']) : 0;
$filter_vehicle = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$trips_sql = "SELECT t.*, v.plate_number, v.brand, v.model, v.vehicle_type,
                     u.username as driver_name
              FROM tbl_trip_logs t
              INNER JOIN tbl_vehicles v ON t.vehicle_id = v.vehicle_id
              INNER JOIN tbl_users u ON t.driver_id = u.user_id
              WHERE 1=1";

$params = [];
$types = "";

if ($filter_driver > 0) {
    $trips_sql .= " AND t.driver_id = ?";
    $params[] = $filter_driver;
    $types .= "i";
}

if ($filter_vehicle > 0) {
    $trips_sql .= " AND t.vehicle_id = ?";
    $params[] = $filter_vehicle;
    $types .= "i";
}

if ($filter_status === 'completed') {
    $trips_sql .= " AND t.arrival_time IS NOT NULL";
} elseif ($filter_status === 'ongoing') {
    $trips_sql .= " AND t.arrival_time IS NULL";
}

if ($filter_date_from) {
    $trips_sql .= " AND t.trip_date >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $trips_sql .= " AND t.trip_date <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

$trips_sql .= " ORDER BY t.trip_date DESC, t.departure_time DESC LIMIT 100";

$stmt = $conn->prepare($trips_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$trips = [];
while ($row = $result->fetch_assoc()) {
    $trips[] = $row;
}
$stmt->close();

// Calculate statistics
$total_trips = count($trips);
$completed_trips = count(array_filter($trips, fn($t) => $t['arrival_time'] !== null));
$ongoing_trips = $total_trips - $completed_trips;
$total_distance = array_sum(array_column($trips, 'distance_km'));

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
}

/* Table Enhancements */
.table {
    margin-bottom: 0;
}

.table thead th {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 700;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
    transform: scale(1.01);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Enhanced Buttons */
.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn:active {
    transform: translateY(0);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-group .btn {
    box-shadow: none;
}

/* Alert Enhancements */
.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-success {
    background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
    border-left-color: #198754;
}

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

.alert i {
    font-size: 1.1rem;
}

/* Modal Enhancements */
.modal-content {
    border: none;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-lg);
}

.modal-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
    padding: 1.5rem;
}

.modal-title {
    font-weight: 700;
    font-size: 1.25rem;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
    padding: 1.25rem 2rem;
    border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
}

/* Form Enhancements */
.form-label {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

/* Route Display */
.route-display {
    line-height: 1.8;
}

.route-display .route-origin {
    color: #198754;
}

.route-display .route-destination {
    color: #dc3545;
}

.route-display .route-arrow {
    color: #adb5bd;
    font-size: 0.75rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stat-card {
        margin-bottom: 1rem;
    }
    
    .table thead th {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
    
    .table tbody td {
        font-size: 0.875rem;
        padding: 0.75rem;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-route me-2 text-primary"></i>
                        Trip Logs Management
                    </h2>
                    <p class="text-muted mb-0">View and manage all driver trip logs</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTripModal">
                    <i class="fas fa-plus me-2"></i>Add New Trip
                </button>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total Trips</p>
                            <h3 class="mb-0"><?php echo $total_trips; ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-route fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Completed</p>
                            <h3 class="mb-0"><?php echo $completed_trips; ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-check-circle fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Ongoing</p>
                            <h3 class="mb-0"><?php echo $ongoing_trips; ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                            <i class="fas fa-clock fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total KM</p>
                            <h3 class="mb-0"><?php echo number_format($total_distance, 1); ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                            <i class="fas fa-road fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Driver</label>
                    <select name="driver" class="form-select">
                        <option value="0">All Drivers</option>
                        <?php foreach ($all_drivers as $driver): ?>
                            <option value="<?php echo $driver['user_id']; ?>" <?php echo $filter_driver == $driver['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['driver_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle" class="form-select">
                        <option value="0">All Vehicles</option>
                        <?php foreach ($all_vehicles as $vehicle): ?>
                            <option value="<?php echo $vehicle['vehicle_id']; ?>" <?php echo $filter_vehicle == $vehicle['vehicle_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vehicle['plate_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="ongoing" <?php echo $filter_status === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $filter_date_from; ?>">
                </div>
                <div class="col-md-2">
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

    <!-- Trip Logs Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                Trip Logs
                <span class="badge bg-primary ms-2"><?php echo count($trips); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($trips)): ?>
            <div class="empty-state">
                <i class="fas fa-route"></i>
                <p>No trip logs found</p>
                <p class="text-muted mt-2">Try adjusting your filters or add a new trip log</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Driver</th>
                            <th>Vehicle</th>
                            <th>Route</th>
                            <th>Purpose</th>
                            <th>Distance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trips as $trip): ?>
                        <tr>
                            <td>
                                <strong><?php echo date('M d, Y', strtotime($trip['trip_date'])); ?></strong><br>
                                <small class="text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php echo date('h:i A', strtotime($trip['departure_time'])); ?>
                                    <?php if ($trip['arrival_time']): ?>
                                        - <?php echo date('h:i A', strtotime($trip['arrival_time'])); ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <i class="fas fa-user text-muted me-1"></i>
                                <strong><?php echo htmlspecialchars($trip['driver_name']); ?></strong>
                            </td>
                            <td>
                                <i class="fas fa-car text-muted me-1"></i>
                                <strong><?php echo htmlspecialchars($trip['plate_number']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($trip['vehicle_type']); ?></small>
                            </td>
                            <td>
                                <div class="route-display">
                                    <div class="route-origin">
                                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i> 
                                        <?php echo htmlspecialchars($trip['origin']); ?>
                                    </div>
                                    <div class="route-arrow text-center">↓</div>
                                    <div class="route-destination">
                                        <i class="fas fa-map-marker-alt" style="font-size: 0.7rem;"></i> 
                                        <?php echo htmlspecialchars($trip['destination']); ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($trip['purpose']); ?></td>
                            <td>
                                <?php if ($trip['distance_km']): ?>
                                    <strong><?php echo number_format($trip['distance_km'], 1); ?> km</strong>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($trip['arrival_time']): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check me-1"></i>Completed
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-clock me-1"></i>Ongoing
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-warning" 
                                            data-trip='<?php echo htmlspecialchars(json_encode($trip), ENT_QUOTES, 'UTF-8'); ?>'
                                            onclick="editTripFromData(this)" 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-danger" 
                                            onclick="deleteTrip(<?php echo $trip['trip_id']; ?>, '<?php echo htmlspecialchars(addslashes($trip['origin'] . ' to ' . $trip['destination'])); ?>')" 
                                            title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
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

<!-- Add Trip Modal -->
<div class="modal fade" id="addTripModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Trip</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_trip">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Driver *</label>
                            <select name="driver_id" class="form-select" required>
                                <option value="">Select Driver</option>
                                <?php foreach ($all_drivers as $driver): ?>
                                    <option value="<?php echo $driver['user_id']; ?>">
                                        <?php echo htmlspecialchars($driver['driver_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle *</label>
                            <select name="vehicle_id" class="form-select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($all_vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                        <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Trip Date *</label>
                            <input type="date" name="trip_date" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Departure Time *</label>
                            <input type="time" name="departure_time" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Arrival Time</label>
                            <input type="time" name="arrival_time" id="add_arrival_time" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="trip_status" id="add_trip_status" class="form-select" required>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Origin *</label>
                            <input type="text" name="origin" class="form-control" placeholder="Starting location" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Destination *</label>
                            <input type="text" name="destination" class="form-control" placeholder="Destination location" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purpose *</label>
                        <input type="text" name="purpose" class="form-control" placeholder="e.g., Official business, Emergency response" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Odometer Start (km) *</label>
                            <input type="number" name="odometer_start" class="form-control" step="0.1" min="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Odometer End (km)</label>
                            <input type="number" name="odometer_end" class="form-control" step="0.1" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Passengers *</label>
                            <input type="number" name="passengers" class="form-control" min="0" value="0" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this trip"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Add Trip
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Trip Modal -->
<div class="modal fade" id="editTripModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Trip</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_trip">
                    <input type="hidden" name="trip_id" id="edit_trip_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Driver *</label>
                            <select name="driver_id" id="edit_driver_id" class="form-select" required>
                                <option value="">Select Driver</option>
                                <?php foreach ($all_drivers as $driver): ?>
                                    <option value="<?php echo $driver['user_id']; ?>">
                                        <?php echo htmlspecialchars($driver['driver_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle *</label>
                            <select name="vehicle_id" id="edit_vehicle_id" class="form-select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($all_vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                        <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Trip Date *</label>
                            <input type="date" name="trip_date" id="edit_trip_date" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Departure Time *</label>
                            <input type="time" name="departure_time" id="edit_departure_time" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Arrival Time</label>
                            <input type="time" name="arrival_time" id="edit_arrival_time" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="trip_status" id="edit_trip_status" class="form-select" required>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Origin *</label>
                            <input type="text" name="origin" id="edit_origin" class="form-control" placeholder="Starting location" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Destination *</label>
                            <input type="text" name="destination" id="edit_destination" class="form-control" placeholder="Destination location" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purpose *</label>
                        <input type="text" name="purpose" id="edit_purpose" class="form-control" placeholder="e.g., Official business, Emergency response" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Odometer Start (km) *</label>
                            <input type="number" name="odometer_start" id="edit_odometer_start" class="form-control" step="0.1" min="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Odometer End (km)</label>
                            <input type="number" name="odometer_end" id="edit_odometer_end" class="form-control" step="0.1" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Passengers *</label>
                            <input type="number" name="passengers" id="edit_passengers" class="form-control" min="0" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3" placeholder="Additional notes about this trip"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Trip
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteTripModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2 text-danger"></i>Delete Trip Log
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body" style="padding: 2rem;">
                    <input type="hidden" name="action" value="delete_trip">
                    <input type="hidden" name="trip_id" id="delete_trip_id">
                    
                    <div style="text-align: center; margin-bottom: 1.5rem;">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                            <i class="fas fa-trash-alt" style="font-size: 2rem; color: #dc2626;"></i>
                        </div>
                    </div>
                    
                    <h5 style="text-align: center; color: #212529; margin-bottom: 1rem; font-weight: 700;">
                        Are you sure?
                    </h5>
                    
                    <p style="text-align: center; color: #64748b; margin-bottom: 1.5rem;">
                        You are about to delete this trip log:
                    </p>
                    
                    <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 3px solid #dc3545;">
                        <p style="margin: 0; color: #495057; font-weight: 600;">
                            <i class="fas fa-route me-2" style="color: #dc3545;"></i>
                            <strong id="delete_trip_info"></strong>
                        </p>
                    </div>
                    
                    <div class="alert alert-danger mb-0" style="font-size: 0.875rem;">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone. All trip data will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i>Delete Trip Log
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleArrivalTime(prefix) {
    const statusSelect = document.getElementById(prefix + '_trip_status');
    const arrivalTimeInput = document.getElementById(prefix + '_arrival_time');
    
    if (statusSelect.value === 'ongoing') {
        arrivalTimeInput.value = '';
        arrivalTimeInput.removeAttribute('required');
        arrivalTimeInput.disabled = true;
    } else {
        arrivalTimeInput.disabled = false;
        arrivalTimeInput.setAttribute('required', 'required');
    }
}

function editTripFromData(button) {
    const trip = JSON.parse(button.getAttribute('data-trip'));
    
    // Set form values
    document.getElementById('edit_trip_id').value = trip.trip_id;
    document.getElementById('edit_driver_id').value = trip.driver_id;
    document.getElementById('edit_vehicle_id').value = trip.vehicle_id;
    document.getElementById('edit_trip_date').value = trip.trip_date;
    document.getElementById('edit_departure_time').value = trip.departure_time;
    document.getElementById('edit_arrival_time').value = trip.arrival_time || '';
    document.getElementById('edit_origin').value = trip.origin;
    document.getElementById('edit_destination').value = trip.destination;
    document.getElementById('edit_purpose').value = trip.purpose;
    document.getElementById('edit_odometer_start').value = trip.odometer_start;
    document.getElementById('edit_odometer_end').value = trip.odometer_end || '';
    document.getElementById('edit_passengers').value = trip.passengers;
    document.getElementById('edit_notes').value = trip.notes || '';
    
    // Set status based on arrival_time
    const statusSelect = document.getElementById('edit_trip_status');
    if (trip.arrival_time && trip.arrival_time !== '') {
        statusSelect.value = 'completed';
    } else {
        statusSelect.value = 'ongoing';
    }
    
    // Toggle arrival time field
    toggleArrivalTime('edit');
    
    // Show modal
    new bootstrap.Modal(document.getElementById('editTripModal')).show();
}

function deleteTrip(tripId, tripInfo) {
    document.getElementById('delete_trip_id').value = tripId;
    document.getElementById('delete_trip_info').textContent = tripInfo;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteTripModal'));
    modal.show();
}

// Initialize status change listeners
document.addEventListener('DOMContentLoaded', function() {
    const addStatusSelect = document.getElementById('add_trip_status');
    const editStatusSelect = document.getElementById('edit_trip_status');
    
    if (addStatusSelect) {
        addStatusSelect.addEventListener('change', function() {
            toggleArrivalTime('add');
        });
        // Initialize on load
        toggleArrivalTime('add');
    }
    
    if (editStatusSelect) {
        editStatusSelect.addEventListener('change', function() {
            toggleArrivalTime('edit');
        });
    }
});

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