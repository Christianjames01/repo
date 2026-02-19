<?php
/**
 * Admin Duty Schedule Management - COMPLETELY FIXED VERSION
 * Path: barangaylink/modules/attendance/admin/duty-schedule.php
 */

require_once __DIR__ . '/../../../config/config.php';

// Session is already started in config.php, so check status before starting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has permission
if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Duty Schedule Management';
$current_user_id = getCurrentUserId();

// Handle schedule assignment - COMPLETELY FIXED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_schedule'])) {
    $user_id = intval($_POST['user_id']);
    $days = $_POST['days'] ?? [];
    
    // Delete existing schedules for the user
    executeQuery($conn, "DELETE FROM tbl_duty_schedules WHERE user_id = ?", [$user_id], 'i');
    
    $success_count = 0;
    foreach ($days as $day => $times) {
        if (!empty($times['time_in']) && !empty($times['time_out'])) {
            // FIXED: Use executeInsert instead of executeQuery for INSERT statements
            $sql = "INSERT INTO tbl_duty_schedules (user_id, day_of_week, time_in, time_out, is_active, created_by) 
                    VALUES (?, ?, ?, ?, 1, ?)";
            
            // This returns the new schedule_id or false
            $new_schedule_id = executeInsert($conn, $sql, [$user_id, $day, $times['time_in'], $times['time_out'], $current_user_id], 'isssi');
            
            if ($new_schedule_id) {
                $success_count++;
            }
        }
    }
    
    if ($success_count > 0) {
        // FIXED: Pass user_id as record_id (valid positive integer)
        logActivity($conn, $current_user_id, "Assigned duty schedule to user ID: $user_id", 'tbl_duty_schedules', $user_id);
        $_SESSION['success_message'] = "Successfully assigned schedule for $success_count day(s)";
    } else {
        $_SESSION['error_message'] = "Failed to assign schedule";
    }
    
    header("Location: duty-schedule.php");
    exit();
}

// Handle template application - COMPLETELY FIXED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_template'])) {
    $user_ids = $_POST['selected_users'] ?? [];
    $template_id = intval($_POST['template_id']);
    
    // Get template
    $template = fetchOne($conn, "SELECT * FROM tbl_schedule_templates WHERE template_id = ?", [$template_id], 'i');
    
    if ($template && !empty($user_ids)) {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $success_count = 0;
        
        foreach ($user_ids as $user_id) {
            $user_id = intval($user_id);
            
            // Delete existing schedules
            executeQuery($conn, "DELETE FROM tbl_duty_schedules WHERE user_id = ?", [$user_id], 'i');
            
            // Insert new schedules
            foreach ($days as $day) {
                $day_lower = strtolower($day);
                $time_in = $template[$day_lower . '_in'];
                $time_out = $template[$day_lower . '_out'];
                
                if ($time_in && $time_out) {
                    // FIXED: Use executeInsert instead of executeQuery
                    $sql = "INSERT INTO tbl_duty_schedules (user_id, day_of_week, time_in, time_out, is_active, created_by) 
                            VALUES (?, ?, ?, ?, 1, ?)";
                    executeInsert($conn, $sql, [$user_id, $day, $time_in, $time_out, $current_user_id], 'isssi');
                }
            }
            $success_count++;
        }
        
        // FIXED: Don't pass record_id for bulk operations (pass null)
        logActivity($conn, $current_user_id, "Applied schedule template to $success_count user(s)", 'tbl_duty_schedules', null);
        $_SESSION['success_message'] = "Successfully applied template to $success_count user(s)";
    } else {
        $_SESSION['error_message'] = "Failed to apply template";
    }
    
    header("Location: duty-schedule.php");
    exit();
}

// Handle special schedule - COMPLETELY FIXED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_special_schedule'])) {
    $user_id = intval($_POST['user_id']);
    $schedule_date = sanitizeInput($_POST['schedule_date']);
    $time_in = sanitizeInput($_POST['time_in']);
    $time_out = sanitizeInput($_POST['time_out']);
    $schedule_type = sanitizeInput($_POST['schedule_type']);
    $notes = sanitizeInput($_POST['notes']);
    
    // FIXED: Check if exists, then INSERT or UPDATE accordingly
    $existing = fetchOne($conn, 
        "SELECT special_schedule_id FROM tbl_special_duty_schedules WHERE user_id = ? AND schedule_date = ?",
        [$user_id, $schedule_date], 'is'
    );
    
    $special_schedule_id = null;
    
    if ($existing) {
        // Update existing
        $sql = "UPDATE tbl_special_duty_schedules 
                SET time_in = ?, time_out = ?, schedule_type = ?, notes = ? 
                WHERE user_id = ? AND schedule_date = ?";
        $success = executeQuery($conn, $sql, [$time_in, $time_out, $schedule_type, $notes, $user_id, $schedule_date], 'ssssis');
        $special_schedule_id = $existing['special_schedule_id'];
    } else {
        // Insert new - FIXED: Use executeInsert
        $sql = "INSERT INTO tbl_special_duty_schedules (user_id, schedule_date, time_in, time_out, schedule_type, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $special_schedule_id = executeInsert($conn, $sql, [$user_id, $schedule_date, $time_in, $time_out, $schedule_type, $notes, $current_user_id], 'isssssi');
        $success = ($special_schedule_id !== false);
    }
    
    if ($success && $special_schedule_id) {
        // FIXED: Pass valid special_schedule_id
        logActivity($conn, $current_user_id, "Added special schedule for user ID: $user_id on $schedule_date", 'tbl_special_duty_schedules', $special_schedule_id);
        $_SESSION['success_message'] = "Special schedule added successfully";
    } else {
        $_SESSION['error_message'] = "Failed to add special schedule";
    }
    
    header("Location: duty-schedule.php?user_id=" . $user_id);
    exit();
}

// Handle delete special schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_special_schedule'])) {
    $special_schedule_id = intval($_POST['special_schedule_id']);
    
    if (executeQuery($conn, "DELETE FROM tbl_special_duty_schedules WHERE special_schedule_id = ?", [$special_schedule_id], 'i')) {
        // FIXED: Pass valid special_schedule_id
        logActivity($conn, $current_user_id, "Deleted special schedule ID: $special_schedule_id", 'tbl_special_duty_schedules', $special_schedule_id);
        $_SESSION['success_message'] = "Special schedule deleted successfully";
    } else {
        $_SESSION['error_message'] = "Failed to delete special schedule";
    }
    
    header("Location: duty-schedule.php?user_id=" . ($_POST['user_id'] ?? ''));
    exit();
}

// Get all active staff
$users = fetchAll($conn, 
    "SELECT u.user_id, u.username, u.role, 
            CONCAT(r.first_name, ' ', r.last_name) as full_name,
            r.profile_photo
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')
    ORDER BY u.role, r.last_name"
);

// Get all templates
$templates = fetchAll($conn, 
    "SELECT * FROM tbl_schedule_templates WHERE is_active = 1 ORDER BY template_name"
);

// Get filter
$selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

// Get schedules for selected user
$user_schedules = [];
$special_schedules = [];
if ($selected_user) {
    $user_schedules = fetchAll($conn,
        "SELECT * FROM tbl_duty_schedules WHERE user_id = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')",
        [$selected_user], 'i'
    );
    
    $special_schedules = fetchAll($conn,
        "SELECT ss.*, CONCAT(r.first_name, ' ', r.last_name) as created_by_name
        FROM tbl_special_duty_schedules ss
        LEFT JOIN tbl_users u ON ss.created_by = u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE ss.user_id = ? AND ss.schedule_date >= CURDATE()
        ORDER BY ss.schedule_date",
        [$selected_user], 'i'
    );
}

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2">
                <i class="fas fa-calendar-week text-primary me-2"></i>
                Duty Schedule Management
            </h1>
            <p class="text-muted mb-0">Assign and manage staff duty schedules</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Attendance
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                <i class="fas fa-clone me-1"></i> Apply Template
            </button>
            <?php if ($selected_user): ?>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#specialScheduleModal">
                <i class="fas fa-calendar-plus me-1"></i> Special Schedule
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Staff Selection -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET">
                <div class="row g-3 align-items-end">
                    <div class="col-md-10">
                        <label for="user_id" class="form-label fw-bold">Select Staff Member</label>
                        <select class="form-select form-select-lg" id="user_id" name="user_id" onchange="this.form.submit()">
                            <option value="">-- Select a staff member --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>" <?php echo $selected_user == $user['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?> 
                                    (<?php echo $user['role']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <a href="duty-schedule.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-redo me-1"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_user): ?>
        <?php 
        $selected_user_data = null;
        foreach ($users as $user) {
            if ($user['user_id'] == $selected_user) {
                $selected_user_data = $user;
                break;
            }
        }
        ?>
        
        <!-- Staff Info Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <?php if (!empty($selected_user_data['profile_photo'])): ?>
                        <img src="<?php echo '/barangaylink/uploads/profiles/' . $selected_user_data['profile_photo']; ?>" 
                             class="rounded-circle me-3" width="64" height="64" alt="Profile">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" 
                             style="width: 64px; height: 64px; font-size: 24px;">
                            <?php echo strtoupper(substr($selected_user_data['full_name'] ?? $selected_user_data['username'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h4 class="mb-1"><?php echo htmlspecialchars($selected_user_data['full_name'] ?? $selected_user_data['username']); ?></h4>
                        <p class="text-muted mb-0">
                            <span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo $selected_user_data['role']; ?></span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Schedule -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-week text-primary me-2"></i>
                    Weekly Duty Schedule
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="assign_schedule" value="1">
                    <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">
                    
                    <?php 
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    $schedule_map = [];
                    foreach ($user_schedules as $sched) {
                        $schedule_map[$sched['day_of_week']] = $sched;
                    }
                    ?>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="150">Day</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th width="120">Total Hours</th>
                                    <th width="80">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day): ?>
                                    <?php 
                                    $existing = $schedule_map[$day] ?? null;
                                    $time_in = $existing ? substr($existing['time_in'], 0, 5) : '';
                                    $time_out = $existing ? substr($existing['time_out'], 0, 5) : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $day; ?></strong>
                                        </td>
                                        <td>
                                            <input type="time" class="form-control" 
                                                   name="days[<?php echo $day; ?>][time_in]" 
                                                   value="<?php echo $time_in; ?>"
                                                   onchange="calculateDayHours('<?php echo $day; ?>')">
                                        </td>
                                        <td>
                                            <input type="time" class="form-control" 
                                                   name="days[<?php echo $day; ?>][time_out]" 
                                                   value="<?php echo $time_out; ?>"
                                                   onchange="calculateDayHours('<?php echo $day; ?>')">
                                        </td>
                                        <td>
                                            <span class="badge bg-info" id="hours_<?php echo $day; ?>">
                                                <?php 
                                                if ($time_in && $time_out) {
                                                    $in = strtotime($time_in);
                                                    $out = strtotime($time_out);
                                                    $diff = ($out - $in) / 3600;
                                                    if ($diff < 0) $diff += 24;
                                                    echo number_format($diff, 1) . ' hrs';
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input" 
                                                   <?php echo ($time_in && $time_out) ? 'checked' : ''; ?> disabled>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Quick Presets -->
                    <div class="mt-3">
                        <label class="form-label fw-bold">Quick Presets:</label>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="applyPreset('08:00', '17:00', 'weekday')">
                                8AM-5PM (Mon-Fri)
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="applyPreset('07:00', '16:00', 'weekday')">
                                7AM-4PM (Mon-Fri)
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="applyPreset('09:00', '18:00', 'all')">
                                9AM-6PM (All Days)
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAllSchedules()">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i> Save Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Special Schedules -->
        <?php if (!empty($special_schedules)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-alt text-success me-2"></i>
                    Special Schedules
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Type</th>
                                <th>Notes</th>
                                <th>Created By</th>
                                <th width="100">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($special_schedules as $special): ?>
                                <tr>
                                    <td><?php echo formatDate($special['schedule_date']); ?></td>
                                    <td><?php echo date('h:i A', strtotime($special['time_in'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($special['time_out'])); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($special['schedule_type']); ?></span></td>
                                    <td><?php echo htmlspecialchars($special['notes'] ?? '-'); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($special['created_by_name'] ?? 'System'); ?></small></td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this special schedule?');">
                                            <input type="hidden" name="delete_special_schedule" value="1">
                                            <input type="hidden" name="special_schedule_id" value="<?php echo $special['special_schedule_id']; ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <!-- No Staff Selected -->
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">No Staff Selected</h4>
                <p class="text-muted">Please select a staff member from the dropdown above to view and manage their duty schedule.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Apply Template Modal -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary bg-opacity-10">
                    <h5 class="modal-title">
                        <i class="fas fa-clone me-2"></i>
                        Apply Schedule Template
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="apply_template" value="1">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instructions:</strong> Select staff members and a template to apply the schedule to multiple users at once.
                    </div>
                    
                    <!-- Template Selection -->
                    <div class="mb-4">
                        <label for="template_id" class="form-label fw-bold">
                            Select Template <span class="text-danger">*</span>
                        </label>
                        <select class="form-select form-select-lg" id="template_id" name="template_id" required onchange="showTemplatePreview()">
                            <option value="">-- Choose a template --</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $template['template_id']; ?>" 
                                        data-template='<?php echo htmlspecialchars(json_encode($template)); ?>'>
                                    <?php echo htmlspecialchars($template['template_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="template_description"></small>
                    </div>

                    <!-- Template Preview -->
                    <div id="template_preview" class="card border-secondary mb-4" style="display: none;">
                        <div class="card-header bg-secondary bg-opacity-10">
                            <strong>Template Preview</strong>
                        </div>
                        <div class="card-body" id="preview_content">
                        </div>
                    </div>
                    
                    <!-- Staff Selection -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Staff Members <span class="text-danger">*</span></label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="selectAllTemplate" onchange="toggleSelectAllTemplate()">
                            <label class="form-check-label" for="selectAllTemplate">
                                <strong>Select All</strong>
                            </label>
                        </div>
                        <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem;">
                            <?php foreach ($users as $user): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input template-user-checkbox" type="checkbox" 
                                           name="selected_users[]" value="<?php echo $user['user_id']; ?>" 
                                           id="template_user_<?php echo $user['user_id']; ?>">
                                    <label class="form-check-label" for="template_user_<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?php echo $user['role']; ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i> Apply Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Special Schedule Modal -->
<?php if ($selected_user): ?>
<div class="modal fade" id="specialScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success bg-opacity-10">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus me-2"></i>
                        Add Special Schedule
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_special_schedule" value="1">
                    <input type="hidden" name="user_id" value="<?php echo $selected_user; ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Special schedules override the regular weekly schedule for specific dates.
                    </div>
                    
                    <div class="mb-3">
                        <label for="schedule_date" class="form-label fw-bold">
                            Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="schedule_date" name="schedule_date" 
                               min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="time_in" class="form-label fw-bold">
                                Time In <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control" id="time_in" name="time_in" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="time_out" class="form-label fw-bold">
                                Time Out <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control" id="time_out" name="time_out" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="schedule_type" class="form-label fw-bold">
                            Type <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="schedule_type" name="schedule_type" required>
                            <option value="">-- Select type --</option>
                            <option value="Overtime">Overtime</option>
                            <option value="Special Event">Special Event</option>
                            <option value="Coverage">Coverage</option>
                            <option value="Training">Training</option>
                            <option value="Meeting">Meeting</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label fw-bold">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Optional notes about this special schedule..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i> Add Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Calculate hours for a specific day
function calculateDayHours(day) {
    const timeIn = document.querySelector(`input[name="days[${day}][time_in]"]`).value;
    const timeOut = document.querySelector(`input[name="days[${day}][time_out]"]`).value;
    const hoursSpan = document.getElementById('hours_' + day);
    
    if (timeIn && timeOut) {
        const [inHour, inMin] = timeIn.split(':').map(Number);
        const [outHour, outMin] = timeOut.split(':').map(Number);
        
        let diff = (outHour * 60 + outMin) - (inHour * 60 + inMin);
        if (diff < 0) diff += 24 * 60;
        
        const hours = (diff / 60).toFixed(1);
        hoursSpan.textContent = hours + ' hrs';
        hoursSpan.className = 'badge bg-info';
    } else {
        hoursSpan.textContent = '-';
        hoursSpan.className = 'badge bg-secondary';
    }
}

// Apply preset schedule
function applyPreset(timeIn, timeOut, type) {
    const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    const applyDays = type === 'weekday' ? weekdays : days;
    
    applyDays.forEach(day => {
        document.querySelector(`input[name="days[${day}][time_in]"]`).value = timeIn;
        document.querySelector(`input[name="days[${day}][time_out]"]`).value = timeOut;
        calculateDayHours(day);
    });
}

// Clear all schedules
function clearAllSchedules() {
    if (confirm('Are you sure you want to clear all schedules?')) {
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        days.forEach(day => {
            document.querySelector(`input[name="days[${day}][time_in]"]`).value = '';
            document.querySelector(`input[name="days[${day}][time_out]"]`).value = '';
            calculateDayHours(day);
        });
    }
}

// Format time from 24h to 12h
function formatTime(time) {
    if (!time) return '-';
    const [hour, min] = time.split(':');
    const h = parseInt(hour);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const displayHour = h % 12 || 12;
    return `${displayHour}:${min} ${ampm}`;
}

// Show template preview
function showTemplatePreview() {
    const select = document.getElementById('template_id');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption.value) {
        document.getElementById('template_preview').style.display = 'none';
        document.getElementById('template_description').textContent = '';
        return;
    }
    
    const template = JSON.parse(selectedOption.getAttribute('data-template'));
    
    // Show description
    document.getElementById('template_description').textContent = template.description || '';
    
    // Build preview table
    const days = [
        {name: 'Monday', in: template.monday_in, out: template.monday_out},
        {name: 'Tuesday', in: template.tuesday_in, out: template.tuesday_out},
        {name: 'Wednesday', in: template.wednesday_in, out: template.wednesday_out},
        {name: 'Thursday', in: template.thursday_in, out: template.thursday_out},
        {name: 'Friday', in: template.friday_in, out: template.friday_out},
        {name: 'Saturday', in: template.saturday_in, out: template.saturday_out},
        {name: 'Sunday', in: template.sunday_in, out: template.sunday_out}
    ];
    
    let previewHTML = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">';
    previewHTML += '<thead><tr><th>Day</th><th>Time In</th><th>Time Out</th><th>Hours</th></tr></thead><tbody>';
    
    days.forEach(day => {
        if (day.in && day.out) {
            // Calculate hours
            const [inHour, inMin] = day.in.split(':').map(Number);
            const [outHour, outMin] = day.out.split(':').map(Number);
            let diff = (outHour * 60 + outMin) - (inHour * 60 + inMin);
            if (diff < 0) diff += 24 * 60;
            const hours = (diff / 60).toFixed(1);
            
            // Format time for display
            const timeIn = formatTime(day.in);
            const timeOut = formatTime(day.out);
            
            previewHTML += `<tr>
                <td><strong>${day.name}</strong></td>
                <td><span class="text-success">${timeIn}</span></td>
                <td><span class="text-danger">${timeOut}</span></td>
                <td><span class="badge bg-info">${hours} hrs</span></td>
            </tr>`;
        } else {
            previewHTML += `<tr>
                <td><strong>${day.name}</strong></td>
                <td colspan="3" class="text-center text-muted">Rest Day</td>
            </tr>`;
        }
    });
    
    previewHTML += '</tbody></table></div>';
    
    document.getElementById('preview_content').innerHTML = previewHTML;
    document.getElementById('template_preview').style.display = 'block';
}

// Toggle select all template users
function toggleSelectAllTemplate() {
    const checked = document.getElementById('selectAllTemplate').checked;
    document.querySelectorAll('.template-user-checkbox').forEach(cb => {
        cb.checked = checked;
    });
}

// Update select all checkbox when individual checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    const templateCheckboxes = document.querySelectorAll('.template-user-checkbox');
    const selectAllCheckbox = document.getElementById('selectAllTemplate');
    
    if (templateCheckboxes.length > 0 && selectAllCheckbox) {
        templateCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(templateCheckboxes).every(cb => cb.checked);
                const someChecked = Array.from(templateCheckboxes).some(cb => cb.checked);
                
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = someChecked && !allChecked;
            });
        });
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>