<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireRole('Driver');

$current_user_id = getCurrentUserId();

// Get trip ID from URL
$trip_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($trip_id === 0) {
    $_SESSION['error'] = "Invalid trip ID.";
    header('Location: trip-log.php');
    exit();
}

// Fetch trip details - ONLY if it belongs to this driver's vehicle
$trip_sql = "SELECT t.*, v.plate_number, v.brand, v.model, v.vehicle_type
             FROM tbl_trip_logs t
             INNER JOIN tbl_vehicles v ON t.vehicle_id = v.vehicle_id
             WHERE t.trip_id = ? AND v.assigned_driver_id = ?";

$stmt = $conn->prepare($trip_sql);
$stmt->bind_param("ii", $trip_id, $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Trip not found or you don't have permission to view it.";
    header('Location: trip-log.php');
    exit();
}

$trip = $result->fetch_assoc();
$stmt->close();

$page_title = 'View Trip Details';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <a href="trip-log.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to My Trip Logs
            </a>
            <h2><i class="fas fa-info-circle me-2"></i>Trip Details</h2>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-route me-2"></i>Trip Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Trip ID</label>
                            <p class="fw-bold">#<?php echo $trip['trip_id']; ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Status</label>
                            <p class="fw-bold">
                                <?php if ($trip['arrival_time']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check"></i> Completed</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> In Progress</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="text-muted small">Vehicle</label>
                            <p class="fw-bold">
                                <i class="fas fa-car me-2"></i><?php echo htmlspecialchars($trip['plate_number']); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($trip['brand'] . ' ' . $trip['model'] . ' - ' . $trip['vehicle_type']); ?></small>
                            </p>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="text-muted small">Trip Date</label>
                            <p class="fw-bold"><i class="fas fa-calendar me-2"></i><?php echo date('F d, Y', strtotime($trip['trip_date'])); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Departure Time</label>
                            <p class="fw-bold"><i class="fas fa-clock me-2"></i><?php echo date('h:i A', strtotime($trip['departure_time'])); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Arrival Time</label>
                            <p class="fw-bold">
                                <?php if ($trip['arrival_time']): ?>
                                    <i class="fas fa-clock me-2"></i><?php echo date('h:i A', strtotime($trip['arrival_time'])); ?>
                                <?php else: ?>
                                    <span class="text-warning">Ongoing</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Origin</label>
                            <p class="fw-bold"><i class="fas fa-map-marker-alt text-success me-2"></i><?php echo htmlspecialchars($trip['origin']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Destination</label>
                            <p class="fw-bold"><i class="fas fa-map-marker-alt text-danger me-2"></i><?php echo htmlspecialchars($trip['destination']); ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="text-muted small">Purpose</label>
                            <p class="fw-bold"><i class="fas fa-clipboard me-2"></i><?php echo htmlspecialchars($trip['purpose']); ?></p>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Distance Traveled</label>
                            <p class="fw-bold">
                                <?php if ($trip['distance_km']): ?>
                                    <i class="fas fa-road me-2"></i><?php echo number_format($trip['distance_km'], 1); ?> km
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Number of Passengers</label>
                            <p class="fw-bold"><i class="fas fa-users me-2"></i><?php echo $trip['passengers']; ?></p>
                        </div>
                    </div>

                    <?php if (!empty($trip['notes'])): ?>
                    <hr>
                    <div class="row">
                        <div class="col-12">
                            <label class="text-muted small">Notes</label>
                            <p class="fw-bold"><i class="fas fa-sticky-note me-2"></i><?php echo nl2br(htmlspecialchars($trip['notes'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Quick Actions -->
            <?php if (!$trip['arrival_time']): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                </div>
                <div class="card-body">
                    <button class="btn btn-success w-100" onclick="completeTrip(<?php echo $trip['trip_id']; ?>)">
                        <i class="fas fa-check me-2"></i>Complete This Trip
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Trip Summary -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Trip Summary</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Duration:</span>
                        <strong>
                            <?php 
                            if ($trip['arrival_time']) {
                                $departure = new DateTime($trip['trip_date'] . ' ' . $trip['departure_time']);
                                $arrival = new DateTime($trip['trip_date'] . ' ' . $trip['arrival_time']);
                                $duration = $departure->diff($arrival);
                                echo $duration->format('%h hours %i minutes');
                            } else {
                                echo '<span class="text-warning">Ongoing</span>';
                            }
                            ?>
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Distance:</span>
                        <strong><?php echo $trip['distance_km'] ? number_format($trip['distance_km'], 1) . ' km' : 'N/A'; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Passengers:</span>
                        <strong><?php echo $trip['passengers']; ?></strong>
                    </div>
                </div>
            </div>
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
            <form method="POST" action="trip-log.php">
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

<script>
function completeTrip(tripId) {
    document.getElementById('complete_trip_id').value = tripId;
    const modal = new bootstrap.Modal(document.getElementById('completeTripModal'));
    modal.show();
}
</script>

<?php include '../../includes/footer.php'; ?>