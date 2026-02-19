<?php
require_once '../../../config/config.php';

// Check if user is logged in and has admin access
if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Permit Dashboard";

// Get statistics
$stats = [];

// Total permits
$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits");
$stats['total_permits'] = $result->fetch_assoc()['total'];

// Pending applications
$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'pending'");
$stats['pending'] = $result->fetch_assoc()['total'];

// Approved permits
$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'approved'");
$stats['approved'] = $result->fetch_assoc()['total'];

// Expired permits
$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'expired' OR (status = 'approved' AND expiry_date < CURDATE())");
$stats['expired'] = $result->fetch_assoc()['total'];

// Expiring soon (within 30 days)
$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'approved' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stats['expiring_soon'] = $result->fetch_assoc()['total'];

// Total revenue collected - FIXED: using permit_fee and amount_paid
$result = $conn->query("SELECT SUM(amount_paid) as total FROM tbl_business_permits WHERE payment_status = 'paid'");
$stats['total_revenue'] = $result->fetch_assoc()['total'] ?? 0;

// Recent applications - FIXED: removed business_type_id join since column doesn't exist
$recent_applications = $conn->query("
    SELECT bp.*, r.first_name, r.last_name
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    ORDER BY bp.created_at DESC
    LIMIT 10
");

// Monthly revenue chart data - FIXED: using permit_fee instead of total_fee
$monthly_revenue = $conn->query("
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        SUM(amount_paid) as revenue,
        COUNT(*) as permits
    FROM tbl_business_permits
    WHERE payment_status = 'paid' 
    AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND payment_date IS NOT NULL
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month
");

$chart_data = ['labels' => [], 'revenue' => [], 'permits' => []];
while ($row = $monthly_revenue->fetch_assoc()) {
    $chart_data['labels'][] = date('M Y', strtotime($row['month'] . '-01'));
    $chart_data['revenue'][] = $row['revenue'];
    $chart_data['permits'][] = $row['permits'];
}

// Business type distribution - FIXED: using business_type column
$type_distribution = $conn->query("
    SELECT business_type, COUNT(*) as count
    FROM tbl_business_permits
    WHERE status = 'approved'
    GROUP BY business_type
    ORDER BY count DESC
    LIMIT 10
");

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Business Permit Dashboard</h1>
            <p class="text-muted">Manage and monitor business permits</p>
        </div>
        <div>
            <a href="applications.php" class="btn btn-primary">
                <i class="fas fa-file-alt me-1"></i> View Applications
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Permits</p>
                            <h3 class="mb-0"><?php echo number_format($stats['total_permits']); ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                            <i class="fas fa-briefcase fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Pending Applications</p>
                            <h3 class="mb-0"><?php echo number_format($stats['pending']); ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                    <?php if ($stats['pending'] > 0): ?>
                        <a href="applications.php?status=pending" class="btn btn-sm btn-outline-warning mt-2">
                            Review Now <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Active Permits</p>
                            <h3 class="mb-0"><?php echo number_format($stats['approved']); ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Expiring Soon</p>
                            <h3 class="mb-0"><?php echo number_format($stats['expiring_soon']); ?></h3>
                            <small class="text-muted">Within 30 days</small>
                        </div>
                        <div class="bg-danger bg-opacity-10 p-3 rounded">
                            <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                        </div>
                    </div>
                    <?php if ($stats['expiring_soon'] > 0): ?>
                        <a href="renewals.php" class="btn btn-sm btn-outline-danger mt-2">
                            View Details <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Expired Permits</p>
                            <h3 class="mb-0"><?php echo number_format($stats['expired']); ?></h3>
                        </div>
                        <div class="bg-secondary bg-opacity-10 p-3 rounded">
                            <i class="fas fa-ban fa-2x text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Revenue</p>
                            <h3 class="mb-0">₱<?php echo number_format($stats['total_revenue'], 2); ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded">
                            <i class="fas fa-peso-sign fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Revenue & Permits Trend</h5>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="80"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Business Type Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Applications -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Applications</h5>
            <a href="applications.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Permit #</th>
                            <th>Business Name</th>
                            <th>Owner</th>
                            <th>Type</th>
                            <th>Application Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_applications->num_rows > 0): ?>
                            <?php while ($app = $recent_applications->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($app['permit_number'] ?? 'Pending'); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($app['business_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($app['business_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'pending' => 'warning',
                                            'for_inspection' => 'info',
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            'expired' => 'secondary',
                                            'cancelled' => 'dark'
                                        ];
                                        $class = $badge_class[$app['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view-permit.php?id=<?php echo $app['permit_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No applications found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Trend Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($chart_data['labels']); ?>,
        datasets: [{
            label: 'Revenue (₱)',
            data: <?php echo json_encode($chart_data['revenue']); ?>,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.1)',
            tension: 0.4,
            yAxisID: 'y'
        }, {
            label: 'Permits Issued',
            data: <?php echo json_encode($chart_data['permits']); ?>,
            borderColor: 'rgb(255, 99, 132)',
            backgroundColor: 'rgba(255, 99, 132, 0.1)',
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Revenue (₱)'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Number of Permits'
                },
                grid: {
                    drawOnChartArea: false
                }
            }
        }
    }
});

// Business Type Distribution Chart
const typeCtx = document.getElementById('typeChart').getContext('2d');
new Chart(typeCtx, {
    type: 'doughnut',
    data: {
        labels: [
            <?php
            $type_distribution->data_seek(0);
            $labels = [];
            while ($row = $type_distribution->fetch_assoc()) {
                $labels[] = "'" . addslashes($row['business_type']) . "'";
            }
            echo implode(',', $labels);
            ?>
        ],
        datasets: [{
            data: [
                <?php
                $type_distribution->data_seek(0);
                $data = [];
                while ($row = $type_distribution->fetch_assoc()) {
                    $data[] = $row['count'];
                }
                echo implode(',', $data);
                ?>
            ],
            backgroundColor: [
                'rgba(255, 99, 132, 0.8)',
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 206, 86, 0.8)',
                'rgba(75, 192, 192, 0.8)',
                'rgba(153, 102, 255, 0.8)',
                'rgba(255, 159, 64, 0.8)',
                'rgba(199, 199, 199, 0.8)',
                'rgba(83, 102, 255, 0.8)',
                'rgba(255, 99, 255, 0.8)',
                'rgba(99, 255, 132, 0.8)'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>

<?php include_once '../../../includes/footer.php'; ?>