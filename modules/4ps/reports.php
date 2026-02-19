<?php
// Include config which handles session, database, and functions
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and is Super Admin
if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();
$page_title = '4Ps Reports';

// Get date range from filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';

// Overall Statistics
$stats_query = "SELECT 
    COUNT(*) as total_beneficiaries,
    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN status = 'Suspended' THEN 1 ELSE 0 END) as suspended,
    SUM(CASE WHEN status = 'Graduated' THEN 1 ELSE 0 END) as graduated,
    SUM(CASE WHEN status = 'Active' THEN monthly_grant ELSE 0 END) as total_active_grants,
    SUM(monthly_grant) as total_all_grants,
    AVG(CASE WHEN status = 'Active' THEN monthly_grant ELSE NULL END) as avg_grant,
    SUM(CASE WHEN compliance_status = 'Compliant' THEN 1 ELSE 0 END) as compliant,
    SUM(CASE WHEN compliance_status = 'Non-Compliant' THEN 1 ELSE 0 END) as non_compliant,
    SUM(CASE WHEN compliance_status = 'Partial' THEN 1 ELSE 0 END) as partial_compliant
    FROM tbl_4ps_beneficiaries
    WHERE date_registered BETWEEN ? AND ?";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Status Distribution (for simple table)
$status_query = "SELECT 
    status,
    COUNT(*) as count,
    SUM(monthly_grant) as total_grant,
    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?)), 1) as percentage
    FROM tbl_4ps_beneficiaries
    WHERE date_registered BETWEEN ? AND ?
    GROUP BY status
    ORDER BY count DESC";

$stmt = $conn->prepare($status_query);
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$status_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Compliance Distribution
$compliance_query = "SELECT 
    compliance_status,
    COUNT(*) as count,
    SUM(monthly_grant) as total_grant,
    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?)), 1) as percentage
    FROM tbl_4ps_beneficiaries
    WHERE date_registered BETWEEN ? AND ?
    GROUP BY compliance_status
    ORDER BY count DESC";

$stmt = $conn->prepare($compliance_query);
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$compliance_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Monthly Registrations (simplified - last 12 months only)
$trend_query = "SELECT 
    DATE_FORMAT(date_registered, '%Y-%m') as month,
    DATE_FORMAT(date_registered, '%b %Y') as month_label,
    COUNT(*) as registrations
    FROM tbl_4ps_beneficiaries
    WHERE date_registered >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_registered, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12";

$trend_result = $conn->query($trend_query);
$trend_data = $trend_result->fetch_all(MYSQLI_ASSOC);
$trend_data = array_reverse($trend_data); // Reverse to show oldest to newest

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-6">
            <h2><i class="fas fa-chart-bar me-2"></i>4Ps Reports & Analytics</h2>
            <p class="text-muted">Beneficiaries data for <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-success" onclick="printReport()">
                <i class="fas fa-print me-2"></i>Print Report
            </button>
            <a href="beneficiaries-debug.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stats-card primary">
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stats-details">
                    <div class="stats-number"><?php echo number_format($stats['total_beneficiaries']); ?></div>
                    <div class="stats-label">Total Beneficiaries</div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stats-card success">
                <div class="stats-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stats-details">
                    <div class="stats-number"><?php echo number_format($stats['active']); ?></div>
                    <div class="stats-label">Active Status</div>
                    <small class="stats-sublabel"><?php echo $stats['total_beneficiaries'] > 0 ? round(($stats['active'] / $stats['total_beneficiaries']) * 100, 1) : 0; ?>% of total</small>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stats-card info">
                <div class="stats-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stats-details">
                    <div class="stats-number">₱<?php echo number_format($stats['total_active_grants'], 2); ?></div>
                    <div class="stats-label">Monthly Budget</div>
                    <small class="stats-sublabel">Active beneficiaries</small>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stats-card warning">
                <div class="stats-icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <div class="stats-details">
                    <div class="stats-number">₱<?php echo number_format($stats['avg_grant'], 2); ?></div>
                    <div class="stats-label">Average Grant</div>
                    <small class="stats-sublabel">Per family</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Report Type</label>
                    <select class="form-select" name="report_type">
                        <option value="overview" <?php echo $report_type == 'overview' ? 'selected' : ''; ?>>Overview</option>
                        <option value="status" <?php echo $report_type == 'status' ? 'selected' : ''; ?>>Status Analysis</option>
                        <option value="compliance" <?php echo $report_type == 'compliance' ? 'selected' : ''; ?>>Compliance Report</option>
                        <option value="trend" <?php echo $report_type == 'trend' ? 'selected' : ''; ?>>Registration Trend</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="fas fa-filter me-1"></i>Apply
                    </button>
                    <a href="reports.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <!-- Status Distribution Table -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-pie text-primary me-2"></i>Status Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Status</th>
                                    <th class="text-center">Count</th>
                                    <th class="text-end">Percentage</th>
                                    <th class="text-end">Total Grant</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($status_data as $row): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $row['status'] == 'Active' ? 'success' : 
                                                ($row['status'] == 'Suspended' ? 'warning' : 
                                                ($row['status'] == 'Graduated' ? 'info' : 'secondary')); 
                                        ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <strong><?php echo number_format($row['count']); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?php echo $row['percentage']; ?>%" 
                                                 aria-valuenow="<?php echo $row['percentage']; ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                                <?php echo $row['percentage']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        ₱<?php echo number_format($row['total_grant'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-center"><?php echo number_format($stats['total_beneficiaries']); ?></th>
                                    <th class="text-end">100%</th>
                                    <th class="text-end">₱<?php echo number_format($stats['total_all_grants'], 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Compliance Distribution Table -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-clipboard-check text-success me-2"></i>Compliance Status
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Compliance</th>
                                    <th class="text-center">Count</th>
                                    <th class="text-end">Percentage</th>
                                    <th class="text-end">Total Grant</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compliance_data as $row): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $row['compliance_status'] == 'Compliant' ? 'success' : 
                                                ($row['compliance_status'] == 'Partial' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo $row['compliance_status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <strong><?php echo number_format($row['count']); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-<?php 
                                                echo $row['compliance_status'] == 'Compliant' ? 'success' : 
                                                    ($row['compliance_status'] == 'Partial' ? 'warning' : 'danger'); 
                                            ?>" role="progressbar" 
                                                 style="width: <?php echo $row['percentage']; ?>%" 
                                                 aria-valuenow="<?php echo $row['percentage']; ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                                <?php echo $row['percentage']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        ₱<?php echo number_format($row['total_grant'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-center"><?php echo number_format($stats['total_beneficiaries']); ?></th>
                                    <th class="text-end">100%</th>
                                    <th class="text-end">₱<?php echo number_format($stats['total_all_grants'], 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Registration Trend -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-line text-info me-2"></i>Registration Trend (Last 12 Months)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Month</th>
                                    <th class="text-center">New Registrations</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $prev_count = 0;
                                foreach ($trend_data as $index => $row): 
                                    $trend_change = $index > 0 ? $row['registrations'] - $prev_count : 0;
                                    $prev_count = $row['registrations'];
                                ?>
                                <tr>
                                    <td><strong><?php echo $row['month_label']; ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary fs-6">
                                            <?php echo number_format($row['registrations']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($index > 0): ?>
                                            <?php if ($trend_change > 0): ?>
                                                <span class="text-success">
                                                    <i class="fas fa-arrow-up"></i> +<?php echo $trend_change; ?>
                                                </span>
                                            <?php elseif ($trend_change < 0): ?>
                                                <span class="text-danger">
                                                    <i class="fas fa-arrow-down"></i> <?php echo $trend_change; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">
                                                    <i class="fas fa-minus"></i> No change
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total (12 Months)</th>
                                    <th class="text-center">
                                        <?php echo number_format(array_sum(array_column($trend_data, 'registrations'))); ?>
                                    </th>
                                    <th>-</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Breakdown -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list-alt text-dark me-2"></i>Detailed Breakdown
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <!-- Status Breakdown -->
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2 mb-3">Status Breakdown</h6>
                            <div class="list-group list-group-flush">
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-check-circle text-success me-2"></i>Active</span>
                                    <span class="badge bg-success rounded-pill"><?php echo number_format($stats['active']); ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-pause-circle text-warning me-2"></i>Suspended</span>
                                    <span class="badge bg-warning rounded-pill"><?php echo number_format($stats['suspended']); ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-graduation-cap text-info me-2"></i>Graduated</span>
                                    <span class="badge bg-info rounded-pill"><?php echo number_format($stats['graduated']); ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-times-circle text-secondary me-2"></i>Inactive</span>
                                    <span class="badge bg-secondary rounded-pill"><?php echo number_format($stats['inactive']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Compliance Breakdown -->
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2 mb-3">Compliance Breakdown</h6>
                            <div class="list-group list-group-flush">
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-check-double text-success me-2"></i>Compliant</span>
                                    <span class="badge bg-success rounded-pill"><?php echo number_format($stats['compliant']); ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-exclamation-triangle text-warning me-2"></i>Partial</span>
                                    <span class="badge bg-warning rounded-pill"><?php echo number_format($stats['partial_compliant']); ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-times-circle text-danger me-2"></i>Non-Compliant</span>
                                    <span class="badge bg-danger rounded-pill"><?php echo number_format($stats['non_compliant']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Financial Summary -->
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mb-3">Financial Summary</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="p-3 bg-light rounded">
                                        <div class="text-muted small">Active Monthly Budget</div>
                                        <div class="h4 mb-0 text-primary">₱<?php echo number_format($stats['total_active_grants'], 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-light rounded">
                                        <div class="text-muted small">Total Budget (All Status)</div>
                                        <div class="h4 mb-0 text-info">₱<?php echo number_format($stats['total_all_grants'], 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-light rounded">
                                        <div class="text-muted small">Average Grant per Family</div>
                                        <div class="h4 mb-0 text-success">₱<?php echo number_format($stats['avg_grant'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Stats Cards */
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left: 4px solid #3b82f6; }
.stats-card.success { border-left: 4px solid #10b981; }
.stats-card.info { border-left: 4px solid #06b6d4; }
.stats-card.warning { border-left: 4px solid #f59e0b; }

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
}

.stats-card.primary .stats-icon { background: #dbeafe; color: #3b82f6; }
.stats-card.success .stats-icon { background: #d1fae5; color: #10b981; }
.stats-card.info .stats-icon { background: #cffafe; color: #06b6d4; }
.stats-card.warning .stats-icon { background: #fef3c7; color: #f59e0b; }

.stats-details {
    flex: 1;
    min-width: 0;
}

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.2;
    color: #1f2937;
}

.stats-label {
    font-size: 0.875rem;
    color: #6b7280;
    font-weight: 500;
    margin-top: 0.25rem;
}

.stats-sublabel {
    font-size: 0.75rem;
    color: #9ca3af;
    display: block;
    margin-top: 0.25rem;
}

/* Cards */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 0;
}

.card-header {
    background: white;
    border-bottom: 1px solid #e5e7eb;
    padding: 1.25rem 1.5rem;
    border-radius: 12px 12px 0 0;
}

.card-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1f2937;
}

.card-body {
    padding: 1.5rem;
}

/* Tables */
.table {
    margin-bottom: 0;
}

.table thead th {
    border-bottom: 2px solid #e5e7eb;
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    padding: 0.75rem;
}

.table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border-bottom: 1px solid #f3f4f6;
}

.table-hover tbody tr:hover {
    background-color: #f9fafb;
}

/* Progress bars */
.progress {
    background-color: #e5e7eb;
    border-radius: 4px;
}

.progress-bar {
    font-size: 0.75rem;
    font-weight: 600;
}

/* Badges */
.badge {
    font-weight: 500;
    padding: 0.375rem 0.75rem;
}

/* List Groups */
.list-group-item {
    border: none;
    border-bottom: 1px solid #f3f4f6;
    padding: 0.875rem 0;
}

.list-group-item:last-child {
    border-bottom: none;
}

/* Forms */
.form-label {
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.5rem 0.75rem;
}

.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Buttons */
.btn {
    border-radius: 8px;
    padding: 0.5rem 1rem;
    font-weight: 500;
    transition: all 0.2s;
}

.btn:hover {
    transform: translateY(-1px);
}

/* Print Styles */
@media print {
    .btn, .card-header, form {
        display: none !important;
    }
    
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
        page-break-inside: avoid;
    }
    
    .stats-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .stats-icon {
        width: 50px;
        height: 50px;
        font-size: 1.5rem;
    }
    
    .card-body {
        padding: 1rem;
    }
}
</style>

<script>
function printReport() {
    window.print();
}

// Add animation on load
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.stats-card, .card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'opacity 0.3s, transform 0.3s';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 50);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>