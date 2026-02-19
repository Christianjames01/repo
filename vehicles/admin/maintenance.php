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

$page_title = 'Maintenance Management';
$success_message = '';
$error_message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_maintenance':
            $vehicle_id = intval($_POST['vehicle_id']);
            $maintenance_type = trim($_POST['maintenance_type']);
            $description = trim($_POST['description']);
            $maintenance_date = $_POST['maintenance_date'];
            $cost = floatval($_POST['cost']);
            $odometer_reading = !empty($_POST['odometer_reading']) ? floatval($_POST['odometer_reading']) : null;
            $performed_by = trim($_POST['performed_by']);
            $status = $_POST['status'];
            $next_maintenance_date = !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : null;
            $notes = trim($_POST['notes']);
            
            $stmt = $conn->prepare("INSERT INTO tbl_maintenance (vehicle_id, maintenance_type, description, maintenance_date, cost, odometer_reading, performed_by, status, next_maintenance_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssdsssss", $vehicle_id, $maintenance_type, $description, $maintenance_date, $cost, $odometer_reading, $performed_by, $status, $next_maintenance_date, $notes);
            
            if ($stmt->execute()) {
                $success_message = "Maintenance record added successfully!";
            } else {
                $error_message = "Error adding maintenance record: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'edit_maintenance':
            $maintenance_id = intval($_POST['maintenance_id']);
            $vehicle_id = intval($_POST['vehicle_id']);
            $maintenance_type = trim($_POST['maintenance_type']);
            $description = trim($_POST['description']);
            $maintenance_date = $_POST['maintenance_date'];
            $cost = floatval($_POST['cost']);
            $odometer_reading = !empty($_POST['odometer_reading']) ? floatval($_POST['odometer_reading']) : null;
            $performed_by = trim($_POST['performed_by']);
            $status = $_POST['status'];
            $next_maintenance_date = !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : null;
            $notes = trim($_POST['notes']);
            
            $stmt = $conn->prepare("UPDATE tbl_maintenance SET vehicle_id=?, maintenance_type=?, description=?, maintenance_date=?, cost=?, odometer_reading=?, performed_by=?, status=?, next_maintenance_date=?, notes=? WHERE maintenance_id=?");
            $stmt->bind_param("isssdsssssi", $vehicle_id, $maintenance_type, $description, $maintenance_date, $cost, $odometer_reading, $performed_by, $status, $next_maintenance_date, $notes, $maintenance_id);
            
            if ($stmt->execute()) {
                $success_message = "Maintenance record updated successfully!";
            } else {
                $error_message = "Error updating maintenance record: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'delete_maintenance':
            $maintenance_id = intval($_POST['maintenance_id']);
            
            $stmt = $conn->prepare("DELETE FROM tbl_maintenance WHERE maintenance_id = ?");
            $stmt->bind_param("i", $maintenance_id);
            
            if ($stmt->execute()) {
                $success_message = "Maintenance record deleted successfully!";
            } else {
                $error_message = "Error deleting maintenance record: " . $stmt->error;
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
$filter_vehicle = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get all maintenance records with filters
$maintenance_sql = "SELECT m.*, 
                    v.plate_number, v.brand, v.model
                    FROM tbl_maintenance m
                    LEFT JOIN tbl_vehicles v ON m.vehicle_id = v.vehicle_id
                    WHERE 1=1";

$params = [];
$types = "";

if ($filter_vehicle > 0) {
    $maintenance_sql .= " AND m.vehicle_id = ?";
    $params[] = $filter_vehicle;
    $types .= "i";
}

if ($filter_status) {
    $maintenance_sql .= " AND m.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($filter_type) {
    $maintenance_sql .= " AND m.maintenance_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($filter_date_from) {
    $maintenance_sql .= " AND m.maintenance_date >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $maintenance_sql .= " AND m.maintenance_date <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

$maintenance_sql .= " ORDER BY m.maintenance_date DESC LIMIT 100";

$stmt = $conn->prepare($maintenance_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$maintenance_records = [];
while ($row = $result->fetch_assoc()) {
    $maintenance_records[] = $row;
}
$stmt->close();

// Get vehicles for dropdown
$vehicles = [];
$vehicles_sql = "SELECT vehicle_id, plate_number, brand, model FROM tbl_vehicles ORDER BY plate_number";
$result = $conn->query($vehicles_sql);
while ($row = $result->fetch_assoc()) {
    $vehicles[] = $row;
}

// Calculate statistics
$total_maintenance = count($maintenance_records);
$completed_maintenance = count(array_filter($maintenance_records, fn($m) => $m['status'] === 'Completed'));
$pending_maintenance = count(array_filter($maintenance_records, fn($m) => $m['status'] === 'Pending'));
$scheduled_maintenance = count(array_filter($maintenance_records, fn($m) => $m['status'] === 'Scheduled'));
$total_cost = array_sum(array_column($maintenance_records, 'cost'));

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

.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
    border-left-color: #ffc107;
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
                        <i class="fas fa-tools me-2 text-primary"></i>
                        Maintenance Management
                    </h2>
                    <p class="text-muted mb-0">Track and manage all vehicle maintenance records</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                    <i class="fas fa-plus me-2"></i>Add Maintenance
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
                            <p class="text-muted mb-1 small">Total Records</p>
                            <h3 class="mb-0"><?php echo $total_maintenance; ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-clipboard-list fs-4"></i>
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
                            <h3 class="mb-0"><?php echo $completed_maintenance; ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-check-double fs-4"></i>
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
                            <p class="text-muted mb-1 small">Pending</p>
                            <h3 class="mb-0"><?php echo $pending_maintenance; ?></h3>
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
                            <p class="text-muted mb-1 small">Total Cost</p>
                            <h3 class="mb-0">₱<?php echo number_format($total_cost, 0); ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                            <i class="fas fa-peso-sign fs-4"></i>
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
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle" class="form-select">
                        <option value="0">All Vehicles</option>
                        <?php foreach ($vehicles as $vehicle): ?>
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
                        <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Scheduled" <?php echo $filter_status === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Oil Change" <?php echo $filter_type === 'Oil Change' ? 'selected' : ''; ?>>Oil Change</option>
                        <option value="Tire Rotation" <?php echo $filter_type === 'Tire Rotation' ? 'selected' : ''; ?>>Tire Rotation</option>
                        <option value="Brake Service" <?php echo $filter_type === 'Brake Service' ? 'selected' : ''; ?>>Brake Service</option>
                        <option value="Engine Repair" <?php echo $filter_type === 'Engine Repair' ? 'selected' : ''; ?>>Engine Repair</option>
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

    <!-- Maintenance Records Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                Maintenance Records
                <span class="badge bg-primary ms-2"><?php echo count($maintenance_records); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($maintenance_records)): ?>
            <div class="empty-state">
                <i class="fas fa-tools"></i>
                <p>No maintenance records found</p>
                <p class="text-muted mt-2">Try adjusting your filters or add a new maintenance record</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Vehicle</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Performed By</th>
                            <th>Cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenance_records as $record): ?>
                        <tr>
                            <td>
                                <strong><?php echo date('M d, Y', strtotime($record['maintenance_date'])); ?></strong>
                                <?php if (!empty($record['next_maintenance_date'])): ?>
                                    <br><small class="text-muted">
                                        <i class="fas fa-calendar-plus me-1"></i>Next: <?php echo date('M d, Y', strtotime($record['next_maintenance_date'])); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <i class="fas fa-car text-muted me-1"></i>
                                <strong><?php echo htmlspecialchars($record['plate_number']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($record['brand'] . ' ' . $record['model']); ?></small>
                            </td>
                            <td>
                                <i class="fas fa-wrench text-primary me-1"></i>
                                <?php echo htmlspecialchars($record['maintenance_type']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($record['description']); ?></td>
                            <td>
                                <i class="fas fa-user-cog text-muted me-1"></i>
                                <?php echo htmlspecialchars($record['performed_by']); ?>
                            </td>
                            <td>
                                <strong>₱<?php echo number_format($record['cost'], 2); ?></strong>
                                <?php if (!empty($record['odometer_reading'])): ?>
                                    <br><small class="text-muted">
                                        <i class="fas fa-tachometer-alt me-1"></i><?php echo number_format($record['odometer_reading'], 1); ?> km
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge_class = 'secondary';
                                $icon = 'info-circle';
                                if ($record['status'] === 'Completed') {
                                    $badge_class = 'success';
                                    $icon = 'check-circle';
                                } elseif ($record['status'] === 'Pending') {
                                    $badge_class = 'warning';
                                    $icon = 'clock';
                                } elseif ($record['status'] === 'Scheduled') {
                                    $badge_class = 'info';
                                    $icon = 'calendar-check';
                                }
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>">
                                    <i class="fas fa-<?php echo $icon; ?> me-1"></i>
                                    <?php echo htmlspecialchars($record['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-warning" 
                                            data-maintenance='<?php echo htmlspecialchars(json_encode($record), ENT_QUOTES, 'UTF-8'); ?>'
                                            onclick="editMaintenanceFromData(this)" 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-danger" 
                                            onclick="deleteMaintenance(<?php echo $record['maintenance_id']; ?>, '<?php echo htmlspecialchars(addslashes($record['maintenance_type'])); ?>', '<?php echo htmlspecialchars(addslashes($record['plate_number'] . ' - ' . $record['brand'] . ' ' . $record['model'])); ?>')" 
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

<!-- Add Maintenance Modal -->
<div class="modal fade" id="addMaintenanceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Maintenance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_maintenance">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle *</label>
                            <select name="vehicle_id" class="form-select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                    <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Maintenance Type *</label>
                            <select name="maintenance_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Oil Change">Oil Change</option>
                                <option value="Tire Rotation">Tire Rotation</option>
                                <option value="Brake Service">Brake Service</option>
                                <option value="Engine Repair">Engine Repair</option>
                                <option value="Transmission Service">Transmission Service</option>
                                <option value="Battery Replacement">Battery Replacement</option>
                                <option value="General Inspection">General Inspection</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Maintenance Date *</label>
                            <input type="date" name="maintenance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control" rows="2" required></textarea>
                    </div>
                                
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cost (₱) *</label>
                            <input type="number" step="0.01" name="cost" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Odometer Reading (km)</label>
                            <input type="number" step="0.1" name="odometer_reading" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Performed By *</label>
                            <input type="text" name="performed_by" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="Scheduled">Scheduled</option>
                                <option value="Pending">Pending</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Next Maintenance Date</label>
                            <input type="date" name="next_maintenance_date" class="form-control">
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
                        <i class="fas fa-save me-2"></i>Save Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Maintenance Modal -->
<div class="modal fade" id="editMaintenanceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Maintenance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_maintenance">
                    <input type="hidden" name="maintenance_id" id="edit_maintenance_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vehicle *</label>
                            <select name="vehicle_id" id="edit_vehicle_id" class="form-select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                    <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Maintenance Type *</label>
                            <select name="maintenance_type" id="edit_maintenance_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Oil Change">Oil Change</option>
                                <option value="Tire Rotation">Tire Rotation</option>
                                <option value="Brake Service">Brake Service</option>
                                <option value="Engine Repair">Engine Repair</option>
                                <option value="Transmission Service">Transmission Service</option>
                                <option value="Battery Replacement">Battery Replacement</option>
                                <option value="General Inspection">General Inspection</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Maintenance Date *</label>
                            <input type="date" name="maintenance_date" id="edit_maintenance_date" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2" required></textarea>
                    </div>
                                
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cost (₱) *</label>
                            <input type="number" step="0.01" name="cost" id="edit_cost" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Odometer Reading (km)</label>
                            <input type="number" step="0.1" name="odometer_reading" id="edit_odometer_reading" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Performed By *</label>
                            <input type="text" name="performed_by" id="edit_performed_by" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="Scheduled">Scheduled</option>
                                <option value="Pending">Pending</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Next Maintenance Date</label>
                            <input type="date" name="next_maintenance_date" id="edit_next_maintenance_date" class="form-control">
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
                        <i class="fas fa-save me-2"></i>Update Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteMaintenanceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2 text-danger"></i>Delete Maintenance Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body" style="padding: 2rem;">
                    <input type="hidden" name="action" value="delete_maintenance">
                    <input type="hidden" name="maintenance_id" id="delete_maintenance_id">
                    
                    <div style="text-align: center; margin-bottom: 1.5rem;">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                            <i class="fas fa-trash-alt" style="font-size: 2rem; color: #dc2626;"></i>
                        </div>
                    </div>
                    
                    <h5 style="text-align: center; color: #212529; margin-bottom: 1rem; font-weight: 700;">
                        Are you sure?
                    </h5>
                    
                    <p style="text-align: center; color: #64748b; margin-bottom: 1.5rem;">
                        You are about to delete this maintenance record:
                    </p>
                    
                    <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 3px solid #dc3545;">
                        <p style="margin: 0; color: #495057; font-weight: 600;">
                            <i class="fas fa-wrench me-2" style="color: #dc3545;"></i>
                            <strong id="delete_maintenance_type"></strong>
                        </p>
                        <p style="margin: 0.5rem 0 0 0; color: #6c757d; font-size: 0.9rem;">
                            <i class="fas fa-car me-2"></i>
                            <span id="delete_vehicle_info"></span>
                        </p>
                    </div>
                    
                    <div class="alert alert-danger mb-0" style="font-size: 0.875rem;">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone. All maintenance data will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i>Delete Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editMaintenanceFromData(button) {
    const maintenance = JSON.parse(button.getAttribute('data-maintenance'));
    
    // Set form values
    document.getElementById('edit_maintenance_id').value = maintenance.maintenance_id;
    document.getElementById('edit_vehicle_id').value = maintenance.vehicle_id;
    document.getElementById('edit_maintenance_type').value = maintenance.maintenance_type;
    document.getElementById('edit_maintenance_date').value = maintenance.maintenance_date;
    document.getElementById('edit_description').value = maintenance.description;
    document.getElementById('edit_cost').value = maintenance.cost;
    document.getElementById('edit_odometer_reading').value = maintenance.odometer_reading || '';
    document.getElementById('edit_performed_by').value = maintenance.performed_by;
    document.getElementById('edit_status').value = maintenance.status;
    document.getElementById('edit_next_maintenance_date').value = maintenance.next_maintenance_date || '';
    document.getElementById('edit_notes').value = maintenance.notes || '';
    
    // Show modal
    new bootstrap.Modal(document.getElementById('editMaintenanceModal')).show();
}

function deleteMaintenance(id, type, vehicleInfo) {
    document.getElementById('delete_maintenance_id').value = id;
    document.getElementById('delete_maintenance_type').textContent = type;
    document.getElementById('delete_vehicle_info').textContent = vehicleInfo || 'Vehicle information unavailable';
    
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteMaintenanceModal'));
    deleteModal.show();
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