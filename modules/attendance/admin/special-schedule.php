<?php
/**
 * Special Schedule Management
 * modules/attendance/admin/special-schedule.php
 * Manage special schedules for holidays, events, and custom duty arrangements
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Special Schedule Management';
$current_user_id = getCurrentUserId();

// Handle Create Special Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule'])) {
    $schedule_name = sanitizeInput($_POST['schedule_name']);
    $schedule_type = sanitizeInput($_POST['schedule_type']);
    $schedule_date = sanitizeInput($_POST['schedule_date']);
    $description = sanitizeInput($_POST['description']);
    $is_working_day = isset($_POST['is_working_day']) ? 1 : 0;
    $custom_time_in = !empty($_POST['custom_time_in']) ? sanitizeInput($_POST['custom_time_in']) : null;
    $custom_time_out = !empty($_POST['custom_time_out']) ? sanitizeInput($_POST['custom_time_out']) : null;
    
    $sql = "INSERT INTO tbl_special_schedules (schedule_name, schedule_type, schedule_date, description, 
            is_working_day, custom_time_in, custom_time_out, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    if (executeQuery($conn, $sql, [$schedule_name, $schedule_type, $schedule_date, $description, 
                                    $is_working_day, $custom_time_in, $custom_time_out, $current_user_id], 
                     'ssssissi')) {
        logActivity($conn, $current_user_id, "Created special schedule: $schedule_name", 'tbl_special_schedules');
        $_SESSION['success_message'] = 'Special schedule created successfully';
    } else {
        $_SESSION['error_message'] = 'Failed to create special schedule';
    }
    
    header("Location: special-schedule.php");
    exit();
}

// Handle Assign Staff to Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_staff'])) {
    $schedule_id = intval($_POST['schedule_id']);
    $selected_users = $_POST['selected_users'] ?? [];
    
    $success_count = 0;
    foreach ($selected_users as $user_id) {
        $user_id = intval($user_id);
        
        // Check if already assigned
        $existing = fetchOne($conn,
            "SELECT id FROM tbl_special_schedule_assignments WHERE schedule_id = ? AND user_id = ?",
            [$schedule_id, $user_id], 'ii'
        );
        
        if (!$existing) {
            $sql = "INSERT INTO tbl_special_schedule_assignments (schedule_id, user_id, created_by) 
                    VALUES (?, ?, ?)";
            if (executeQuery($conn, $sql, [$schedule_id, $user_id, $current_user_id], 'iii')) {
                $success_count++;
            }
        }
    }
    
    if ($success_count > 0) {
        logActivity($conn, $current_user_id, "Assigned $success_count staff to special schedule", 'tbl_special_schedule_assignments');
        $_SESSION['success_message'] = "Successfully assigned $success_count staff member(s)";
    } else {
        $_SESSION['error_message'] = "No new assignments were created";
    }
    
    header("Location: special-schedule.php");
    exit();
}

// Handle Delete Schedule
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $schedule_id = intval($_GET['delete']);
    
    // Delete assignments first
  executeQuery($conn, "DELETE FROM tbl_special_schedule_assignments WHERE schedule_id = ?", [$schedule_id], 'i');
    
    // Delete schedule
    if (executeQuery($conn, "DELETE FROM tbl_special_schedules WHERE schedule_id = ?", [$schedule_id], 'i')) {
        logActivity($conn, $current_user_id, "Deleted special schedule", 'tbl_special_schedules', $schedule_id);
        $_SESSION['success_message'] = 'Special schedule deleted successfully';
    } else {
        $_SESSION['error_message'] = 'Failed to delete special schedule';
    }
    
    header("Location: special-schedule.php");
    exit();
}

// Get all special schedules
$schedules = fetchAll($conn,
    "SELECT ss.*, 
            COUNT(DISTINCT ssa.user_id) as assigned_count,
            CONCAT(cr.first_name, ' ', cr.last_name) as created_by_name
     FROM tbl_special_schedules ss
     LEFT JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id = ssa.schedule_id
     LEFT JOIN tbl_users cu ON ss.created_by = cu.user_id
     LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
     GROUP BY ss.schedule_id
     ORDER BY ss.schedule_date DESC"
);

// Get all active staff
$staff = fetchAll($conn,
    "SELECT u.user_id, u.username, u.role, 
            CONCAT(r.first_name, ' ', r.last_name) as full_name,
            r.profile_photo
     FROM tbl_users u
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
     WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
     ORDER BY u.role, r.last_name"
);

include '../../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-alt text-primary me-2"></i>
                Special Schedule Management
            </h1>
            <p class="text-muted mb-0">Manage holidays, events, and custom duty schedules</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Attendance
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createScheduleModal">
                <i class="fas fa-plus me-1"></i> Create Special Schedule
            </button>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Schedule Types Info -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="fs-1 text-danger mb-2">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <h6 class="fw-bold">Holiday</h6>
                    <p class="text-muted small mb-0">Regular or special holidays</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="fs-1 text-success mb-2">
                        <i class="fas fa-star"></i>
                    </div>
                    <h6 class="fw-bold">Special Event</h6>
                    <p class="text-muted small mb-0">Community events, festivals</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="fs-1 text-warning mb-2">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h6 class="fw-bold">Emergency</h6>
                    <p class="text-muted small mb-0">Disaster response, emergencies</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="fs-1 text-info mb-2">
                        <i class="fas fa-cog"></i>
                    </div>
                    <h6 class="fw-bold">Custom</h6>
                    <p class="text-muted small mb-0">Custom duty arrangements</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Schedules List -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <i class="fas fa-list text-primary me-2"></i>
                Special Schedules
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Schedule Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Assigned Staff</th>
                            <th>Description</th>
                            <th width="200">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($schedules)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-calendar-plus fa-3x mb-3 d-block"></i>
                                    <p class="mb-0">No special schedules created yet</p>
                                    <small>Click "Create Special Schedule" to add one</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($schedules as $schedule): 
                                $is_past = strtotime($schedule['schedule_date']) < strtotime('today');
                                $is_today = $schedule['schedule_date'] === date('Y-m-d');
                            ?>
                                <tr class="<?php echo $is_today ? 'table-primary' : ''; ?>">
                                    <td>
                                        <strong><?php echo formatDate($schedule['schedule_date']); ?></strong>
                                        <?php if ($is_today): ?>
                                            <span class="badge bg-primary ms-1">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($schedule['schedule_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            Created by <?php echo htmlspecialchars($schedule['created_by_name']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $type_icons = [
                                            'Holiday' => '<i class="fas fa-calendar-times text-danger"></i>',
                                            'Special Event' => '<i class="fas fa-star text-success"></i>',
                                            'Emergency' => '<i class="fas fa-exclamation-triangle text-warning"></i>',
                                            'Custom' => '<i class="fas fa-cog text-info"></i>'
                                        ];
                                        echo $type_icons[$schedule['schedule_type']] ?? '';
                                        ?>
                                        <span class="ms-1"><?php echo $schedule['schedule_type']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($schedule['is_working_day']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-briefcase me-1"></i> Working Day
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-moon me-1"></i> Non-Working
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($schedule['custom_time_in'] || $schedule['custom_time_out']): ?>
                                            <small>
                                                <?php if ($schedule['custom_time_in']): ?>
                                                    <i class="fas fa-sign-in-alt text-success"></i>
                                                    <?php echo date('h:i A', strtotime($schedule['custom_time_in'])); ?>
                                                <?php endif; ?>
                                                <?php if ($schedule['custom_time_out']): ?>
                                                    <br>
                                                    <i class="fas fa-sign-out-alt text-danger"></i>
                                                    <?php echo date('h:i A', strtotime($schedule['custom_time_out'])); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary fs-6">
                                            <?php echo $schedule['assigned_count']; ?> staff
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            $desc = $schedule['description'];
                                            echo strlen($desc) > 50 ? substr($desc, 0, 50) . '...' : $desc;
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-info" 
                                                    onclick="viewSchedule(<?php echo htmlspecialchars(json_encode($schedule)); ?>)"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-success" 
                                                    onclick="assignStaff(<?php echo $schedule['schedule_id']; ?>, '<?php echo htmlspecialchars($schedule['schedule_name']); ?>')"
                                                    title="Assign Staff">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                            <?php if (!$is_past): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="showDeleteModal(<?php echo $schedule['schedule_id']; ?>, '<?php echo htmlspecialchars($schedule['schedule_name']); ?>')"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
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

<!-- Create Schedule Modal -->
<div class="modal fade" id="createScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary bg-opacity-10">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus me-2"></i>
                        Create Special Schedule
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="create_schedule" value="1">
                    
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="schedule_name" class="form-label fw-bold">
                                Schedule Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="schedule_name" name="schedule_name" 
                                   placeholder="e.g., Christmas Day, Barangay Fiesta" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="schedule_date" class="form-label fw-bold">
                                Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="schedule_date" name="schedule_date" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="schedule_type" class="form-label fw-bold">
                                Schedule Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="schedule_type" name="schedule_type" required>
                                <option value="">Select Type...</option>
                                <option value="Holiday">🎉 Holiday</option>
                                <option value="Special Event">⭐ Special Event</option>
                                <option value="Emergency">⚠️ Emergency</option>
                                <option value="Custom">⚙️ Custom</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Work Status</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_working_day" 
                                       name="is_working_day" onchange="toggleCustomTimes()">
                                <label class="form-check-label" for="is_working_day">
                                    This is a working day
                                </label>
                            </div>
                            <small class="text-muted">Check if staff will work on this date</small>
                        </div>
                        
                        <div class="col-12">
                            <label for="description" class="form-label fw-bold">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"
                                      placeholder="Add details about this schedule..."></textarea>
                        </div>
                        
                        <!-- Custom Times (shown when working day is checked) -->
                        <div class="col-12" id="custom_times_section" style="display: none;">
                            <div class="card border-primary">
                                <div class="card-header bg-primary bg-opacity-10">
                                    <h6 class="mb-0">
                                        <i class="fas fa-clock me-2"></i>
                                        Custom Duty Hours (Optional)
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="custom_time_in" class="form-label">Time In</label>
                                            <input type="time" class="form-control" id="custom_time_in" name="custom_time_in">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="custom_time_out" class="form-label">Time Out</label>
                                            <input type="time" class="form-control" id="custom_time_out" name="custom_time_out">
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        Leave blank to use regular duty hours
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Create Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Staff Modal -->
<div class="modal fade" id="assignStaffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success bg-opacity-10">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>
                        Assign Staff to Schedule
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="assign_staff" value="1">
                    <input type="hidden" name="schedule_id" id="assign_schedule_id">
                    
                    <div class="alert alert-info">
                        <strong>Schedule:</strong> <span id="assign_schedule_name"></span>
                    </div>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="selectAllStaff()">
                            <i class="fas fa-check-square me-1"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStaff()">
                            <i class="fas fa-square me-1"></i> Deselect All
                        </button>
                    </div>
                    
                    <div class="list-group" style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($staff as $member): ?>
                            <label class="list-group-item list-group-item-action">
                                <div class="d-flex align-items-center">
                                    <input class="form-check-input me-3 staff-checkbox" type="checkbox" 
                                           name="selected_users[]" value="<?php echo $member['user_id']; ?>">
                                    <?php if ($member['profile_photo']): ?>
                                        <img src="/barangaylink/uploads/profiles/<?php echo $member['profile_photo']; ?>" 
                                             class="rounded-circle me-2" width="40" height="40" alt="Profile">
                                    <?php else: ?>
                                        <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                             style="width: 40px; height: 40px;">
                                            <?php echo strtoupper(substr($member['full_name'] ?? $member['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?php echo htmlspecialchars($member['full_name'] ?? $member['username']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $member['role']; ?></small>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i> Assign Staff
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Schedule Modal -->
<div class="modal fade" id="viewScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info bg-opacity-10">
                <h5 class="modal-title">
                    <i class="fas fa-eye me-2"></i>
                    Schedule Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="scheduleDetailsContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger bg-opacity-10">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Confirm Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to delete the schedule:</p>
                <div class="alert alert-warning">
                    <strong id="delete_schedule_name"></strong>
                </div>
                <p class="text-danger mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    This will also remove all staff assignments for this schedule.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Cancel
                </button>
                <a href="#" id="confirm_delete_btn" class="btn btn-danger">
                    <i class="fas fa-trash me-1"></i> Yes, Delete Schedule
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCustomTimes() {
    const isChecked = document.getElementById('is_working_day').checked;
    document.getElementById('custom_times_section').style.display = isChecked ? 'block' : 'none';
}

function assignStaff(scheduleId, scheduleName) {
    document.getElementById('assign_schedule_id').value = scheduleId;
    document.getElementById('assign_schedule_name').textContent = scheduleName;
    
    // Uncheck all checkboxes
    document.querySelectorAll('.staff-checkbox').forEach(cb => cb.checked = false);
    
    const modal = new bootstrap.Modal(document.getElementById('assignStaffModal'));
    modal.show();
}

function selectAllStaff() {
    document.querySelectorAll('.staff-checkbox').forEach(cb => cb.checked = true);
}

function deselectAllStaff() {
    document.querySelectorAll('.staff-checkbox').forEach(cb => cb.checked = false);
}

function viewSchedule(schedule) {
    const content = `
        <div class="row g-3">
            <div class="col-md-6">
                <strong>Schedule Name:</strong>
                <p class="mb-0">${schedule.schedule_name}</p>
            </div>
            <div class="col-md-6">
                <strong>Date:</strong>
                <p class="mb-0">${new Date(schedule.schedule_date).toLocaleDateString('en-US', {
                    year: 'numeric', month: 'long', day: 'numeric'
                })}</p>
            </div>
            <div class="col-md-6">
                <strong>Type:</strong>
                <p class="mb-0">${schedule.schedule_type}</p>
            </div>
            <div class="col-md-6">
                <strong>Status:</strong>
                <p class="mb-0">${schedule.is_working_day ? '✅ Working Day' : '🌙 Non-Working Day'}</p>
            </div>
            ${schedule.custom_time_in || schedule.custom_time_out ? `
                <div class="col-12">
                    <strong>Custom Duty Hours:</strong>
                    <p class="mb-0">
                        ${schedule.custom_time_in ? `Time In: ${schedule.custom_time_in}` : ''}
                        ${schedule.custom_time_out ? `<br>Time Out: ${schedule.custom_time_out}` : ''}
                    </p>
                </div>
            ` : ''}
            <div class="col-12">
                <strong>Description:</strong>
                <p class="mb-0">${schedule.description || 'No description'}</p>
            </div>
            <div class="col-md-6">
                <strong>Assigned Staff:</strong>
                <p class="mb-0">${schedule.assigned_count} staff member(s)</p>
            </div>
            <div class="col-md-6">
                <strong>Created By:</strong>
                <p class="mb-0">${schedule.created_by_name}</p>
            </div>
        </div>
    `;
    
    document.getElementById('scheduleDetailsContent').innerHTML = content;
    const modal = new bootstrap.Modal(document.getElementById('viewScheduleModal'));
    modal.show();
}

function showDeleteModal(scheduleId, scheduleName) {
    document.getElementById('delete_schedule_name').textContent = scheduleName;
    document.getElementById('confirm_delete_btn').href = `special-schedule.php?delete=${scheduleId}&confirm=1`;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteScheduleModal'));
    modal.show();
}

// Set minimum date to today
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('schedule_date');
    if (dateInput) {
        dateInput.min = new Date().toISOString().split('T')[0];
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>