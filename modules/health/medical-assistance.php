<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Medical Assistance Requests';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = getCurrentUserId();
    
    if ($_POST['action'] === 'approve') {
        $assistance_id = (int)$_POST['assistance_id'];
        $approved_amount = (float)$_POST['approved_amount'];
        $remarks = trim($_POST['remarks']);
        
        $stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status = 'Approved', approved_amount = ?, approved_date = CURDATE(), approved_by = ?, remarks = ? WHERE assistance_id = ?");
        $stmt->bind_param("disi", $approved_amount, $user_id, $remarks, $assistance_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Medical assistance approved successfully!";
        }
        $stmt->close();
    }
    elseif ($_POST['action'] === 'reject') {
        $assistance_id = (int)$_POST['assistance_id'];
        $remarks = trim($_POST['remarks']);
        
        $stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status = 'Rejected', approved_by = ?, remarks = ? WHERE assistance_id = ?");
        $stmt->bind_param("isi", $user_id, $remarks, $assistance_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Request rejected.";
        }
        $stmt->close();
    }
    elseif ($_POST['action'] === 'release') {
        $assistance_id = (int)$_POST['assistance_id'];
        $remarks = trim($_POST['remarks']);
        
        $stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status = 'Released', released_date = CURDATE(), processed_by = ?, remarks = CONCAT(IFNULL(remarks, ''), '\n[Released] ', ?) WHERE assistance_id = ?");
        $stmt->bind_param("isi", $user_id, $remarks, $assistance_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Assistance released successfully!";
        }
        $stmt->close();
    }
    
    header("Location: medical-assistance.php");
    exit;
}

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'Pending';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($status_filter) {
    $where_clauses[] = "m.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($priority_filter) {
    $where_clauses[] = "m.priority = ?";
    $params[] = $priority_filter;
    $types .= "s";
}

if ($type_filter) {
    $where_clauses[] = "m.request_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Get medical assistance requests
$sql = "SELECT 
            m.*,
            r.first_name,
            r.last_name,
            r.contact_number,
            r.date_of_birth,
            approver.username as approved_by_name,
            processor.username as processed_by_name
        FROM tbl_medical_assistance m
        JOIN tbl_residents r ON m.resident_id = r.resident_id
        LEFT JOIN tbl_users approver ON m.approved_by = approver.user_id
        LEFT JOIN tbl_users processor ON m.processed_by = processor.user_id
        WHERE $where_sql
        ORDER BY 
            CASE m.priority 
                WHEN 'Emergency' THEN 1 
                WHEN 'Urgent' THEN 2 
                ELSE 3 
            END,
            m.request_date DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

// Get statistics
$stats = [];
$stats['pending'] = $conn->query("SELECT COUNT(*) as count FROM tbl_medical_assistance WHERE status = 'Pending'")->fetch_assoc()['count'];
$stats['approved'] = $conn->query("SELECT COUNT(*) as count FROM tbl_medical_assistance WHERE status = 'Approved'")->fetch_assoc()['count'];
$stats['released'] = $conn->query("SELECT COUNT(*) as count FROM tbl_medical_assistance WHERE status = 'Released'")->fetch_assoc()['count'];
$stats['total_amount'] = $conn->query("SELECT IFNULL(SUM(approved_amount), 0) as total FROM tbl_medical_assistance WHERE status IN ('Approved', 'Released')")->fetch_assoc()['total'];

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-hand-holding-medical"></i> Medical Assistance Requests</h1>
        <p class="page-subtitle">Manage financial medical assistance applications</p>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #ed8936;">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #48bb78;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['approved']; ?></div>
            <div class="stat-label">Approved</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #4299e1;">
            <i class="fas fa-hand-holding-heart"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['released']; ?></div>
            <div class="stat-label">Released</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #9f7aea;">
            <i class="fas fa-peso-sign"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value">₱<?php echo number_format($stats['total_amount'], 2); ?></div>
            <div class="stat-label">Total Assistance</div>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="filter-tabs">
    <a href="?status=Pending" class="tab-link <?php echo $status_filter === 'Pending' ? 'active' : ''; ?>">
        Pending (<?php echo $stats['pending']; ?>)
    </a>
    <a href="?status=Approved" class="tab-link <?php echo $status_filter === 'Approved' ? 'active' : ''; ?>">
        Approved (<?php echo $stats['approved']; ?>)
    </a>
    <a href="?status=Released" class="tab-link <?php echo $status_filter === 'Released' ? 'active' : ''; ?>">
        Released (<?php echo $stats['released']; ?>)
    </a>
    <a href="?status=Rejected" class="tab-link <?php echo $status_filter === 'Rejected' ? 'active' : ''; ?>">
        Rejected
    </a>
</div>

<!-- Additional Filters -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
        
        <select name="priority" class="filter-select">
            <option value="">All Priorities</option>
            <option value="Emergency" <?php echo $priority_filter === 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
            <option value="Urgent" <?php echo $priority_filter === 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
            <option value="Normal" <?php echo $priority_filter === 'Normal' ? 'selected' : ''; ?>>Normal</option>
        </select>
        
        <select name="type" class="filter-select">
            <option value="">All Types</option>
            <option value="Medicine" <?php echo $type_filter === 'Medicine' ? 'selected' : ''; ?>>Medicine</option>
            <option value="Laboratory" <?php echo $type_filter === 'Laboratory' ? 'selected' : ''; ?>>Laboratory</option>
            <option value="Hospitalization" <?php echo $type_filter === 'Hospitalization' ? 'selected' : ''; ?>>Hospitalization</option>
            <option value="Surgery" <?php echo $type_filter === 'Surgery' ? 'selected' : ''; ?>>Surgery</option>
            <option value="Other" <?php echo $type_filter === 'Other' ? 'selected' : ''; ?>>Other</option>
        </select>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
    </form>
</div>

<!-- Requests List -->
<div class="requests-container">
    <?php if ($requests->num_rows > 0): ?>
        <?php while ($req = $requests->fetch_assoc()): 
            $age = $req['date_of_birth'] ? floor((time() - strtotime($req['date_of_birth'])) / 31556926) : 'N/A';
            $priority_class = strtolower($req['priority']);
        ?>
            <div class="request-card priority-<?php echo $priority_class; ?>">
                <div class="request-header">
                    <div class="request-patient">
                        <h3><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></h3>
                        <p><?php echo $age; ?> years old • <?php echo htmlspecialchars($req['contact_number']); ?></p>
                    </div>
                    <div class="request-badges">
                        <span class="badge badge-<?php echo $priority_class === 'emergency' ? 'danger' : ($priority_class === 'urgent' ? 'warning' : 'info'); ?>">
                            <?php echo $req['priority']; ?>
                        </span>
                        <span class="badge badge-secondary">
                            <?php echo $req['request_type']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="request-body">
                    <div class="request-info">
                        <div class="info-item">
                            <label>Request Date:</label>
                            <span><?php echo date('F j, Y', strtotime($req['request_date'])); ?></span>
                        </div>
                        
                        <?php if ($req['diagnosis']): ?>
                        <div class="info-item">
                            <label>Diagnosis:</label>
                            <span><?php echo htmlspecialchars($req['diagnosis']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <label>Requested Assistance:</label>
                            <span><?php echo nl2br(htmlspecialchars($req['requested_assistance'])); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <label>Estimated Amount:</label>
                            <span class="amount">₱<?php echo number_format($req['estimated_amount'], 2); ?></span>
                        </div>
                        
                        <?php if ($req['approved_amount']): ?>
                        <div class="info-item">
                            <label>Approved Amount:</label>
                            <span class="amount approved">₱<?php echo number_format($req['approved_amount'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($req['remarks']): ?>
                        <div class="info-item full-width">
                            <label>Remarks:</label>
                            <span><?php echo nl2br(htmlspecialchars($req['remarks'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="request-footer">
                    <div class="request-status">
                        Status: <strong><?php echo $req['status']; ?></strong>
                        <?php if ($req['approved_by_name']): ?>
                            • Approved by <?php echo htmlspecialchars($req['approved_by_name']); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="request-actions">
                        <?php if ($req['status'] === 'Pending'): ?>
                            <button class="btn btn-sm btn-success" onclick='approveRequest(<?php echo json_encode($req); ?>)'>
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-sm btn-danger" onclick='rejectRequest(<?php echo $req['assistance_id']; ?>)'>
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php elseif ($req['status'] === 'Approved'): ?>
                            <button class="btn btn-sm btn-primary" onclick='releaseAssistance(<?php echo $req['assistance_id']; ?>)'>
                                <i class="fas fa-hand-holding-heart"></i> Release
                            </button>
                        <?php endif; ?>
                        
                        <button class="btn btn-sm btn-info" onclick='viewDetails(<?php echo json_encode($req); ?>)'>
                            <i class="fas fa-eye"></i> Details
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox"></i>
            <p>No <?php echo strtolower($status_filter); ?> requests found</p>
        </div>
    <?php endif; ?>
</div>

<!-- Approve Request Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Approve Medical Assistance</h2>
            <button class="close-btn" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="assistance_id" id="approve_assistance_id">
            
            <div class="form-group">
                <label>Approved Amount (₱) *</label>
                <input type="number" name="approved_amount" id="approve_amount" class="form-control" step="0.01" required>
                <small class="text-muted">Requested: ₱<span id="requested_amount"></span></small>
            </div>
            
            <div class="form-group">
                <label>Remarks</label>
                <textarea name="remarks" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Approve Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Request Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Reject Request</h2>
            <button class="close-btn" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="assistance_id" id="reject_assistance_id">
            
            <div class="form-group">
                <label>Reason for Rejection *</label>
                <textarea name="remarks" class="form-control" rows="3" required></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-times"></i> Reject Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Release Assistance Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Release Assistance</h2>
            <button class="close-btn" onclick="closeModal('releaseModal')">&times;</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="release">
            <input type="hidden" name="assistance_id" id="release_assistance_id">
            
            <div class="form-group">
                <label>Release Notes</label>
                <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes about the release..."></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('releaseModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-hand-holding-heart"></i> Confirm Release
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function approveRequest(req) {
    document.getElementById('approve_assistance_id').value = req.assistance_id;
    document.getElementById('approve_amount').value = req.estimated_amount;
    document.getElementById('requested_amount').textContent = parseFloat(req.estimated_amount).toFixed(2);
    openModal('approveModal');
}

function rejectRequest(assistanceId) {
    document.getElementById('reject_assistance_id').value = assistanceId;
    openModal('rejectModal');
}

function releaseAssistance(assistanceId) {
    document.getElementById('release_assistance_id').value = assistanceId;
    openModal('releaseModal');
}

function viewDetails(req) {
    alert('View full details - to be implemented');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
function viewDetails(req) {
    const statusClass = req.status === 'Approved' ? 'badge-success' : 
                       (req.status === 'Pending' ? 'badge-warning' : 
                       (req.status === 'Released' ? 'badge-info' : 'badge-secondary'));
    
    const priorityClass = req.priority === 'Emergency' ? 'badge-danger' : 
                         (req.priority === 'Urgent' ? 'badge-warning' : 'badge-secondary');
    
    const age = req.date_of_birth ? Math.floor((new Date() - new Date(req.date_of_birth)) / 31556926000) : 'N/A';
    
    const modalHTML = `
        <div class="info-group">
            <h4>Patient Information</h4>
            <div class="info-row">
                <label>Name:</label>
                <span><strong>${req.first_name} ${req.last_name}</strong></span>
            </div>
            <div class="info-row">
                <label>Age:</label>
                <span>${age} years old</span>
            </div>
            <div class="info-row">
                <label>Contact Number:</label>
                <span>${req.contact_number || 'N/A'}</span>
            </div>
        </div>
        
        <div class="info-group">
            <h4>Request Details</h4>
            <div class="info-row">
                <label>Request Type:</label>
                <span><span class="badge badge-info">${req.request_type}</span></span>
            </div>
            <div class="info-row">
                <label>Priority:</label>
                <span><span class="badge ${priorityClass}">${req.priority}</span></span>
            </div>
            <div class="info-row">
                <label>Status:</label>
                <span><span class="badge ${statusClass}">${req.status}</span></span>
            </div>
            <div class="info-row">
                <label>Request Date:</label>
                <span>${new Date(req.request_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>
        </div>
        
        <div class="info-group">
            <h4>Medical Information</h4>
            ${req.diagnosis ? `
            <div class="info-row">
                <label>Diagnosis/Condition:</label>
                <span>${req.diagnosis}</span>
            </div>` : ''}
            <div class="info-row">
                <label>Requested Assistance:</label>
                <span>${req.requested_assistance}</span>
            </div>
            ${req.hospital_name ? `
            <div class="info-row">
                <label>Hospital/Clinic:</label>
                <span>${req.hospital_name}</span>
            </div>` : ''}
            ${req.additional_notes ? `
            <div class="info-row">
                <label>Additional Notes:</label>
                <span>${req.additional_notes}</span>
            </div>` : ''}
        </div>
        
        <div class="info-group">
            <h4>Financial Information</h4>
            <div class="info-row">
                <label>Estimated Amount:</label>
                <span>₱${parseFloat(req.estimated_amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
            </div>
            ${req.approved_amount ? `
            <div class="info-row">
                <label>Approved Amount:</label>
                <span><strong>₱${parseFloat(req.approved_amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
            </div>` : ''}
            ${req.approved_date ? `
            <div class="info-row">
                <label>Approved Date:</label>
                <span>${new Date(req.approved_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>` : ''}
            ${req.released_date ? `
            <div class="info-row">
                <label>Released Date:</label>
                <span>${new Date(req.released_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>` : ''}
        </div>
        
        ${req.approved_by_name || req.processed_by_name ? `
        <div class="info-group">
            <h4>Processing Information</h4>
            ${req.approved_by_name ? `
            <div class="info-row">
                <label>Approved By:</label>
                <span>${req.approved_by_name}</span>
            </div>` : ''}
            ${req.processed_by_name ? `
            <div class="info-row">
                <label>Processed By:</label>
                <span>${req.processed_by_name}</span>
            </div>` : ''}
        </div>` : ''}
        
        ${req.remarks ? `
        <div class="info-group">
            <h4>Remarks</h4>
            <div class="info-row">
                <span>${req.remarks.replace(/\n/g, '<br>')}</span>
            </div>
        </div>` : ''}
    `;
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'viewDetailsModal';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h2 class="modal-title">Medical Assistance Details</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                ${modalHTML}
            </div>
            <div class="modal-footer">
                ${req.status === 'Pending' ? `
                    <button class="btn btn-success" onclick="this.closest('.modal').remove(); approveRequest(${JSON.stringify(req).replace(/"/g, '&quot;')})">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="btn btn-danger" onclick="this.closest('.modal').remove(); rejectRequest(${req.assistance_id})">
                        <i class="fas fa-times"></i> Reject
                    </button>
                ` : ''}
                ${req.status === 'Approved' ? `
                    <button class="btn btn-primary" onclick="this.closest('.modal').remove(); releaseAssistance(${req.assistance_id})">
                        <i class="fas fa-hand-holding-heart"></i> Release
                    </button>
                ` : ''}
                <button class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    modal.onclick = function(event) {
        if (event.target === modal) {
            modal.remove();
        }
    };
}

</script>

<style>
.filter-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid #e2e8f0;
}

.tab-link {
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: #718096;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.tab-link:hover {
    color: #2d3748;
}

.tab-link.active {
    color: #4299e1;
    border-bottom-color: #4299e1;
}

.requests-container {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.request-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
    border-left: 4px solid #e2e8f0;
}

.request-card.priority-emergency {
    border-left-color: #f56565;
}

.request-card.priority-urgent {
    border-left-color: #ed8936;
}

.request-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    padding: 1.5rem;
    background: #f7fafc;
    border-bottom: 1px solid #e2e8f0;
}

.request-patient h3 {
    margin: 0 0 0.25rem 0;
    color: #2d3748;
    font-size: 1.1rem;
}

.request-patient p {
    margin: 0;
    color: #718096;
    font-size: 0.875rem;
}

.request-badges {
    display: flex;
    gap: 0.5rem;
}

.request-body {
    padding: 1.5rem;
}

.request-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.info-item.full-width {
    grid-column: 1 / -1;
}

.info-item label {
    font-size: 0.875rem;
    color: #718096;
    font-weight: 500;
}

.info-item span {
    color: #2d3748;
}

.amount {
    font-weight: 600;
    font-size: 1.1rem;
    color: #2d3748;
}

.amount.approved {
    color: #48bb78;
}

.request-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: #f7fafc;
    border-top: 1px solid #e2e8f0;
}

.request-status {
    color: #718096;
    font-size: 0.875rem;
}

.request-actions {
    display: flex;
    gap: 0.5rem;
}
</style>

<?php include '../../includes/footer.php'; ?>