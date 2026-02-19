<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Super Administrator', 'Barangay Secretary']);

$page_title = 'Process Document Requests';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $request_id = intval($_POST['request_id']);
    $new_status = sanitizeInput($_POST['status']);
    $remarks = sanitizeInput($_POST['remarks'] ?? '');
    
    $sql = "UPDATE tbl_document_requests SET status = ?, rejection_reason = ?, processed_by = ? WHERE request_id = ?";
    
    if (executeQuery($conn, $sql, [$new_status, $remarks, getCurrentUserId(), $request_id], 'ssii')) {
        logActivity($conn, getCurrentUserId(), 'Updated document request status', 'tbl_document_requests', $request_id, $new_status);
        setSuccessMessage('Request status updated successfully!');
    } else {
        setErrorMessage('Failed to update request status.');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Get statistics
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'Processing' THEN 1 ELSE 0 END) as processing,
              SUM(CASE WHEN status = 'For Pickup' THEN 1 ELSE 0 END) as for_pickup,
              SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
              SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
              FROM tbl_document_requests";
$stats = $conn->query($stats_sql)->fetch_assoc();

// Fetch requests
$sql = "SELECT dr.*, dt.document_name, dt.fee,
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.contact_no, r.email
        FROM tbl_document_requests dr
        LEFT JOIN tbl_document_types dt ON dr.doc_type_id = dt.doc_type_id
        LEFT JOIN tbl_residents r ON dr.resident_id = r.resident_id
        WHERE 1=1";

$params = [];
$types = "";

if ($status_filter) {
    $sql .= " AND dr.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($search) {
    $sql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR dt.document_name LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$sql .= " ORDER BY 
          CASE dr.status 
            WHEN 'Pending' THEN 1 
            WHEN 'Processing' THEN 2 
            WHEN 'For Pickup' THEN 3 
            ELSE 4 
          END,
          dr.request_date DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $requests = $stmt->get_result();
} else {
    $requests = $conn->query($sql);
}

include '../../includes/header.php';
?>

<style>
.stat-card {
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid transparent;
}

.stat-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 1rem 3rem rgba(0,0,0,0.175) !important;
}

.stat-card.active {
    border-color: var(--bs-primary);
    background: linear-gradient(135deg, rgba(13,110,253,0.05) 0%, rgba(13,110,253,0.02) 100%);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.search-box {
    position: relative;
}

.search-box .form-control {
    padding-left: 2.75rem;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

.search-box .form-control:focus {
    border-color: var(--bs-primary);
    box-shadow: 0 0 0 0.25rem rgba(13,110,253,0.1);
}

.search-box i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    z-index: 10;
}

.request-card {
    transition: all 0.3s ease;
    border: 2px solid #f8f9fa;
    border-radius: 12px;
    overflow: hidden;
}

.request-card:hover {
    border-color: var(--bs-primary);
    transform: translateY(-4px);
    box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1);
}

.request-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 1rem 1.25rem;
    border-bottom: 2px solid #dee2e6;
}

.request-body {
    padding: 1.25rem;
}

.info-item {
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #f1f3f5;
}

.info-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.info-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 0.25rem;
}

.info-value {
    font-size: 0.95rem;
    color: #212529;
    font-weight: 500;
}

.badge-custom {
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.75rem;
    letter-spacing: 0.3px;
}

.action-btn {
    border-radius: 8px;
    padding: 0.5rem 1rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.25rem 0.75rem rgba(0,0,0,0.15);
}

.empty-state {
    padding: 4rem 2rem;
    text-align: center;
}

.empty-state i {
    font-size: 5rem;
    color: #dee2e6;
    margin-bottom: 1.5rem;
}

.filter-card {
    border-radius: 12px;
    border: 2px solid #f1f3f5;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.request-card {
    animation: fadeInUp 0.5s ease-out;
}

.modal-content {
    border-radius: 15px;
    border: none;
}

.modal-header {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    border-radius: 15px 15px 0 0;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bold">
                <i class="fas fa-tasks me-2 text-primary"></i>Process Document Requests
            </h2>
            <p class="text-muted mb-0">Review and manage all document requests from residents</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="document-types.php" class="btn btn-outline-primary">
                <i class="fas fa-cog me-2"></i>Manage Document Types
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo !$status_filter ? 'active' : ''; ?>" 
                 onclick="filterByStatus('')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Total Requests</div>
                            <h2 class="mb-0 fw-bold"><?php echo $stats['total']; ?></h2>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo $status_filter === 'Pending' ? 'active' : ''; ?>" 
                 onclick="filterByStatus('Pending')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Pending</div>
                            <h2 class="mb-0 fw-bold text-warning"><?php echo $stats['pending']; ?></h2>
                        </div>
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo $status_filter === 'Processing' ? 'active' : ''; ?>" 
                 onclick="filterByStatus('Processing')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Processing</div>
                            <h2 class="mb-0 fw-bold text-info"><?php echo $stats['processing']; ?></h2>
                        </div>
                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-sync"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo $status_filter === 'For Pickup' ? 'active' : ''; ?>" 
                 onclick="filterByStatus('For Pickup')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">For Pickup</div>
                            <h2 class="mb-0 fw-bold text-primary"><?php echo $stats['for_pickup']; ?></h2>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-box"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo $status_filter === 'Released' ? 'active' : ''; ?>" 
                 onclick="filterByStatus('Released')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Released</div>
                            <h2 class="mb-0 fw-bold text-success"><?php echo $stats['released']; ?></h2>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-check-double"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo $status_filter === 'Rejected' ? 'active' : ''; ?>" 
                 onclick="filterByStatus('Rejected')">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Rejected</div>
                            <h2 class="mb-0 fw-bold text-danger"><?php echo $stats['rejected']; ?></h2>
                        </div>
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Filter -->
    <div class="card border-0 shadow-sm filter-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="" id="filterForm" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-search me-2 text-primary"></i>Search Requests
                    </label>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Search by resident name or document type..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-filter me-2 text-primary"></i>Filter by Status
                    </label>
                    <select name="status" class="form-select" id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Processing" <?php echo $status_filter === 'Processing' ? 'selected' : ''; ?>>Processing</option>
                        <option value="For Pickup" <?php echo $status_filter === 'For Pickup' ? 'selected' : ''; ?>>For Pickup</option>
                        <option value="Released" <?php echo $status_filter === 'Released' ? 'selected' : ''; ?>>Released</option>
                        <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="fas fa-search me-2"></i>Apply Filters
                        </button>
                        <?php if ($status_filter || $search): ?>
                            <a href="?" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Requests Grid -->
    <?php if ($requests && $requests->num_rows > 0): ?>
        <div class="row g-4">
            <?php while ($request = $requests->fetch_assoc()): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card request-card border-0 shadow-sm h-100">
                        <div class="request-header">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="text-muted small mb-1">Request ID</div>
                                    <h5 class="mb-0 fw-bold text-primary">
                                        #<?php echo str_pad($request['request_id'], 5, '0', STR_PAD_LEFT); ?>
                                    </h5>
                                </div>
                                <div>
                                    <?php
                                    $status_config = [
                                        'Pending' => ['class' => 'warning', 'icon' => 'clock'],
                                        'Processing' => ['class' => 'info', 'icon' => 'sync'],
                                        'For Pickup' => ['class' => 'primary', 'icon' => 'box'],
                                        'Released' => ['class' => 'success', 'icon' => 'check-double'],
                                        'Rejected' => ['class' => 'danger', 'icon' => 'times-circle']
                                    ];
                                    $config = $status_config[$request['status']] ?? ['class' => 'secondary', 'icon' => 'info-circle'];
                                    ?>
                                    <span class="badge badge-custom bg-<?php echo $config['class']; ?>">
                                        <i class="fas fa-<?php echo $config['icon']; ?> me-1"></i>
                                        <?php echo $request['status']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="request-body">
                            <div class="info-item">
                                <div class="info-label">Resident</div>
                                <div class="info-value">
                                    <i class="fas fa-user me-2 text-primary"></i>
                                    <?php echo htmlspecialchars($request['resident_name']); ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($request['email'] ?? 'N/A'); ?>
                                </div>
                                <?php if ($request['contact_no']): ?>
                                <div class="text-muted small">
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($request['contact_no']); ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Document Type</div>
                                <div class="info-value">
                                    <i class="fas fa-file-alt me-2 text-info"></i>
                                    <?php echo htmlspecialchars($request['document_name'] ?? 'N/A'); ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Purpose</div>
                                <div class="info-value text-muted" style="font-size: 0.875rem;">
                                    <?php 
                                    $purpose = $request['purpose'];
                                    echo htmlspecialchars(strlen($purpose) > 60 ? substr($purpose, 0, 60) . '...' : $purpose); 
                                    ?>
                                </div>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="info-label">Request Date</div>
                                    <div class="info-value small">
                                        <i class="far fa-calendar me-1 text-muted"></i>
                                        <?php echo date('M d, Y', strtotime($request['request_date'])); ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-label">Fee</div>
                                    <div class="info-value">
                                        <?php if ($request['amount'] > 0): ?>
                                            <strong class="text-success">
                                                ₱<?php echo number_format($request['amount'], 2); ?>
                                            </strong>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">Free</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($request['amount'] > 0): ?>
                            <div class="mt-2">
                                <div class="info-label">Payment Status</div>
                                <?php if ($request['payment_status'] == 'Paid'): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check-circle me-1"></i>Paid
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-exclamation-circle me-1"></i>Unpaid
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2 mt-4">
                                <button onclick="updateStatus(<?php echo $request['request_id']; ?>, '<?php echo $request['status']; ?>')" 
                                        class="btn btn-primary action-btn">
                                    <i class="fas fa-edit me-2"></i>Update Status
                                </button>
                                <button onclick="viewDetails(<?php echo $request['request_id']; ?>)" 
                                        class="btn btn-outline-secondary action-btn">
                                    <i class="fas fa-eye me-2"></i>View Full Details
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4 class="text-muted mb-3">
                        <?php if ($status_filter || $search): ?>
                            No requests found matching your filters
                        <?php else: ?>
                            No document requests yet
                        <?php endif; ?>
                    </h4>
                    <p class="text-muted">
                        <?php if ($status_filter || $search): ?>
                            Try adjusting your search criteria or clearing the filters
                        <?php else: ?>
                            Requests from residents will appear here
                        <?php endif; ?>
                    </p>
                    <?php if ($status_filter || $search): ?>
                        <a href="?" class="btn btn-primary mt-3">
                            <i class="fas fa-times me-2"></i>Clear All Filters
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Update Request Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="status_request_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            New Status <span class="text-danger">*</span>
                        </label>
                        <select name="status" id="status_select" class="form-select form-select-lg" required>
                            <option value="Pending">Pending</option>
                            <option value="Processing">Processing</option>
                            <option value="For Pickup">For Pickup</option>
                            <option value="Released">Released</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks / Notes</label>
                        <textarea name="remarks" class="form-control" rows="4" 
                                  placeholder="Add any remarks, notes, or reason for rejection..."></textarea>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Required when rejecting a request
                        </small>
                    </div>

                    <div class="alert alert-info mb-0">
                        <div class="fw-semibold mb-2">
                            <i class="fas fa-lightbulb me-2"></i>Status Workflow
                        </div>
                        <ul class="mb-0 small">
                            <li><strong>Pending</strong> → Under review</li>
                            <li><strong>Processing</strong> → Being prepared</li>
                            <li><strong>For Pickup</strong> → Ready for collection</li>
                            <li><strong>Released</strong> → Collected by resident</li>
                            <li><strong>Rejected</strong> → Request denied</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" name="update_status" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-alt me-2"></i>Request Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-3">Loading details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Filter by status
function filterByStatus(status) {
    const form = document.getElementById('filterForm');
    const statusSelect = document.getElementById('statusFilter');
    statusSelect.value = status;
    form.submit();
}

// Update status modal
function updateStatus(requestId, currentStatus) {
    document.getElementById('status_request_id').value = requestId;
    document.getElementById('status_select').value = currentStatus;
    
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

// View details
function viewDetails(requestId) {
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    const modalBody = document.getElementById('detailsModalBody');
    
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-3">Loading details...</p>
        </div>
    `;
    
    modal.show();
    
    // You can implement AJAX call here to load full details
    setTimeout(() => {
        modalBody.innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Full details view - implement AJAX call to load complete request information
            </div>
        `;
    }, 500);
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});