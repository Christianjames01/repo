<?php
/**
 * Certificate List/History Page
 * Location: /modules/certificates/list.php
 * Barangay Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_functions.php';

// Require admin or staff access
requireAnyRole(['Admin', 'Super Admin', 'Staff']);

$page_title = "Certificate History";

// Handle certificate cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'cancel') {
        $certificate_id = intval($_POST['certificate_id'] ?? 0);
        
        if ($certificate_id > 0) {
            $result = executeQuery($conn,
                "UPDATE tbl_certificates SET status = 'cancelled' WHERE certificate_id = ?",
                [$certificate_id], 'i'
            );
            
            if ($result) {
                // Log activity
                logActivity($conn, getCurrentUserId(), 
                    "Cancelled certificate ID: {$certificate_id}",
                    'tbl_certificates', $certificate_id);
                
                setSuccessMessage('Certificate cancelled successfully');
            } else {
                setErrorMessage('Failed to cancel certificate');
            }
        }
        
        header('Location: list.php');
        exit;
    }
}

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Search and filter
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$filter_type = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';
$filter_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

// Build WHERE clause
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(c.resident_name LIKE ? OR c.control_number LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

if (!empty($filter_type)) {
    $where_clauses[] = "c.certificate_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

if (!empty($filter_status)) {
    $where_clauses[] = "c.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM tbl_certificates c {$where_sql}";
$total_result = empty($params) ? 
    fetchOne($conn, $count_sql) : 
    fetchOne($conn, $count_sql, $params, $types);
$total_records = $total_result['total'];
$total_pages = ceil($total_records / $per_page);

// Fetch certificates
$sql = "SELECT 
            c.certificate_id,
            c.certificate_type,
            c.resident_name,
            c.control_number,
            c.or_number,
            c.purpose,
            c.status,
            c.issued_date,
            c.valid_until,
            c.created_at,
            CONCAT(u.first_name, ' ', u.last_name) as issued_by_name
        FROM tbl_certificates c
        LEFT JOIN tbl_users u ON c.issued_by = u.user_id
        {$where_sql}
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$certificates = fetchAll($conn, $sql, $params, $types);

// Get statistics
$stats = [
    'total' => fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_certificates WHERE status = 'issued'")['count'],
    'today' => fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_certificates WHERE DATE(created_at) = CURDATE() AND status = 'issued'")['count'],
    'this_month' => fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_certificates WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'issued'")['count'],
    'cancelled' => fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_certificates WHERE status = 'cancelled'")['count']
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <?php echo displayMessage(); ?>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-certificate fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-0">Total Issued</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['total']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-calendar-day fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-0">Today</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['today']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-info text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-calendar-alt fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-0">This Month</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['this_month']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-danger text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-ban fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-0">Cancelled</h6>
                            <h2 class="mb-0"><?php echo number_format($stats['cancelled']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="fas fa-list me-2"></i>Certificate History</h4>
                        <a href="index.php" class="btn btn-light btn-sm">
                            <i class="fas fa-plus me-1"></i>Generate New Certificate
                        </a>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Search and Filter Form -->
                    <form method="GET" class="mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Search by name or control number..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <select name="type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="residency" <?php echo $filter_type === 'residency' ? 'selected' : ''; ?>>Residency</option>
                                    <option value="indigency" <?php echo $filter_type === 'indigency' ? 'selected' : ''; ?>>Indigency</option>
                                    <option value="clearance" <?php echo $filter_type === 'clearance' ? 'selected' : ''; ?>>Clearance</option>
                                    <option value="good_moral" <?php echo $filter_type === 'good_moral' ? 'selected' : ''; ?>>Good Moral</option>
                                    <option value="business_permit" <?php echo $filter_type === 'business_permit' ? 'selected' : ''; ?>>Business Permit</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="issued" <?php echo $filter_status === 'issued' ? 'selected' : ''; ?>>Issued</option>
                                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                                <a href="list.php" class="btn btn-secondary">
                                    <i class="fas fa-redo me-1"></i>Reset
                                </a>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Results Info -->
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Showing <?php echo count($certificates); ?> of <?php echo number_format($total_records); ?> certificates
                        <?php if (!empty($search)): ?>
                            matching "<?php echo htmlspecialchars($search); ?>"
                        <?php endif; ?>
                    </div>
                    
                    <!-- Certificates Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Control #</th>
                                    <th>Type</th>
                                    <th>Resident Name</th>
                                    <th>Purpose</th>
                                    <th>OR Number</th>
                                    <th>Status</th>
                                    <th>Issued Date</th>
                                    <th>Issued By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($certificates)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">No certificates found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($certificates as $cert): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($cert['control_number']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php 
                                                    echo ucwords(str_replace('_', ' ', $cert['certificate_type'])); 
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($cert['resident_name']); ?></td>
                                            <td><?php echo htmlspecialchars(truncateText($cert['purpose'], 30)); ?></td>
                                            <td><?php echo htmlspecialchars($cert['or_number'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($cert['status'] === 'issued'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle"></i> Issued
                                                    </span>
                                                <?php elseif ($cert['status'] === 'cancelled'): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-ban"></i> Cancelled
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">
                                                        <i class="fas fa-clock"></i> <?php echo ucfirst($cert['status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                echo !empty($cert['issued_date']) 
                                                    ? formatDate($cert['issued_date']) 
                                                    : formatDate($cert['created_at']); 
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($cert['issued_by_name'] ?? 'Unknown'); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-info" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#viewModal<?php echo $cert['certificate_id']; ?>"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <?php if ($cert['status'] === 'issued'): ?>
                                                        <button type="button" class="btn btn-success" 
                                                                onclick="window.open('view.php?id=<?php echo $cert['certificate_id']; ?>', '_blank')"
                                                                title="View Certificate">
                                                            <i class="fas fa-file-pdf"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn btn-danger" 
                                                                onclick="cancelCertificate(<?php echo $cert['certificate_id']; ?>)"
                                                                title="Cancel Certificate">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- View Details Modal -->
                                        <div class="modal fade" id="viewModal<?php echo $cert['certificate_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Certificate Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <table class="table table-borderless">
                                                            <tr>
                                                                <th width="40%">Control Number:</th>
                                                                <td><?php echo htmlspecialchars($cert['control_number']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Certificate Type:</th>
                                                                <td><?php echo ucwords(str_replace('_', ' ', $cert['certificate_type'])); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Resident Name:</th>
                                                                <td><?php echo htmlspecialchars($cert['resident_name']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Purpose:</th>
                                                                <td><?php echo htmlspecialchars($cert['purpose']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>OR Number:</th>
                                                                <td><?php echo htmlspecialchars($cert['or_number'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Status:</th>
                                                                <td>
                                                                    <?php if ($cert['status'] === 'issued'): ?>
                                                                        <span class="badge bg-success">Issued</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-danger">Cancelled</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <th>Issued Date:</th>
                                                                <td><?php echo formatDate($cert['issued_date'] ?? $cert['created_at']); ?></td>
                                                            </tr>
                                                            <?php if (!empty($cert['valid_until'])): ?>
                                                            <tr>
                                                                <th>Valid Until:</th>
                                                                <td><?php echo formatDate($cert['valid_until']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <tr>
                                                                <th>Issued By:</th>
                                                                <td><?php echo htmlspecialchars($cert['issued_by_name'] ?? 'Unknown'); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        <?php if ($cert['status'] === 'issued'): ?>
                                                            <a href="view.php?id=<?php echo $cert['certificate_id']; ?>" 
                                                               class="btn btn-primary" target="_blank">
                                                                <i class="fas fa-file-pdf me-1"></i>View Certificate
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Certificate pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <!-- Previous -->
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <!-- Page Numbers -->
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <!-- Next -->
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Certificate Form -->
<form id="cancelForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="certificate_id" id="cancelCertificateId">
</form>

<script>
function cancelCertificate(certificateId) {
    if (confirm('Are you sure you want to cancel this certificate? This action cannot be undone.')) {
        document.getElementById('cancelCertificateId').value = certificateId;
        document.getElementById('cancelForm').submit();
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>