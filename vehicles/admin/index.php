<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Only Admin, Staff, and Super Admin can access
requireLogin();
$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Staff', 'Super Admin'])) {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$page_title = 'Vehicle Management';
$success_message = '';
$error_message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_vehicle':
            $plate_number = trim($_POST['plate_number']);
            $brand = trim($_POST['brand']);
            $model = trim($_POST['model']);
            $year = intval($_POST['year']);
            $vehicle_type = trim($_POST['vehicle_type']);
            $capacity = intval($_POST['capacity']);
            $fuel_type = trim($_POST['fuel_type']);
            $status = $_POST['status'];
            $notes = trim($_POST['notes']);
            
            $stmt = $conn->prepare("INSERT INTO tbl_vehicles (plate_number, brand, model, year, vehicle_type, capacity, fuel_type, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssisssss", $plate_number, $brand, $model, $year, $vehicle_type, $capacity, $fuel_type, $status, $notes);
            
            if ($stmt->execute()) {
                $success_message = "Vehicle added successfully!";
            } else {
                $error_message = "Error adding vehicle: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'edit_vehicle':
            $vehicle_id = intval($_POST['vehicle_id']);
            $plate_number = trim($_POST['plate_number']);
            $brand = trim($_POST['brand']);
            $model = trim($_POST['model']);
            $year = intval($_POST['year']);
            $vehicle_type = trim($_POST['vehicle_type']);
            $capacity = intval($_POST['capacity']);
            $fuel_type = trim($_POST['fuel_type']);
            $status = $_POST['status'];
            $notes = trim($_POST['notes']);
            
            $stmt = $conn->prepare("UPDATE tbl_vehicles SET plate_number=?, brand=?, model=?, year=?, vehicle_type=?, capacity=?, fuel_type=?, status=?, notes=? WHERE vehicle_id=?");
            $stmt->bind_param("sssisssssi", $plate_number, $brand, $model, $year, $vehicle_type, $capacity, $fuel_type, $status, $notes, $vehicle_id);
            
            if ($stmt->execute()) {
                $success_message = "Vehicle updated successfully!";
            } else {
                $error_message = "Error updating vehicle: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'delete_vehicle':
            $vehicle_id = intval($_POST['vehicle_id']);
            
            // Check if vehicle has assigned driver
            $check = $conn->prepare("SELECT assigned_driver_id FROM tbl_vehicles WHERE vehicle_id = ?");
            $check->bind_param("i", $vehicle_id);
            $check->execute();
            $result = $check->get_result();
            $vehicle = $result->fetch_assoc();
            $check->close();
            
            if ($vehicle && !empty($vehicle['assigned_driver_id'])) {
                $error_message = "Cannot delete vehicle with assigned driver. Unassign driver first.";
            } else {
                $stmt = $conn->prepare("DELETE FROM tbl_vehicles WHERE vehicle_id = ?");
                $stmt->bind_param("i", $vehicle_id);
                
                if ($stmt->execute()) {
                    $success_message = "Vehicle deleted successfully!";
                } else {
                    $error_message = "Error deleting vehicle: " . $stmt->error;
                }
                $stmt->close();
            }
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
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';

// Get all vehicles with driver info
$vehicles_sql = "SELECT v.*, 
                 u.username as driver_name,
                 CONCAT(r.first_name, ' ', r.last_name) as driver_full_name,
                 r.contact_number as driver_contact
                 FROM tbl_vehicles v
                 LEFT JOIN tbl_users u ON v.assigned_driver_id = u.user_id
                 LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                 WHERE 1=1";

$params = [];
$types = "";

if ($filter_status) {
    $vehicles_sql .= " AND v.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($filter_type) {
    $vehicles_sql .= " AND v.vehicle_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

$vehicles_sql .= " ORDER BY v.plate_number";

$stmt = $conn->prepare($vehicles_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$vehicles = [];
while ($row = $result->fetch_assoc()) {
    $vehicles[] = $row;
}
$stmt->close();

// Calculate statistics
$total_vehicles = count($vehicles);
$active_vehicles = count(array_filter($vehicles, fn($v) => $v['status'] === 'Active'));
$maintenance_vehicles = count(array_filter($vehicles, fn($v) => $v['status'] === 'Maintenance'));
$assigned_vehicles = count(array_filter($vehicles, fn($v) => !empty($v['assigned_driver_id'])));

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
                        <i class="fas fa-car me-2 text-primary"></i>
                        Vehicle Management
                    </h2>
                    <p class="text-muted mb-0">Manage all vehicles in the fleet</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                    <i class="fas fa-plus me-2"></i>Add Vehicle
                </button>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Quick Access Menu -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5><i class="fas fa-bolt me-2"></i>Quick Access</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <a href="trip-log.php" class="btn btn-outline-primary w-100 py-3">
                        <i class="fas fa-route fa-2x mb-2 d-block"></i>
                        <span>Trip Logs</span>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="maintenance.php" class="btn btn-outline-primary w-100 py-3">
                        <i class="fas fa-tools fa-2x mb-2 d-block"></i>
                        <span>Maintenance</span>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="assign-driver.php" class="btn btn-outline-primary w-100 py-3">
                        <i class="fas fa-user-check fa-2x mb-2 d-block"></i>
                        <span>Assign Driver</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total Vehicles</p>
                            <h3 class="mb-0"><?php echo $total_vehicles; ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-car fs-4"></i>
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
                            <p class="text-muted mb-1 small">Active</p>
                            <h3 class="mb-0"><?php echo $active_vehicles; ?></h3>
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
                            <p class="text-muted mb-1 small">In Maintenance</p>
                            <h3 class="mb-0"><?php echo $maintenance_vehicles; ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                            <i class="fas fa-wrench fs-4"></i>
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
                            <p class="text-muted mb-1 small">Assigned</p>
                            <h3 class="mb-0"><?php echo $assigned_vehicles; ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                            <i class="fas fa-user-tie fs-4"></i>
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
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Maintenance" <?php echo $filter_status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="Inactive" <?php echo $filter_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Vehicle Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Sedan" <?php echo $filter_type === 'Sedan' ? 'selected' : ''; ?>>Sedan</option>
                        <option value="SUV" <?php echo $filter_type === 'SUV' ? 'selected' : ''; ?>>SUV</option>
                        <option value="Van" <?php echo $filter_type === 'Van' ? 'selected' : ''; ?>>Van</option>
                        <option value="Truck" <?php echo $filter_type === 'Truck' ? 'selected' : ''; ?>>Truck</option>
                        <option value="Motorcycle" <?php echo $filter_type === 'Motorcycle' ? 'selected' : ''; ?>>Motorcycle</option>
                        <option value="Bus" <?php echo $filter_type === 'Bus' ? 'selected' : ''; ?>>Bus</option>
                    </select>
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

    <!-- Vehicles Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                Vehicles
                <span class="badge bg-primary ms-2"><?php echo count($vehicles); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($vehicles)): ?>
            <div class="empty-state">
                <i class="fas fa-car"></i>
                <p>No vehicles found</p>
                <p class="text-muted mt-2">Click "Add Vehicle" to get started</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Plate Number</th>
                            <th>Vehicle Details</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Fuel</th>
                            <th>Driver</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($vehicle['plate_number']); ?></strong>
                            </td>
                            <td>
                                <i class="fas fa-car text-muted me-1"></i>
                                <strong><?php echo htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']); ?></strong><br>
                                <small class="text-muted">Year: <?php echo $vehicle['year']; ?></small>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo htmlspecialchars($vehicle['vehicle_type']); ?></span>
                            </td>
                            <td>
                                <i class="fas fa-users text-muted me-1"></i>
                                <?php echo $vehicle['capacity']; ?> seats
                            </td>
                            <td><?php echo htmlspecialchars($vehicle['fuel_type']); ?></td>
                            <td>
                                <?php if (!empty($vehicle['driver_full_name'])): ?>
                                    <i class="fas fa-user text-muted me-1"></i>
                                    <strong><?php echo htmlspecialchars($vehicle['driver_full_name']); ?></strong><br>
                                    <small class="text-muted">
                                        <i class="fas fa-phone text-muted me-1"></i>
                                        <?php echo htmlspecialchars($vehicle['driver_contact'] ?? 'N/A'); ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted"><i>Unassigned</i></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge_class = 'secondary';
                                $icon = 'info-circle';
                                if ($vehicle['status'] === 'Active') {
                                    $badge_class = 'success';
                                    $icon = 'check-circle';
                                } elseif ($vehicle['status'] === 'Maintenance') {
                                    $badge_class = 'warning';
                                    $icon = 'wrench';
                                } elseif ($vehicle['status'] === 'Inactive') {
                                    $badge_class = 'danger';
                                    $icon = 'times-circle';
                                }
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>">
                                    <i class="fas fa-<?php echo $icon; ?> me-1"></i>
                                    <?php echo htmlspecialchars($vehicle['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-warning" 
                                            data-vehicle='<?php echo htmlspecialchars(json_encode($vehicle), ENT_QUOTES, 'UTF-8'); ?>'
                                            onclick="editVehicleFromData(this)" 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-danger" 
                                            onclick="deleteVehicle(<?php echo $vehicle['vehicle_id']; ?>, '<?php echo htmlspecialchars(addslashes($vehicle['plate_number'])); ?>')" 
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

<!-- Add Vehicle Modal -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_vehicle">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plate Number *</label>
                            <input type="text" name="plate_number" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Brand *</label>
                            <input type="text" name="brand" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Model *</label>
                            <input type="text" name="model" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Year *</label>
                            <input type="number" name="year" class="form-control" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle Type *</label>
                            <select name="vehicle_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Van">Van</option>
                                <option value="Truck">Truck</option>
                                <option value="Motorcycle">Motorcycle</option>
                                <option value="Bus">Bus</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" class="form-control" min="1" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fuel Type *</label>
                            <select name="fuel_type" class="form-select" required>
                                <option value="">Select Fuel Type</option>
                                <option value="Gasoline">Gasoline</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Add Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Vehicle Modal -->
<div class="modal fade" id="editVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_vehicle">
                    <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plate Number *</label>
                            <input type="text" name="plate_number" id="edit_plate_number" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Brand *</label>
                            <input type="text" name="brand" id="edit_brand" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Model *</label>
                            <input type="text" name="model" id="edit_model" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Year *</label>
                            <input type="number" name="year" id="edit_year" class="form-control" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle Type *</label>
                            <select name="vehicle_type" id="edit_vehicle_type" class="form-select" required>
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Van">Van</option>
                                <option value="Truck">Truck</option>
                                <option value="Motorcycle">Motorcycle</option>
                                <option value="Bus">Bus</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Capacity *</label>
                            <input type="number" name="capacity" id="edit_capacity" class="form-control" min="1" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fuel Type *</label>
                            <select name="fuel_type" id="edit_fuel_type" class="form-select" required>
                                <option value="Gasoline">Gasoline</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2 text-danger"></i>Delete Vehicle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body" style="padding: 2rem;">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="vehicle_id" id="delete_vehicle_id">
                    
                    <div style="text-align: center; margin-bottom: 1.5rem;">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                            <i class="fas fa-trash-alt" style="font-size: 2rem; color: #dc2626;"></i>
                        </div>
                    </div>
                    
                    <h5 style="text-align: center; color: #212529; margin-bottom: 1rem; font-weight: 700;">
                        Are you sure?
                    </h5>
                    
                    <p style="text-align: center; color: #64748b; margin-bottom: 1.5rem;">
                        You are about to delete:
                    </p>
                    
                    <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 3px solid #dc3545;">
                        <p style="margin: 0; color: #495057; font-weight: 600;">
                            <i class="fas fa-car me-2" style="color: #dc3545;"></i>
                            Plate Number: <strong id="delete_vehicle_plate"></strong>
                        </p>
                    </div>
                    
                    <div class="alert alert-danger mb-0" style="font-size: 0.875rem;">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone. All vehicle data will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i>Delete Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editVehicleFromData(button) {
    const vehicle = JSON.parse(button.getAttribute('data-vehicle'));
    
    document.getElementById('edit_vehicle_id').value = vehicle.vehicle_id;
    document.getElementById('edit_plate_number').value = vehicle.plate_number;
    document.getElementById('edit_brand').value = vehicle.brand;
    document.getElementById('edit_model').value = vehicle.model;
    document.getElementById('edit_year').value = vehicle.year;
    document.getElementById('edit_vehicle_type').value = vehicle.vehicle_type;
    document.getElementById('edit_capacity').value = vehicle.capacity;
    document.getElementById('edit_fuel_type').value = vehicle.fuel_type;
    document.getElementById('edit_status').value = vehicle.status;
    document.getElementById('edit_notes').value = vehicle.notes || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editVehicleModal'));
    modal.show();
}

function deleteVehicle(vehicleId, plateNumber) {
    document.getElementById('delete_vehicle_id').value = vehicleId;
    document.getElementById('delete_vehicle_plate').textContent = plateNumber;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteVehicleModal'));
    modal.show();
}

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