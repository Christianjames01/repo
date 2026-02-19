<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Only admin/staff can access
requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Staff', 'Secretary']);

$current_user_id = getCurrentUserId();
$page_title = 'Vehicle Management';

// Handle delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $vehicle_id = intval($_GET['id']);
    $delete_stmt = $conn->prepare("DELETE FROM tbl_vehicles WHERE vehicle_id = ?");
    $delete_stmt->bind_param("i", $vehicle_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success_message'] = 'Vehicle deleted successfully!';
    } else {
        $_SESSION['error_message'] = 'Failed to delete vehicle.';
    }
    $delete_stmt->close();
    header('Location: manage-vehicles.php');
    exit();
}

// Fetch all vehicles with driver information
$sql = "SELECT v.*, 
        CONCAT(r.first_name, ' ', r.last_name) as driver_name,
        r.contact_number as driver_contact
        FROM tbl_vehicles v
        LEFT JOIN tbl_residents r ON v.driver_id = r.resident_id
        ORDER BY v.created_at DESC";

$result = $conn->query($sql);
$vehicles = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="mb-0">
                <i class="fas fa-car me-2"></i>Vehicle Management
            </h2>
            <p class="text-muted">Manage barangay vehicles and assignments</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="add-vehicle.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i>Add New Vehicle
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php 
        echo htmlspecialchars($_SESSION['success_message']); 
        unset($_SESSION['success_message']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php 
        echo htmlspecialchars($_SESSION['error_message']); 
        unset($_SESSION['error_message']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Vehicles Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Vehicles</p>
                            <h3 class="mb-0"><?php echo count($vehicles); ?></h3>
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
                            <p class="text-muted mb-1">Active</p>
                            <h3 class="mb-0">
                                <?php echo count(array_filter($vehicles, function($v) { return $v['status'] === 'Active'; })); ?>
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
                            <p class="text-muted mb-1">Under Maintenance</p>
                            <h3 class="mb-0">
                                <?php echo count(array_filter($vehicles, function($v) { return $v['status'] === 'Maintenance'; })); ?>
                            </h3>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-wrench fa-2x"></i>
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
                            <p class="text-muted mb-1">Inactive</p>
                            <h3 class="mb-0">
                                <?php echo count(array_filter($vehicles, function($v) { return $v['status'] === 'Inactive'; })); ?>
                            </h3>
                        </div>
                        <div class="text-danger">
                            <i class="fas fa-ban fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Vehicles Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h5 class="mb-0">Vehicle List</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="vehiclesTable">
                    <thead class="table-light">
                        <tr>
                            <th>Vehicle ID</th>
                            <th>Vehicle Type</th>
                            <th>Plate Number</th>
                            <th>Model/Brand</th>
                            <th>Driver Assigned</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehicles)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="fas fa-car fa-3x mb-3 d-block"></i>
                                <p class="mb-0">No vehicles found. Add your first vehicle!</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <tr>
                                <td>#<?php echo str_pad($vehicle['vehicle_id'], 4, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <i class="fas <?php 
                                        echo $vehicle['vehicle_type'] === 'Car' ? 'fa-car' : 
                                            ($vehicle['vehicle_type'] === 'Motorcycle' ? 'fa-motorcycle' : 
                                            ($vehicle['vehicle_type'] === 'Truck' ? 'fa-truck' : 'fa-bus'));
                                    ?> me-2"></i>
                                    <?php echo htmlspecialchars($vehicle['vehicle_type']); ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($vehicle['plate_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($vehicle['model'] . ' ' . $vehicle['brand']); ?></td>
                                <td>
                                    <?php if ($vehicle['driver_name']): ?>
                                        <div>
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($vehicle['driver_name']); ?>
                                        </div>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i>
                                            <?php echo htmlspecialchars($vehicle['driver_contact'] ?? 'N/A'); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">No driver assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'Active' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
                                        'Inactive' => '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Inactive</span>',
                                        'Maintenance' => '<span class="badge bg-warning text-dark"><i class="fas fa-wrench me-1"></i>Maintenance</span>'
                                    ];
                                    echo $status_badges[$vehicle['status']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($vehicle['status']) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="view-vehicle.php?id=<?php echo $vehicle['vehicle_id']; ?>" 
                                           class="btn btn-outline-primary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit-vehicle.php?id=<?php echo $vehicle['vehicle_id']; ?>" 
                                           class="btn btn-outline-warning" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?delete=1&id=<?php echo $vehicle['vehicle_id']; ?>" 
                                           class="btn btn-outline-danger" 
                                           title="Delete"
                                           onclick="return confirm('Are you sure you want to delete this vehicle?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    transition: transform 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
}

.table tbody tr {
    transition: background-color 0.2s ease;
}

.table tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts
    var alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });

    // Initialize DataTable if available
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#vehiclesTable').DataTable({
            "pageLength": 10,
            "order": [[0, "desc"]],
            "language": {
                "search": "Search vehicles:",
                "lengthMenu": "Show _MENU_ vehicles per page",
                "info": "Showing _START_ to _END_ of _TOTAL_ vehicles",
                "emptyTable": "No vehicles available"
            }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>