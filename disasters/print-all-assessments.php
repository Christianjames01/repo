<?php
/**
 * Print All Damage Assessments
 * Path: barangaylink/disasters/print-all-assessments.php
 */

require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

// Get filter parameters
$filter_type = $_POST['filter_type'] ?? 'all';
$status = $_POST['status'] ?? '';
$severity = $_POST['severity'] ?? '';
$date_from = $_POST['date_from'] ?? '';
$date_to = $_POST['date_to'] ?? '';

// Build SQL query based on filters
$sql = "SELECT da.*, 
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.address as resident_address,
        u.username as assessed_by_name
        FROM tbl_damage_assessments da
        LEFT JOIN tbl_residents r ON da.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON da.assessed_by = u.user_id
        WHERE 1=1";

$params = [];
$types = '';

if ($filter_type === 'status' && !empty($status)) {
    $sql .= " AND da.status = ?";
    $params[] = $status;
    $types .= 's';
} elseif ($filter_type === 'severity' && !empty($severity)) {
    $sql .= " AND da.severity = ?";
    $params[] = $severity;
    $types .= 's';
} elseif ($filter_type === 'date' && !empty($date_from) && !empty($date_to)) {
    $sql .= " AND da.assessment_date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}

$sql .= " ORDER BY da.assessment_date DESC, da.created_at DESC";

if (!empty($params)) {
    $assessments = fetchAll($conn, $sql, $params, $types);
} else {
    $assessments = fetchAll($conn, $sql);
}

// Fetch barangay settings
$barangay_name = BARANGAY_NAME ?? 'Barangay';
$municipality = MUNICIPALITY ?? '';
$province = PROVINCE ?? '';
$logo_path = BARANGAY_LOGO ?? '';

// Calculate totals
$total_cost = array_sum(array_column($assessments, 'estimated_cost'));
$total_count = count($assessments);

// Filter title
$filter_title = 'All Assessments';
if ($filter_type === 'status') {
    $filter_title = "$status Assessments";
} elseif ($filter_type === 'severity') {
    $filter_title = "$severity Severity Assessments";
} elseif ($filter_type === 'date') {
    $filter_title = "Assessments from " . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damage Assessment Summary Report</title>
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { size: landscape; margin: 0.5in; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 16px;
            margin: 5px 0;
        }
        
        .header p {
            font-size: 11px;
            margin: 2px 0;
        }
        
        .report-title {
            text-align: center;
            margin: 20px 0;
        }
        
        .report-title h2 {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .report-title p {
            font-size: 12px;
            color: #666;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
            background: #f9f9f9;
        }
        
        .stat-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            margin-top: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th {
            background: #333;
            color: white;
            padding: 8px 5px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        td {
            padding: 6px 5px;
            border-bottom: 1px solid #ddd;
            font-size: 10px;
        }
        
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .severity-minor { background: #17a2b8; color: white; }
        .severity-moderate { background: #ffc107; color: #000; }
        .severity-severe { background: #dc3545; color: white; }
        .severity-critical { background: #343a40; color: white; }
        
        .status-pending { background: #ffc107; color: #000; }
        .status-in-progress { background: #17a2b8; color: white; }
        .status-completed { background: #28a745; color: white; }
        .status-cancelled { background: #dc3545; color: white; }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .print-button:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        🖨️ Print Report
    </button>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <p>Republic of the Philippines</p>
            <p>Province of <?php echo htmlspecialchars($province); ?></p>
            <p>Municipality of <?php echo htmlspecialchars($municipality); ?></p>
            <h1><?php echo htmlspecialchars($barangay_name); ?></h1>
        </div>

        <!-- Report Title -->
        <div class="report-title">
            <h2>DAMAGE ASSESSMENT SUMMARY REPORT</h2>
            <p><?php echo htmlspecialchars($filter_title); ?></p>
        </div>

        <!-- Summary Statistics -->
        <div class="summary-stats">
            <div class="stat-card">
                <div class="stat-label">Total Assessments</div>
                <div class="stat-value"><?php echo $total_count; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Estimated Cost</div>
                <div class="stat-value">₱<?php echo number_format($total_cost, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending</div>
                <div class="stat-value">
                    <?php echo count(array_filter($assessments, fn($a) => $a['status'] === 'Pending')); ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completed</div>
                <div class="stat-value">
                    <?php echo count(array_filter($assessments, fn($a) => $a['status'] === 'Completed')); ?>
                </div>
            </div>
        </div>

        <!-- Assessments Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">ID</th>
                    <th style="width: 8%;">Date</th>
                    <th style="width: 15%;">Resident</th>
                    <th style="width: 12%;">Location</th>
                    <th style="width: 10%;">Disaster</th>
                    <th style="width: 10%;">Damage Type</th>
                    <th style="width: 8%;">Severity</th>
                    <th style="width: 12%;">Est. Cost</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 10%;">Assessed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assessments)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 20px; color: #999;">
                        No assessments found matching the selected criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($assessments as $assessment): ?>
                    <tr>
                        <td><?php echo str_pad($assessment['assessment_id'], 4, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo date('M d, Y', strtotime($assessment['assessment_date'])); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($assessment['resident_name']); ?></strong><br>
                            <small style="color: #666;"><?php echo htmlspecialchars($assessment['resident_address']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($assessment['location']); ?></td>
                        <td><?php echo htmlspecialchars($assessment['disaster_type']); ?></td>
                        <td><?php echo htmlspecialchars($assessment['damage_type']); ?></td>
                        <td>
                            <?php 
                            $severity = strtolower($assessment['severity']);
                            $severity_class = 'severity-' . str_replace(' ', '-', $severity);
                            ?>
                            <span class="badge <?php echo $severity_class; ?>">
                                <?php echo htmlspecialchars($assessment['severity']); ?>
                            </span>
                        </td>
                        <td>₱<?php echo number_format($assessment['estimated_cost'], 2); ?></td>
                        <td>
                            <?php 
                            $status = strtolower($assessment['status']);
                            $status_class = 'status-' . str_replace(' ', '-', $status);
                            ?>
                            <span class="badge <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($assessment['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($assessment['assessed_by_name'] ?? 'N/A'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Footer -->
        <div class="footer">
            <p>This is a computer-generated document. No signature is required.</p>
            <p>Generated on: <?php echo date('F d, Y h:i A'); ?> | Total Records: <?php echo $total_count; ?></p>
        </div>
    </div>
</body>
</html>