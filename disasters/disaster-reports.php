<?php
/**
 * Disaster Reports
 * Path: barangaylink/disasters/disaster-reports.php
 */

// Don't call session_start() here - config.php handles it
require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Admin', 'Super Admin', 'Staff', 'Secretary'])) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit();
}

$page_title = 'Disaster Reports';

// Fetch all incidents for report generation
$incidents_sql = "SELECT * FROM tbl_disaster_incidents ORDER BY incident_date DESC";
$incidents = fetchAll($conn, $incidents_sql);

// Fetch all assessments
$assessments_sql = "SELECT da.*, 
                    CONCAT(r.first_name, ' ', r.last_name) as resident_name
                    FROM tbl_damage_assessments da
                    LEFT JOIN tbl_residents r ON da.resident_id = r.resident_id
                    ORDER BY da.assessment_date DESC";
$assessments = fetchAll($conn, $assessments_sql);

// Fetch evacuation centers with evacuee count
$evacuation_centers = [];
if (tableExists($conn, 'tbl_evacuation_centers')) {
    if (tableExists($conn, 'tbl_evacuee_registrations')) {
        $evacuees_sql = "SELECT ec.*, 
                        COUNT(CASE WHEN er.status = 'Active' THEN 1 END) as evacuee_count
                        FROM tbl_evacuation_centers ec
                        LEFT JOIN tbl_evacuee_registrations er ON ec.center_id = er.center_id
                        GROUP BY ec.center_id";
        $evacuation_centers = fetchAll($conn, $evacuees_sql);
    } else {
        $evacuation_centers = fetchAll($conn, "SELECT *, 0 as evacuee_count FROM tbl_evacuation_centers");
    }
}

// Calculate statistics
$total_incidents = count($incidents);
$total_assessments = count($assessments);
$total_damage_cost = array_sum(array_column($assessments, 'estimated_cost'));
$total_affected_families = array_sum(array_column($incidents, 'affected_families'));
$total_evacuees = array_sum(array_column($evacuation_centers, 'evacuee_count'));

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-file-alt text-info me-2"></i>Disaster Reports</h1>
            <p class="text-muted">Generate and view disaster-related reports</p>
        </div>
        <div>
            <button class="btn btn-success me-2" onclick="generatePDFReport()">
                <i class="fas fa-file-pdf me-2"></i>Export PDF
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print Report
            </button>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Report Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">Report Filters</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" id="date_from" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" id="date_to" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Disaster Type</label>
                    <select id="disaster_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Typhoon">Typhoon</option>
                        <option value="Flood">Flood</option>
                        <option value="Earthquake">Earthquake</option>
                        <option value="Fire">Fire</option>
                        <option value="Landslide">Landslide</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary w-100" onclick="applyFilters()">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row g-3 mb-4" id="printable-content">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Disaster Summary Report</h4>
                    <small>Generated on: <?php echo date('F d, Y'); ?></small>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="border-start border-4 border-danger ps-3">
                                <h6 class="text-muted mb-1">Total Disaster Incidents</h6>
                                <h2 class="mb-0"><?php echo $total_incidents; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border-start border-4 border-warning ps-3">
                                <h6 class="text-muted mb-1">Damage Assessments</h6>
                                <h2 class="mb-0"><?php echo $total_assessments; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border-start border-4 border-info ps-3">
                                <h6 class="text-muted mb-1">Total Damage Cost</h6>
                                <h2 class="mb-0">₱<?php echo number_format($total_damage_cost, 2); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border-start border-4 border-success ps-3">
                                <h6 class="text-muted mb-1">Affected Families</h6>
                                <h2 class="mb-0"><?php echo $total_affected_families; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border-start border-4 border-secondary ps-3">
                                <h6 class="text-muted mb-1">Current Evacuees</h6>
                                <h2 class="mb-0"><?php echo $total_evacuees; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border-start border-4 border-primary ps-3">
                                <h6 class="text-muted mb-1">Evacuation Centers</h6>
                                <h2 class="mb-0"><?php echo count($evacuation_centers); ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Incidents by Type Chart -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Incidents by Type</h5>
                </div>
                <div class="card-body">
                    <?php
                    $incident_types = [];
                    foreach ($incidents as $incident) {
                        $type = $incident['disaster_type'] ?? 'Unknown';
                        if (!isset($incident_types[$type])) {
                            $incident_types[$type] = 0;
                        }
                        $incident_types[$type]++;
                    }
                    ?>
                    <?php if (empty($incident_types)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-chart-pie fs-1 mb-3"></i>
                            <p>No incidents to display</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th class="text-end">Count</th>
                                    <th class="text-end">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incident_types as $type => $count): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($type); ?></td>
                                    <td class="text-end"><strong><?php echo $count; ?></strong></td>
                                    <td class="text-end"><?php echo $total_incidents > 0 ? round(($count / $total_incidents) * 100, 1) : 0; ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Severity Distribution -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Severity Distribution</h5>
                </div>
                <div class="card-body">
                    <?php
                    $severity_counts = ['Low' => 0, 'Medium' => 0, 'High' => 0, 'Critical' => 0];
                    foreach ($incidents as $incident) {
                        $severity = $incident['severity'] ?? 'Medium';
                        if (isset($severity_counts[$severity])) {
                            $severity_counts[$severity]++;
                        }
                    }
                    ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Severity</th>
                                <th class="text-end">Count</th>
                                <th class="text-end">Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($severity_counts as $severity => $count): ?>
                            <tr>
                                <td><?php echo getSeverityBadge($severity); ?></td>
                                <td class="text-end"><strong><?php echo $count; ?></strong></td>
                                <td class="text-end"><?php echo $total_incidents > 0 ? round(($count / $total_incidents) * 100, 1) : 0; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Incidents Table -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Recent Disaster Incidents</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($incidents)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-exclamation-triangle fs-1 mb-3"></i>
                            <p>No disaster incidents recorded</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Severity</th>
                                        <th>Affected Families</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($incidents, 0, 10) as $incident): ?>
                                    <tr>
                                        <td><?php echo formatDate($incident['incident_date']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($incident['disaster_name'] ?? 'N/A'); ?></strong></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($incident['disaster_type'] ?? 'Unknown'); ?></span></td>
                                        <td><?php echo htmlspecialchars($incident['location'] ?? 'N/A'); ?></td>
                                        <td><?php echo getSeverityBadge($incident['severity'] ?? 'Medium'); ?></td>
                                        <td><?php echo $incident['affected_families'] ?? 0; ?></td>
                                        <td><?php echo getStatusBadge($incident['status'] ?? 'Active'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Evacuation Centers Status -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Evacuation Centers Status</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($evacuation_centers)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-home fs-1 mb-3"></i>
                            <p>No evacuation centers registered</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Center Name</th>
                                        <th>Location</th>
                                        <th>Capacity</th>
                                        <th>Current Evacuees</th>
                                        <th>Occupancy Rate</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($evacuation_centers as $center): ?>
                                    <?php
                                    $capacity = $center['capacity'] ?? 0;
                                    $evacuee_count = $center['evacuee_count'] ?? 0;
                                    $occupancy = $capacity > 0 ? ($evacuee_count / $capacity) * 100 : 0;
                                    $occupancy_class = $occupancy >= 90 ? 'danger' : ($occupancy >= 70 ? 'warning' : 'success');
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($center['center_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($center['location']); ?></td>
                                        <td><?php echo $capacity; ?></td>
                                        <td><?php echo $evacuee_count; ?></td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-<?php echo $occupancy_class; ?>" 
                                                     style="width: <?php echo min($occupancy, 100); ?>%">
                                                    <?php echo round($occupancy, 1); ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo getStatusBadge($center['status']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .card-header h5, nav, aside, header, .no-print {
        display: none !important;
    }
    
    .main-content {
        padding: 0 !important;
    }
    
    .card {
        break-inside: avoid;
    }
}
</style>

<script>
function applyFilters() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const disasterType = document.getElementById('disaster_type').value;
    
    // Implement filter logic here
    alert('Filters applied:\nFrom: ' + dateFrom + '\nTo: ' + dateTo + '\nType: ' + (disasterType || 'All'));
}

function generatePDFReport() {
    alert('PDF generation will be implemented with a PDF library like TCPDF or FPDF');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>