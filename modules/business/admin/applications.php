<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Permit Applications";

// Filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Build query
$where_clauses = [];
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $where_clauses[] = "bp.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($type_filter !== 'all') {
    $where_clauses[] = "bp.business_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_clauses[] = "(bp.business_name LIKE ? OR bp.owner_name LIKE ? OR bp.permit_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM tbl_business_permits bp $where_sql";
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get applications
$query = "
    SELECT bp.*, r.first_name, r.last_name, r.contact_number as resident_contact
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    $where_sql
    ORDER BY 
        CASE bp.status
            WHEN 'pending' THEN 1
            WHEN 'for_inspection' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'rejected' THEN 4
            ELSE 5
        END,
        bp.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$applications = $stmt->get_result();

// Get unique business types from existing permits for filter
$business_types = $conn->query("
    SELECT DISTINCT business_type 
    FROM tbl_business_permits 
    WHERE business_type IS NOT NULL 
    ORDER BY business_type
");

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Business Permit Applications</h1>
            <p class="text-muted">Review and manage permit applications</p>
        </div>
        <div>
            <a href="registry.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-list"></i> Business Registry
            </a>
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Business name, owner, permit #" 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all">All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="for_inspection" <?php echo $status_filter === 'for_inspection' ? 'selected' : ''; ?>>For Inspection</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Business Type</label>
                    <select name="type" class="form-select">
                        <option value="all">All Types</option>
                        <?php while ($type = $business_types->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($type['business_type']); ?>" 
                                    <?php echo $type_filter == $type['business_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['business_type']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="applications.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Applications List -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                Applications 
                <span class="badge bg-primary"><?php echo $total_records; ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Permit #</th>
                            <th>Business Details</th>
                            <th>Owner</th>
                            <th>Type</th>
                            <th>Application Date</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($applications->num_rows > 0): ?>
                            <?php while ($app = $applications->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($app['permit_number'] ?? 'Pending'); ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($app['business_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>
                                        <?php if ($app['owner_contact']): ?>
                                            <br><small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($app['owner_contact']); ?></small>
                                        <?php endif; ?>
                                    </td>
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
                                        <?php
                                        $payment_badge = [
                                            'unpaid' => 'danger',
                                            'partial' => 'warning',
                                            'paid' => 'success'
                                        ];
                                        $pay_class = $payment_badge[$app['payment_status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $pay_class; ?>">
                                            <?php echo ucfirst($app['payment_status']); ?>
                                        </span>
                                        <?php if ($app['permit_fee'] > 0): ?>
                                            <br><small class="text-muted">₱<?php echo number_format($app['permit_fee'], 2); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view-permit.php?id=<?php echo $app['permit_id']; ?>" 
                                               class="btn btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($app['status'] === 'pending' || $app['status'] === 'for_inspection'): ?>
                                                <a href="process-permit.php?id=<?php echo $app['permit_id']; ?>" 
                                                   class="btn btn-outline-success" title="Process Application">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="print-permit.php?id=<?php echo $app['permit_id']; ?>" 
                                               class="btn btn-outline-secondary" title="Print" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                    No applications found
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&type=<?php echo urlencode($type_filter); ?>&search=<?php echo urlencode($search); ?>">
                                Previous
                            </a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&type=<?php echo urlencode($type_filter); ?>&search=<?php echo urlencode($search); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&type=<?php echo urlencode($type_filter); ?>&search=<?php echo urlencode($search); ?>">
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_once '../../../includes/footer.php'; ?>