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

$page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$filter_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

// Detect date column
$date_columns = ['created_at', 'date_filed', 'date_created'];
$date_column  = null;
foreach ($date_columns as $col) {
    if (columnExists($conn, 'tbl_complaints', $col)) { $date_column = $col; break; }
}

// ── Count query ──────────────────────────────────────────────────────────────
$count_sql    = "SELECT COUNT(*) as total FROM tbl_complaints WHERE 1=1";
$count_params = [];
$count_types  = '';

if ($is_resident && $resident_id) { $count_sql .= " AND resident_id = ?";      $count_params[] = $resident_id;    $count_types .= 'i'; }
if ($filter_status)               { $count_sql .= " AND TRIM(status) = ?";     $count_params[] = $filter_status;  $count_types .= 's'; }

if (!empty($count_params)) {
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $total_records = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
} else {
    $total_records = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $per_page);

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
$sql .= " LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset; $types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$complaints_result = $stmt->get_result();
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

function paginationQuery($overrides = []) {
    global $filter_status;
    $merged = array_merge(['status' => $filter_status], $overrides);
    return http_build_query(array_filter($merged, fn($v) => $v !== ''));
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
:root { --ts:.3s; --sh-sm:0 2px 8px rgba(0,0,0,.08); --sh-md:0 4px 16px rgba(0,0,0,.12); --sh-lg:0 8px 24px rgba(0,0,0,.15); --br:12px; }

.card { border:none !important; border-radius:var(--br); box-shadow:var(--sh-sm); transition:box-shadow var(--ts) ease,transform var(--ts) ease; overflow:hidden; }
.card:hover { box-shadow:var(--sh-md); transform:translateY(-2px); }
.card-header { background:linear-gradient(135deg,#f8f9fa 0%,#fff 100%); border-bottom:2px solid #e9ecef; padding:1.25rem 1.5rem; }
.card-header h5 { font-weight:700; font-size:1.1rem; margin:0; display:flex; align-items:center; }

.stat-card { cursor:pointer; }
.stat-card:hover       { transform:translateY(-4px) !important; box-shadow:var(--sh-md) !important; }
.stat-card.active-card { border:2px solid #0d6efd !important; background:linear-gradient(to bottom,#fff,#f8f9ff); }

.table { margin-bottom:0; }
.table thead th { background:linear-gradient(135deg,#f8f9fa 0%,#fff 100%); border-bottom:2px solid #dee2e6; font-weight:700; font-size:.85rem; text-transform:uppercase; letter-spacing:.5px; color:#495057; padding:1rem; white-space:nowrap; }
.table tbody tr { transition:background var(--ts) ease; border-bottom:1px solid #f1f3f5; <?php echo !$is_resident ? 'cursor:pointer;' : ''; ?> }
.table tbody tr:hover { background:linear-gradient(135deg,rgba(13,110,253,.03) 0%,rgba(13,110,253,.05) 100%); <?php echo !$is_resident ? 'box-shadow:inset 3px 0 0 #0d6efd;' : ''; ?> }
.table tbody td { padding:1rem; vertical-align:middle; }

.badge { font-weight:600; padding:.4rem .85rem; border-radius:50px; font-size:.8rem; letter-spacing:.3px; }

.alert { border:none; border-radius:var(--br); border-left:4px solid; box-shadow:var(--sh-sm); }
.alert-success { background:linear-gradient(135deg,#d1f4e0,#e7f9ee); border-left-color:#198754; }
.alert-danger  { background:linear-gradient(135deg,#ffd6d6,#ffe5e5); border-left-color:#dc3545; }

.empty-state { text-align:center; padding:4rem 2rem; color:#6c757d; }
.empty-state i { font-size:4rem; opacity:.3; margin-bottom:1.5rem; }
.empty-state p { font-size:1.1rem; font-weight:500; }

.complaint-preview-card { position:fixed; z-index:9999; width:320px; background:#fff; border-radius:var(--br); box-shadow:var(--sh-lg),0 2px 8px rgba(0,0,0,.10); border:1px solid #e9ecef; overflow:hidden; pointer-events:none; animation:previewIn .18s ease; }
@keyframes previewIn { from{opacity:0;transform:translateY(6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.preview-header { display:flex; align-items:center; gap:12px; padding:14px 16px 10px; border-bottom:1px solid #f0f0f0; }
.preview-icon-box { flex-shrink:0; width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
.preview-header-text { flex:1; min-width:0; }
.preview-type-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; margin-bottom:2px; }
.preview-title { font-size:.92rem; font-weight:700; color:#212529; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.preview-body { padding:12px 16px 14px; }
.preview-message { font-size:.82rem; color:#495057; line-height:1.6; margin-bottom:10px; }
.preview-footer { font-size:.75rem; color:#adb5bd; display:flex; align-items:center; gap:8px; }

@media(max-width:768px){ .complaint-preview-card{display:none !important} .table thead th,.table tbody td{font-size:.8rem;padding:.75rem} }
</style>

<div class="container-fluid py-4">

    <!-- Page header -->
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
                    <i class="fas fa-plus me-1"></i>File Complaint
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

    <!-- Clickable stat cards -->
    <div class="row mb-4 g-3">
        <div class="col-md">
            <a href="view-complaints.php" class="text-decoration-none">
                <div class="card stat-card <?php echo !$filter_status ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Total Complaints</p><h3 class="mb-0"><?php echo $stats['total']; ?></h3></div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3"><i class="fas fa-comments fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Pending" class="text-decoration-none">
                <div class="card stat-card <?php echo $filter_status === 'Pending' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Pending</p><h3 class="mb-0"><?php echo $stats['pending']; ?></h3></div>
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3"><i class="fas fa-clock fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=In+Progress" class="text-decoration-none">
                <div class="card stat-card <?php echo $filter_status === 'In Progress' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">In Progress</p><h3 class="mb-0"><?php echo $stats['in_progress']; ?></h3></div>
                            <div class="bg-info bg-opacity-10 text-info rounded-circle p-3"><i class="fas fa-spinner fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Resolved" class="text-decoration-none">
                <div class="card stat-card <?php echo $filter_status === 'Resolved' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Resolved</p><h3 class="mb-0"><?php echo $stats['resolved']; ?></h3></div>
                            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3"><i class="fas fa-check-circle fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Closed" class="text-decoration-none">
                <div class="card stat-card <?php echo $filter_status === 'Closed' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Closed</p><h3 class="mb-0"><?php echo $stats['closed']; ?></h3></div>
                            <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle p-3"><i class="fas fa-times-circle fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Complaints table -->
    <div class="card">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    <?php
                    if ($filter_status) echo htmlspecialchars($filter_status) . ' Complaints';
                    else                echo $is_resident ? 'My Complaints' : 'All Complaints';
                    ?>
                    <span class="badge bg-primary ms-2"><?php echo $total_records; ?></span>
                </h5>
                <div class="text-muted small">
                    Showing <?php echo $total_records ? min($offset + 1, $total_records) : 0; ?> –
                    <?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if ($complaints_result && $complaints_result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Complaint #</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <?php if (!$is_resident): ?>
                            <th>Complainant</th>
                            <th>Assigned To</th>
                            <?php endif; ?>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date Filed</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($complaint = $complaints_result->fetch_assoc()):
                        if (empty($complaint['complaint_id'])) continue;

                        $view_url   = 'complaint-details.php?id=' . $complaint['complaint_id'];
                        $prev_color = 'primary';
                        $priority   = trim($complaint['priority'] ?? 'Medium');
                        if ($priority === 'Urgent') $prev_color = 'danger';
                        elseif ($priority === 'High')   $prev_color = 'warning';
                        elseif ($priority === 'Medium') $prev_color = 'info';
                        elseif ($priority === 'Low')    $prev_color = 'success';

                        $icon_class = 'fa-comment';
                        switch ($complaint['category']) {
                            case 'Noise':          $icon_class = 'fa-volume-up';      break;
                            case 'Garbage':        $icon_class = 'fa-trash';          break;
                            case 'Property':       $icon_class = 'fa-home';           break;
                            case 'Infrastructure': $icon_class = 'fa-road';           break;
                            case 'Public Safety':  $icon_class = 'fa-shield-alt';     break;
                            case 'Services':       $icon_class = 'fa-concierge-bell'; break;
                        }

                        $date_to_show = $complaint['complaint_date'] ?? null;
                        $prev_title   = htmlspecialchars(($complaint['complaint_number'] ?? '') . ' – ' . ($complaint['subject'] ?? ''));
                        $prev_message = htmlspecialchars(mb_strimwidth($complaint['description'] ?? '', 0, 150, '…'));
                        $prev_type    = htmlspecialchars($complaint['category'] ?? '');
                        $prev_time    = $date_to_show ? date('M j, Y', strtotime($date_to_show)) : 'N/A';
                    ?>
                    <tr class="complaint-row"
                        data-url="<?php echo htmlspecialchars($view_url); ?>"
                        data-preview-title="<?php echo $prev_title; ?>"
                        data-preview-message="<?php echo $prev_message; ?>"
                        data-preview-type="<?php echo $prev_type; ?>"
                        data-preview-color="<?php echo $prev_color; ?>"
                        data-preview-icon="<?php echo $icon_class; ?>"
                        data-preview-time="<?php echo $prev_time; ?>">
                        <td><strong class="text-primary"><?php echo htmlspecialchars($complaint['complaint_number'] ?? 'N/A'); ?></strong></td>
                        <td>
                            <strong><?php echo htmlspecialchars($complaint['subject'] ?? 'N/A'); ?></strong>
                            <?php if (!empty($complaint['description']) && strlen($complaint['description']) > 50): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($complaint['description'], 0, 50)); ?>…</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <i class="fas <?php echo $icon_class; ?> me-1 text-primary"></i>
                            <?php echo htmlspecialchars($complaint['category'] ?? 'N/A'); ?>
                        </td>
                        <?php if (!$is_resident): ?>
                        <td><?php echo htmlspecialchars($complaint['complainant_name'] ?? 'Unknown'); ?></td>
                        <td>
                            <?php if (!empty($complaint['assigned_to_name'])): ?>
                                <i class="fas fa-user-tie me-1 text-info"></i>
                                <?php echo htmlspecialchars($complaint['assigned_to_name']); ?>
                            <?php else: ?>
                                <span class="text-muted"><i>Not assigned</i></span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?php echo getComplaintPriorityBadge($complaint['priority'] ?? 'Medium'); ?></td>
                        <td><?php echo getComplaintStatusBadge($complaint['status'] ?? 'Pending'); ?></td>
                        <td>
                            <small><?php echo $date_to_show ? date('M d, Y', strtotime($date_to_show)) : 'N/A'; ?></small>
                            <?php if ($date_to_show): ?>
                            <br><small class="text-muted"><?php echo date('h:i A', strtotime($date_to_show)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="<?php echo htmlspecialchars($view_url); ?>"
                               class="btn btn-sm btn-primary"
                               onclick="event.stopPropagation();">
                                <i class="fas fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-comments d-block mb-3"></i>
                <p>No complaints found</p>
                <?php if ($filter_status): ?>
                <a href="view-complaints.php" class="btn btn-outline-primary mt-2">
                    <i class="fas fa-times me-1"></i>Clear Filter
                </a>
                <?php elseif ($is_resident): ?>
                <a href="file-complaint.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus me-1"></i>File Your First Complaint
                </a>
                <?php else: ?>
                <p class="text-muted small">No complaints have been filed yet.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white">
            <nav><ul class="pagination justify-content-center mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?<?php echo paginationQuery(['page' => $page - 1]); ?>"><i class="fas fa-chevron-left"></i> Previous</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo paginationQuery(['page' => $i]); ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <li class="page-item"><a class="page-link" href="?<?php echo paginationQuery(['page' => $page + 1]); ?>">Next <i class="fas fa-chevron-right"></i></a></li>
                <?php endif; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Hover preview card -->
<div id="complaintPreviewCard" class="complaint-preview-card" style="display:none;">
    <div class="preview-header">
        <div class="preview-icon-box" id="prevIconBox"><i class="fas fa-comment" id="prevIcon"></i></div>
        <div class="preview-header-text">
            <div class="preview-type-label" id="prevType"></div>
            <div class="preview-title" id="prevTitle"></div>
        </div>
    </div>
    <div class="preview-body">
        <p class="preview-message" id="prevMessage"></p>
        <div class="preview-footer"><i class="far fa-calendar-alt"></i><span id="prevTime"></span></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () { try { new bootstrap.Alert(el).close(); } catch(e) {} }, 5000);
    });

    const card     = document.getElementById('complaintPreviewCard');
    const iconBox  = document.getElementById('prevIconBox');
    const icon     = document.getElementById('prevIcon');
    const title    = document.getElementById('prevTitle');
    const message  = document.getElementById('prevMessage');
    const type     = document.getElementById('prevType');
    const time     = document.getElementById('prevTime');

    const colorMap = {
        primary  : { bg:'rgba(13,110,253,.12)',  text:'#0d6efd' },
        warning  : { bg:'rgba(255,193,7,.12)',   text:'#d39e00' },
        success  : { bg:'rgba(25,135,84,.12)',   text:'#198754' },
        info     : { bg:'rgba(13,202,240,.12)',  text:'#0aa2c0' },
        danger   : { bg:'rgba(220,53,69,.12)',   text:'#dc3545' },
        secondary: { bg:'rgba(108,117,125,.12)', text:'#6c757d' },
    };

    let hideTimer = null;

    function positionCard(e) {
        const m = 16, cw = card.offsetWidth || 320, ch = card.offsetHeight || 180;
        let x = e.clientX + m, y = e.clientY + m;
        if (x + cw > window.innerWidth  - m) x = e.clientX - cw - m;
        if (y + ch > window.innerHeight - m) y = e.clientY - ch - m;
        card.style.left = x + 'px'; card.style.top = y + 'px';
    }

    function showCard(row, e) {
        clearTimeout(hideTimer);
        const c = colorMap[row.dataset.previewColor] || colorMap.primary;
        title.textContent   = row.dataset.previewTitle;
        message.textContent = row.dataset.previewMessage;
        type.textContent    = row.dataset.previewType;
        time.textContent    = row.dataset.previewTime;
        icon.className      = 'fas ' + row.dataset.previewIcon;
        iconBox.style.background = c.bg;
        icon.style.color         = c.text;
        positionCard(e);
        card.style.display = 'block';
    }

    function hideCard() { card.style.display = 'none'; }

    <?php if (!$is_resident): ?>
    document.querySelectorAll('.complaint-row').forEach(function (row) {
        row.addEventListener('mouseenter', function (e) { showCard(this, e); });
        row.addEventListener('mousemove',  function (e) { positionCard(e); });
        row.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(function () { if (!card.matches(':hover')) hideCard(); }, 150);
        });
        row.addEventListener('click', function (e) {
            if (!e.target.closest('a')) window.location.href = this.dataset.url;
        });
    });
    <?php endif; ?>
});
</script>

<?php include '../../includes/footer.php'; ?>