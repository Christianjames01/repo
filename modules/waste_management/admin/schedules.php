<?php
require_once('../../../config/config.php');

// Check if user is logged in and has appropriate role
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Waste Collection Schedules";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $area_zone = sanitize($_POST['area_zone']);
                $purok = sanitize($_POST['purok']);
                $waste_type = sanitize($_POST['waste_type']);
                $collection_day = sanitize($_POST['collection_day']);
                $collection_time = sanitize($_POST['collection_time']);
                $collector_name = sanitize($_POST['collector_name']);
                $truck_number = sanitize($_POST['truck_number']);
                $notes = sanitize($_POST['notes']);
                
                $sql = "INSERT INTO tbl_waste_schedules 
                        (area_zone, purok, waste_type, collection_day, collection_time, collector_name, truck_number, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                if (execute($conn, $sql, [
                    $area_zone, $purok, $waste_type, $collection_day, $collection_time, 
                    $collector_name, $truck_number, $notes, $_SESSION['user_id']
                ], 'ssssssssi')) {
                    setMessage("Collection schedule added successfully!", "success");
                } else {
                    setMessage("Failed to add collection schedule.", "danger");
                }
                header("Location: schedules.php");
                exit();
                break;
                
            case 'edit':
                $schedule_id = (int)$_POST['schedule_id'];
                $area_zone = sanitize($_POST['area_zone']);
                $purok = sanitize($_POST['purok']);
                $waste_type = sanitize($_POST['waste_type']);
                $collection_day = sanitize($_POST['collection_day']);
                $collection_time = sanitize($_POST['collection_time']);
                $collector_name = sanitize($_POST['collector_name']);
                $truck_number = sanitize($_POST['truck_number']);
                $status = sanitize($_POST['status']);
                $notes = sanitize($_POST['notes']);
                
                $sql = "UPDATE tbl_waste_schedules 
                        SET area_zone = ?, purok = ?, waste_type = ?, collection_day = ?, collection_time = ?, 
                            collector_name = ?, truck_number = ?, status = ?, notes = ? 
                        WHERE schedule_id = ?";
                
                if (execute($conn, $sql, [
                    $area_zone, $purok, $waste_type, $collection_day, $collection_time, 
                    $collector_name, $truck_number, $status, $notes, $schedule_id
                ], 'sssssssssi')) {
                    setMessage("Collection schedule updated successfully!", "success");
                } else {
                    setMessage("Failed to update collection schedule.", "danger");
                }
                header("Location: schedules.php");
                exit();
                break;
                
            case 'delete':
                $schedule_id = (int)$_POST['schedule_id'];
                
                if ($schedule_id <= 0) {
                    setMessage("Invalid schedule ID.", "danger");
                    header("Location: schedules.php");
                    exit();
                }
                
                $sql = "DELETE FROM tbl_waste_schedules WHERE schedule_id = ?";
                
                if (execute($conn, $sql, [$schedule_id], 'i')) {
                    setMessage("Collection schedule deleted successfully!", "success");
                } else {
                    setMessage("Failed to delete collection schedule.", "danger");
                }
                header("Location: schedules.php");
                exit();
                break;
        }
    }
}

// Get all schedules
$schedules = fetchAll($conn, 
    "SELECT * FROM tbl_waste_schedules 
     ORDER BY 
        FIELD(collection_day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
        collection_time ASC", 
    [], ''
);

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i><?php echo $page_title; ?></h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
            <i class="fas fa-plus me-1"></i>Add Schedule
        </button>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Schedules Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Collection Schedules</h6>
        </div>
        <div class="card-body">
            <?php if (empty($schedules)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>No collection schedules found. Add your first schedule to get started!
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="schedulesTable">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Area/Zone</th>
                                <th>Purok</th>
                                <th>Waste Type</th>
                                <th>Day</th>
                                <th>Time</th>
                                <th>Collector</th>
                                <th>Truck</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><?php echo (int)$schedule['schedule_id']; ?></td>
                                <td><?php echo htmlspecialchars($schedule['area_zone']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['purok'] ?? 'All'); ?></td>
                                <td>
                                    <?php 
                                    $type_badges = [
                                        'biodegradable' => 'success',
                                        'non-biodegradable' => 'danger',
                                        'recyclable' => 'info',
                                        'hazardous' => 'warning',
                                        'mixed' => 'secondary'
                                    ];
                                    $badge_class = $type_badges[$schedule['waste_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_class; ?>">
                                        <?php echo ucfirst($schedule['waste_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($schedule['collection_day']); ?></td>
                                <td><?php echo date('g:i A', strtotime($schedule['collection_time'])); ?></td>
                                <td><?php echo htmlspecialchars($schedule['collector_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($schedule['truck_number'] ?? '-'); ?></td>
                                <td>
                                    <?php 
                                    $status_badges = [
                                        'active' => 'success',
                                        'inactive' => 'secondary',
                                        'suspended' => 'danger'
                                    ];
                                    $status_class = $status_badges[$schedule['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo ucfirst($schedule['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" 
                                            onclick="viewSchedule(<?php echo htmlspecialchars(json_encode($schedule), ENT_QUOTES, 'UTF-8'); ?>)"
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="editSchedule(<?php echo htmlspecialchars(json_encode($schedule), ENT_QUOTES, 'UTF-8'); ?>)"
                                            title="Edit Schedule">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="confirmDelete(<?php echo (int)$schedule['schedule_id']; ?>, '<?php echo htmlspecialchars($schedule['area_zone'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($schedule['collection_day'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($schedule['collection_time'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($schedule['waste_type'], ENT_QUOTES, 'UTF-8'); ?>')"
                                            title="Delete Schedule">
                                        <i class="fas fa-trash"></i>
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
</div>

<!-- Add Schedule Modal -->
<div class="modal fade" id="addScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="schedules.php">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Collection Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Area/Zone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="area_zone" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Purok</label>
                            <input type="text" class="form-control" name="purok" placeholder="Leave blank for all puroks">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Waste Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="waste_type" required>
                                <option value="">Select Type</option>
                                <option value="biodegradable">Biodegradable</option>
                                <option value="non-biodegradable">Non-Biodegradable</option>
                                <option value="recyclable">Recyclable</option>
                                <option value="hazardous">Hazardous</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collection Day <span class="text-danger">*</span></label>
                            <select class="form-select" name="collection_day" required>
                                <option value="">Select Day</option>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                                <option value="Sunday">Sunday</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collection Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="collection_time" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collector Name</label>
                            <input type="text" class="form-control" name="collector_name">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Truck Number</label>
                            <input type="text" class="form-control" name="truck_number">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Add Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Schedule Modal -->
<div class="modal fade" id="editScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="schedules.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="schedule_id" id="edit_schedule_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Collection Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Area/Zone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="area_zone" id="edit_area_zone" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Purok</label>
                            <input type="text" class="form-control" name="purok" id="edit_purok">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Waste Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="waste_type" id="edit_waste_type" required>
                                <option value="biodegradable">Biodegradable</option>
                                <option value="non-biodegradable">Non-Biodegradable</option>
                                <option value="recyclable">Recyclable</option>
                                <option value="hazardous">Hazardous</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collection Day <span class="text-danger">*</span></label>
                            <select class="form-select" name="collection_day" id="edit_collection_day" required>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                                <option value="Sunday">Sunday</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collection Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="collection_time" id="edit_collection_time" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collector Name</label>
                            <input type="text" class="form-control" name="collector_name" id="edit_collector_name">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Truck Number</label>
                            <input type="text" class="form-control" name="truck_number" id="edit_truck_number">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" id="edit_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Schedule Modal -->
<div class="modal fade" id="viewScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-check me-2"></i>Schedule Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewScheduleContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="schedules.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="schedule_id" id="delete_schedule_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-circle fa-2x me-3"></i>
                        <div>
                            <strong>Warning!</strong> You are about to permanently delete this collection schedule.
                        </div>
                    </div>
                    
                    <p class="mb-3">Are you sure you want to delete this collection schedule?</p>
                    
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-3 text-muted">Schedule Details:</h6>
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td width="35%" class="text-muted"><strong>Schedule ID:</strong></td>
                                    <td><span id="delete_info_id" class="badge bg-secondary"></span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Area/Zone:</strong></td>
                                    <td id="delete_info_area"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Collection Day:</strong></td>
                                    <td id="delete_info_day"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Collection Time:</strong></td>
                                    <td id="delete_info_time"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Waste Type:</strong></td>
                                    <td id="delete_info_waste"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> This action cannot be undone. All associated data will be permanently removed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Yes, Delete Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatTime(timeString) {
    if (!timeString) return 'N/A';
    try {
        const [hours, minutes] = timeString.split(':');
        const hour = parseInt(hours);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 || 12;
        return `${displayHour}:${minutes} ${ampm}`;
    } catch (e) {
        return timeString;
    }
}

// ============================================
// DELETE CONFIRMATION FUNCTION
// ============================================
function confirmDelete(id, area, day, time, waste) {
    console.log('confirmDelete called with:', { id, area, day, time, waste });
    
    // Validate ID
    if (!id || id === 0) {
        alert('Error: Invalid schedule ID');
        return false;
    }
    
    // Populate hidden input
    document.getElementById('delete_schedule_id').value = id;
    
    // Populate display fields
    document.getElementById('delete_info_id').textContent = id;
    document.getElementById('delete_info_area').textContent = area || 'N/A';
    document.getElementById('delete_info_day').textContent = day || 'N/A';
    document.getElementById('delete_info_time').textContent = time ? formatTime(time) : 'N/A';
    
    // Format waste type with badge
    const wasteBadges = {
        'biodegradable': 'success',
        'non-biodegradable': 'danger',
        'recyclable': 'info',
        'hazardous': 'warning',
        'mixed': 'secondary'
    };
    const badgeClass = wasteBadges[waste] || 'secondary';
    document.getElementById('delete_info_waste').innerHTML = 
        `<span class="badge bg-${badgeClass}">${capitalizeFirst(waste || 'unknown')}</span>`;
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteScheduleModal'));
    modal.show();
    
    return false;
}

// ============================================
// VIEW SCHEDULE FUNCTION
// ============================================
function viewSchedule(schedule) {
    console.log('View schedule:', schedule);
    
    const typeBadges = {
        'biodegradable': 'success',
        'non-biodegradable': 'danger',
        'recyclable': 'info',
        'hazardous': 'warning',
        'mixed': 'secondary'
    };
    
    const statusBadges = {
        'active': 'success',
        'inactive': 'secondary',
        'suspended': 'danger'
    };
    
    const content = `
        <div class="row">
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr><th width="40%">Schedule ID:</th><td>${schedule.schedule_id}</td></tr>
                    <tr><th>Area/Zone:</th><td>${escapeHtml(schedule.area_zone)}</td></tr>
                    <tr><th>Purok:</th><td>${escapeHtml(schedule.purok || 'All')}</td></tr>
                    <tr><th>Waste Type:</th><td><span class="badge bg-${typeBadges[schedule.waste_type] || 'secondary'}">${capitalizeFirst(schedule.waste_type)}</span></td></tr>
                    <tr><th>Collection Day:</th><td><strong>${schedule.collection_day}</strong></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr><th width="40%">Time:</th><td><strong>${formatTime(schedule.collection_time)}</strong></td></tr>
                    <tr><th>Collector:</th><td>${escapeHtml(schedule.collector_name || 'Not Assigned')}</td></tr>
                    <tr><th>Truck Number:</th><td>${escapeHtml(schedule.truck_number || 'Not Assigned')}</td></tr>
                    <tr><th>Status:</th><td><span class="badge bg-${statusBadges[schedule.status] || 'secondary'}">${capitalizeFirst(schedule.status)}</span></td></tr>
                </table>
            </div>
        </div>
        ${schedule.notes ? `
        <div class="row mt-3">
            <div class="col-12">
                <h6 class="text-muted">Notes:</h6>
                <p class="border-start border-primary border-3 ps-3">${escapeHtml(schedule.notes)}</p>
            </div>
        </div>
        ` : ''}
    `;
    
    document.getElementById('viewScheduleContent').innerHTML = content;
    const modal = new bootstrap.Modal(document.getElementById('viewScheduleModal'));
    modal.show();
}

// ============================================
// EDIT SCHEDULE FUNCTION
// ============================================
function editSchedule(schedule) {
    console.log('Edit schedule:', schedule);
    
    document.getElementById('edit_schedule_id').value = schedule.schedule_id;
    document.getElementById('edit_area_zone').value = schedule.area_zone;
    document.getElementById('edit_purok').value = schedule.purok || '';
    document.getElementById('edit_waste_type').value = schedule.waste_type;
    document.getElementById('edit_collection_day').value = schedule.collection_day;
    document.getElementById('edit_collection_time').value = schedule.collection_time;
    document.getElementById('edit_collector_name').value = schedule.collector_name || '';
    document.getElementById('edit_truck_number').value = schedule.truck_number || '';
    document.getElementById('edit_status').value = schedule.status;
    document.getElementById('edit_notes').value = schedule.notes || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editScheduleModal'));
    modal.show();
}

// ============================================
// DATATABLE INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded - initializing DataTable...');
    
    // Initialize DataTable if jQuery and DataTables are available
    if (typeof $ !== 'undefined' && typeof $.fn.DataTable !== 'undefined' && $('#schedulesTable').length) {
        $('#schedulesTable').DataTable({
            "order": [[4, "asc"], [5, "asc"]],
            "pageLength": 25,
            "language": {
                "emptyTable": "No schedules available"
            }
        });
        console.log('DataTable initialized successfully');
    } else {
        console.log('DataTable not initialized - jQuery or DataTables not available');
    }
});
</script>

<?php require_once '../../../includes/footer.php'; ?>