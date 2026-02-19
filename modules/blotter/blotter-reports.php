<?php
/**
 * Blotter Reports Page
 * Path: modules/blotter/blotter-reports.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: my-blotter.php');
    exit();
}

$page_title = 'Blotter Reports & Statistics';

// Get date range and status filter
$start_date = isset($_GET['start_date']) && $_GET['start_date'] != '' ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) && $_GET['end_date'] != '' ? $_GET['end_date'] : '';
$status_filter = isset($_GET['status']) && $_GET['status'] != '' ? $_GET['status'] : '';

// Build WHERE clause for filters
$where_clause = "1=1";
$params = [];
$types = "";

if ($start_date && $end_date) {
    $where_clause .= " AND incident_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

if ($status_filter) {
    $where_clause .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Get total blotter records
$total_sql = "SELECT COUNT(*) as total FROM tbl_blotter WHERE $where_clause";
$stmt = $conn->prepare($total_sql);
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_result = $stmt->get_result();
$total_count = $total_result->fetch_assoc()['total'];
$stmt->close();

// Get records by status
$status_sql = "SELECT status, COUNT(*) as count FROM tbl_blotter 
               WHERE $where_clause
               GROUP BY status";
$stmt = $conn->prepare($status_sql);
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$status_result = $stmt->get_result();
$status_data = $status_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get records by incident type
$type_sql = "SELECT incident_type, COUNT(*) as count FROM tbl_blotter 
             WHERE $where_clause
             GROUP BY incident_type 
             ORDER BY count DESC 
             LIMIT 10";
$stmt = $conn->prepare($type_sql);
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$type_result = $stmt->get_result();
$type_data = $type_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get monthly trend (last 12 months)
$trend_where = "incident_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
if ($status_filter) {
    $trend_where .= " AND status = ?";
    $trend_sql = "SELECT DATE_FORMAT(incident_date, '%Y-%m') as month, COUNT(*) as count 
                  FROM tbl_blotter 
                  WHERE $trend_where
                  GROUP BY DATE_FORMAT(incident_date, '%Y-%m') 
                  ORDER BY month ASC";
    $stmt = $conn->prepare($trend_sql);
    $stmt->bind_param("s", $status_filter);
    $stmt->execute();
    $trend_result = $stmt->get_result();
    $trend_data = $trend_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $trend_sql = "SELECT DATE_FORMAT(incident_date, '%Y-%m') as month, COUNT(*) as count 
                  FROM tbl_blotter 
                  WHERE $trend_where
                  GROUP BY DATE_FORMAT(incident_date, '%Y-%m') 
                  ORDER BY month ASC";
    $trend_result = $conn->query($trend_sql);
    $trend_data = $trend_result->fetch_all(MYSQLI_ASSOC);
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="blotter_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='4' style='background-color: #007bff; color: white; font-size: 16px;'>BLOTTER REPORT</th></tr>";
    if ($start_date && $end_date) {
        echo "<tr><th colspan='4'>Period: " . date('F d, Y', strtotime($start_date)) . " to " . date('F d, Y', strtotime($end_date)) . "</th></tr>";
    } else {
        echo "<tr><th colspan='4'>Period: All Records</th></tr>";
    }
    if ($status_filter) {
        echo "<tr><th colspan='4'>Status Filter: " . htmlspecialchars($status_filter) . "</th></tr>";
    }
    echo "<tr><th colspan='4'>&nbsp;</th></tr>";
    
    $status_counts = ['Pending' => 0, 'Under Investigation' => 0, 'Resolved' => 0, 'Archived' => 0];
    foreach ($status_data as $status) {
        $status_counts[$status['status']] = $status['count'];
    }
    
    echo "<tr style='background-color: #f8f9fa;'><th colspan='4'>SUMMARY STATISTICS</th></tr>";
    echo "<tr><td>Total Records</td><td>" . $total_count . "</td><td></td><td></td></tr>";
    echo "<tr><td>Pending</td><td>" . $status_counts['Pending'] . "</td><td></td><td></td></tr>";
    echo "<tr><td>Under Investigation</td><td>" . $status_counts['Under Investigation'] . "</td><td></td><td></td></tr>";
    echo "<tr><td>Resolved</td><td>" . $status_counts['Resolved'] . "</td><td></td><td></td></tr>";
    echo "<tr><td>Archived</td><td>" . $status_counts['Archived'] . "</td><td></td><td></td></tr>";
    echo "<tr><th colspan='4'>&nbsp;</th></tr>";
    
    echo "<tr style='background-color: #f8f9fa;'><th colspan='4'>TOP INCIDENT TYPES</th></tr>";
    echo "<tr><th>Incident Type</th><th>Count</th><th>Percentage</th><th></th></tr>";
    foreach ($type_data as $type) {
        $percentage = $total_count > 0 ? ($type['count'] / $total_count) * 100 : 0;
        echo "<tr><td>" . htmlspecialchars($type['incident_type']) . "</td><td>" . $type['count'] . "</td><td>" . number_format($percentage, 1) . "%</td><td></td></tr>";
    }
    echo "<tr><th colspan='4'>&nbsp;</th></tr>";
    
    echo "<tr style='background-color: #f8f9fa;'><th colspan='4'>MONTHLY TREND</th></tr>";
    echo "<tr><th>Month</th><th>Cases</th><th></th><th></th></tr>";
    foreach ($trend_data as $trend) {
        echo "<tr><td>" . date('F Y', strtotime($trend['month'] . '-01')) . "</td><td>" . $trend['count'] . "</td><td></td><td></td></tr>";
    }
    
    echo "</table>";
    exit();
}

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Blotter Report PDF</title>
        <style>
            @page { margin: 20px; }
            body { font-family: Arial, sans-serif; font-size: 11px; }
            .header { text-align: center; margin-bottom: 15px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .header h2 { margin: 5px 0; font-size: 18px; }
            .header p { margin: 3px 0; font-size: 11px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; }
            th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .summary-box { display: inline-block; width: 18%; margin: 5px 1%; text-align: center; border: 1px solid #ddd; padding: 8px; }
            .summary-box h3 { margin: 0; font-size: 20px; }
            .summary-box p { margin: 3px 0; font-size: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>BARANGAY BLOTTER REPORT</h2>
            <?php if ($start_date && $end_date): ?>
            <p>Period: <?= date('F d, Y', strtotime($start_date)) ?> to <?= date('F d, Y', strtotime($end_date)) ?></p>
            <?php else: ?>
            <p>Period: All Records</p>
            <?php endif; ?>
            <?php if ($status_filter): ?>
            <p>Status Filter: <?= htmlspecialchars($status_filter) ?></p>
            <?php endif; ?>
            <p>Generated: <?= date('F d, Y h:i A') ?></p>
        </div>
        
        <div style="margin-bottom: 15px;">
            <?php
            $status_counts = ['Pending' => 0, 'Under Investigation' => 0, 'Resolved' => 0, 'Archived' => 0];
            foreach ($status_data as $status) {
                $status_counts[$status['status']] = $status['count'];
            }
            ?>
            <div class="summary-box">
                <h3><?= $total_count ?></h3>
                <p>Total Records</p>
            </div>
            <div class="summary-box">
                <h3><?= $status_counts['Pending'] ?></h3>
                <p>Pending</p>
            </div>
            <div class="summary-box">
                <h3><?= $status_counts['Under Investigation'] ?></h3>
                <p>Under Investigation</p>
            </div>
            <div class="summary-box">
                <h3><?= $status_counts['Resolved'] ?></h3>
                <p>Resolved</p>
            </div>
            <div class="summary-box">
                <h3><?= $status_counts['Archived'] ?></h3>
                <p>Archived</p>
            </div>
        </div>
        
        <h3 style="margin-top: 15px; font-size: 13px;">Top Incident Types</h3>
        <table>
            <tr>
                <th>Incident Type</th>
                <th style="text-align: right;">Count</th>
                <th style="text-align: right;">Percentage</th>
            </tr>
            <?php foreach ($type_data as $type): 
                $percentage = $total_count > 0 ? ($type['count'] / $total_count) * 100 : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($type['incident_type']) ?></td>
                <td style="text-align: right;"><?= $type['count'] ?></td>
                <td style="text-align: right;"><?= number_format($percentage, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <h3 style="margin-top: 10px; font-size: 13px;">Monthly Trend</h3>
        <table>
            <tr>
                <th>Month</th>
                <th style="text-align: right;">Number of Cases</th>
            </tr>
            <?php foreach ($trend_data as $trend): ?>
            <tr>
                <td><?= date('F Y', strtotime($trend['month'] . '-01')) ?></td>
                <td style="text-align: right;"><?= $trend['count'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <script>
            window.print();
            setTimeout(function() {
                window.close();
            }, 100);
        </script>
    </body>
    </html>
    <?php
    exit();
}

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
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
    font-weight: 700;
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    height: 100%;
    text-align: center;
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.stat-icon {
    width: 64px;
    height: 64px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1rem;
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1a1a1a;
    line-height: 1;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
}

/* Form Enhancements */
.form-label {
    font-weight: 700;
    font-size: 0.875rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

/* Enhanced Buttons */
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

.btn:active {
    transform: translateY(0);
}

/* Table Enhancements */
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
    padding: 0.75rem 1rem;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
}

.table tbody td {
    padding: 0.75rem 1rem;
    vertical-align: middle;
}

/* Chart Cards */
.chart-card .card-header {
    color: white;
    font-weight: 700;
    padding: 1rem 1.5rem;
}

.chart-card .card-body {
    padding: 0;
}

/* Export Section */
.export-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: var(--border-radius-lg);
    padding: 2rem;
    text-align: center;
    box-shadow: var(--shadow-sm);
}

.export-section h5 {
    font-weight: 700;
    margin-bottom: 0.5rem;
}

/* Print Styles */
@media print {
    @page {
        size: A4 landscape;
        margin: 0.5cm;
    }
    
    body {
        font-size: 9px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .no-print {
        display: none !important;
    }
    
    .print-only {
        display: block !important;
    }
    
    .container-fluid {
        padding: 0 !important;
        max-width: 100% !important;
    }
    
    .card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        margin-bottom: 0.3cm !important;
    }
    
    .card-header {
        padding: 0.2cm !important;
        font-size: 10px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .card-body {
        padding: 0.2cm !important;
    }
    
    table {
        font-size: 8px !important;
    }
    
    th, td {
        padding: 2px 4px !important;
    }
}

.print-only {
    display: none;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .stat-card {
        padding: 1.25rem;
        margin-bottom: 1rem;
    }
    
    .stat-value {
        font-size: 2rem;
    }
    
    .stat-icon {
        width: 56px;
        height: 56px;
        font-size: 1.5rem;
    }
    
    /* Stack statistics cards on mobile */
    .row.g-4 > .col {
        flex: 0 0 100%;
        max-width: 100%;
    }
}

@media (min-width: 769px) and (max-width: 1199px) {
    /* 2 cards per row on tablets */
    .row.g-4 > .col {
        flex: 0 0 50%;
        max-width: 50%;
    }
}

@media (min-width: 1200px) {
    /* 5 cards in one row on desktop */
    .row.g-4 > .col {
        flex: 0 0 20%;
        max-width: 20%;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-chart-bar me-2 text-primary"></i>
                        Blotter Reports & Statistics
                    </h2>
                    <p class="text-muted mb-0">Analyze blotter records and trends</p>
                </div>
                <a href="manage-blotter.php" class="btn btn-secondary no-print">
                    <i class="fas fa-arrow-left me-2"></i>Back to Management
                </a>
            </div>
        </div>
    </div>

    <!-- Print Header -->
    <div class="print-only text-center mb-4">
        <h3>BARANGAY BLOTTER REPORT</h3>
        <?php if ($start_date && $end_date): ?>
        <p class="mb-1">Period: <?= date('F d, Y', strtotime($start_date)) ?> to <?= date('F d, Y', strtotime($end_date)) ?></p>
        <?php else: ?>
        <p class="mb-1">Period: All Records</p>
        <?php endif; ?>
        <?php if ($status_filter): ?>
        <p class="mb-1">Status Filter: <?= htmlspecialchars($status_filter) ?></p>
        <?php endif; ?>
        <p class="text-muted">Generated: <?= date('F d, Y h:i A') ?></p>
        <hr>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <?php
        $status_counts = [
            'Pending' => 0,
            'Under Investigation' => 0,
            'Resolved' => 0,
            'Archived' => 0,
            'Closed' => 0
        ];
        
        foreach ($status_data as $status) {
            $status_counts[$status['status']] = $status['count'];
        }
        
        // Handle both 'Archived' and 'Closed' statuses
        if (!isset($status_counts['Closed'])) {
            $status_counts['Closed'] = $status_counts['Archived'];
        }
        ?>
        
        <div class="col">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary no-print">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?= $total_count ?></div>
                <div class="stat-label">Total Records</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning no-print">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?= $status_counts['Pending'] ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div class="stat-icon bg-info bg-opacity-10 text-info no-print">
                    <i class="fas fa-search"></i>
                </div>
                <div class="stat-value"><?= $status_counts['Under Investigation'] ?></div>
                <div class="stat-label">Investigating</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success no-print">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?= $status_counts['Resolved'] ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary no-print">
                    <i class="fas fa-archive"></i>
                </div>
                <div class="stat-value"><?= $status_counts['Closed'] ?></div>
                <div class="stat-label">Closed</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Incident Type Chart -->
        <div class="col-lg-6">
            <div class="card chart-card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-chart-pie me-2"></i>Top Incident Types
                </div>
                <div class="card-body p-0">
                    <?php if (empty($type_data)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-inbox fs-1 mb-3 opacity-25"></i>
                            <p class="mb-0">No data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Incident Type</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($type_data as $type): 
                                        $percentage = $total_count > 0 ? ($type['count'] / $total_count) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-circle text-primary me-2" style="font-size: 0.5rem;"></i>
                                            <?= htmlspecialchars($type['incident_type']) ?>
                                        </td>
                                        <td class="text-end"><strong><?= $type['count'] ?></strong></td>
                                        <td class="text-end">
                                            <span class="badge bg-primary bg-opacity-25 text-primary">
                                                <?= number_format($percentage, 1) ?>%
                                            </span>
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

        <!-- Status Distribution -->
        <div class="col-lg-6">
            <div class="card chart-card">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-chart-bar me-2"></i>Status Distribution
                </div>
                <div class="card-body p-0">
                    <?php if (empty($status_data)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-inbox fs-1 mb-3 opacity-25"></i>
                            <p class="mb-0">No data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($status_data as $status): 
                                        $percentage = $total_count > 0 ? ($status['count'] / $total_count) * 100 : 0;
                                        $color = 'secondary';
                                        if ($status['status'] === 'Pending') $color = 'warning';
                                        elseif ($status['status'] === 'Under Investigation') $color = 'info';
                                        elseif ($status['status'] === 'Resolved') $color = 'success';
                                    ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-circle text-<?= $color ?> me-2" style="font-size: 0.5rem;"></i>
                                            <?= htmlspecialchars($status['status']) ?>
                                        </td>
                                        <td class="text-end"><strong><?= $status['count'] ?></strong></td>
                                        <td class="text-end">
                                            <span class="badge bg-<?= $color ?> bg-opacity-25 text-<?= $color ?>">
                                                <?= number_format($percentage, 1) ?>%
                                            </span>
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
    </div>

    <!-- Monthly Trend -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card chart-card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-chart-line me-2"></i>Monthly Trend (Last 12 Months)
                </div>
                <div class="card-body p-0">
                    <?php if (empty($trend_data)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-inbox fs-1 mb-3 opacity-25"></i>
                            <p class="mb-0">No data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-end">Number of Cases</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trend_data as $trend): ?>
                                    <tr>
                                        <td>
                                            <i class="far fa-calendar text-info me-2"></i>
                                            <?= date('F Y', strtotime($trend['month'] . '-01')) ?>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-info bg-opacity-25 text-info">
                                                <?= $trend['count'] ?> cases
                                            </span>
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
    </div>

    <!-- Export Options -->
    <div class="row no-print">
        <div class="col-12">
            <div class="export-section">
                <h5>
                    <i class="fas fa-download me-2"></i>
                    Export Reports
                </h5>
                <p class="text-muted mb-3">Download blotter reports in different formats</p>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Report
                    </button>
                    <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&status=<?= $status_filter ?>&export=pdf" target="_blank" class="btn btn-primary">
                        <i class="fas fa-file-pdf me-2"></i>Export to PDF
                    </a>
                    <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&status=<?= $status_filter ?>&export=excel" class="btn btn-info">
                        <i class="fas fa-file-excel me-2"></i>Export to Excel
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>