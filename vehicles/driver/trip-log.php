<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'Trip Logs';

// Check if driver has assigned vehicles
$check_vehicle = $conn->prepare("SELECT COUNT(*) as count FROM tbl_vehicles WHERE assigned_driver_id = ?");
$check_vehicle->bind_param("i", $current_user_id);
$check_vehicle->execute();
$result = $check_vehicle->get_result();
$vehicle_count = $result->fetch_assoc()['count'];
$check_vehicle->close();

// If no vehicles assigned, show message and prevent access
$has_assigned_vehicle = $vehicle_count > 0;

$success_message = '';
$error_message = '';

// Get driver's vehicles
$vehicles_sql = "SELECT vehicle_id, plate_number, brand, model, vehicle_type
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

// Handle form submission - ONLY if driver has assigned vehicle
if ($has_assigned_vehicle && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_trip') {
        $vehicle_id = intval($_POST['vehicle_id']);
        $destination = trim($_POST['destination']);
        $purpose = trim($_POST['purpose']);
        $departure_time = $_POST['departure_time'];
        $arrival_time = !empty($_POST['arrival_time']) ? $_POST['arrival_time'] : null;
        $distance = !empty($_POST['distance']) ? floatval($_POST['distance']) : null;
        $passengers = intval($_POST['passengers']);
        
        $trip_date = date('Y-m-d', strtotime($departure_time));
        $departure_time_only = date('H:i:s', strtotime($departure_time));
        $arrival_time_only = $arrival_time ? date('H:i:s', strtotime($arrival_time)) : null;
        
        // Verify vehicle belongs to driver
        $check = $conn->prepare("SELECT vehicle_id FROM tbl_vehicles WHERE vehicle_id = ? AND assigned_driver_id = ?");
        $check->bind_param("ii", $vehicle_id, $current_user_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO tbl_trip_logs (vehicle_id, driver_id, trip_date, departure_time, arrival_time, origin, destination, purpose, odometer_start, odometer_end, distance_km, passengers, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $origin = "Barangay Hall";
            $odometer_start = 0;
            $odometer_end = $distance > 0 ? $distance : null;
            $notes = "Logged by driver";
            
            $stmt->bind_param("iissssssdddis", 
                $vehicle_id, 
                $current_user_id, 
                $trip_date, 
                $departure_time_only, 
                $arrival_time_only, 
                $origin, 
                $destination, 
                $purpose, 
                $odometer_start, 
                $odometer_end, 
                $distance, 
                $passengers, 
                $notes
            );
            
            if ($stmt->execute()) {
                $success_message = "Trip log added successfully!";
                logActivity($conn, $current_user_id, "Added trip log", "tbl_trip_logs", $stmt->insert_id, "Trip to $destination");
            } else {
                $error_message = "Error adding trip log: " . $stmt->error;
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
    
    if ($_POST['action'] === 'complete_trip') {
        $trip_id = intval($_POST['trip_id']);
        $arrival_time = $_POST['arrival_time'];
        $arrival_time_only = date('H:i:s', strtotime($arrival_time));
        
        $check = $conn->prepare("SELECT t.trip_id FROM tbl_trip_logs t 
                                INNER JOIN tbl_vehicles v ON t.vehicle_id = v.vehicle_id 
                                WHERE t.trip_id = ? AND v.assigned_driver_id = ?");
        $check->bind_param("ii", $trip_id, $current_user_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $update = $conn->prepare("UPDATE tbl_trip_logs SET arrival_time = ? WHERE trip_id = ?");
            $update->bind_param("si", $arrival_time_only, $trip_id);
            
            if ($update->execute()) {
                $success_message = "Trip marked as completed!";
                logActivity($conn, $current_user_id, "Completed trip", "tbl_trip_logs", $trip_id, "");
            } else {
                $error_message = "Error updating trip: " . $update->error;
            }
            $update->close();
        } else {
            $error_message = "Unauthorized to update this trip";
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

// Get trip logs - ONLY if driver has assigned vehicle
$trip_logs = [];
if ($has_assigned_vehicle) {
    $logs_sql = "SELECT t.*, v.plate_number, v.brand, v.model, v.vehicle_type
                 FROM tbl_trip_logs t
                 INNER JOIN tbl_vehicles v ON t.vehicle_id = v.vehicle_id
                 WHERE v.assigned_driver_id = ?
                 ORDER BY t.trip_date DESC, t.departure_time DESC
                 LIMIT 50";
    $stmt = $conn->prepare($logs_sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $trip_logs[] = $row;
    }
    $stmt->close();
}

$total_trips = count($trip_logs);
$completed_trips = count(array_filter($trip_logs, fn($log) => $log['arrival_time'] !== null));
$in_progress = $total_trips - $completed_trips;

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
                        <i class="fas fa-route me-2"></i>My Trip Logs
                    </h2>
                    <p class="text-muted">Track your vehicle trips and journeys</p>
                </div>
                <?php if ($has_assigned_vehicle): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTripModal">
                    <i class="fas fa-plus me-2"></i>Log New Trip
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
                <p class="mb-2">You currently don't have any vehicle assigned to you. Trip logging is not available until a vehicle is assigned by the administrator.</p>
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
            <i class="fas fa-car-side fa-4x text-muted mb-3"></i>
            <h5 class="text-muted">Waiting for Vehicle Assignment</h5>
            <p class="text-muted">Once a vehicle is assigned to you, you'll be able to:</p>
            <div class="row justify-content-center mt-4">
                <div class="col-md-3">
                    <i class="fas fa-route fa-2x text-primary mb-2"></i>
                    <p class="small">Log Trips</p>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-gas-pump fa-2x text-success mb-2"></i>
                    <p class="small">Track Fuel</p>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-wrench fa-2x text-warning mb-2"></i>
                    <p class="small">Report Maintenance</p>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="text-primary mb-0"><?php echo $total_trips; ?></h3>
                            <p class="text-muted mb-0">Total Trips</p>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-route fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="text-success mb-0"><?php echo $completed_trips; ?></h3>
                            <p class="text-muted mb-0">Completed</p>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="text-warning mb-0"><?php echo $in_progress; ?></h3>
                            <p class="text-muted mb-0">In Progress</p>
                        </div>
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                            <i class="fas fa-clock fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Trip Logs Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5 class="mb-3"><i class="fas fa-list me-2"></i>Trip History</h5>
            <?php if (empty($trip_logs)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-route fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">No trip logs yet</h5>
                    <p class="text-muted">Start logging your trips by clicking "Log New Trip"</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Vehicle</th>
                                <th>Route</th>
                                <th>Purpose</th>
                                <th>Distance</th>
                                <th>Passengers</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trip_logs as $log): ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('M d, Y', strtotime($log['trip_date'])); ?></strong><br>
                                    <small class="text-muted">
                                        <?php echo date('g:i A', strtotime($log['departure_time'])); ?>
                                        <?php if ($log['arrival_time']): ?>
                                            - <?php echo date('g:i A', strtotime($log['arrival_time'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['plate_number']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($log['vehicle_type']); ?></small>
                                </td>
                                <td>
                                    <div><i class="fas fa-circle text-success" style="font-size: 0.5rem;"></i> <?php echo htmlspecialchars($log['origin']); ?></div>
                                    <div class="text-muted small">↓</div>
                                    <div><i class="fas fa-map-marker-alt text-danger" style="font-size: 0.7rem;"></i> <?php echo htmlspecialchars($log['destination']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($log['purpose']); ?></td>
                                <td>
                                    <?php if ($log['distance_km']): ?>
                                        <strong><?php echo number_format($log['distance_km'], 1); ?> km</strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $log['passengers']; ?></td>
                                <td>
                                    <?php if ($log['arrival_time']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Completed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> In Progress</span>
                                    <?php endif; ?>
                                </td>
   <td>
    <?php if (!$log['arrival_time']): ?>
        <button class="btn btn-sm btn-success" onclick="completeTrip(<?php echo $log['trip_id']; ?>)">
            <i class="fas fa-check me-1"></i>Complete
        </button>
    <?php else: ?>
        <a href="view-trip-log.php?id=<?php echo $log['trip_id']; ?>" class="btn btn-sm btn-info">
            <i class="fas fa-eye"></i> View
        </a>
    <?php endif; ?>
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
<!-- Add Trip Modal -->
<div class="modal fade" id="addTripModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-route me-2"></i>Log New Trip
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_trip">
                    
                    <div class="mb-3">
                        <label class="form-label">Vehicle *</label>
                        <select name="vehicle_id" class="form-select" required>
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
                            <label class="form-label">Destination *</label>
                            <input type="text" name="destination" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Purpose *</label>
                            <input type="text" name="purpose" class="form-control" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Departure Time *</label>
                            <input type="datetime-local" name="departure_time" class="form-control" 
                                   value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Arrival Time</label>
                            <input type="datetime-local" name="arrival_time" class="form-control">
                            <small class="text-muted">Leave empty if trip is ongoing</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Distance (km)</label>
                            <input type="number" name="distance" class="form-control" step="0.1" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Number of Passengers *</label>
                            <input type="number" name="passengers" class="form-control" min="0" value="0" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Log Trip
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Trip Modal -->
<div class="modal fade" id="completeTripModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Complete Trip
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="complete_trip">
                    <input type="hidden" name="trip_id" id="complete_trip_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Arrival Time *</label>
                        <input type="datetime-local" name="arrival_time" class="form-control" 
                               value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Complete Trip
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Trip Modal -->
<div class="modal fade" id="viewTripModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Trip Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Vehicle</label>
                        <p class="fw-bold" id="view_vehicle_info"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Trip Date</label>
                        <p class="fw-bold" id="view_trip_date"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Departure Time</label>
                        <p class="fw-bold" id="view_departure_time"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Arrival Time</label>
                        <p class="fw-bold" id="view_arrival_time"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Origin</label>
                        <p class="fw-bold" id="view_origin"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">Destination</label>
                        <p class="fw-bold" id="view_destination"></p>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="text-muted small">Purpose</label>
                    <p class="fw-bold" id="view_purpose"></p>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Distance</label>
                        <p class="fw-bold" id="view_distance"></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Passengers</label>
                        <p class="fw-bold" id="view_passengers"></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="text-muted small">Status</label>
                        <p class="fw-bold" id="view_status"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function completeTrip(tripId) {
    document.getElementById('complete_trip_id').value = tripId;
    const modal = new bootstrap.Modal(document.getElementById('completeTripModal'));
    modal.show();
}

function viewTrip(trip) {
    document.getElementById('view_vehicle_info').textContent = trip.plate_number + ' - ' + trip.brand + ' ' + trip.model;
    document.getElementById('view_trip_date').textContent = new Date(trip.trip_date).toLocaleDateString();
    document.getElementById('view_departure_time').textContent = formatTime(trip.departure_time);
    document.getElementById('view_arrival_time').textContent = trip.arrival_time ? formatTime(trip.arrival_time) : 'Ongoing';
    document.getElementById('view_origin').textContent = trip.origin;
    document.getElementById('view_destination').textContent = trip.destination;
    document.getElementById('view_purpose').textContent = trip.purpose;
    document.getElementById('view_distance').textContent = trip.distance_km ? trip.distance_km.toFixed(1) + ' km' : 'N/A';
    document.getElementById('view_passengers').textContent = trip.passengers;
    document.getElementById('view_status').innerHTML = trip.arrival_time 
        ? '<span class="badge bg-success">Completed</span>' 
        : '<span class="badge bg-warning text-dark">Ongoing</span>';
    
    new bootstrap.Modal(document.getElementById('viewTripModal')).show();
}

function formatTime(timeString) {
    if (!timeString) return '';
    const [hours, minutes] = timeString.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
}
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>