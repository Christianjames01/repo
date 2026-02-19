<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Education Reports';

// Get date range filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Overall Statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT student_id) as total_students,
    COUNT(DISTINCT CASE WHEN scholarship_status = 'active' THEN student_id END) as active_scholars,
    COUNT(DISTINCT CASE WHEN scholarship_status = 'pending' THEN student_id END) as pending_applications,
    COUNT(DISTINCT CASE WHEN scholarship_status = 'rejected' THEN student_id END) as rejected_applications,
    SUM(CASE WHEN scholarship_status = 'active' THEN scholarship_amount ELSE 0 END) as total_scholarship_amount,
    COUNT(DISTINCT school_name) as total_schools
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?";
$stats = fetchOne($conn, $stats_sql, [$start_date, $end_date], 'ss');

// Scholarship by Type
$type_sql = "SELECT 
    scholarship_type,
    COUNT(*) as count,
    SUM(scholarship_amount) as total_amount
    FROM tbl_education_students
    WHERE scholarship_status = 'active'
    AND application_date BETWEEN ? AND ?
    GROUP BY scholarship_type
    ORDER BY count DESC";
$scholarship_types = fetchAll($conn, $type_sql, [$start_date, $end_date], 'ss');

// Students by Grade Level
$grade_sql = "SELECT 
    grade_level,
    COUNT(*) as count,
    COUNT(CASE WHEN scholarship_status = 'active' THEN 1 END) as scholars
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?
    GROUP BY grade_level
    ORDER BY grade_level";
$grade_distribution = fetchAll($conn, $grade_sql, [$start_date, $end_date], 'ss');

// Students by School
$school_sql = "SELECT 
    school_name,
    COUNT(*) as count,
    COUNT(CASE WHEN scholarship_status = 'active' THEN 1 END) as scholars,
    SUM(CASE WHEN scholarship_status = 'active' THEN scholarship_amount ELSE 0 END) as total_amount
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?
    GROUP BY school_name
    ORDER BY count DESC
    LIMIT 10";
$school_distribution = fetchAll($conn, $school_sql, [$start_date, $end_date], 'ss');

// Monthly Applications Trend
$monthly_sql = "SELECT 
    DATE_FORMAT(application_date, '%Y-%m') as month,
    COUNT(*) as applications,
    COUNT(CASE WHEN scholarship_status = 'active' THEN 1 END) as approved
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(application_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12";
$monthly_trend = fetchAll($conn, $monthly_sql, [$start_date, $end_date], 'ss');

// Assistance Requests Summary
$assistance_sql = "SELECT 
    COUNT(*) as total_requests,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
    SUM(CASE WHEN status = 'approved' OR status = 'completed' THEN approved_amount ELSE 0 END) as total_assistance
    FROM tbl_education_assistance_requests
    WHERE request_date BETWEEN ? AND ?";
$assistance_stats = fetchOne($conn, $assistance_sql, [$start_date, $end_date], 'ss');

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
    border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
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
    padding: 1rem;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
    transform: scale(1.01);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
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

/* Form Enhancements */
.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

.form-label {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
}

/* Report Table */
.report-table {
    font-size: 0.9rem;
}

.report-table th {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    font-weight: 700;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

@media print {
    .no-print {
        display: none;
    }
    .card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 no-print">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-chart-bar me-2 text-primary"></i>Education Reports
                    </h2>
                    <p class="text-muted mb-0">Comprehensive scholarship and assistance analytics</p>
                </div>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print me-1"></i>Print Report
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-success">
                        <i class="fas fa-file-excel me-1"></i>Export to Excel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-header">
            <h5><i class="fas fa-filter me-2"></i>Date Range</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i>Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Header (for print) -->
    <div class="text-center mb-4 d-none d-print-block">
        <h3>Barangay Education Assistance Report</h3>
        <p class="text-muted">Period: <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?></p>
        <p class="text-muted">Generated: <?php echo date('F d, Y h:i A'); ?></p>
    </div>

    <!-- Overall Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total Students</p>
                            <h3 class="mb-0"><?php echo number_format($stats['total_students'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-users fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Active Scholars</p>
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['active_scholars'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-user-graduate fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Pending</p>
                            <h3 class="mb-0 text-warning"><?php echo number_format($stats['pending_applications'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                            <i class="fas fa-clock fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total Amount</p>
                            <h3 class="mb-0 text-success">₱<?php echo number_format($stats['total_scholarship_amount'] ?? 0, 2); ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-money-bill-wave fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Scholarship by Type -->
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5><i class="fas fa-award me-2 text-warning"></i>Scholarships by Type</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($scholarship_types)): ?>
                        <div class="empty-state py-3">
                            <i class="fas fa-chart-pie"></i>
                            <p class="small mb-0">No scholarship data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table report-table">
                                <thead>
                                    <tr>
                                        <th>Scholarship Type</th>
                                        <th class="text-center">Scholars</th>
                                        <th class="text-end">Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($scholarship_types as $type): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($type['scholarship_type'] ?? 'N/A'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?php echo $type['count']; ?></span>
                                            </td>
                                            <td class="text-end text-success">
                                                ₱<?php echo number_format($type['total_amount'], 2); ?>
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

        <!-- Grade Level Distribution -->
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5><i class="fas fa-graduation-cap me-2 text-info"></i>Grade Level Distribution</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($grade_distribution)): ?>
                        <div class="empty-state py-3">
                            <i class="fas fa-chart-bar"></i>
                            <p class="small mb-0">No grade level data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table report-table">
                                <thead>
                                    <tr>
                                        <th>Grade Level</th>
                                        <th class="text-center">Students</th>
                                        <th class="text-center">Scholars</th>
                                        <th class="text-end">Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grade_distribution as $grade): ?>
                                        <?php 
                                        $rate = $grade['count'] > 0 ? ($grade['scholars'] / $grade['count']) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($grade['grade_level']); ?></td>
                                            <td class="text-center"><?php echo $grade['count']; ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-success"><?php echo $grade['scholars']; ?></span>
                                            </td>
                                            <td class="text-end"><?php echo number_format($rate, 1); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Schools -->
        <div class="col-md-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5><i class="fas fa-school me-2 text-primary"></i>Top 10 Schools</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($school_distribution)): ?>
                        <div class="empty-state py-3">
                            <i class="fas fa-school"></i>
                            <p class="small mb-0">No school data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table report-table">
                                <thead>
                                    <tr>
                                        <th>School Name</th>
                                        <th class="text-center">Total Students</th>
                                        <th class="text-center">Scholars</th>
                                        <th class="text-end">Total Scholarship Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($school_distribution as $school): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($school['school_name']); ?></strong></td>
                                            <td class="text-center"><?php echo $school['count']; ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-success"><?php echo $school['scholars']; ?></span>
                                            </td>
                                            <td class="text-end text-success">
                                                ₱<?php echo number_format($school['total_amount'], 2); ?>
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

        <!-- Monthly Trend -->
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line me-2 text-success"></i>Monthly Application Trend</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($monthly_trend)): ?>
                        <div class="empty-state py-3">
                            <i class="fas fa-chart-line"></i>
                            <p class="small mb-0">No monthly data available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table report-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-center">Applications</th>
                                        <th class="text-center">Approved</th>
                                        <th class="text-end">Approval Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_trend as $month): ?>
                                        <?php 
                                        $approval_rate = $month['applications'] > 0 ? ($month['approved'] / $month['applications']) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                            <td class="text-center"><?php echo $month['applications']; ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-success"><?php echo $month['approved']; ?></span>
                                            </td>
                                            <td class="text-end"><?php echo number_format($approval_rate, 1); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Assistance Summary -->
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5><i class="fas fa-hand-holding-usd me-2 text-warning"></i>Assistance Requests Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3 border-end">
                            <p class="text-muted small mb-1">Total Requests</p>
                            <h4 class="mb-0"><?php echo number_format($assistance_stats['total_requests'] ?? 0); ?></h4>
                        </div>
                        <div class="col-6 mb-3">
                            <p class="text-muted small mb-1">Pending</p>
                            <h4 class="mb-0 text-warning"><?php echo number_format($assistance_stats['pending'] ?? 0); ?></h4>
                        </div>
                        <div class="col-6 mb-3 border-end border-top pt-3">
                            <p class="text-muted small mb-1">Approved</p>
                            <h4 class="mb-0 text-success"><?php echo number_format($assistance_stats['approved'] ?? 0); ?></h4>
                        </div>
                        <div class="col-6 mb-3 border-top pt-3">
                            <p class="text-muted small mb-1">Completed</p>
                            <h4 class="mb-0 text-info"><?php echo number_format($assistance_stats['completed'] ?? 0); ?></h4>
                        </div>
                        <div class="col-12 border-top pt-3">
                            <p class="text-muted small mb-1">Total Assistance Given</p>
                            <h3 class="mb-0 text-success">₱<?php echo number_format($assistance_stats['total_assistance'] ?? 0, 2); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportToExcel() {
    let tables = document.querySelectorAll('table');
    let workbook = 'data:application/vnd.ms-excel;base64,';
    let template = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">' +
        '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>' +
        '<x:Name>Education Report</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>' +
        '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
    
    template += '<h1>Barangay Education Assistance Report</h1>';
    template += '<p>Period: <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?></p>';
    
    tables.forEach(function(table) {
        template += table.outerHTML;
    });
    
    template += '</body></html>';
    
    window.location.href = workbook + btoa(unescape(encodeURIComponent(template)));
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php
$conn->close();
include '../../includes/footer.php';
?>  