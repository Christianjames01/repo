<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Super Admin', 'Admin', 'Barangay Captain', 'Barangay Tanod']);

$page_title = 'Incident Reports';

$date_from     = isset($_GET['date_from'])     ? $_GET['date_from']                        : date('Y-m-01');
$date_to       = isset($_GET['date_to'])       ? $_GET['date_to']                          : date('Y-m-d');
$incident_type = isset($_GET['incident_type']) ? sanitizeInput($_GET['incident_type'])      : '';
$severity      = isset($_GET['severity'])      ? sanitizeInput($_GET['severity'])           : '';

// ── Statistics ───────────────────────────────────────────────────────────────
$stats_sql = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN severity = 'Critical' THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN severity = 'High'     THEN 1 ELSE 0 END) as high,
    SUM(CASE WHEN severity = 'Medium'   THEN 1 ELSE 0 END) as medium,
    SUM(CASE WHEN severity = 'Low'      THEN 1 ELSE 0 END) as low,
    SUM(CASE WHEN status = 'Resolved'                         THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN status IN ('Reported','Pending')            THEN 1 ELSE 0 END) as reported,
    SUM(CASE WHEN status = 'Under Investigation'              THEN 1 ELSE 0 END) as investigating
    FROM tbl_incidents WHERE DATE(date_reported) BETWEEN ? AND ?";

$s_params = [$date_from, $date_to]; $s_types = 'ss';
if ($incident_type) { $stats_sql .= " AND incident_type = ?"; $s_params[] = $incident_type; $s_types .= 's'; }
if ($severity)      { $stats_sql .= " AND severity = ?";      $s_params[] = $severity;      $s_types .= 's'; }

$stmt = $conn->prepare($stats_sql);
$stmt->bind_param($s_types, ...$s_params);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$resolution_rate = $stats['total'] > 0 ? round(($stats['resolved'] / $stats['total']) * 100, 1) : 0;

// ── Type distribution ────────────────────────────────────────────────────────
$type_sql = "SELECT incident_type, COUNT(*) as count FROM tbl_incidents WHERE DATE(date_reported) BETWEEN ? AND ?";
$t_params = [$date_from, $date_to]; $t_types = 'ss';
if ($severity) { $type_sql .= " AND severity = ?"; $t_params[] = $severity; $t_types .= 's'; }
$type_sql .= " GROUP BY incident_type ORDER BY count DESC";
$stmt = $conn->prepare($type_sql); $stmt->bind_param($t_types, ...$t_params); $stmt->execute();
$type_distribution = $stmt->get_result(); $stmt->close();

// ── Status distribution ──────────────────────────────────────────────────────
$status_sql = "SELECT status, COUNT(*) as count FROM tbl_incidents WHERE DATE(date_reported) BETWEEN ? AND ?";
$st_params = [$date_from, $date_to]; $st_types = 'ss';
if ($incident_type) { $status_sql .= " AND incident_type = ?"; $st_params[] = $incident_type; $st_types .= 's'; }
if ($severity)      { $status_sql .= " AND severity = ?";      $st_params[] = $severity;      $st_types .= 's'; }
$status_sql .= " GROUP BY status ORDER BY count DESC";
$stmt = $conn->prepare($status_sql); $stmt->bind_param($st_types, ...$st_params); $stmt->execute();
$status_distribution = $stmt->get_result(); $stmt->close();

// ── Detailed list ────────────────────────────────────────────────────────────
$detail_sql = "SELECT i.*, CONCAT(r.first_name,' ',r.last_name) as reporter_name,
               u.username as responder_name
               FROM tbl_incidents i
               LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
               LEFT JOIN tbl_users u ON i.responder_id = u.user_id
               WHERE DATE(i.date_reported) BETWEEN ? AND ?";
$d_params = [$date_from, $date_to]; $d_types = 'ss';
if ($incident_type) { $detail_sql .= " AND i.incident_type = ?"; $d_params[] = $incident_type; $d_types .= 's'; }
if ($severity)      { $detail_sql .= " AND i.severity = ?";      $d_params[] = $severity;      $d_types .= 's'; }
$detail_sql .= " ORDER BY i.date_reported DESC";
$stmt = $conn->prepare($detail_sql); $stmt->bind_param($d_types, ...$d_params); $stmt->execute();
$incidents = $stmt->get_result(); $stmt->close();

include '../../includes/header.php';
?>

<style>
:root { --ts:.3s; --sh-sm:0 2px 8px rgba(0,0,0,.08); --sh-md:0 4px 16px rgba(0,0,0,.12); --br:12px; }

.card { border:none !important; border-radius:var(--br); box-shadow:var(--sh-sm); transition:box-shadow var(--ts),transform var(--ts); overflow:hidden; }
.card:hover { box-shadow:var(--sh-md); transform:translateY(-2px); }

.card-header { background:linear-gradient(135deg,#f8f9fa 0%,#fff 100%); border-bottom:2px solid #e9ecef; padding:1.25rem 1.5rem; }
.card-header h5 { font-weight:700; font-size:1.05rem; margin:0; display:flex; align-items:center; }

.stat-card { cursor:default; }
.stat-card:hover { transform:translateY(-4px) !important; box-shadow:var(--sh-md) !important; }

.table { margin-bottom:0; }
.table thead th { background:linear-gradient(135deg,#f8f9fa 0%,#fff 100%); border-bottom:2px solid #dee2e6; font-weight:700; font-size:.82rem; text-transform:uppercase; letter-spacing:.5px; color:#495057; padding:.9rem 1rem; white-space:nowrap; }
.table tbody tr { border-bottom:1px solid #f1f3f5; transition:background var(--ts); }
.table tbody tr:hover { background:linear-gradient(135deg,rgba(13,110,253,.03),rgba(13,110,253,.05)); box-shadow:inset 3px 0 0 #0d6efd; }
.table tbody td { padding:.9rem 1rem; vertical-align:middle; }

.badge { font-weight:600; padding:.4rem .85rem; border-radius:50px; font-size:.78rem; }

.form-label { font-weight:600; font-size:.88rem; color:#1a1a1a; }
.form-control, .form-select { border:2px solid #e9ecef; border-radius:8px; padding:.6rem 1rem; transition:all var(--ts); }
.form-control:focus, .form-select:focus { border-color:#0d6efd; box-shadow:0 0 0 4px rgba(13,110,253,.1); }

.btn { border-radius:8px; font-weight:600; transition:all var(--ts); }
.btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.15); }

.empty-state { text-align:center; padding:4rem 2rem; color:#6c757d; }
.empty-state i { font-size:3.5rem; opacity:.25; margin-bottom:1.25rem; }

@media print {
    .no-print { display:none !important; }
    .card { break-inside:avoid; }
}
</style>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-chart-bar me-2 text-primary"></i>Incident Reports &amp; Analytics
                    </h2>
                    <p class="text-muted mb-0">Generate and analyze incident data</p>
                </div>
                <div class="d-flex gap-2 no-print">
                    <button onclick="window.print()" class="btn btn-outline-primary">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <button onclick="exportToCSV()" class="btn btn-success">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </button>
                    <a href="manage-incidents.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-header">
            <h5><i class="fas fa-filter me-2 text-primary"></i>Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?php echo htmlspecialchars($date_from); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?php echo htmlspecialchars($date_to); ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Incident Type</label>
                    <select name="incident_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Crime"            <?php echo $incident_type === 'Crime'            ? 'selected' : ''; ?>>Crime</option>
                        <option value="Fire"             <?php echo $incident_type === 'Fire'             ? 'selected' : ''; ?>>Fire</option>
                        <option value="Accident"         <?php echo $incident_type === 'Accident'         ? 'selected' : ''; ?>>Accident</option>
                        <option value="Health Emergency" <?php echo $incident_type === 'Health Emergency' ? 'selected' : ''; ?>>Health Emergency</option>
                        <option value="Violation"        <?php echo $incident_type === 'Violation'        ? 'selected' : ''; ?>>Violation</option>
                        <option value="Natural Disaster" <?php echo $incident_type === 'Natural Disaster' ? 'selected' : ''; ?>>Natural Disaster</option>
                        <option value="Others"           <?php echo $incident_type === 'Others'           ? 'selected' : ''; ?>>Others</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Severity</label>
                    <select name="severity" class="form-select">
                        <option value="">All Levels</option>
                        <option value="Low"      <?php echo $severity === 'Low'      ? 'selected' : ''; ?>>Low</option>
                        <option value="Medium"   <?php echo $severity === 'Medium'   ? 'selected' : ''; ?>>Medium</option>
                        <option value="High"     <?php echo $severity === 'High'     ? 'selected' : ''; ?>>High</option>
                        <option value="Critical" <?php echo $severity === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-chart-bar me-1"></i>Generate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><p class="text-muted mb-1 small">Total</p><h3 class="mb-0"><?php echo $stats['total']; ?></h3></div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3"><i class="fas fa-exclamation-triangle fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><p class="text-muted mb-1 small">Critical</p><h3 class="mb-0 text-danger"><?php echo $stats['critical']; ?></h3></div>
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3"><i class="fas fa-fire fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><p class="text-muted mb-1 small">High</p><h3 class="mb-0 text-warning"><?php echo $stats['high']; ?></h3></div>
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3"><i class="fas fa-exclamation-circle fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><p class="text-muted mb-1 small">Medium</p><h3 class="mb-0 text-info"><?php echo $stats['medium']; ?></h3></div>
                        <div class="bg-info bg-opacity-10 text-info rounded-circle p-3"><i class="fas fa-minus-circle fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><p class="text-muted mb-1 small">Resolved</p><h3 class="mb-0 text-success"><?php echo $stats['resolved']; ?></h3></div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3"><i class="fas fa-check-circle fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><p class="text-muted mb-1 small">Resolution Rate</p><h3 class="mb-0 text-primary"><?php echo $resolution_rate; ?>%</h3></div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3"><i class="fas fa-percentage fs-4"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row -->
    <div class="row mb-4 g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2 text-primary"></i>Incident Type Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="typeChart" height="260"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2 text-danger"></i>Severity Breakdown</h5>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="severityChart" height="260"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Status chart -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2 text-info"></i>Status Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" height="90"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed table -->
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Detailed Incident List
                    <span class="badge bg-primary ms-2"><?php echo $stats['total']; ?></span>
                </h5>
                <small class="text-muted">
                    <?php echo date('M d, Y', strtotime($date_from)); ?> –
                    <?php echo date('M d, Y', strtotime($date_to)); ?>
                </small>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if ($incidents && $incidents->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="incidentsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Reporter</th>
                            <th>Responder</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($incident = $incidents->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($incident['reference_no']); ?></strong></td>
                        <td><small><?php echo formatDate($incident['date_reported'], 'M d, Y'); ?></small></td>
                        <td>
                            <i class="fas fa-tag me-1 text-primary"></i>
                            <?php echo htmlspecialchars($incident['incident_type']); ?>
                        </td>
                        <td>
                            <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                            <?php
                            $loc = $incident['location'];
                            echo htmlspecialchars(strlen($loc) > 30 ? substr($loc, 0, 30) . '…' : $loc);
                            ?>
                        </td>
                        <td><?php echo getSeverityBadge($incident['severity']); ?></td>
                        <td><?php echo getStatusBadge($incident['status']); ?></td>
                        <td><?php echo htmlspecialchars($incident['reporter_name']  ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($incident['responder_name'] ?? 'Unassigned'); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox d-block mb-3"></i>
                <p class="fw-500">No incidents found for the selected period</p>
                <p class="text-muted small">Try adjusting the date range or filters above.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// ── Collect PHP data ─────────────────────────────────────────────────────────
<?php
// Re-read result sets for JS (they were already iterated above for the table, so re-query)
$type_distribution->data_seek(0);
$typeLabels = []; $typeCounts = [];
while ($r = $type_distribution->fetch_assoc()) { $typeLabels[] = $r['incident_type']; $typeCounts[] = (int)$r['count']; }

$status_distribution->data_seek(0);
$statusLabels = []; $statusCounts = [];
while ($r = $status_distribution->fetch_assoc()) { $statusLabels[] = $r['status']; $statusCounts[] = (int)$r['count']; }
?>
const typeLabels   = <?php echo json_encode($typeLabels);   ?>;
const typeCounts   = <?php echo json_encode($typeCounts);   ?>;
const statusLabels = <?php echo json_encode($statusLabels); ?>;
const statusCounts = <?php echo json_encode($statusCounts); ?>;
const severityData = [
    <?php echo (int)$stats['critical']; ?>,
    <?php echo (int)$stats['high'];     ?>,
    <?php echo (int)$stats['medium'];   ?>,
    <?php echo (int)$stats['low'];      ?>
];

const defaultFont = { family: 'inherit', size: 13 };

// ── Type chart ───────────────────────────────────────────────────────────────
new Chart(document.getElementById('typeChart'), {
    type: 'bar',
    data: {
        labels: typeLabels,
        datasets: [{
            label: 'Incidents',
            data: typeCounts,
            backgroundColor: 'rgba(13,110,253,.8)',
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1, font: defaultFont } },
            x: { ticks: { font: defaultFont } }
        }
    }
});

// ── Severity chart ───────────────────────────────────────────────────────────
new Chart(document.getElementById('severityChart'), {
    type: 'doughnut',
    data: {
        labels: ['Critical', 'High', 'Medium', 'Low'],
        datasets: [{
            data: severityData,
            backgroundColor: ['#dc3545','#ffc107','#0dcaf0','#198754'],
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right', labels: { font: defaultFont, padding: 16 } }
        }
    }
});

// ── Status chart ─────────────────────────────────────────────────────────────
const statusColors = ['#ffc107','#0dcaf0','#0d6efd','#198754','#6c757d','#fd7e14'];
new Chart(document.getElementById('statusChart'), {
    type: 'bar',
    data: {
        labels: statusLabels,
        datasets: [{
            label: 'Incidents',
            data: statusCounts,
            backgroundColor: statusLabels.map((_, i) => statusColors[i % statusColors.length]),
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { stepSize: 1, font: defaultFont } },
            y: { ticks: { font: defaultFont } }
        }
    }
});

// ── CSV export ───────────────────────────────────────────────────────────────
function exportToCSV() {
    const table = document.getElementById('incidentsTable');
    if (!table) return;
    const rows = [];
    rows.push([...table.querySelectorAll('thead th')].map(th => '"' + th.textContent.trim() + '"').join(','));
    table.querySelectorAll('tbody tr').forEach(tr => {
        const cells = [...tr.querySelectorAll('td')].map(td => '"' + td.textContent.trim().replace(/"/g, '""') + '"');
        if (cells.length) rows.push(cells.join(','));
    });
    const blob = new Blob([rows.join('\n')], { type: 'text/csv' });
    const a    = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: 'incident_report_<?php echo date('Y-m-d'); ?>.csv' });
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>

<?php include '../../includes/footer.php'; ?>