<?php
/**
 * Manage Incidents - Admin Dashboard
 * Main page for viewing and managing all incidents
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Super Admin', 'Admin', 'Staff', 'Barangay Captain', 'Barangay Tanod']);

$page_title = 'Manage Incidents';

$success = isset($_GET['success']) ? sanitizeInput($_GET['success']) : '';
$error   = isset($_GET['error'])   ? sanitizeInput($_GET['error'])   : '';

$page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset   = ($page - 1) * $per_page;

// Only stat-card filters remain (no free-text search or type filter)
$status_filter   = isset($_GET['status'])    ? sanitizeInput($_GET['status'])    : '';
$severity_filter = isset($_GET['severity'])  ? sanitizeInput($_GET['severity'])  : '';
$date_from       = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$date_to         = isset($_GET['date_to'])   ? sanitizeInput($_GET['date_to'])   : '';

$table_check      = $conn->query("SHOW TABLES LIKE 'tbl_incident_images'");
$has_images_table = ($table_check && $table_check->num_rows > 0);

// ── Count query ──────────────────────────────────────────────────────────────
$count_sql    = "SELECT COUNT(*) as total FROM tbl_incidents WHERE 1=1";
$count_params = [];
$count_types  = '';

if ($status_filter)   { $count_sql .= " AND status = ?";               $count_params[] = $status_filter;   $count_types .= 's'; }
if ($severity_filter) { $count_sql .= " AND severity = ?";             $count_params[] = $severity_filter; $count_types .= 's'; }
if ($date_from)       { $count_sql .= " AND DATE(date_reported) >= ?"; $count_params[] = $date_from;       $count_types .= 's'; }
if ($date_to)         { $count_sql .= " AND DATE(date_reported) <= ?"; $count_params[] = $date_to;         $count_types .= 's'; }

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
$img_join = $has_images_table ? "LEFT JOIN tbl_incident_images ii ON i.incident_id = ii.incident_id" : "";
$img_col  = $has_images_table ? "COUNT(ii.image_id) as image_count" : "0 as image_count";
$img_grp  = $has_images_table ? "GROUP BY i.incident_id" : "";

$sql = "SELECT i.*, CONCAT(r.first_name, ' ', r.last_name) as reporter_name,
        r.contact_number as reporter_contact, $img_col
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        $img_join
        WHERE 1=1";

$params = [];
$types  = '';

if ($status_filter)   { $sql .= " AND i.status = ?";               $params[] = $status_filter;   $types .= 's'; }
if ($severity_filter) { $sql .= " AND i.severity = ?";             $params[] = $severity_filter; $types .= 's'; }
if ($date_from)       { $sql .= " AND DATE(i.date_reported) >= ?"; $params[] = $date_from;       $types .= 's'; }
if ($date_to)         { $sql .= " AND DATE(i.date_reported) <= ?"; $params[] = $date_to;         $types .= 's'; }

if ($img_grp) $sql .= " $img_grp";
$sql .= " ORDER BY i.date_reported DESC LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset; $types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$incidents = $stmt->get_result();
$stmt->close();

// ── Statistics ───────────────────────────────────────────────────────────────
$stats = $conn->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Reported'            THEN 1 ELSE 0 END) as reported,
    SUM(CASE WHEN status = 'Under Investigation' THEN 1 ELSE 0 END) as investigating,
    SUM(CASE WHEN status = 'In Progress'         THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'Resolved'            THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN severity = 'Critical'          THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN DATE(date_reported) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM tbl_incidents")->fetch_assoc();

function paginationQuery($overrides = []) {
    global $status_filter, $severity_filter, $date_from, $date_to;
    $merged = array_merge(
        ['status' => $status_filter, 'severity' => $severity_filter, 'date_from' => $date_from, 'date_to' => $date_to],
        $overrides
    );
    return http_build_query(array_filter($merged, fn($v) => $v !== ''));
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
.table tbody tr { transition:background var(--ts) ease; border-bottom:1px solid #f1f3f5; cursor:pointer; }
.table tbody tr:hover { background:linear-gradient(135deg,rgba(13,110,253,.03) 0%,rgba(13,110,253,.05) 100%); box-shadow:inset 3px 0 0 #0d6efd; }
.table tbody td { padding:1rem; vertical-align:middle; }

.badge { font-weight:600; padding:.4rem .85rem; border-radius:50px; font-size:.8rem; letter-spacing:.3px; }

.alert { border:none; border-radius:var(--br); border-left:4px solid; box-shadow:var(--sh-sm); }
.alert-success { background:linear-gradient(135deg,#d1f4e0,#e7f9ee); border-left-color:#198754; }
.alert-danger  { background:linear-gradient(135deg,#ffd6d6,#ffe5e5); border-left-color:#dc3545; }

.empty-state { text-align:center; padding:4rem 2rem; color:#6c757d; }
.empty-state i { font-size:4rem; opacity:.3; margin-bottom:1.5rem; }
.empty-state p { font-size:1.1rem; font-weight:500; }

.incident-preview-card { position:fixed; z-index:9999; width:320px; background:#fff; border-radius:var(--br); box-shadow:var(--sh-lg),0 2px 8px rgba(0,0,0,.10); border:1px solid #e9ecef; overflow:hidden; pointer-events:none; animation:previewIn .18s ease; }
@keyframes previewIn { from{opacity:0;transform:translateY(6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.preview-header { display:flex; align-items:center; gap:12px; padding:14px 16px 10px; border-bottom:1px solid #f0f0f0; }
.preview-icon-box { flex-shrink:0; width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
.preview-header-text { flex:1; min-width:0; }
.preview-type-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; margin-bottom:2px; }
.preview-title { font-size:.92rem; font-weight:700; color:#212529; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.preview-body { padding:12px 16px 14px; }
.preview-message { font-size:.82rem; color:#495057; line-height:1.6; margin-bottom:10px; }
.preview-footer { font-size:.75rem; color:#adb5bd; display:flex; align-items:center; gap:8px; }

@media(max-width:768px){ .incident-preview-card{display:none !important} .table thead th,.table tbody td{font-size:.8rem;padding:.75rem} }
</style>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold"><i class="fas fa-list-alt me-2 text-primary"></i>Manage Incidents</h2>
                    <p class="text-muted mb-0">View and manage all incident reports</p>
                </div>
                <a href="incident-reports.php" class="btn btn-outline-primary">
                    <i class="fas fa-chart-bar me-1"></i>View Reports
                </a>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success === 'updated'): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>Incident updated successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error === 'not_found'): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i>Incident not found.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Clickable stat cards -->
    <div class="row mb-4 g-3">
        <div class="col-md">
            <a href="manage-incidents.php" class="text-decoration-none">
                <div class="card stat-card <?php echo (!$status_filter && !$severity_filter && !$date_from && !$date_to) ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Total Incidents</p><h3 class="mb-0"><?php echo $stats['total']; ?></h3></div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3"><i class="fas fa-exclamation-triangle fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Reported" class="text-decoration-none">
                <div class="card stat-card <?php echo $status_filter === 'Reported' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Pending</p><h3 class="mb-0"><?php echo $stats['reported']; ?></h3></div>
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3"><i class="fas fa-clock fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Under+Investigation" class="text-decoration-none">
                <div class="card stat-card <?php echo $status_filter === 'Under Investigation' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Investigating</p><h3 class="mb-0"><?php echo $stats['investigating']; ?></h3></div>
                            <div class="bg-info bg-opacity-10 text-info rounded-circle p-3"><i class="fas fa-search fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=In+Progress" class="text-decoration-none">
                <div class="card stat-card <?php echo $status_filter === 'In Progress' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">In Progress</p><h3 class="mb-0"><?php echo $stats['in_progress']; ?></h3></div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3"><i class="fas fa-spinner fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?severity=Critical" class="text-decoration-none">
                <div class="card stat-card <?php echo $severity_filter === 'Critical' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Critical</p><h3 class="mb-0"><?php echo $stats['critical']; ?></h3></div>
                            <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3"><i class="fas fa-fire fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="text-decoration-none">
                <div class="card stat-card <?php echo ($date_from === date('Y-m-d') && $date_to === date('Y-m-d')) ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><p class="text-muted mb-1 small">Today</p><h3 class="mb-0"><?php echo $stats['today']; ?></h3></div>
                            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3"><i class="fas fa-calendar-day fs-4"></i></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Incidents table -->
    <div class="card">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    <?php
                    if ($status_filter)                                              echo htmlspecialchars($status_filter) . ' Incidents';
                    elseif ($severity_filter)                                        echo htmlspecialchars($severity_filter) . ' Severity Incidents';
                    elseif ($date_from === date('Y-m-d') && $date_to === date('Y-m-d')) echo "Today's Incidents";
                    else                                                             echo 'All Incident Reports';
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
            <?php if ($incidents && $incidents->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Reporter</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Date Reported</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($incident = $incidents->fetch_assoc()):
                        $view_url   = 'incident-details.php?id=' . $incident['incident_id'];
                        $prev_color = 'primary';
                        if ($incident['severity'] === 'Critical')     $prev_color = 'danger';
                        elseif ($incident['severity'] === 'High')     $prev_color = 'warning';
                        elseif ($incident['severity'] === 'Medium')   $prev_color = 'info';
                        elseif ($incident['severity'] === 'Low')      $prev_color = 'success';
                        $prev_title   = htmlspecialchars($incident['reference_no'] . ' – ' . $incident['incident_type']);
                        $prev_message = htmlspecialchars(mb_strimwidth($incident['description'] ?? '', 0, 150, '…'));
                        $prev_type    = htmlspecialchars($incident['incident_type']);
                        $prev_time    = date('M j, Y', strtotime($incident['date_reported']));
                    ?>
                    <tr class="incident-row"
                        data-url="<?php echo htmlspecialchars($view_url); ?>"
                        data-preview-title="<?php echo $prev_title; ?>"
                        data-preview-message="<?php echo $prev_message; ?>"
                        data-preview-type="<?php echo $prev_type; ?>"
                        data-preview-color="<?php echo $prev_color; ?>"
                        data-preview-icon="fa-exclamation-triangle"
                        data-preview-time="<?php echo $prev_time; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($incident['reference_no']); ?></strong>
                            <?php if ($incident['image_count'] > 0): ?>
                            <br><small class="text-muted"><i class="fas fa-images"></i> <?php echo $incident['image_count']; ?></small>
                            <?php endif; ?>
                        </td>
                        <td><i class="fas fa-tag me-1 text-primary"></i><?php echo htmlspecialchars($incident['incident_type']); ?></td>
                        <td>
                            <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                            <?php $loc = $incident['location']; echo htmlspecialchars(strlen($loc) > 30 ? substr($loc, 0, 30) . '…' : $loc); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?>
                            <?php if ($incident['reporter_contact']): ?>
                            <br><small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($incident['reporter_contact']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo getSeverityBadge($incident['severity']); ?></td>
                        <td><?php echo getStatusBadge($incident['status']); ?></td>
                        <td>
                            <small><?php echo formatDate($incident['date_reported'], 'M d, Y'); ?></small>
                            <br><small class="text-muted"><?php echo formatDate($incident['date_reported'], 'h:i A'); ?></small>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox d-block mb-3"></i>
                <p>No incidents found</p>
                <?php if ($status_filter || $severity_filter || $date_from || $date_to): ?>
                <a href="manage-incidents.php" class="btn btn-outline-primary mt-2">
                    <i class="fas fa-times me-1"></i>Clear Filter
                </a>
                <?php else: ?>
                <p class="text-muted small">No incidents have been reported yet.</p>
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
<div id="incidentPreviewCard" class="incident-preview-card" style="display:none;">
    <div class="preview-header">
        <div class="preview-icon-box" id="prevIconBox"><i class="fas fa-exclamation-triangle" id="prevIcon"></i></div>
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

    const card = document.getElementById('incidentPreviewCard');
    const iconBox = document.getElementById('prevIconBox');
    const icon = document.getElementById('prevIcon');
    const title = document.getElementById('prevTitle');
    const message = document.getElementById('prevMessage');
    const type = document.getElementById('prevType');
    const time = document.getElementById('prevTime');

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
        title.textContent = row.dataset.previewTitle;
        message.textContent = row.dataset.previewMessage;
        type.textContent = row.dataset.previewType;
        time.textContent = row.dataset.previewTime;
        icon.className = 'fas ' + row.dataset.previewIcon;
        iconBox.style.background = c.bg; icon.style.color = c.text;
        positionCard(e); card.style.display = 'block';
    }

    function hideCard() { card.style.display = 'none'; }

    document.querySelectorAll('.incident-row').forEach(function (row) {
        row.addEventListener('mouseenter', function (e) { showCard(this, e); });
        row.addEventListener('mousemove',  function (e) { positionCard(e); });
        row.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(function () { if (!card.matches(':hover')) hideCard(); }, 150);
        });
        row.addEventListener('click', function () { if (this.dataset.url) window.location.href = this.dataset.url; });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>