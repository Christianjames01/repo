<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod', 'Resident']);

$current_user_id = getCurrentUserId();
$current_role    = getCurrentUserRole();

$staff_roles = ['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod'];
$is_resident = !in_array($current_role, $staff_roles);

$page_title = 'View Complaints';

$resident_id = null;
if ($is_resident) {
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) $resident_id = $row['resident_id'];
    $stmt->close();

    if ($resident_id) {
        $stmt = $conn->prepare("SELECT is_verified, id_photo FROM tbl_residents WHERE resident_id = ?");
        $stmt->bind_param("i", $resident_id);
        $stmt->execute();
        $resident_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$resident_info || $resident_info['is_verified'] != 1 || empty($resident_info['id_photo'])) {
            header('Location: not-verified-complaints.php');
            exit();
        }
    }
}

$success = isset($_GET['success']) ? sanitizeInput($_GET['success']) : '';
$error   = isset($_GET['error'])   ? sanitizeInput($_GET['error'])   : '';

$filter_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

// Detect date column
$date_columns = ['created_at', 'date_filed', 'date_created'];
$date_column  = null;
foreach ($date_columns as $col) {
    if (columnExists($conn, 'tbl_complaints', $col)) { $date_column = $col; break; }
}

// ── Main query ───────────────────────────────────────────────────────────────
$sql = "SELECT c.complaint_id, c.complaint_number, c.subject, c.description, c.category,
        c.priority, c.status, c.resident_id, c.assigned_to";
if ($date_column) $sql .= ", c.$date_column as complaint_date";
$sql .= ", CONCAT(r.first_name, ' ', r.last_name) as complainant_name,
          u.username as assigned_to_name
          FROM tbl_complaints c
          LEFT JOIN tbl_residents r ON c.resident_id = r.resident_id
          LEFT JOIN tbl_users u ON c.assigned_to = u.user_id
          WHERE 1=1";

$params = [];
$types  = '';

if ($is_resident && $resident_id) { $sql .= " AND c.resident_id = ?";     $params[] = $resident_id;   $types .= 'i'; }
if ($filter_status)               { $sql .= " AND TRIM(c.status) = ?";    $params[] = $filter_status; $types .= 's'; }

$sql .= $date_column ? " ORDER BY c.$date_column DESC" : " ORDER BY c.complaint_id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$complaints_result = $stmt->get_result();
$complaints = $complaints_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Statistics ───────────────────────────────────────────────────────────────
$stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
$stats_sql = "SELECT status, COUNT(*) as count FROM tbl_complaints";
if ($is_resident && $resident_id) $stats_sql .= " WHERE resident_id = " . (int)$resident_id;
$stats_sql .= " GROUP BY status";
$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    while ($row = $stats_result->fetch_assoc()) {
        $s = trim($row['status']);
        $stats['total'] += $row['count'];
        if ($s === 'Pending')     $stats['pending']     = $row['count'];
        if ($s === 'In Progress') $stats['in_progress'] = $row['count'];
        if ($s === 'Resolved')    $stats['resolved']    = $row['count'];
        if ($s === 'Closed')      $stats['closed']      = $row['count'];
    }
}

function getComplaintStatusBadge($status) {
    $status = trim($status);
    $badges = [
        'Pending'     => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>',
        'In Progress' => '<span class="badge bg-primary"><i class="fas fa-spinner me-1"></i>In Progress</span>',
        'Resolved'    => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Resolved</span>',
        'Closed'      => '<span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>Closed</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

function getComplaintPriorityBadge($priority) {
    $priority = trim($priority);
    $badges = [
        'Low'    => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Low</span>',
        'Medium' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
        'High'   => '<span class="badge bg-danger bg-opacity-75"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
        'Urgent' => '<span class="badge bg-danger"><i class="fas fa-fire me-1"></i>Urgent</span>',
    ];
    return $badges[$priority] ?? '<span class="badge bg-secondary">' . htmlspecialchars($priority) . '</span>';
}

include '../../includes/header.php';
?>

<style>
/* ── Variables ── */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* ── Cards ── */
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

/* ── Stat Cards ── */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
}

.stat-card.active-card {
    border: 2px solid #0d6efd !important;
    background: linear-gradient(to bottom, #ffffff, #f8f9ff);
}

/* ── Table ── */
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
    white-space: nowrap;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13,110,253,0.03) 0%, rgba(13,110,253,0.05) 100%);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.complaint-row {
    transition: all 0.2s;
    background: white;
    cursor: pointer;
}

.complaint-row:hover {
    background: #f8f9fa;
    box-shadow: inset 3px 0 0 #0d6efd;
}

/* Resident rows — normal hover, no pointer cursor */
.complaint-row-resident {
    transition: background 0.2s;
    background: white;
}

.complaint-row-resident:hover {
    background: #f8f9fa;
}

/* ── Badges ── */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* ── Buttons ── */
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

.btn:active { transform: translateY(0); }

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* ── Alerts ── */
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

/* ── Empty State ── */
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

/* ── Hover Preview Card ── */
.complaint-preview-card {
    position: fixed;
    z-index: 9999;
    width: 320px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.10);
    border: 1px solid #e9ecef;
    overflow: hidden;
    pointer-events: none;
    animation: previewFadeIn 0.18s ease;
}

@keyframes previewFadeIn {
    from { opacity: 0; transform: translateY(6px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.complaint-preview-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f0f0f0;
}

.complaint-preview-icon-wrap { flex-shrink: 0; }

.complaint-preview-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.complaint-preview-header-text { flex: 1; min-width: 0; }

.complaint-preview-type-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 2px;
}

.complaint-preview-title {
    font-size: 0.92rem;
    font-weight: 700;
    color: #212529;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.complaint-preview-body { padding: 12px 16px 14px; }

.complaint-preview-message {
    font-size: 0.82rem;
    color: #495057;
    line-height: 1.6;
    margin-bottom: 10px;
}

.complaint-preview-footer {
    font-size: 0.75rem;
    color: #adb5bd;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Responsive ── */
@media (max-width: 768px) {
    .container-fluid { padding-left: 1rem; padding-right: 1rem; }
    .stat-card { margin-bottom: 1rem; }
    .table thead th, .table tbody td { font-size: 0.8rem; padding: 0.75rem; }
    .complaint-preview-card { display: none !important; }
}

html { scroll-behavior: smooth; }
</style>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-comments me-2 text-primary"></i>
                        <?php echo $is_resident ? 'My Complaints' : 'Complaints Management'; ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <?php echo $is_resident ? 'View all your submitted complaints' : 'Manage all barangay complaints'; ?>
                    </p>
                </div>
                <?php if ($is_resident): ?>
                <a href="file-complaint.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>File Complaint
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success === 'filed'): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>Complaint filed successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error === 'not_found'): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i>Complaint not found.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md">
            <a href="view-complaints.php" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo empty($filter_status) ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Total Complaints</p>
                                <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                <i class="fas fa-comments fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Pending' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Pending</p>
                                <h3 class="mb-0"><?php echo $stats['pending']; ?></h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                                <i class="fas fa-clock fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=In+Progress" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'In Progress' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">In Progress</p>
                                <h3 class="mb-0"><?php echo $stats['in_progress']; ?></h3>
                            </div>
                            <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                                <i class="fas fa-spinner fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Resolved" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Resolved' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Resolved</p>
                                <h3 class="mb-0"><?php echo $stats['resolved']; ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                                <i class="fas fa-check-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Closed" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Closed' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Closed</p>
                                <h3 class="mb-0"><?php echo $stats['closed']; ?></h3>
                            </div>
                            <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle p-3">
                                <i class="fas fa-times-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Complaints Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                <?php
                if ($filter_status) echo htmlspecialchars($filter_status) . ' Complaints';
                else                echo $is_resident ? 'My Complaints' : 'All Complaints';
                ?>
                <span class="badge bg-primary ms-2"><?php echo count($complaints); ?></span>
            </h5>
        </div>
        <div class="card-body">

            <?php if (empty($complaints)): ?>
            <div class="empty-state">
                <i class="fas fa-comments d-block"></i>
                <p>No complaints found</p>
                <?php if ($filter_status): ?>
                <a href="view-complaints.php" class="btn btn-outline-primary mt-2">
                    <i class="fas fa-times me-2"></i>Clear Filter
                </a>
                <?php elseif ($is_resident): ?>
                <a href="file-complaint.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus me-2"></i>File Your First Complaint
                </a>
                <?php else: ?>
                <p class="text-muted small">No complaints have been filed yet.</p>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Complaint #</th>
                            <th>Date Filed</th>
                            <th>Category</th>
                            <?php if (!$is_resident): ?>
                            <th>Complainant</th>
                            <th>Assigned To</th>
                            <?php endif; ?>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <?php if ($is_resident): ?>
                            <th class="text-center">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($complaints as $complaint):
                        if (empty($complaint['complaint_id'])) continue;

                        $view_url  = 'complaint-details.php?id=' . $complaint['complaint_id'];
                        $priority  = trim($complaint['priority'] ?? 'Medium');
                        $date_val  = $complaint['complaint_date'] ?? null;

                        // Icon per category
                        $icon_class = 'fa-comment';
                        switch ($complaint['category']) {
                            case 'Noise':          $icon_class = 'fa-volume-up';      break;
                            case 'Garbage':        $icon_class = 'fa-trash';          break;
                            case 'Property':       $icon_class = 'fa-home';           break;
                            case 'Infrastructure': $icon_class = 'fa-road';           break;
                            case 'Public Safety':  $icon_class = 'fa-shield-alt';     break;
                            case 'Services':       $icon_class = 'fa-concierge-bell'; break;
                        }

                        // Preview color per priority (admin only)
                        $prev_color = 'primary';
                        if ($priority === 'Urgent')     $prev_color = 'danger';
                        elseif ($priority === 'High')   $prev_color = 'warning';
                        elseif ($priority === 'Medium') $prev_color = 'info';
                        elseif ($priority === 'Low')    $prev_color = 'success';

                        $prev_title   = htmlspecialchars(($complaint['complaint_number'] ?? '') . ' – ' . ($complaint['subject'] ?? ''));
                        $prev_message = htmlspecialchars(mb_strimwidth($complaint['description'] ?? '', 0, 150, '…'));
                        $prev_type    = htmlspecialchars($complaint['category'] ?? '');
                        $prev_time    = $date_val ? date('M j, Y', strtotime($date_val)) : 'N/A';

                        // Resident rows: plain, no hover data, no row-click
                        // Admin rows: hover preview + row-click
                        $row_class = $is_resident ? 'complaint-row-resident' : 'complaint-row';
                    ?>
                    <tr class="<?php echo $row_class; ?>"
                        <?php if (!$is_resident): ?>
                        data-url="<?php echo htmlspecialchars($view_url); ?>"
                        data-preview-title="<?php echo $prev_title; ?>"
                        data-preview-message="<?php echo $prev_message; ?>"
                        data-preview-type="<?php echo $prev_type; ?>"
                        data-preview-color="<?php echo $prev_color; ?>"
                        data-preview-icon="<?php echo $icon_class; ?>"
                        data-preview-time="<?php echo $prev_time; ?>"
                        <?php endif; ?>>

                        <td><strong class="text-primary"><?php echo htmlspecialchars($complaint['complaint_number'] ?? 'N/A'); ?></strong></td>
                        <td>
                            <i class="fas fa-calendar-alt text-muted me-1"></i>
                            <?php echo $date_val ? date('M d, Y', strtotime($date_val)) : 'N/A'; ?>
                            <?php if ($date_val): ?>
                            <br><small class="text-muted"><?php echo date('h:i A', strtotime($date_val)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-info">
                                <i class="fas <?php echo $icon_class; ?> me-1"></i>
                                <?php echo htmlspecialchars($complaint['category'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <?php if (!$is_resident): ?>
                        <td>
                            <i class="fas fa-user text-muted me-1"></i>
                            <?php echo htmlspecialchars($complaint['complainant_name'] ?? 'Unknown'); ?>
                        </td>
                        <td>
                            <?php if (!empty($complaint['assigned_to_name'])): ?>
                                <i class="fas fa-user-tie me-1 text-info"></i>
                                <?php echo htmlspecialchars($complaint['assigned_to_name']); ?>
                            <?php else: ?>
                                <span class="text-muted"><i>Not assigned</i></span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <strong><?php echo htmlspecialchars($complaint['subject'] ?? 'N/A'); ?></strong>
                            <?php if (!empty($complaint['description'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($complaint['description'], 0, 50)); ?>…</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo getComplaintPriorityBadge($complaint['priority'] ?? 'Medium'); ?></td>
                        <td><?php echo getComplaintStatusBadge($complaint['status'] ?? 'Pending'); ?></td>
                        <?php if ($is_resident): ?>
                        <td class="text-center">
                            <a href="<?php echo htmlspecialchars($view_url); ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye me-1"></i>View
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<?php if (!$is_resident): ?>
<!-- Hover Preview Card — Admin/Staff only -->
<div id="complaintPreviewCard" class="complaint-preview-card" style="display:none;">
    <div class="complaint-preview-header">
        <div class="complaint-preview-icon-wrap">
            <div class="complaint-preview-icon" id="previewIconBox">
                <i class="fas fa-comment" id="previewIcon"></i>
            </div>
        </div>
        <div class="complaint-preview-header-text">
            <div class="complaint-preview-type-label" id="previewTypeLabel"></div>
            <div class="complaint-preview-title"      id="previewTitle"></div>
        </div>
    </div>
    <div class="complaint-preview-body">
        <p class="complaint-preview-message" id="previewMessage"></p>
        <div class="complaint-preview-footer">
            <i class="far fa-calendar-alt"></i>
            <span id="previewTime"></span>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Auto-dismiss alerts
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () { try { new bootstrap.Alert(el).close(); } catch(e){} }, 5000);
    });

    // ── Hover Preview — Admin/Staff only (.complaint-row) ──
    const card       = document.getElementById('complaintPreviewCard');
    <?php if (!$is_resident): ?>
    const iconBox    = document.getElementById('previewIconBox');
    const icon       = document.getElementById('previewIcon');
    const titleEl    = document.getElementById('previewTitle');
    const messageEl  = document.getElementById('previewMessage');
    const typeEl     = document.getElementById('previewTypeLabel');
    const timeEl     = document.getElementById('previewTime');

    const colorMap = {
        primary  : { bg: 'rgba(13,110,253,0.12)',  text: '#0d6efd' },
        warning  : { bg: 'rgba(255,193,7,0.12)',   text: '#d39e00' },
        success  : { bg: 'rgba(25,135,84,0.12)',   text: '#198754' },
        info     : { bg: 'rgba(13,202,240,0.12)',  text: '#0aa2c0' },
        danger   : { bg: 'rgba(220,53,69,0.12)',   text: '#dc3545' },
        secondary: { bg: 'rgba(108,117,125,0.12)', text: '#6c757d' },
    };

    let hideTimer = null;

    function positionCard(e) {
        const margin = 16;
        const cw = card.offsetWidth  || 320;
        const ch = card.offsetHeight || 200;
        let x = e.clientX + margin;
        let y = e.clientY + margin;
        if (x + cw > window.innerWidth  - margin) x = e.clientX - cw - margin;
        if (y + ch > window.innerHeight - margin) y = e.clientY - ch - margin;
        card.style.left = x + 'px';
        card.style.top  = y + 'px';
    }

    function showCard(row, e) {
        clearTimeout(hideTimer);
        const c = colorMap[row.dataset.previewColor] || colorMap.primary;
        titleEl.textContent   = row.dataset.previewTitle;
        messageEl.textContent = row.dataset.previewMessage;
        typeEl.textContent    = row.dataset.previewType;
        timeEl.textContent    = row.dataset.previewTime;
        icon.className        = 'fas ' + row.dataset.previewIcon;
        iconBox.style.background = c.bg;
        icon.style.color         = c.text;
        positionCard(e);
        card.style.display = 'block';
    }

    function hideCard() { card.style.display = 'none'; }

    document.querySelectorAll('.complaint-row').forEach(function (row) {
        row.addEventListener('mouseenter', function (e) { showCard(this, e); });
        row.addEventListener('mousemove',  function (e) { positionCard(e); });
        row.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(function () {
                if (!card.matches(':hover')) hideCard();
            }, 150);
        });
        // Click whole row to navigate
        row.addEventListener('click', function (e) {
            if (!e.target.closest('a,button')) window.location.href = this.dataset.url;
        });
    });
    <?php endif; ?>
});
</script>

<?php include '../../includes/footer.php'; ?>