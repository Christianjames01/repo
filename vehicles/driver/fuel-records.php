<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'Fuel Records';

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

// Handle form submission - ONLY if driver has assigned vehicle
if ($has_assigned_vehicle && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_fuel_record') {
        $vehicle_id = intval($_POST['vehicle_id']);
        $fuel_type = trim($_POST['fuel_type']);
        $liters = floatval($_POST['liters']);
        $cost = floatval($_POST['cost']);
        $odometer = intval($_POST['odometer']);
        $station = trim($_POST['station']);
        $notes = trim($_POST['notes']);
        
        // Verify vehicle belongs to driver
        $check = $conn->prepare("SELECT vehicle_id FROM tbl_vehicles WHERE vehicle_id = ? AND assigned_driver_id = ?");
        $check->bind_param("ii", $vehicle_id, $current_user_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            // Add to vehicle logs
            $log_desc = "Fuel refill: $liters liters of $fuel_type at " . ($station ?: 'Unknown Station') . " - Cost: ₱" . number_format($cost, 2);
            $stmt = $conn->prepare("INSERT INTO tbl_vehicle_logs (vehicle_id, driver_id, log_type, description) VALUES (?, ?, 'Fuel', ?)");
            $stmt->bind_param("iis", $vehicle_id, $current_user_id, $log_desc);
            
            if ($stmt->execute()) {
                $success_message = "Fuel record added successfully!";
            } else {
                $error_message = "Error adding fuel record: " . $stmt->error;
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

// Get fuel records from logs - ONLY if driver has assigned vehicle
$fuel_logs = [];
if ($has_assigned_vehicle) {
    $logs_sql = "SELECT l.*, v.plate_number, v.brand, v.vehicle_type
                 FROM tbl_vehicle_logs l
                 INNER JOIN tbl_vehicles v ON l.vehicle_id = v.vehicle_id
                 WHERE v.assigned_driver_id = ? AND l.log_type = 'Fuel'
                 ORDER BY l.log_date DESC
                 LIMIT 50";
    $stmt = $conn->prepare($logs_sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $fuel_logs[] = $row;
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
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-gas-pump me-2"></i>Fuel Records
                    </h2>
                    <p class="text-muted">Track fuel consumption and refills</p>
                </div>
                <?php if ($has_assigned_vehicle): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFuelModal">
                    <i class="fas fa-plus me-2"></i>Add Fuel Record
                </button>
                <?php endif; ?>
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

    <?php if (!$has_assigned_vehicle): ?>
    <!-- No Vehicle Assigned Message -->
    <div class="alert alert-warning border-0 shadow-sm">
        <div class="d-flex align-items-center">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle fa-3x text-warning"></i>
            </div>
            <div class="flex-grow-1 ms-3">
                <h4 class="alert-heading mb-2">No Vehicle Assigned</h4>
                <p class="mb-2">You currently don't have any vehicle assigned to you. Fuel tracking is not available until a vehicle is assigned by the administrator.</p>
                <hr>
                <p class="mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Please contact the administrator to request a vehicle assignment.
                </p>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-gas-pump fa-4x text-muted mb-3"></i>
            <h5 class="text-muted">Waiting for Vehicle Assignment</h5>
            <p class="text-muted">Once a vehicle is assigned to you, you'll be able to track fuel consumption and refills.</p>
        </div>
    </div>
    <?php else: ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if (empty($fuel_logs)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-gas-pump fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">No fuel records yet</h5>
                    <p class="text-muted">Add your first fuel record to start tracking</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Vehicle</th>
                                <th>Details</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fuel_logs as $log): ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('M d, Y', strtotime($log['log_date'])); ?></strong><br>
                                    <small class="text-muted"><?php echo date('g:i A', strtotime($log['log_date'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['plate_number']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($log['vehicle_type']); ?></small>
                                </td>
                                <td>
                                    <i class="fas fa-gas-pump text-primary me-1"></i>
                                    <?php echo htmlspecialchars($log['description']); ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($has_assigned_vehicle): ?>
<!-- Add Fuel Modal -->
<div class="modal fade" id="addFuelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-gas-pump me-2"></i>Add Fuel Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_fuel_record">
                    
                    <div class="mb-3">
                        <label class="form-label">Vehicle *</label>
                        <select name="vehicle_id" class="form-control" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($my_vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['vehicle_id']; ?>">
                                    <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fuel Type *</label>
                            <select name="fuel_type" class="form-control" required>
                                <option value="Gasoline">Gasoline</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Premium">Premium</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Liters *</label>
                            <input type="number" name="liters" class="form-control" step="0.01" min="0" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Cost (₱) *</label>
                            <input type="number" name="cost" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Odometer (km)</label>
                            <input type="number" name="odometer" class="form-control" min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Gas Station</label>
                        <input type="text" name="station" class="form-control" placeholder="e.g., Shell, Petron">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
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

<!-- View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Fuel Record Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<script>
function viewDetails(log) {
    const content = `
        <div class="mb-3">
            <label class="text-muted small">Date & Time</label>
            <p class="mb-0"><strong>${new Date(log.log_date).toLocaleString()}</strong></p>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Vehicle</label>
            <p class="mb-0"><strong>${log.plate_number}</strong> - ${log.vehicle_type}</p>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Details</label>
            <p class="mb-0">${log.description}</p>
        </div>
    `;
    document.getElementById('detailsContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewDetailsModal')).show();
}
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>