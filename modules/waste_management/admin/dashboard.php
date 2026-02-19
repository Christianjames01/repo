<?php
require_once('../../../config/config.php');

// Check if user is logged in and has appropriate role
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Waste Management Dashboard";

// Get statistics
$total_schedules = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_waste_schedules WHERE status = 'active'", [], '')['count'] ?? 0;
$total_programs = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_recycling_programs WHERE status = 'active'", [], '')['count'] ?? 0;
$total_participants = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_recycling_participants WHERE status = 'active'", [], '')['count'] ?? 0;
$pending_issues = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_waste_issues WHERE status = 'pending'", [], '')['count'] ?? 0;

// Get recent collection reports
$recent_reports = fetchAll($conn, 
    "SELECT * FROM tbl_waste_collection_reports 
     ORDER BY collection_date DESC, created_at DESC 
     LIMIT 5", 
    [], ''
);

// Get pending waste issues - FIXED QUERY
$pending_waste_issues = fetchAll($conn,
    "SELECT * FROM tbl_waste_issues 
     WHERE status IN ('pending', 'acknowledged') 
     ORDER BY 
        CASE urgency 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
            ELSE 4 
        END,
        created_at DESC 
     LIMIT 5",
    [], ''
);

// Get monthly statistics
$current_month = date('Y-m');
$monthly_stats = fetchOne($conn,
    "SELECT * FROM tbl_waste_statistics 
     WHERE stat_month = ? 
     ORDER BY stat_date DESC 
     LIMIT 1",
    [$current_month], 's'
);

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-trash-alt me-2"></i><?php echo $page_title; ?></h1>
    </div>

    <?php echo displayMessage(); ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Active Schedules</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_schedules; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Active Programs</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_programs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-recycle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Program Participants</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_participants; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-users fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        Pending Issues</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pending_issues; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Statistics -->
            <?php if ($monthly_stats): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-chart-bar me-2"></i>Monthly Statistics - <?php echo date('F Y', strtotime($current_month . '-01')); ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Biodegradable</p>
                                        <h4 class="text-success"><?php echo number_format($monthly_stats['biodegradable_kg'], 2); ?> kg</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Non-Biodegradable</p>
                                        <h4 class="text-danger"><?php echo number_format($monthly_stats['non_biodegradable_kg'], 2); ?> kg</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Recyclable</p>
                                        <h4 class="text-info"><?php echo number_format($monthly_stats['recyclable_kg'], 2); ?> kg</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Total Waste</p>
                                        <h4 class="text-primary"><?php echo number_format($monthly_stats['total_kg'], 2); ?> kg</h4>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Households Served</p>
                                        <h5><?php echo number_format($monthly_stats['households_served']); ?></h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Recycling Rate</p>
                                        <h5><?php echo number_format($monthly_stats['recycling_rate'], 2); ?>%</h5>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <p class="mb-1 text-muted">Landfill Diversion</p>
                                        <h5><?php echo number_format($monthly_stats['landfill_diversion_rate'], 2); ?>%</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row">
                <!-- Recent Collection Reports -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-clipboard-list me-2"></i>Recent Collection Reports
                            </h6>
                            <a href="reports.php" class="btn btn-sm btn-primary">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_reports)): ?>
                                <p class="text-muted text-center py-3">No collection reports yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Area</th>
                                                <th>Type</th>
                                                <th>Quantity</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_reports as $report): ?>
                                            <tr>
                                                <td><?php echo formatDate($report['collection_date']); ?></td>
                                                <td><?php echo htmlspecialchars($report['area_zone']); ?></td>
                                                <td><?php echo ucfirst($report['waste_type']); ?></td>
                                                <td><?php echo $report['quantity_kg'] ? number_format($report['quantity_kg'], 2) . ' kg' : '-'; ?></td>
                                                <td><?php echo getStatusBadge($report['status']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pending Waste Issues -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-warning">
                                <i class="fas fa-exclamation-circle me-2"></i>Pending Issues
                            </h6>
                            <a href="reports-issues.php" class="btn btn-sm btn-warning">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_waste_issues)): ?>
                                <p class="text-muted text-center py-3">No pending issues.</p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($pending_waste_issues as $issue): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($issue['issue_type']); ?></h6>
                                                <p class="mb-1 text-muted small"><?php echo htmlspecialchars(truncateText($issue['description'], 100)); ?></p>
                                                <small class="text-muted">
                                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($issue['location']); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <?php echo getSeverityBadge($issue['urgency']); ?>
                                                <br>
                                                <small class="text-muted"><?php echo timeAgo($issue['created_at']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-bolt me-2"></i>Quick Actions
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3 mb-3">
                                    <a href="schedules.php" class="text-decoration-none">
                                        <div class="p-3 border rounded hover-shadow">
                                            <i class="fas fa-calendar-plus fa-3x text-primary mb-2"></i>
                                            <p class="mb-0 font-weight-bold">Manage Schedules</p>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="programs.php" class="text-decoration-none">
                                        <div class="p-3 border rounded hover-shadow">
                                            <i class="fas fa-leaf fa-3x text-success mb-2"></i>
                                            <p class="mb-0 font-weight-bold">Recycling Programs</p>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="reports.php" class="text-decoration-none">
                                        <div class="p-3 border rounded hover-shadow">
                                            <i class="fas fa-file-alt fa-3x text-info mb-2"></i>
                                            <p class="mb-0 font-weight-bold">Collection Reports</p>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="reports-issues.php" class="text-decoration-none">
                                        <div class="p-3 border rounded hover-shadow">
                                            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-2"></i>
                                            <p class="mb-0 font-weight-bold">Waste Issues</p>
                                        </div>
                                    </a>
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
.border-left-primary {
    border-left: 4px solid #4e73df !important;
}
.border-left-success {
    border-left: 4px solid #1cc88a !important;
}
.border-left-info {
    border-left: 4px solid #36b9cc !important;
}
.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}
.hover-shadow:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
    transition: all 0.3s;
}
</style>

<?php require_once '../../../includes/footer.php'; ?>