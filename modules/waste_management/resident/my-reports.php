<?php
require_once('../../../config/config.php');

// Check if user is logged in
requireLogin();

$page_title = "My Waste Reports";

// Get current user ID
$user_id = $_SESSION['user_id'];

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Filter settings
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$urgency_filter = isset($_GET['urgency']) ? sanitize($_GET['urgency']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Build WHERE clause
$where_conditions = ["reporter_id = ?"];
$params = [$user_id];
$types = 'i';

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($urgency_filter)) {
    $where_conditions[] = "urgency = ?";
    $params[] = $urgency_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_conditions[] = "(issue_type LIKE ? OR location LIKE ? OR description LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM tbl_waste_issues WHERE {$where_clause}";
$count_result = fetchOne($conn, $count_sql, $params, $types);
$total_records = $count_result['total'] ?? 0;
$total_pages = ceil($total_records / $records_per_page);

// Fetch reports - using only basic columns
$sql = "SELECT 
            issue_id,
            issue_type,
            location,
            description,
            urgency,
            status,
            photo_path,
            created_at
        FROM tbl_waste_issues 
        WHERE {$where_clause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$reports = fetchAll($conn, $sql, $params, $types);

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
              FROM tbl_waste_issues 
              WHERE reporter_id = ?";
$stats = fetchOne($conn, $stats_sql, [$user_id], 'i');

// Helper function for urgency badge if not in functions.php
if (!function_exists('getUrgencyBadge')) {
    function getUrgencyBadge($urgency) {
        $urgency = strtolower(trim($urgency));
        $badges = [
            'low' => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Low</span>',
            'medium' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
            'high' => '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
            'critical' => '<span class="badge bg-dark"><i class="fas fa-skull-crossbones me-1"></i>Critical</span>'
        ];
        
        return $badges[$urgency] ?? '<span class="badge bg-secondary">Unknown</span>';
    }
}

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-clipboard-list me-2"></i><?php echo $page_title; ?></h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="report-issue.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i>Report New Issue
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Reports</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total'] ?? 0); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">In Progress</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['in_progress'] ?? 0); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-spinner fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Resolved</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['resolved'] ?? 0); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filter Reports
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="my-reports.php" id="filterForm">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="in progress" <?php echo $status_filter === 'in progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="urgency" class="form-label">Urgency</label>
                        <select class="form-select" id="urgency" name="urgency">
                            <option value="">All Urgency Levels</option>
                            <option value="low" <?php echo $urgency_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $urgency_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $urgency_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo $urgency_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Search by issue type, location, or description..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="col-md-2 mb-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i>Filter
                        </button>
                        <a href="my-reports.php" class="btn btn-secondary">
                            <i class="fas fa-redo me-1"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Reports List -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>My Reports (<?php echo number_format($total_records); ?> total)
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($reports)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>No reports found</h5>
                    <p class="mb-0">
                        <?php if (!empty($status_filter) || !empty($urgency_filter) || !empty($search)): ?>
                            No reports match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            You haven't submitted any waste issue reports yet.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($status_filter) && empty($urgency_filter) && empty($search)): ?>
                        <a href="report-issue.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-1"></i>Report Your First Issue
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Issue Type</th>
                                <th>Location</th>
                                <th>Urgency</th>
                                <th>Status</th>
                                <th>Date Reported</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo $report['issue_id']; ?></strong>
                                    </td>
                                    <td>
                                        <i class="fas fa-trash-alt me-1 text-muted"></i>
                                        <?php echo htmlspecialchars($report['issue_type']); ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                                        <?php echo htmlspecialchars(truncateText($report['location'], 40)); ?>
                                    </td>
                                    <td><?php echo getUrgencyBadge($report['urgency']); ?></td>
                                    <td><?php echo getStatusBadge($report['status']); ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <i class="far fa-calendar me-1"></i>
                                            <?php echo formatDate($report['created_at']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="view-report.php?id=<?php echo $report['issue_id']; ?>" 
                                           class="btn btn-sm btn-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="mt-4">
                        <?php
                        $query_string = '';
                        if (!empty($status_filter)) $query_string .= '&status=' . urlencode($status_filter);
                        if (!empty($urgency_filter)) $query_string .= '&urgency=' . urlencode($urgency_filter);
                        if (!empty($search)) $query_string .= '&search=' . urlencode($search);
                        echo generatePagination($current_page, $total_pages, 'my-reports.php?' . ltrim($query_string, '&'));
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Help Section -->
    <div class="card shadow">
        <div class="card-header py-3 bg-info text-white">
            <h6 class="m-0 font-weight-bold">
                <i class="fas fa-question-circle me-2"></i>About Your Reports
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="fas fa-info-circle text-info me-2"></i>Report Status Meanings</h6>
                    <ul class="mb-3">
                        <li><strong>Pending:</strong> Your report has been received and is awaiting review</li>
                        <li><strong>In Progress:</strong> The issue is being actively addressed by our team</li>
                        <li><strong>Resolved:</strong> The issue has been resolved</li>
                        <li><strong>Closed:</strong> The report has been closed (resolved or deemed invalid)</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6><i class="fas fa-exclamation-triangle text-warning me-2"></i>Urgency Levels</h6>
                    <ul class="mb-3">
                        <li><strong>Low:</strong> Can wait a few days</li>
                        <li><strong>Medium:</strong> Needs attention soon</li>
                        <li><strong>High:</strong> Urgent attention required</li>
                        <li><strong>Critical:</strong> Immediate action needed</li>
                    </ul>
                </div>
            </div>
            <hr>
            <p class="mb-0">
                <i class="fas fa-phone-alt text-success me-2"></i>
                <strong>Need immediate assistance?</strong> Contact the barangay office at (123) 456-7890
            </p>
        </div>
    </div>
</div>

<style>
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}

.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}

.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}

.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}

.card {
    transition: transform 0.2s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
}

.table tbody tr {
    transition: background-color 0.2s ease-in-out;
}

.table tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}
</style>

<script>
// Auto-submit form when filter changes
document.getElementById('status').addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});

document.getElementById('urgency').addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});

// Confirm before clearing filters
document.querySelector('a[href="my-reports.php"]').addEventListener('click', function(e) {
    const hasFilters = <?php echo (!empty($status_filter) || !empty($urgency_filter) || !empty($search)) ? 'true' : 'false'; ?>;
    if (hasFilters) {
        if (!confirm('Clear all filters?')) {
            e.preventDefault();
        }
    }
});
</script>

<?php 
require_once '../../../includes/footer.php'; 
?>