<?php
/**
 * Admin Attendance Management
 * modules/attendance/admin/index.php
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
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Admin Attendance Management';
$current_user_id = getCurrentUserId();

$selected_date   = isset($_GET['date'])   ? $_GET['date']   : date('Y-m-d');
$selected_role   = isset($_GET['role'])   ? $_GET['role']   : 'all';
$selected_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// ── Handle manual attendance marking ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $user_id         = intval($_POST['user_id']);
    $attendance_date = sanitizeInput($_POST['attendance_date']);
    $status          = sanitizeInput($_POST['status']);
    $time_in         = !empty($_POST['time_in'])  ? sanitizeInput($_POST['time_in'])  : null;
    $time_out        = !empty($_POST['time_out']) ? sanitizeInput($_POST['time_out']) : null;
    $notes           = sanitizeInput($_POST['notes']);

    if ($user_id <= 0) {
        $_SESSION['error_message'] = 'Invalid staff member selected.';
        header("Location: index.php?date=" . urlencode($attendance_date ?: date('Y-m-d')));
        exit();
    }

    $existing = fetchOne($conn,
        "SELECT attendance_id FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
        [$user_id, $attendance_date], 'is'
    );

    $columns_check  = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'updated_by'");
    $has_updated_by = $columns_check && $columns_check->num_rows > 0;

    if ($existing) {
        if ($has_updated_by) {
            $sql = "UPDATE tbl_attendance
                    SET status = ?, time_in = ?, time_out = ?, notes = ?, updated_by = ?
                    WHERE attendance_id = ?";
            $success = executeQuery($conn, $sql,
                [$status, $time_in, $time_out, $notes, $current_user_id, $existing['attendance_id']],
                'ssssii');
        } else {
            $sql = "UPDATE tbl_attendance
                    SET status = ?, time_in = ?, time_out = ?, notes = ?
                    WHERE attendance_id = ?";
            $success = executeQuery($conn, $sql,
                [$status, $time_in, $time_out, $notes, $existing['attendance_id']],
                'ssssi');
        }
        $message = 'Attendance updated successfully';
    } else {
        $sql = "INSERT INTO tbl_attendance
                    (user_id, attendance_date, status, time_in, time_out, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $success = executeQuery($conn, $sql,
            [$user_id, $attendance_date, $status, $time_in, $time_out, $notes, $current_user_id],
            'isssssi');
        $message = 'Attendance marked successfully';
    }

    if ($success) {
        logActivity($conn, $current_user_id,
            "Marked attendance for user #{$user_id}: {$status} on {$attendance_date}",
            'tbl_attendance', $user_id);
        $_SESSION['success_message'] = $message;
    } else {
        $_SESSION['error_message'] = 'Failed to mark attendance';
    }

    header("Location: index.php?date=$attendance_date");
    exit();
}

// ── Handle bulk attendance marking ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_mark'])) {
    $attendance_date    = sanitizeInput($_POST['bulk_date']);
    $bulk_status        = sanitizeInput($_POST['bulk_status']);
    $selected_users     = $_POST['selected_users'] ?? [];
    $bulk_time_in       = !empty($_POST['bulk_time_in'])  ? sanitizeInput($_POST['bulk_time_in'])  : null;
    $bulk_time_out      = !empty($_POST['bulk_time_out']) ? sanitizeInput($_POST['bulk_time_out']) : null;
    $overwrite_existing = isset($_POST['overwrite_existing']);

    $success_count = 0;
    $updated_count = 0;
    $skipped_count = 0;

    $columns_check  = $conn->query("SHOW COLUMNS FROM tbl_attendance LIKE 'updated_by'");
    $has_updated_by = $columns_check && $columns_check->num_rows > 0;

    foreach ($selected_users as $user_id) {
        $user_id = intval($user_id);

        if ($user_id <= 0) {
            continue;
        }

        $existing = fetchOne($conn,
            "SELECT attendance_id FROM tbl_attendance WHERE user_id = ? AND attendance_date = ?",
            [$user_id, $attendance_date], 'is'
        );

        if ($existing) {
            if ($overwrite_existing) {
                if ($has_updated_by) {
                    $sql = "UPDATE tbl_attendance
                            SET status = ?, time_in = ?, time_out = ?, updated_by = ?
                            WHERE attendance_id = ?";
                    if (executeQuery($conn, $sql,
                        [$bulk_status, $bulk_time_in, $bulk_time_out, $current_user_id, $existing['attendance_id']],
                        'sssii')) {
                        $updated_count++;
                    }
                } else {
                    $sql = "UPDATE tbl_attendance
                            SET status = ?, time_in = ?, time_out = ?
                            WHERE attendance_id = ?";
                    if (executeQuery($conn, $sql,
                        [$bulk_status, $bulk_time_in, $bulk_time_out, $existing['attendance_id']],
                        'sssi')) {
                        $updated_count++;
                    }
                }
            } else {
                $skipped_count++;
            }
        } else {
            $sql = "INSERT INTO tbl_attendance
                        (user_id, attendance_date, status, time_in, time_out, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)";
            if (executeQuery($conn, $sql,
                [$user_id, $attendance_date, $bulk_status, $bulk_time_in, $bulk_time_out, $current_user_id],
                'issssi')) {
                $success_count++;
            }
        }
    } // end foreach

    $messages = [];
    if ($success_count > 0) {
        $messages[] = "Created $success_count new record" . ($success_count > 1 ? 's' : '');
    }
    if ($updated_count > 0) {
        $messages[] = "Updated $updated_count existing record" . ($updated_count > 1 ? 's' : '');
    }
    if ($skipped_count > 0) {
        $messages[] = "Skipped $skipped_count record" . ($skipped_count > 1 ? 's' : '') . " (already marked)";
    }

    if ($success_count > 0 || $updated_count > 0) {
        $time_info = ($bulk_time_in || $bulk_time_out) ? " with time" : "";
        $total     = $success_count + $updated_count;
        logActivity($conn, $current_user_id,
            "Bulk marked attendance for $total users$time_info", 'tbl_attendance');
        $_SESSION['success_message'] = "Bulk attendance completed: " . implode(', ', $messages);
    } else {
        $_SESSION['error_message'] = "No attendance records were created or updated. "
            . ($skipped_count > 0
                ? "$skipped_count staff already have attendance marked. Enable 'Overwrite Existing' to update them."
                : "");
    }

    header("Location: index.php?date=$attendance_date");
    exit();
} // end bulk_mark

// ── Fetch data for display ───────────────────────────────────────────────────
$users_query = "SELECT u.user_id, u.username, u.role,
                CONCAT(r.first_name, ' ', r.last_name) as full_name,
                r.profile_photo
                FROM tbl_users u
                LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                WHERE u.is_active = 1 AND u.role IN ('Admin', 'Staff', 'Tanod', 'Driver')";

if ($selected_role !== 'all') {
    $users = fetchAll($conn, $users_query . " AND u.role = ? ORDER BY u.role, r.last_name",
        [$selected_role], 's');
} else {
    $users = fetchAll($conn, $users_query . " ORDER BY u.role, r.last_name");
}

$attendance_records = [];
foreach ($users as $user) {
    $attendance = fetchOne($conn,
        "SELECT a.*,
                CONCAT(cr.first_name, ' ', cr.last_name) as marked_by_name
         FROM tbl_attendance a
         LEFT JOIN tbl_users cu ON a.created_by = cu.user_id
         LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
         WHERE a.user_id = ? AND a.attendance_date = ?",
        [$user['user_id'], $selected_date], 'is'
    );
    $attendance_records[$user['user_id']] = $attendance;
}

$stats = fetchOne($conn,
    "SELECT
        COUNT(*) as total_marked,
        SUM(CASE WHEN status = 'Present'  THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'Late'     THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status = 'Absent'   THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'On Leave' THEN 1 ELSE 0 END) as on_leave
     FROM tbl_attendance
     WHERE attendance_date = ?",
    [$selected_date], 's'
);

$total_users = count($users);
$unmarked    = $total_users - ($stats['total_marked'] ?? 0);

include '../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-2">
            <i class="fas fa-user-check text-primary me-2"></i>
            Admin Attendance Management
        </h1>
        <p class="text-muted mb-0">Manage and track staff attendance</p>
    </div>
    <div class="d-flex gap-2">
        <a href="generate-payslip.php" class="btn btn-outline-primary">
            <i class="fas fa-file-invoice-dollar me-1"></i> Generate Payslip
        </a>
        <a href="duty-schedule.php" class="btn btn-outline-success">
            <i class="fas fa-calendar-week me-1"></i> Duty Schedule
        </a>
        <a href="special-schedule.php" class="btn btn-outline-warning">
            <i class="fas fa-calendar-alt me-1"></i> Special Schedules
        </a>
        <a href="manage-leaves.php" class="btn btn-primary">
            <i class="fas fa-calendar-check me-1"></i> Manage Leaves
        </a>
        <a href="attendance-reports.php" class="btn btn-outline-info">
            <i class="fas fa-chart-bar me-1"></i> Reports
        </a>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkMarkModal">
            <i class="fas fa-users me-1"></i> Bulk Mark
        </button>
    </div>
</div>

<?php echo displayMessage(); ?>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Total Staff</h6>
                        <h3 class="mb-0"><?php echo $total_users; ?></h3>
                    </div>
                    <div class="fs-2 text-primary opacity-50">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Present</h6>
                        <h3 class="mb-0 text-success"><?php echo $stats['present'] ?? 0; ?></h3>
                    </div>
                    <div class="fs-2 text-success opacity-50">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Late</h6>
                        <h3 class="mb-0 text-warning"><?php echo $stats['late'] ?? 0; ?></h3>
                    </div>
                    <div class="fs-2 text-warning opacity-50">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Absent</h6>
                        <h3 class="mb-0 text-danger"><?php echo $stats['absent'] ?? 0; ?></h3>
                    </div>
                    <div class="fs-2 text-danger opacity-50">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">On Leave</h6>
                        <h3 class="mb-0 text-info"><?php echo $stats['on_leave'] ?? 0; ?></h3>
                    </div>
                    <div class="fs-2 text-info opacity-50">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h6 class="text-muted mb-1">Unmarked</h6>
                        <h3 class="mb-0 text-secondary"><?php echo $unmarked; ?></h3>
                    </div>
                    <div class="fs-2 text-secondary opacity-50">
                        <i class="fas fa-question-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label for="date" class="form-label">Date</label>
                <input type="date" class="form-control" id="date" name="date" 
                       value="<?php echo $selected_date; ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role" onchange="this.form.submit()">
                    <option value="all" <?php echo $selected_role === 'all' ? 'selected' : ''; ?>>All Roles</option>
                    <option value="Admin" <?php echo $selected_role === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="Staff" <?php echo $selected_role === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="Tanod" <?php echo $selected_role === 'Tanod' ? 'selected' : ''; ?>>Tanod</option>
                    <option value="Driver" <?php echo $selected_role === 'Driver' ? 'selected' : ''; ?>>Driver</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $selected_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="Present" <?php echo $selected_status === 'Present' ? 'selected' : ''; ?>>Present</option>
                    <option value="Late" <?php echo $selected_status === 'Late' ? 'selected' : ''; ?>>Late</option>
                    <option value="Absent" <?php echo $selected_status === 'Absent' ? 'selected' : ''; ?>>Absent</option>
                    <option value="On Leave" <?php echo $selected_status === 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
                    <option value="unmarked" <?php echo $selected_status === 'unmarked' ? 'selected' : ''; ?>>Unmarked</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <a href="index.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-redo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Attendance Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list text-primary me-2"></i>
                Attendance for <?php echo formatDate($selected_date); ?>
            </h5>
            <div>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                    <i class="fas fa-check-square me-1"></i> Select All
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                    <i class="fas fa-square me-1"></i> Deselect All
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th width="50">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()">
                        </th>
                        <th>Staff</th>
                        <th>Role</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Marked By</th>
                        <th width="120">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $filtered_count = 0;
                    foreach ($users as $user): 
                        $attendance = $attendance_records[$user['user_id']] ?? null;
                        
                        // Apply status filter
                        if ($selected_status !== 'all') {
                            if ($selected_status === 'unmarked') {
                                if ($attendance !== null) continue;
                            } else {
                                if ($attendance === null || $attendance['status'] !== $selected_status) continue;
                            }
                        }
                        
                        $filtered_count++;
                    ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="user-checkbox" value="<?php echo $user['user_id']; ?>">
                           <td>
    <div class="d-flex align-items-center">
        <?php if ($user['profile_photo'] && file_exists('../../../uploads/profiles/' . $user['profile_photo'])): ?>
            <img src="<?php echo '../../../uploads/profiles/' . $user['profile_photo']; ?>" 
                 class="rounded-circle me-2" width="32" height="32" alt="Profile">
        <?php else: ?>
            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                 style="width: 32px; height: 32px;">
                <?php echo strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)); ?>
            </div>
        <?php endif; ?>
        <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong>
    </div>
</td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo $user['role']; ?></span></td>
                            <td>
                                <?php if ($attendance && $attendance['time_in']): ?>
                                    <span class="text-success">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('h:i A', strtotime($attendance['time_in'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($attendance && $attendance['time_out']): ?>
                                    <span class="text-danger">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('h:i A', strtotime($attendance['time_out'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($attendance): ?>
                                    <?php echo getStatusBadge($attendance['status']); ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Unmarked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($attendance && $attendance['notes']): ?>
                                    <span class="text-muted" title="<?php echo htmlspecialchars($attendance['notes']); ?>">
                                        <?php echo substr($attendance['notes'], 0, 20) . (strlen($attendance['notes']) > 20 ? '...' : ''); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($attendance && $attendance['marked_by_name']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($attendance['marked_by_name']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="markAttendance(<?php echo htmlspecialchars(json_encode($user)); ?>, <?php echo htmlspecialchars(json_encode($attendance)); ?>)">
                                    <i class="fas fa-edit"></i> Mark
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if ($filtered_count === 0): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p class="mb-0">No records found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Mark Attendance Modal -->
<div class="modal fade" id="markAttendanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary bg-opacity-10">
                    <h5 class="modal-title">
                        <i class="fas fa-user-clock me-2"></i>
                        Mark Staff Attendance
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="mark_attendance" value="1">
                    <input type="hidden" name="user_id" id="mark_user_id">
                    <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                    
                    <!-- Staff Info -->
                    <div class="alert alert-info">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user fa-2x me-3"></i>
                            <div>
                                <strong>Staff Member:</strong>
                                <h5 class="mb-0 mt-1" id="mark_user_name_display"></h5>
                                <small class="text-muted">Date: <?php echo formatDate($selected_date); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" id="mark_user_name">
                    
                    <!-- Status Selection -->
                    <div class="mb-4">
                        <label for="mark_status" class="form-label fw-bold">
                            <i class="fas fa-clipboard-check me-1"></i>
                            Attendance Status <span class="text-danger">*</span>
                        </label>
                        <select class="form-select form-select-lg" id="mark_status" name="status" required onchange="updateStatusHelp()">
                            <option value="Present">✓ Present</option>
                            <option value="Late">⏰ Late</option>
                            <option value="Absent">✗ Absent</option>
                            <option value="On Leave">📅 On Leave</option>
                            <option value="Half Day">◐ Half Day</option>
                        </select>
                        <small class="text-muted" id="status_help">Staff member was present for their full shift</small>
                    </div>
                    
                    <!-- Time In/Out Section -->
                    <div class="card border-primary mb-4">
                        <div class="card-header bg-primary bg-opacity-10">
                            <h6 class="mb-0">
                                <i class="fas fa-clock me-2"></i>
                                Duty Time
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="mark_time_in" class="form-label fw-bold">
                                        <i class="fas fa-sign-in-alt text-success me-1"></i>
                                        Time In
                                    </label>
                                    <div class="input-group">
                                        <input type="time" class="form-control form-control-lg" id="mark_time_in" name="time_in">
                                        <button type="button" class="btn btn-outline-success" onclick="setCurrentTimeIn()">
                                            <i class="fas fa-clock me-1"></i> Now
                                        </button>
                                    </div>
                                    <small class="text-muted">When the staff member started their duty</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="mark_time_out" class="form-label fw-bold">
                                        <i class="fas fa-sign-out-alt text-danger me-1"></i>
                                        Time Out
                                    </label>
                                    <div class="input-group">
                                        <input type="time" class="form-control form-control-lg" id="mark_time_out" name="time_out">
                                        <button type="button" class="btn btn-outline-danger" onclick="setCurrentTimeOut()">
                                            <i class="fas fa-clock me-1"></i> Now
                                        </button>
                                    </div>
                                    <small class="text-muted">When the staff member ended their duty</small>
                                </div>
                            </div>
                            
                            <!-- Quick Time Presets -->
                            <div class="mt-3">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-bolt me-1"></i>
                                    Quick Presets:
                                </label>
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setTimePreset('08:00', '17:00')">
                                        8AM - 5PM
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setTimePreset('09:00', '18:00')">
                                        9AM - 6PM
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setTimePreset('07:00', '16:00')">
                                        7AM - 4PM
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setTimePreset('10:00', '19:00')">
                                        10AM - 7PM
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Hours Display -->
                            <div class="alert alert-secondary mt-3 mb-0" id="hours_display" style="display: none;">
                                <strong>Total Hours:</strong> <span id="total_hours">0h 0m</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="mb-3">
                        <label for="mark_notes" class="form-label fw-bold">
                            <i class="fas fa-sticky-note me-1"></i>
                            Notes (Optional)
                        </label>
                        <textarea class="form-control" id="mark_notes" name="notes" rows="3" 
                                  placeholder="Add any additional notes or remarks..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-1"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Mark Modal -->
<div class="modal fade" id="bulkMarkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success bg-opacity-10">
                    <h5 class="modal-title">
                        <i class="fas fa-users-cog me-2"></i>
                        Bulk Mark Attendance
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="bulk_mark" value="1">
                    <input type="hidden" name="bulk_date" value="<?php echo $selected_date; ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instructions:</strong> Select staff members from the table below, then choose their attendance status and optionally set time in/out for all selected staff at once.
                    </div>
                    
                    <!-- Status Selection -->
                    <div class="mb-4">
                        <label for="bulk_status" class="form-label fw-bold">
                            <i class="fas fa-clipboard-check me-1"></i>
                            Attendance Status <span class="text-danger">*</span>
                        </label>
                        <select class="form-select form-select-lg" id="bulk_status" name="bulk_status" required>
                            <option value="Present">✓ Present</option>
                            <option value="Late">⏰ Late</option>
                            <option value="Absent">✗ Absent</option>
                            <option value="On Leave">📅 On Leave</option>
                        </select>
                    </div>
                    
                    <!-- Optional Time Settings -->
                    <div class="card border-secondary mb-4">
                        <div class="card-header bg-secondary bg-opacity-10">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="bulk_set_times" onchange="toggleBulkTimes()">
                                <label class="form-check-label fw-bold" for="bulk_set_times">
                                    <i class="fas fa-clock me-1"></i>
                                    Set Time In/Out for all selected staff
                                </label>
                            </div>
                        </div>
                        <div class="card-body" id="bulk_times_section" style="display: none;">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="bulk_time_in" class="form-label">
                                        <i class="fas fa-sign-in-alt text-success me-1"></i>
                                        Time In
                                    </label>
                                    <div class="input-group">
                                        <input type="time" class="form-control" id="bulk_time_in" name="bulk_time_in">
                                        <button type="button" class="btn btn-outline-success" onclick="setBulkCurrentTimeIn()">
                                            <i class="fas fa-clock"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="bulk_time_out" class="form-label">
                                        <i class="fas fa-sign-out-alt text-danger me-1"></i>
                                        Time Out
                                    </label>
                                    <div class="input-group">
                                        <input type="time" class="form-control" id="bulk_time_out" name="bulk_time_out">
                                        <button type="button" class="btn btn-outline-danger" onclick="setBulkCurrentTimeOut()">
                                            <i class="fas fa-clock"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Presets -->
                            <div class="mt-3">
                                <label class="form-label fw-bold">Quick Presets:</label>
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setBulkTimePreset('08:00', '17:00')">
                                        8AM - 5PM
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setBulkTimePreset('09:00', '18:00')">
                                        9AM - 6PM
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setBulkTimePreset('07:00', '16:00')">
                                        7AM - 4PM
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Selected Users Display -->
                    <div id="selectedUsersList" class="alert alert-secondary">
                        <strong><i class="fas fa-users me-2"></i>Selected:</strong> 
                        <span id="selectedCount" class="badge bg-primary fs-6">0</span> staff member(s)
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> This will only mark attendance for staff who don't have attendance recorded yet for this date.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success btn-lg" id="bulkMarkSubmit">
                        <i class="fas fa-check me-1"></i> Mark Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle bulk times section
function toggleBulkTimes() {
    const checked = document.getElementById('bulk_set_times').checked;
    document.getElementById('bulk_times_section').style.display = checked ? 'block' : 'none';
    
    if (!checked) {
        document.getElementById('bulk_time_in').value = '';
        document.getElementById('bulk_time_out').value = '';
    }
}

// Set current time for bulk Time In
function setBulkCurrentTimeIn() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('bulk_time_in').value = `${hours}:${minutes}`;
}

// Set current time for bulk Time Out
function setBulkCurrentTimeOut() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('bulk_time_out').value = `${hours}:${minutes}`;
}

// Set bulk time preset
function setBulkTimePreset(timeIn, timeOut) {
    document.getElementById('bulk_time_in').value = timeIn;
    document.getElementById('bulk_time_out').value = timeOut;
    document.getElementById('bulk_set_times').checked = true;
    toggleBulkTimes();
}
</script>

<script>
function markAttendance(user, attendance) {
    const modal = new bootstrap.Modal(document.getElementById('markAttendanceModal'));
    
    document.getElementById('mark_user_id').value = user.user_id;
    document.getElementById('mark_user_name').value = user.full_name || user.username;
    document.getElementById('mark_user_name_display').textContent = user.full_name || user.username;
    
    if (attendance) {
        document.getElementById('mark_status').value = attendance.status;
        document.getElementById('mark_time_in').value = attendance.time_in ? attendance.time_in.substring(0, 5) : '';
        document.getElementById('mark_time_out').value = attendance.time_out ? attendance.time_out.substring(0, 5) : '';
        document.getElementById('mark_notes').value = attendance.notes || '';
    } else {
        document.getElementById('mark_status').value = 'Present';
        document.getElementById('mark_time_in').value = '';
        document.getElementById('mark_time_out').value = '';
        document.getElementById('mark_notes').value = '';
    }
    
    updateStatusHelp();
    calculateHours();
    modal.show();
}

// Set current time for Time In
function setCurrentTimeIn() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('mark_time_in').value = `${hours}:${minutes}`;
    calculateHours();
}

// Set current time for Time Out
function setCurrentTimeOut() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('mark_time_out').value = `${hours}:${minutes}`;
    calculateHours();
}

// Set time preset
function setTimePreset(timeIn, timeOut) {
    document.getElementById('mark_time_in').value = timeIn;
    document.getElementById('mark_time_out').value = timeOut;
    calculateHours();
}

// Calculate total hours
function calculateHours() {
    const timeIn = document.getElementById('mark_time_in').value;
    const timeOut = document.getElementById('mark_time_out').value;
    
    if (timeIn && timeOut) {
        const [inHour, inMin] = timeIn.split(':').map(Number);
        const [outHour, outMin] = timeOut.split(':').map(Number);
        
        const inMinutes = inHour * 60 + inMin;
        const outMinutes = outHour * 60 + outMin;
        
        let diffMinutes = outMinutes - inMinutes;
        if (diffMinutes < 0) {
            diffMinutes += 24 * 60; // Handle overnight shifts
        }
        
        const hours = Math.floor(diffMinutes / 60);
        const minutes = diffMinutes % 60;
        
        document.getElementById('total_hours').textContent = `${hours}h ${minutes}m`;
        document.getElementById('hours_display').style.display = 'block';
    } else {
        document.getElementById('hours_display').style.display = 'none';
    }
}

// Update status help text
function updateStatusHelp() {
    const status = document.getElementById('mark_status').value;
    const helpText = {
        'Present': 'Staff member was present for their full shift',
        'Late': 'Staff member arrived late but was present',
        'Absent': 'Staff member did not report for duty',
        'On Leave': 'Staff member is on approved leave',
        'Half Day': 'Staff member worked only half of their shift'
    };
    document.getElementById('status_help').textContent = helpText[status] || '';
}

function selectAll() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelectedCount();
}

function deselectAll() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
}

function toggleSelectAll() {
    const checked = document.getElementById('selectAllCheckbox').checked;
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.user-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

// Update selected count when checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.user-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // Calculate hours when time inputs change
    document.getElementById('mark_time_in').addEventListener('change', calculateHours);
    document.getElementById('mark_time_out').addEventListener('change', calculateHours);
    
    // Handle bulk mark form submission
    document.getElementById('bulkMarkSubmit').addEventListener('click', function(e) {
        const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
        
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('Please select at least one staff member');
            return false;
        }
        
        // Add hidden inputs for selected users
        const form = this.closest('form');
        checkedBoxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_users[]';
            input.value = checkbox.value;
            form.appendChild(input);
        });
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>