<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Permit Renewals";

// Filter parameters
$filter = $_GET['filter'] ?? 'expiring'; // expiring, expired, all
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Build query based on filter
$where_clauses = ["bp.status = 'Approved'"];
$params = [];
$types = '';

if ($filter === 'expiring') {
    $where_clauses[] = "bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
} elseif ($filter === 'expired') {
    $where_clauses[] = "bp.expiry_date < CURDATE()";
}

if (!empty($search)) {
    $where_clauses[] = "(bp.business_name LIKE ? OR bp.owner_name LIKE ? OR bp.permit_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_sql = implode(' AND ', $where_clauses);

// Get total count
$count_query = "SELECT COUNT(*) as total FROM tbl_business_permits bp WHERE $where_sql";
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get permits
$query = "
    SELECT bp.*, r.first_name, r.last_name, bt.type_name,
           DATEDIFF(bp.expiry_date, CURDATE()) as days_until_expiry
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    WHERE $where_sql
    ORDER BY bp.expiry_date ASC
    LIMIT ? OFFSET ?
";

$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$permits = $stmt->get_result();

// Get statistics
$stats = [];
$stmt = $conn->query("
    SELECT COUNT(*) as count FROM tbl_business_permits 
    WHERE status = 'Approved' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
");
$stats['expiring'] = $stmt->fetch_assoc()['count'];

$stmt = $conn->query("
    SELECT COUNT(*) as count FROM tbl_business_permits 
    WHERE status = 'Approved' AND expiry_date < CURDATE()
");
$stats['expired'] = $stmt->fetch_assoc()['count'];

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Permit Renewals</h1>
            <p class="text-muted">Manage expiring and expired business permits</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-chart-line me-2"></i>Dashboard
        </a>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Expiring Soon</p>
                            <h3 class="mb-0"><?php echo $stats['expiring']; ?></h3>
                            <small class="text-muted">Within 60 days</small>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Expired Permits</p>
                            <h3 class="mb-0"><?php echo $stats['expired']; ?></h3>
                            <small class="text-muted">Requires renewal</small>
                        </div>
                        <div class="bg-danger bg-opacity-10 p-3 rounded">
                            <i class="fas fa-times-circle fa-2x text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Business name, owner, permit #" 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter By</label>
                    <select name="filter" class="form-select">
                        <option value="expiring" <?php echo $filter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon (60 days)</option>
                        <option value="expired" <?php echo $filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Active Permits</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="renewals.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Permits List -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <?php
                $title_map = [
                    'expiring' => 'Expiring Soon',
                    'expired' => 'Expired Permits',
                    'all' => 'All Active Permits'
                ];
                echo $title_map[$filter] ?? 'Permits';
                ?>
                <span class="badge bg-primary"><?php echo $total_records; ?></span>
            </h5>
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
                            <th>Issue Date</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($permits->num_rows > 0): ?>
                            <?php while ($permit = $permits->fetch_assoc()): ?>
                                <?php
                                $days = $permit['days_until_expiry'];
                                $is_expired = $days < 0;
                                $urgency_class = '';
                                if ($is_expired) {
                                    $urgency_class = 'table-danger';
                                } elseif ($days <= 30) {
                                    $urgency_class = 'table-warning';
                                }
                                ?>
                                <tr class="<?php echo $urgency_class; ?>">
                                    <td><strong><?php echo htmlspecialchars($permit['permit_number']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($permit['business_name']); ?></strong>
                                        <?php if ($permit['trade_name']): ?>
                                            <br><small class="text-muted">DBA: <?php echo htmlspecialchars($permit['trade_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($permit['first_name'] . ' ' . $permit['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($permit['type_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($permit['issue_date'])); ?></td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($permit['expiry_date'])); ?>
                                        <?php if ($is_expired): ?>
                                            <br><small class="text-danger">
                                                <i class="fas fa-times-circle"></i> Expired <?php echo abs($days); ?> days ago
                                            </small>
                                        <?php else: ?>
                                            <br><small class="text-warning">
                                                <i class="fas fa-clock"></i> <?php echo $days; ?> days remaining
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_expired): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php elseif ($days <= 30): ?>
                                            <span class="badge bg-warning">Expiring Soon</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view-permit.php?id=<?php echo $permit['permit_id']; ?>" 
                                               class="btn btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button class="btn btn-outline-success" 
                                                    onclick="processRenewal(<?php echo $permit['permit_id']; ?>)"
                                                    title="Process Renewal">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                            <a href="print-permit.php?id=<?php echo $permit['permit_id']; ?>" 
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
                                    No permits found
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
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                Previous
                            </a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Renewal Modal -->
<div class="modal fade" id="renewalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Permit Renewal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="renewalForm" method="POST" action="process-renewal.php">
                <div class="modal-body">
                    <input type="hidden" name="permit_id" id="renewal_permit_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Renewal Fee</label>
                        <input type="number" name="renewal_fee" class="form-control" value="1000.00" step="0.01" required>
                        <small class="text-muted">Renewal fee (usually lower than new application)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Validity Period</label>
                        <select name="validity_period" class="form-select" required>
                            <option value="1">1 Year</option>
                            <option value="2">2 Years</option>
                            <option value="3">3 Years</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Process Renewal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function processRenewal(permitId) {
    document.getElementById('renewal_permit_id').value = permitId;
    const modal = new bootstrap.Modal(document.getElementById('renewalModal'));
    modal.show();
}
</script>

<?php include_once '../../../includes/footer.php'; ?>