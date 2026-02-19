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
$success_message = '';
$error_message = '';

// Get trip ID from URL
$trip_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($trip_id === 0) {
    $_SESSION['error'] = "Invalid trip ID.";
    header('Location: trip-log-admin.php');
    exit();
}

// Get all vehicles with driver info
$vehicles_sql = "SELECT v.vehicle_id, v.plate_number, v.brand, v.model, v.vehicle_type
                 FROM tbl_vehicles_new v
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_trip') {
    $vehicle_id = intval($_POST['vehicle_id']);
    $driver_id = intval($_POST['driver_id']);
    $trip_date = $_POST['trip_date'];
    $departure_time = $_POST['departure_time'];
    $arrival_time = !empty($_POST['arrival_time']) ? $_POST['arrival_time'] : null;
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
        $_SESSION['temp_success'] = $success_message;
        header('Location: view-trip-log.php?id=' . $trip_id);
        exit();
    } else {
        $error_message = "Error updating trip log: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch trip details
$trip_sql = "SELECT t.*, v.plate_number, v.brand, v.model, v.vehicle_type,
                    u.username as driver_name
             FROM tbl_trip_logs t
             INNER JOIN tbl_vehicles_new v ON t.vehicle_id = v.vehicle_id
             INNER JOIN tbl_users_new u ON t.driver_id = u.user_id
             WHERE t.trip_id = ?";

$stmt = $conn->prepare($trip_sql);
$stmt->bind_param("i", $trip_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Trip not found.";
    header('Location: trip-log-admin.php');
    exit();
}

$trip = $result->fetch_assoc();
$stmt->close();

$page_title = 'Edit Trip Log';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <a href="view-triplog.php?id=<?php echo $trip_id; ?>" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Trip Details
            </a>
            <h2><i class="fas fa-edit me-2"></i>Edit Trip Log</h2>
            <p class="text-muted">Update trip information for #<?php echo $trip_id; ?></p>
        </div>
    </div>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-warning">
                    <h5 class="mb-0 text-dark"><i class="fas fa-edit me-2"></i>Trip Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_trip">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver *</label>
                                <select name="driver_id" class="form-select" required>
                                    <option value="">Select Driver</option>
                                    <?php foreach ($all_drivers as $driver): ?>
                                        <option value="<?php echo $driver['user_id']; ?>" 
                                            <?php echo $trip['driver_id'] == $driver['user_id'] ? 'selected' : ''; ?>>
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
                                        <option value="<?php echo $vehicle['vehicle_id']; ?>"
                                            <?php echo $trip['vehicle_id'] == $vehicle['vehicle_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vehicle['plate_number'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Trip Date *</label>
                                <input type="date" name="trip_date" class="form-control" 
                                    value="<?php echo $trip['trip_date']; ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Departure Time *</label>
                                <input type="time" name="departure_time" class="form-control" 
                                    value="<?php echo $trip['departure_time']; ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Arrival Time</label>
                                <input type="time" name="arrival_time" class="form-control" 
                                    value="<?php echo $trip['arrival_time'] ?? ''; ?>">
                                <small class="text-muted">Leave empty if ongoing</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Origin *</label>
                                <input type="text" name="origin" class="form-control" 
                                    placeholder="Starting location" 
                                    value="<?php echo htmlspecialchars($trip['origin']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Destination *</label>
                                <input type="text" name="destination" class="form-control" 
                                    placeholder="Destination location" 
                                    value="<?php echo htmlspecialchars($trip['destination']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Purpose *</label>
                            <input type="text" name="purpose" class="form-control" 
                                placeholder="e.g., Official business, Emergency response" 
                                value="<?php echo htmlspecialchars($trip['purpose']); ?>" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Odometer Start (km) *</label>
                                <input type="number" name="odometer_start" class="form-control" 
                                    step="0.1" min="0" 
                                    value="<?php echo $trip['odometer_start']; ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Odometer End (km)</label>
                                <input type="number" name="odometer_end" class="form-control" 
                                    step="0.1" min="0" 
                                    value="<?php echo $trip['odometer_end'] ?? ''; ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Passengers *</label>
                                <input type="number" name="passengers" class="form-control" 
                                    min="0" 
                                    value="<?php echo $trip['passengers']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="4" 
                                placeholder="Additional notes about this trip"><?php echo htmlspecialchars($trip['notes'] ?? ''); ?></textarea>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between">
                            <a href="view-triplog.php?id=<?php echo $trip_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save me-2"></i>Update Trip Log
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>