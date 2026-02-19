<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Force login and admin/staff access
requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'Manage Residents';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['resident_id'])) {
        $resident_id = (int)$_POST['resident_id'];
        
        switch ($_POST['action']) {
            case 'verify':
                $stmt = $conn->prepare("UPDATE tbl_residents SET is_verified = 1 WHERE resident_id = ?");
                $stmt->bind_param("i", $resident_id);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Resident verified successfully";
                } else {
                    $_SESSION['error_message'] = "Failed to verify resident";
                }
                $stmt->close();
                break;
                
            case 'unverify':
                $stmt = $conn->prepare("UPDATE tbl_residents SET is_verified = 0 WHERE resident_id = ?");
                $stmt->bind_param("i", $resident_id);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Resident unverified successfully";
                } else {
                    $_SESSION['error_message'] = "Failed to unverify resident";
                }
                $stmt->close();
                break;
                
            case 'delete':
                // Check if resident has any requests first
                $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_requests WHERE resident_id = ?");
                $check_stmt->bind_param("i", $resident_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check = $check_result->fetch_assoc();
                $check_stmt->close();
                
                if ($check['count'] > 0) {
                    $_SESSION['error_message'] = "Cannot delete resident with existing requests. Please delete their requests first.";
                } else {
                    // Also delete associated user account
                    $stmt = $conn->prepare("DELETE FROM tbl_users WHERE resident_id = ?");
                    $stmt->bind_param("i", $resident_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Delete resident
                    $stmt = $conn->prepare("DELETE FROM tbl_residents WHERE resident_id = ?");
                    $stmt->bind_param("i", $resident_id);
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Resident deleted successfully";
                    } else {
                        $_SESSION['error_message'] = "Failed to delete resident";
                    }
                    $stmt->close();
                }
                break;
        }
        
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit();
    }
}

// Fetch all residents
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Explicitly select resident_id first to ensure it's in results
$sql = "SELECT resident_id, first_name, middle_name, last_name, email, contact_number, address, is_verified, created_at FROM tbl_residents WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param, $search_param];
    $types = "ssss";
}

if ($status_filter === 'verified') {
    $sql .= " AND is_verified = 1";
} elseif ($status_filter === 'unverified') {
    $sql .= " AND is_verified = 0";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$residents = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get stats
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
    SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified
    FROM tbl_residents";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

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
    display: flex;
    align-items: center;
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
    border-radius: var(--border-radius);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
}

.stat-card.active-card {
    border: 2px solid #0d6efd !important;
    background: linear-gradient(to bottom, #ffffff, #f8f9ff);
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

.btn:active {
    transform: translateY(0);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

/* Alert Enhancements */
.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-success {
    background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
    border-left-color: #198754;
}

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

.alert i {
    font-size: 1.1rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

/* Modal Enhancements */
.modal-header {
    border-bottom: 1px solid #dee2e6;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
}

.modal-footer {
    border-top: 1px solid #dee2e6;
}

.modal-content {
    border-radius: var(--border-radius);
    border: none;
    box-shadow: var(--shadow-lg);
}

.resident-info {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    margin: 1rem 0;
}

.resident-info p {
    margin-bottom: 0.5rem;
}

.resident-info strong {
    color: #495057;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stat-card {
        margin-bottom: 1rem;
    }
    
    .table thead th {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
    
    .table tbody td {
        font-size: 0.875rem;
        padding: 0.75rem;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-users me-2 text-primary"></i>
                        Manage Residents
                    </h2>
                    <p class="text-muted mb-0">View and manage all registered residents</p>
                </div>
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add New Resident
                </a>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['error_message']); 
            unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Cards with Clickable Filters -->
    <div class="row mb-4">
        <div class="col-md-4">
            <a href="manage.php" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $status_filter === 'all' ? 'active-card' : ''; ?>">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-users fa-2x text-primary"></i>
                        </div>
                        <h3 class="mb-1"><?php echo $stats['total']; ?></h3>
                        <p class="text-muted small mb-0">Total Residents</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="?status=verified" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $status_filter === 'verified' ? 'active-card' : ''; ?>">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                        <h3 class="mb-1 text-success"><?php echo $stats['verified']; ?></h3>
                        <p class="text-muted small mb-0">Verified</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="?status=unverified" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $status_filter === 'unverified' ? 'active-card' : ''; ?>">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                        <h3 class="mb-1 text-warning"><?php echo $stats['unverified']; ?></h3>
                        <p class="text-muted small mb-0">Pending Verification</p>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" 
                           name="search" 
                           class="form-control"
                           placeholder="Search by name, contact, or email..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="verified" <?php echo $status_filter === 'verified' ? 'selected' : ''; ?>>Verified</option>
                        <option value="unverified" <?php echo $status_filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Residents Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                <?php 
                if ($status_filter === 'verified') {
                    echo 'Verified Residents';
                } elseif ($status_filter === 'unverified') {
                    echo 'Unverified Residents';
                } else {
                    echo 'All Residents';
                }
                ?>
                <span class="badge bg-primary ms-2"><?php echo count($residents); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($residents)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>No residents found</p>
                    <?php if (!empty($search) || $status_filter !== 'all'): ?>
                    <a href="manage.php" class="btn btn-outline-primary">
                        <i class="fas fa-times me-2"></i>Clear Filters
                    </a>
                    <?php else: ?>
                    <p class="text-muted mt-2">Start by adding your first resident</p>
                    <a href="add.php" class="btn btn-primary mt-2">
                        <i class="fas fa-plus me-2"></i>Add Resident
                    </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($residents as $resident): ?>
                                <tr>
                                    <td><strong class="text-primary">#<?php echo str_pad($resident['resident_id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($resident['email'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <small><?php 
                                            $address = $resident['address'] ?? 'N/A';
                                            echo htmlspecialchars(strlen($address) > 30 ? substr($address, 0, 30) . '...' : $address); 
                                        ?></small>
                                    </td>
                                    <td>
                                        <?php if ($resident['is_verified']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i>Verified
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-clock me-1"></i>Unverified
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo date('M d, Y', strtotime($resident['created_at'])); ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="view.php?id=<?php echo (int)$resident['resident_id']; ?>" 
                                               class="btn btn-info" 
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <a href="edit.php?id=<?php echo (int)$resident['resident_id']; ?>" 
                                               class="btn btn-primary" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($resident['is_verified']): ?>
                                                <button type="button" 
                                                        class="btn btn-warning" 
                                                        onclick="showUnverifyModal(<?php echo (int)$resident['resident_id']; ?>, '<?php echo htmlspecialchars(addslashes($resident['first_name'] . ' ' . $resident['last_name'])); ?>')" 
                                                        title="Unverify">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" 
                                                        class="btn btn-success" 
                                                        onclick="showVerifyModal(<?php echo (int)$resident['resident_id']; ?>, '<?php echo htmlspecialchars(addslashes($resident['first_name'] . ' ' . $resident['last_name'])); ?>')" 
                                                        title="Verify">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" 
                                                    class="btn btn-danger" 
                                                    onclick="showDeleteModal(<?php echo (int)$resident['resident_id']; ?>, '<?php echo htmlspecialchars(addslashes($resident['first_name'] . ' ' . $resident['last_name'])); ?>')" 
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
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

<!-- Verify Modal -->
<div class="modal fade" id="verifyModal" tabindex="-1" aria-labelledby="verifyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="verifyModalLabel">
                    <i class="fas fa-check-circle me-2"></i>Verify Resident
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-user-check fa-3x text-success mb-3"></i>
                </div>
                <div class="resident-info">
                    <p><strong>Resident:</strong> <span id="verifyResidentName"></span></p>
                    <p><strong>ID:</strong> <span id="verifyResidentId"></span></p>
                </div>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    <small>Verifying this resident will grant them full access to the system and allow them to submit requests.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="confirmVerify()">
                    <i class="fas fa-check me-1"></i>Verify Resident
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Unverify Modal -->
<div class="modal fade" id="unverifyModal" tabindex="-1" aria-labelledby="unverifyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="unverifyModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Unverify Resident
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-user-times fa-3x text-warning mb-3"></i>
                </div>
                <div class="resident-info">
                    <p><strong>Resident:</strong> <span id="unverifyResidentName"></span></p>
                    <p><strong>ID:</strong> <span id="unverifyResidentId"></span></p>
                </div>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <small>Unverifying this resident will restrict their access to the system. They will need to be verified again to submit requests.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-warning" onclick="confirmUnverify()">
                    <i class="fas fa-times-circle me-1"></i>Unverify Resident
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-trash-alt me-2"></i>Delete Resident
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                </div>
                <div class="resident-info">
                    <p><strong>Resident:</strong> <span id="deleteResidentName"></span></p>
                    <p><strong>ID:</strong> <span id="deleteResidentId"></span></p>
                </div>
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone!
                    <ul class="mt-2 mb-0">
                        <li>The resident's account will be permanently deleted</li>
                        <li>Their user account will also be deleted</li>
                        <li>All associated data will be removed</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash me-1"></i>Delete Permanently
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden forms for actions -->
<form id="actionForm" method="POST" style="display: none;">
    <input type="hidden" name="resident_id" id="actionResidentId">
    <input type="hidden" name="action" id="actionType">
</form>

<script>
let currentResidentId = null;

function showVerifyModal(id, name) {
    currentResidentId = id;
    document.getElementById('verifyResidentName').textContent = name;
    document.getElementById('verifyResidentId').textContent = '#' + String(id).padStart(4, '0');
    const modal = new bootstrap.Modal(document.getElementById('verifyModal'));
    modal.show();
}

function showUnverifyModal(id, name) {
    currentResidentId = id;
    document.getElementById('unverifyResidentName').textContent = name;
    document.getElementById('unverifyResidentId').textContent = '#' + String(id).padStart(4, '0');
    const modal = new bootstrap.Modal(document.getElementById('unverifyModal'));
    modal.show();
}

function showDeleteModal(id, name) {
    currentResidentId = id;
    document.getElementById('deleteResidentName').textContent = name;
    document.getElementById('deleteResidentId').textContent = '#' + String(id).padStart(4, '0');
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

function confirmVerify() {
    submitAction(currentResidentId, 'verify');
}

function confirmUnverify() {
    submitAction(currentResidentId, 'unverify');
}

function confirmDelete() {
    submitAction(currentResidentId, 'delete');
}

function submitAction(residentId, action) {
    document.getElementById('actionResidentId').value = residentId;
    document.getElementById('actionType').value = action;
    document.getElementById('actionForm').submit();
}

// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php 
$conn->close();
include '../../includes/footer.php'; 
?>