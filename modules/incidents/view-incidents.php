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

$page_title = 'View Incidents';

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
            header('Location: not-verified-incidents.php');
            exit();
        }
    }
}

// Only status filter from stat card clicks
$filter_status = $_GET['status'] ?? '';

$sql = "SELECT i.*, 
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        CONCAT(resp_r.first_name, ' ', resp_r.last_name) as responder_name
        FROM tbl_incidents i
        JOIN tbl_residents r ON i.resident_id = r.resident_id
        LEFT JOIN tbl_users resp_u ON i.responder_id = resp_u.user_id
        LEFT JOIN tbl_residents resp_r ON resp_u.resident_id = resp_r.resident_id
        WHERE 1=1";

$params = [];
$types  = '';

if ($is_resident && $resident_id) {
    $sql .= " AND i.resident_id = ?";
    $params[] = $resident_id;
    $types   .= 'i';
}
if (!empty($filter_status)) {
    $sql .= " AND i.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}

$sql .= " ORDER BY i.date_reported DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$incidents = [];
while ($row = $result->fetch_assoc()) $incidents[] = $row;

// Statistics
$stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
$stats_sql = "SELECT status, COUNT(*) as count FROM tbl_incidents";
if ($is_resident && $resident_id) $stats_sql .= " WHERE resident_id = " . (int)$resident_id;
$stats_sql .= " GROUP BY status";
$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    while ($row = $stats_result->fetch_assoc()) {
        $stats['total'] += $row['count'];
        if ($row['status'] === 'Pending')     $stats['pending']     = $row['count'];
        if ($row['status'] === 'In Progress') $stats['in_progress'] = $row['count'];
        if ($row['status'] === 'Resolved')    $stats['resolved']    = $row['count'];
        if ($row['status'] === 'Closed')      $stats['closed']      = $row['count'];
    }
}

include '../../includes/header.php';
?>

<style>
:root { --transition-speed: 0.3s; --shadow-sm: 0 2px 8px rgba(0,0,0,0.08); --shadow-md: 0 4px 16px rgba(0,0,0,0.12); --border-radius: 12px; }

.card { border: none; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); transition: all var(--transition-speed) ease; overflow: hidden; }
.card:hover { box-shadow: var(--shadow-md); transform: translateY(-4px); }
.card-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-bottom: 2px solid #e9ecef; padding: 1.25rem 1.5rem; font-weight: 700; }
.card-body { padding: 1.75rem; }

/* Stat Cards */
.stat-card-link { text-decoration: none; display: block; }
.stat-card {
    background: white; border-radius: var(--border-radius);
    padding: 1.5rem; box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    height: 100%; text-align: center;
    border: 2px solid transparent;
}
.stat-card-link:hover .stat-card { box-shadow: var(--shadow-md); transform: translateY(-4px); }
.stat-card.active-filter { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,0.15); }
.stat-icon { width: 64px; height: 64px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem; }
.stat-value { font-size: 2.5rem; font-weight: 800; color: #1a1a1a; line-height: 1; margin-bottom: 0.5rem; }
.stat-label { font-size: 0.875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; }

/* Table */
.table { margin-bottom: 0; }
.table thead th { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-bottom: 2px solid #dee2e6; font-weight: 700; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; color: #495057; padding: 1rem; }
.table tbody tr { transition: all var(--transition-speed) ease; border-bottom: 1px solid #f1f3f5; }
.table tbody tr:hover { background: linear-gradient(135deg, rgba(13,110,253,0.03) 0%, rgba(13,110,253,0.05) 100%); }
.table tbody td { padding: 1rem; vertical-align: middle; }

.badge { font-weight: 600; padding: 0.5rem 1rem; border-radius: 50px; font-size: 0.85rem; letter-spacing: 0.3px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.btn { border-radius: 8px; padding: 0.625rem 1.5rem; font-weight: 600; transition: all var(--transition-speed) ease; border: none; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; }

.empty-state { text-align: center; padding: 4rem 2rem; color: #6c757d; }
.empty-state i { font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.3; }
.empty-state p { font-size: 1.1rem; font-weight: 500; margin-bottom: 1rem; }

/* Active filter banner */
.filter-active-bar {
    background: linear-gradient(135deg, rgba(13,110,253,0.08), rgba(13,110,253,0.04));
    border: 1px solid rgba(13,110,253,0.2);
    border-radius: 8px; padding: 0.6rem 1rem;
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.875rem; color: #0d6efd; font-weight: 600;
}

@media (max-width: 768px) {
    .stat-card { padding: 1.25rem; margin-bottom: 1rem; }
    .stat-value { font-size: 2rem; }
    .stat-icon { width: 56px; height: 56px; font-size: 1.5rem; }
    .table thead th, .table tbody td { font-size: 0.875rem; padding: 0.75rem; }
}
html { scroll-behavior: smooth; }
</style>

<div class="container-fluid py-4">

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                        <?= $is_resident ? 'My Incident Reports' : 'Incident Reports' ?>
                    </h2>
                    <p class="text-muted mb-0">
                        <?= $is_resident ? 'View all your reported incidents' : 'All incident reports in the system' ?>
                    </p>
                </div>
                <?php if ($is_resident): ?>
                    <a href="report-incident.php" class="btn btn-danger">
                        <i class="fas fa-plus me-2"></i>Report Incident
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards (clickable filters) -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3 mb-md-0">
            <a href="view-incidents.php" class="stat-card-link">
                <div class="stat-card <?= $filter_status === '' ? 'active-filter' : '' ?>">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total Incidents</div>
                </div>
            </a>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <a href="?status=Pending" class="stat-card-link">
                <div class="stat-card <?= $filter_status === 'Pending' ? 'active-filter' : '' ?>">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value text-warning"><?= $stats['pending'] ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </a>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <a href="?status=In+Progress" class="stat-card-link">
                <div class="stat-card <?= $filter_status === 'In Progress' ? 'active-filter' : '' ?>">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-value text-info"><?= $stats['in_progress'] ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="?status=Resolved" class="stat-card-link">
                <div class="stat-card <?= $filter_status === 'Resolved' ? 'active-filter' : '' ?>">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value text-success"><?= $stats['resolved'] ?></div>
                    <div class="stat-label">Resolved</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Active filter banner -->
    <?php if ($filter_status): ?>
    <div class="row mb-3">
        <div class="col-12">
            <div class="filter-active-bar">
                <i class="fas fa-filter"></i>
                Showing: <strong><?= htmlspecialchars($filter_status) ?></strong> incidents
                <a href="view-incidents.php" class="ms-auto btn btn-sm btn-outline-primary py-0">
                    <i class="fas fa-times me-1"></i>Clear Filter
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Incidents Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    <?= $filter_status ? htmlspecialchars($filter_status) . ' Incidents' : 'All Incident Reports' ?>
                    <span class="badge bg-primary ms-2"><?= count($incidents) ?></span>
                </h5>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($incidents)): ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>No Incident Reports Found</p>
                    <p class="text-muted mb-3">
                        <?= $filter_status
                            ? "No $filter_status incidents found."
                            : ($is_resident ? "You haven't reported any incidents yet." : "No incidents have been reported yet.") ?>
                    </p>
                    <?php if ($filter_status): ?>
                        <a href="view-incidents.php" class="btn btn-outline-primary me-2">
                            <i class="fas fa-times me-2"></i>Clear Filter
                        </a>
                    <?php endif; ?>
                    <?php if ($is_resident): ?>
                        <a href="report-incident.php" class="btn btn-danger">
                            <i class="fas fa-plus me-2"></i>Report Incident
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Date Reported</th>
                                <?php if (!$is_resident): ?>
                                    <th>Reporter</th>
                                    <th>Responder</th>
                                <?php endif; ?>
                                <th>Severity</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incidents as $incident):
                                $icon_class = 'fa-exclamation-triangle';
                                switch ($incident['incident_type']) {
                                    case 'Crime':            $icon_class = 'fa-user-secret';         break;
                                    case 'Fire':             $icon_class = 'fa-fire';                break;
                                    case 'Accident':         $icon_class = 'fa-car-crash';           break;
                                    case 'Health Emergency': $icon_class = 'fa-ambulance';           break;
                                    case 'Violation':        $icon_class = 'fa-gavel';               break;
                                    case 'Natural Disaster': $icon_class = 'fa-cloud-showers-heavy'; break;
                                }
                            ?>
                            <tr>
                                <td><strong class="text-primary"><?= htmlspecialchars($incident['reference_no']) ?></strong></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <i class="fas <?= $icon_class ?> me-1"></i>
                                        <?= htmlspecialchars($incident['incident_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                    <small><?= htmlspecialchars($incident['location']) ?></small>
                                </td>
                                <td>
                                    <i class="far fa-calendar me-1 text-muted"></i>
                                    <small><?= date('M d, Y', strtotime($incident['date_reported'])) ?></small><br>
                                    <small class="text-muted"><?= date('h:i A', strtotime($incident['date_reported'])) ?></small>
                                </td>
                                <?php if (!$is_resident): ?>
                                    <td><small><?= htmlspecialchars($incident['resident_name'] ?? 'Unknown') ?></small></td>
                                    <td>
                                        <?php if ($incident['responder_name']): ?>
                                            <i class="fas fa-user-shield me-1 text-info"></i>
                                            <small><?= htmlspecialchars($incident['responder_name']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted"><small><i>Not assigned</i></small></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><?= getSeverityBadge($incident['severity']) ?></td>
                                <td><?= getStatusBadge($incident['status']) ?></td>
                                <td class="text-center">
                                    <a href="incident-details.php?id=<?= intval($incident['incident_id']) ?>"
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="fas fa-eye me-1"></i>View
                                    </a>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () { new bootstrap.Alert(alert).close(); }, 5000);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>