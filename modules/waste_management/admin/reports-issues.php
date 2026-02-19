<?php
// Path: modules/waste_management/admin/reports-issues.php
require_once('../../../config/config.php');

// Check if user is logged in and has appropriate role
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = 'Waste Issues & Reports';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $issue_id = $_POST['issue_id'];
        $status = $_POST['status'];
        
        // Removed admin_remarks from the update - column doesn't exist in database
        $stmt = $conn->prepare("UPDATE tbl_waste_issues SET status = ?, updated_at = NOW() WHERE issue_id = ?");
        $stmt->bind_param("si", $status, $issue_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Issue status updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update issue status.";
        }
        header('Location: reports-issues.php');
        exit;
    }
}

// Pagination settings
$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Filter settings
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$urgency_filter = isset($_GET['urgency']) ? $_GET['urgency'] : '';

// Build WHERE clause for filters
$where_conditions = [];
$filter_params = [];
$filter_types = '';

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $filter_params[] = $status_filter;
    $filter_types .= 's';
}

if (!empty($urgency_filter)) {
    $where_conditions[] = "urgency = ?";
    $filter_params[] = $urgency_filter;
    $filter_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM tbl_waste_issues $where_clause";
if (!empty($filter_params)) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($filter_types, ...$filter_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result()->fetch_assoc();
} else {
    $count_result = $conn->query($count_sql)->fetch_assoc();
}
$total_records = $count_result['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch all reported issues with pagination
$issues_query = "
    SELECT * FROM tbl_waste_issues
    $where_clause
    ORDER BY 
        CASE 
            WHEN status = 'pending' THEN 1
            WHEN status = 'in progress' THEN 2
            WHEN status = 'acknowledged' THEN 3
            WHEN status = 'resolved' THEN 4
            ELSE 5
        END,
        CASE urgency
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
        created_at DESC
    LIMIT ? OFFSET ?
";

if (!empty($filter_params)) {
    $issues_stmt = $conn->prepare($issues_query);
    $filter_params[] = $records_per_page;
    $filter_params[] = $offset;
    $filter_types .= 'ii';
    $issues_stmt->bind_param($filter_types, ...$filter_params);
    $issues_stmt->execute();
    $issues_result = $issues_stmt->get_result();
} else {
    $issues_stmt = $conn->prepare($issues_query);
    $issues_stmt->bind_param('ii', $records_per_page, $offset);
    $issues_stmt->execute();
    $issues_result = $issues_stmt->get_result();
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN urgency = 'critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN urgency = 'high' THEN 1 ELSE 0 END) as high
              FROM tbl_waste_issues";
$stats_result = $conn->query($stats_sql)->fetch_assoc();

// Helper function for urgency badge
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

include('../../../includes/header.php');
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="fas fa-exclamation-circle text-warning me-2"></i>
            Waste Issues & Reports
        </h1>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Reports</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats_result['total']); ?></div>
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
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats_result['pending']); ?></div>
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
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats_result['in_progress']); ?></div>
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
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats_result['resolved']); ?></div>
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
            <form method="GET" action="reports-issues.php" class="row g-3">
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in progress" <?php echo $status_filter === 'in progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="urgency" class="form-label">Urgency</label>
                    <select class="form-select" id="urgency" name="urgency">
                        <option value="">All Urgency Levels</option>
                        <option value="low" <?php echo $urgency_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo $urgency_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $urgency_filter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="critical" <?php echo $urgency_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                    <a href="reports-issues.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Issues Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Reported Issues (<?php echo number_format($total_records); ?> total)
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="issuesTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Issue Type</th>
                            <th>Location</th>
                            <th>Reporter</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Photo</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($issues_result && $issues_result->num_rows > 0): ?>
                            <?php while ($issue = $issues_result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#<?php echo $issue['issue_id']; ?></strong></td>
                                    <td>
                                        <i class="fas fa-trash-alt me-1 text-muted"></i>
                                        <?php echo htmlspecialchars($issue['issue_type']); ?>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($issue['location'], 0, 30)) . (strlen($issue['location']) > 30 ? '...' : ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($issue['reporter_name'] ?? 'N/A'); ?><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($issue['reporter_contact'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo getUrgencyBadge($issue['urgency']); ?></td>
                                    <td>
                                        <?php
                                        $status_class = 'secondary';
                                        if ($issue['status'] == 'resolved') $status_class = 'success';
                                        elseif ($issue['status'] == 'in progress') $status_class = 'warning';
                                        elseif ($issue['status'] == 'pending') $status_class = 'info';
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo date('M d, Y', strtotime($issue['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($issue['photo_path'])): ?>
                                            <button class="btn btn-sm btn-success" onclick="viewPhoto('<?php echo htmlspecialchars($issue['photo_path']); ?>', <?php echo $issue['issue_id']; ?>)" title="View Photo">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                <i class="fas fa-image-slash"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick='viewIssue(<?php echo json_encode($issue); ?>)' title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-info" onclick="updateStatus(<?php echo $issue['issue_id']; ?>, '<?php echo htmlspecialchars($issue['status']); ?>')" title="Update Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No issues reported yet</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="mt-4">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <!-- Previous Button -->
                            <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $current_page - 1; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?><?php echo !empty($urgency_filter) ? '&urgency=' . urlencode($urgency_filter) : ''; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <li class="page-item <?php echo ($current_page == $i) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?><?php echo !empty($urgency_filter) ? '&urgency=' . urlencode($urgency_filter) : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <!-- Next Button -->
                            <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $current_page + 1; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?><?php echo !empty($urgency_filter) ? '&urgency=' . urlencode($urgency_filter) : ''; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Issue Modal -->
<div class="modal fade" id="viewIssueModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-alt me-2"></i>Issue Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="issueDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" onclick="updateStatusFromView()">
                    <i class="fas fa-edit me-1"></i>Update Status
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Photo Modal -->
<div class="modal fade" id="photoModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-image me-2"></i>Issue Photo - Report <span id="photoReportId"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center bg-dark">
                <img id="photoModalImage" src="" alt="Issue Photo" class="img-fluid" style="max-height: 80vh;">
            </div>
            <div class="modal-footer">
                <a id="downloadPhotoLink" href="" download class="btn btn-primary">
                    <i class="fas fa-download me-1"></i>Download Photo
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Update Issue Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="issue_id" id="status_issue_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Status *</label>
                        <select name="status" id="status_select" class="form-control" required>
                            <option value="pending">Pending</option>
                            <option value="acknowledged">Acknowledged</option>
                            <option value="in progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>The status will be updated immediately. Residents will see the new status on their reports page.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Status
                    </button>
                </div>
            </form>
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

.table tbody tr {
    transition: background-color 0.2s ease-in-out;
}

.table tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

#photoModalImage {
    border-radius: 8px;
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
}
</style>

<script>
let currentIssueId = null;

function viewPhoto(photoPath, issueId) {
    const photoUrl = '../../../' + photoPath;
    document.getElementById('photoModalImage').src = photoUrl;
    document.getElementById('downloadPhotoLink').href = photoUrl;
    document.getElementById('photoReportId').textContent = '#' + issueId;
    
    var modal = new bootstrap.Modal(document.getElementById('photoModal'));
    modal.show();
}

function viewIssue(issue) {
    currentIssueId = issue.issue_id;
    
    const urgencyBadge = issue.urgency === 'critical' ? 'dark' : (issue.urgency === 'high' ? 'danger' : (issue.urgency === 'medium' ? 'warning' : 'success'));
    const statusBadge = issue.status === 'resolved' ? 'success' : (issue.status === 'in progress' ? 'warning' : (issue.status === 'pending' ? 'info' : 'secondary'));
    
    const photoSection = issue.photo_path ? `
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <strong><i class="fas fa-camera me-2"></i>Photo Evidence</strong>
                </div>
                <div class="card-body text-center">
                    <img src="../../../${issue.photo_path}" alt="Issue Photo" class="img-fluid rounded shadow" style="max-height: 400px; cursor: pointer;" onclick="viewPhoto('${issue.photo_path}', ${issue.issue_id})">
                    <p class="text-muted mt-2 mb-0">
                        <small><i class="fas fa-info-circle me-1"></i>Click to view full size</small>
                    </p>
                </div>
            </div>
        </div>
    ` : `
        <div class="col-12 mb-3">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No photo evidence was provided for this report.
            </div>
        </div>
    `;
    
    const content = `
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card border-left-primary">
                    <div class="card-body">
                        <small class="text-muted">Issue ID</small>
                        <h5 class="mb-0">#${issue.issue_id}</h5>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card border-left-info">
                    <div class="card-body">
                        <small class="text-muted">Issue Type</small>
                        <h5 class="mb-0">${issue.issue_type}</h5>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <small class="text-muted">Reporter Name</small>
                        <p class="mb-0"><i class="fas fa-user me-2"></i>${issue.reporter_name || 'N/A'}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <small class="text-muted">Contact Number</small>
                        <p class="mb-0"><i class="fas fa-phone me-2"></i>${issue.reporter_contact || 'N/A'}</p>
                    </div>
                </div>
            </div>
            <div class="col-12 mb-3">
                <div class="card">
                    <div class="card-body">
                        <small class="text-muted">Location</small>
                        <p class="mb-0"><i class="fas fa-map-marker-alt me-2 text-danger"></i>${issue.location}</p>
                    </div>
                </div>
            </div>
            <div class="col-12 mb-3">
                <div class="card">
                    <div class="card-body">
                        <small class="text-muted">Description</small>
                        <p class="mb-0 mt-2">${issue.description.replace(/\n/g, '<br>')}</p>
                    </div>
                </div>
            </div>
            ${photoSection}
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <small class="text-muted">Urgency Level</small>
                        <p class="mb-0 mt-2">
                            <span class="badge bg-${urgencyBadge} fs-6">
                                ${issue.urgency.toUpperCase()}
                            </span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <small class="text-muted">Current Status</small>
                        <p class="mb-0 mt-2">
                            <span class="badge bg-${statusBadge} fs-6">
                                ${issue.status.replace('_', ' ').toUpperCase()}
                            </span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-body text-center">
                        <small class="text-muted">Date Reported</small>
                        <p class="mb-0 mt-2">
                            <i class="far fa-calendar me-1"></i>
                            ${new Date(issue.created_at).toLocaleDateString()}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('issueDetailsContent').innerHTML = content;
    var modal = new bootstrap.Modal(document.getElementById('viewIssueModal'));
    modal.show();
}

function updateStatusFromView() {
    if (currentIssueId) {
        // Close view modal
        var viewModal = bootstrap.Modal.getInstance(document.getElementById('viewIssueModal'));
        viewModal.hide();
        
        // Open update modal
        setTimeout(() => {
            updateStatus(currentIssueId, '');
        }, 300);
    }
}

function updateStatus(issueId, currentStatus) {
    document.getElementById('status_issue_id').value = issueId;
    if (currentStatus) {
        document.getElementById('status_select').value = currentStatus;
    }
    var modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
    modal.show();
}
</script>

<?php include('../../../includes/footer.php'); ?>