<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Registry";
$current_user_id = getCurrentUserId();

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'business_name';
$sort_order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = ["bp.status = 'Approved'"];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(bp.business_name LIKE ? OR bp.owner_name LIKE ? OR bp.permit_number LIKE ?)";
    $search_param = "%{$search}%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= "sss";
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $where_conditions[] = "bp.expiry_date >= CURDATE()";
    } elseif ($status_filter === 'expired') {
        $where_conditions[] = "bp.expiry_date < CURDATE()";
    } elseif ($status_filter === 'expiring_soon') {
        $where_conditions[] = "bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    }
}

if ($type_filter > 0) {
    $where_conditions[] = "bp.business_type_id = ?";
    $params[] = $type_filter;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_conditions);

// Valid sort columns
$valid_sorts = ['business_name', 'permit_number', 'issue_date', 'expiry_date', 'type_name'];
if (!in_array($sort_by, $valid_sorts)) {
    $sort_by = 'business_name';
}

// Get total count
$count_sql = "
    SELECT COUNT(DISTINCT bp.permit_id) as total
    FROM tbl_business_permits bp
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    WHERE $where_sql
";

$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Get businesses - Using bp.* to get all columns from business_permits
$sql = "
    SELECT 
        bp.*,
        bt.type_name,
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        COALESCE(r.contact_number, 'N/A') as contact_number,
        r.contact_number as resident_contact,
        CASE 
            WHEN bp.expiry_date < CURDATE() THEN 'expired'
            WHEN bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
            ELSE 'active'
        END as permit_status,
        DATEDIFF(bp.expiry_date, CURDATE()) as days_until_expiry
    FROM tbl_business_permits bp
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    WHERE $where_sql
    ORDER BY $sort_by $sort_order
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$businesses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get business types for filter
$types_result = $conn->query("SELECT * FROM tbl_business_types ORDER BY type_name");
$business_types = $types_result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_businesses,
        SUM(CASE WHEN expiry_date >= CURDATE() THEN 1 ELSE 0 END) as active_businesses,
        SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_businesses,
        SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon
    FROM tbl_business_permits
    WHERE status = 'Approved'
";
$stats = $conn->query($stats_sql)->fetch_assoc();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $permit_id = (int)$_POST['permit_id'];
        
        switch ($_POST['action']) {
            case 'revoke':
                $reason = trim($_POST['revoke_reason']);
                $stmt = $conn->prepare("UPDATE tbl_business_permits SET status = 'Revoked' WHERE permit_id = ?");
                $stmt->bind_param("i", $permit_id);
                if ($stmt->execute()) {
                    // Log action
                    $log_stmt = $conn->prepare("
                        INSERT INTO tbl_business_permit_history (permit_id, action, notes, action_by)
                        VALUES (?, 'Permit Revoked', ?, ?)
                    ");
                    $log_stmt->bind_param("isi", $permit_id, $reason, $current_user_id);
                    $log_stmt->execute();
                    
                    $_SESSION['success_message'] = "Business permit revoked successfully.";
                } else {
                    $_SESSION['error_message'] = "Error revoking permit.";
                }
                break;
                
            case 'send_reminder':
                // Send expiry reminder notification
                $stmt = $conn->prepare("
                    SELECT resident_id, business_name, expiry_date 
                    FROM tbl_business_permits 
                    WHERE permit_id = ?
                ");
                $stmt->bind_param("i", $permit_id);
                $stmt->execute();
                $permit_data = $stmt->get_result()->fetch_assoc();
                
                if ($permit_data) {
                    $notification_stmt = $conn->prepare("
                        INSERT INTO tbl_notifications (user_id, title, message, type)
                        SELECT user_id, ?, ?, 'business_reminder'
                        FROM tbl_users
                        WHERE resident_id = ?
                    ");
                    $title = "Business Permit Expiry Reminder";
                    $message = "Your business permit for {$permit_data['business_name']} will expire on " . 
                               date('F d, Y', strtotime($permit_data['expiry_date'])) . ". Please renew to avoid penalties.";
                    $notification_stmt->bind_param("ssi", $title, $message, $permit_data['resident_id']);
                    $notification_stmt->execute();
                    
                    $_SESSION['success_message'] = "Reminder sent successfully.";
                } else {
                    $_SESSION['error_message'] = "Permit not found.";
                }
                break;
        }
        
        redirect($_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    }
}

include_once '../../../includes/header.php';
?>

<style>
.stats-card {
    border-left: 4px solid;
    transition: all 0.3s ease;
}
.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.business-card {
    transition: all 0.3s ease;
}
.business-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.status-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
}
.filter-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}
</style>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Business Registry</h1>
            <p class="text-muted mb-0">Manage all registered businesses in the barangay</p>
        </div>
        <div>
            <button class="btn btn-success" onclick="exportRegistry()">
                <i class="fas fa-file-excel me-2"></i>Export Registry
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stats-card border-0 shadow-sm" style="border-left-color: #0d6efd !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Businesses</h6>
                            <h3 class="mb-0"><?php echo number_format($stats['total_businesses']); ?></h3>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-store fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card border-0 shadow-sm" style="border-left-color: #198754 !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Active Permits</h6>
                            <h3 class="mb-0"><?php echo number_format($stats['active_businesses']); ?></h3>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card border-0 shadow-sm" style="border-left-color: #ffc107 !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Expiring Soon</h6>
                            <h3 class="mb-0"><?php echo number_format($stats['expiring_soon']); ?></h3>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-clock fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card border-0 shadow-sm" style="border-left-color: #dc3545 !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Expired</h6>
                            <h3 class="mb-0"><?php echo number_format($stats['expired_businesses']); ?></h3>
                        </div>
                        <div class="text-danger">
                            <i class="fas fa-exclamation-triangle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label small">Search</label>
                <input type="text" name="search" class="form-control" 
                       placeholder="Business name, owner, permit number..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="expiring_soon" <?php echo $status_filter === 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                    <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Business Type</label>
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <?php foreach ($business_types as $type): ?>
                        <option value="<?php echo $type['type_id']; ?>" 
                                <?php echo $type_filter == $type['type_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['type_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Sort By</label>
                <select name="sort" class="form-select">
                    <option value="business_name" <?php echo $sort_by === 'business_name' ? 'selected' : ''; ?>>Business Name</option>
                    <option value="permit_number" <?php echo $sort_by === 'permit_number' ? 'selected' : ''; ?>>Permit Number</option>
                    <option value="issue_date" <?php echo $sort_by === 'issue_date' ? 'selected' : ''; ?>>Issue Date</option>
                    <option value="expiry_date" <?php echo $sort_by === 'expiry_date' ? 'selected' : ''; ?>>Expiry Date</option>
                    <option value="type_name" <?php echo $sort_by === 'type_name' ? 'selected' : ''; ?>>Business Type</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small">Order</label>
                <select name="order" class="form-select">
                    <option value="asc" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>ASC</option>
                    <option value="desc" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>DESC</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter me-2"></i>Apply Filters
                </button>
                <a href="registry.php" class="btn btn-outline-secondary">
                    <i class="fas fa-redo me-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Results Info -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <p class="text-muted mb-0">
                Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_records); ?> 
                of <?php echo number_format($total_records); ?> businesses
            </p>
        </div>
    </div>

    <!-- Business Listing -->
    <?php if (empty($businesses)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-store-slash fa-3x text-muted mb-3"></i>
                <h5>No Businesses Found</h5>
                <p class="text-muted">No businesses match your search criteria.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($businesses as $business): ?>
                <div class="col-12">
                    <div class="card business-card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="row">
                                <!-- Business Info -->
                                <div class="col-md-8">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h5 class="mb-1">
                                                <?php echo htmlspecialchars($business['business_name']); ?>
                                            </h5>
                                            <p class="text-muted mb-0">
                                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($business['owner_name']); ?>
                                            </p>
                                        </div>
                                        <div>
                                            <?php if ($business['permit_status'] === 'active'): ?>
                                                <span class="status-badge bg-success text-white">
                                                    <i class="fas fa-check-circle me-1"></i>Active
                                                </span>
                                            <?php elseif ($business['permit_status'] === 'expiring'): ?>
                                                <span class="status-badge bg-warning text-dark">
                                                    <i class="fas fa-clock me-1"></i>Expiring in <?php echo $business['days_until_expiry']; ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge bg-danger text-white">
                                                    <i class="fas fa-times-circle me-1"></i>Expired
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-2 small mt-2">
                                        <div class="col-md-6">
                                            <i class="fas fa-certificate text-primary me-2"></i>
                                            <strong>Permit #:</strong> <?php echo htmlspecialchars($business['permit_number']); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-briefcase text-info me-2"></i>
                                            <strong>Type:</strong> <?php echo htmlspecialchars($business['type_name']); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                            <strong>Address:</strong> <?php echo htmlspecialchars($business['business_address']); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-phone text-success me-2"></i>
                                            <strong>Contact:</strong> <?php echo htmlspecialchars($business['contact_number']); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-calendar text-warning me-2"></i>
                                            <strong>Issued:</strong> <?php echo date('M d, Y', strtotime($business['issue_date'])); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <i class="fas fa-calendar-times text-danger me-2"></i>
                                            <strong>Expires:</strong> <?php echo date('M d, Y', strtotime($business['expiry_date'])); ?>
                                        </div>
                                        <?php if (!empty($business['is_renewal'])): ?>
                                            <div class="col-12">
                                                <span class="badge bg-info">
                                                    <i class="fas fa-sync-alt me-1"></i>Renewal <?php echo !empty($business['renewal_count']) ? '(' . $business['renewal_count'] . ')' : ''; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Actions -->
                                <div class="col-md-4 d-flex flex-column justify-content-between">
                                    <div class="text-end mb-3">
                                        <small class="text-muted d-block">Owner Contact</small>
                                        <strong><?php echo htmlspecialchars($business['resident_name']); ?></strong>
                                        <br>
                                        <small><?php echo htmlspecialchars($business['resident_contact']); ?></small>
                                    </div>
                                    
                                    <div class="btn-group-vertical w-100" role="group">
                                        <a href="view-business.php?id=<?php echo $business['permit_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-2"></i>View Details
                                        </a>
                                        
                                        <?php if ($business['permit_status'] === 'expiring' || $business['permit_status'] === 'expired'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                    onclick="sendReminder(<?php echo $business['permit_id']; ?>)">
                                                <i class="fas fa-bell me-2"></i>Send Reminder
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="btn btn-sm btn-outline-success" 
                                                onclick="printCertificate(<?php echo $business['permit_id']; ?>)">
                                            <i class="fas fa-print me-2"></i>Print Certificate
                                        </button>
                                        
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="revokePermit(<?php echo $business['permit_id']; ?>, '<?php echo htmlspecialchars($business['business_name']); ?>')">
                                            <i class="fas fa-ban me-2"></i>Revoke Permit
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Revoke Modal -->
<div class="modal fade" id="revokeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-ban me-2"></i>Revoke Business Permit
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="permit_id" id="revokePermitId">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        You are about to revoke the permit for <strong id="revokeBusinessName"></strong>. 
                        This action cannot be undone.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Revocation <span class="text-danger">*</span></label>
                        <textarea name="revoke_reason" class="form-control" rows="4" required 
                                  placeholder="Enter the reason for revoking this business permit..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-ban me-2"></i>Revoke Permit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function revokePermit(permitId, businessName) {
    document.getElementById('revokePermitId').value = permitId;
    document.getElementById('revokeBusinessName').textContent = businessName;
    new bootstrap.Modal(document.getElementById('revokeModal')).show();
}

function sendReminder(permitId) {
    if (confirm('Send expiry reminder to business owner?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="send_reminder">
            <input type="hidden" name="permit_id" value="${permitId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function printCertificate(permitId) {
    window.open('print-certificate.php?id=' + permitId, '_blank');
}

function exportRegistry() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = 'export-registry.php?' + params.toString();
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

<?php include_once '../../../includes/footer.php'; ?>