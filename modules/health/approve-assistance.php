<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Approve Medical Assistance';

// Handle filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'Pending';
$assistance_type = isset($_GET['type']) ? $_GET['type'] : '';

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR m.diagnosis LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($status) {
    $where_clauses[] = "m.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($assistance_type) {
    $where_clauses[] = "m.assistance_type = ?";
    $params[] = $assistance_type;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Get assistance requests
$sql = "SELECT 
            m.*,
            r.first_name,
            r.last_name,
            r.date_of_birth,
            r.gender,
            r.contact_number as resident_contact,
            u.username as reviewed_by_name
        FROM tbl_medical_assistance m
        JOIN tbl_residents r ON m.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON m.reviewed_by = u.user_id
        WHERE $where_sql
        ORDER BY m.created_at DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

// Get statistics
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE status = 'Pending'")->fetch_assoc()['total'],
    'approved' => $conn->query("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE status = 'Approved'")->fetch_assoc()['total'],
    'rejected' => $conn->query("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE status = 'Rejected'")->fetch_assoc()['total'],
    'total' => $conn->query("SELECT COUNT(*) as total FROM tbl_medical_assistance")->fetch_assoc()['total']
];

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-hand-holding-medical"></i> Medical Assistance Requests</h1>
        <p class="page-subtitle">Review and approve assistance requests</p>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #ed8936;">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #48bb78;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['approved']); ?></div>
            <div class="stat-label">Approved</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #f56565;">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['rejected']); ?></div>
            <div class="stat-label">Rejected</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #4299e1;">
            <i class="fas fa-list"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total Requests</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <div class="search-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search resident or diagnosis..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="status" class="filter-select">
            <option value="">All Status</option>
            <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="Approved" <?php echo $status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="Rejected" <?php echo $status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
            <option value="Released" <?php echo $status === 'Released' ? 'selected' : ''; ?>>Released</option>
        </select>
        
        <select name="type" class="filter-select">
            <option value="">All Types</option>
            <option value="Medicine" <?php echo $assistance_type === 'Medicine' ? 'selected' : ''; ?>>Medicine</option>
            <option value="Laboratory" <?php echo $assistance_type === 'Laboratory' ? 'selected' : ''; ?>>Laboratory</option>
            <option value="Hospitalization" <?php echo $assistance_type === 'Hospitalization' ? 'selected' : ''; ?>>Hospitalization</option>
            <option value="Financial" <?php echo $assistance_type === 'Financial' ? 'selected' : ''; ?>>Financial</option>
            <option value="Other" <?php echo $assistance_type === 'Other' ? 'selected' : ''; ?>>Other</option>
        </select>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
        
        <?php if ($search || $status || $assistance_type): ?>
            <a href="approve-assistance.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Assistance Requests -->
<div class="assistance-list">
    <?php if ($requests->num_rows > 0): ?>
        <?php while ($req = $requests->fetch_assoc()): 
            $age = $req['date_of_birth'] ? floor((time() - strtotime($req['date_of_birth'])) / 31556926) : 'N/A';
        ?>
            <div class="assistance-card status-<?php echo strtolower($req['status']); ?>">
                <div class="assistance-header">
                    <div class="patient-info">
                        <h3><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></h3>
                        <div class="patient-meta">
                            <span><i class="fas fa-calendar"></i> <?php echo $age; ?> years old</span>
                            <span><i class="fas fa-venus-mars"></i> <?php echo $req['gender']; ?></span>
                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($req['resident_contact']); ?></span>
                        </div>
                    </div>
                    <div class="status-badge">
                        <span class="badge badge-<?php 
                            echo $req['status'] === 'Approved' ? 'success' : 
                                ($req['status'] === 'Pending' ? 'warning' : 
                                ($req['status'] === 'Released' ? 'info' : 'danger')); 
                        ?>">
                            <?php echo $req['status']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="assistance-details">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Request Date</label>
                            <span><?php echo date('M d, Y', strtotime($req['request_date'])); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <label>Assistance Type</label>
                            <span class="badge badge-info"><?php echo htmlspecialchars($req['assistance_type']); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <label>Amount Requested</label>
                            <span class="amount">₱<?php echo number_format($req['amount_requested'], 2); ?></span>
                        </div>
                        
                        <?php if ($req['amount_approved']): ?>
                        <div class="detail-item">
                            <label>Amount Approved</label>
                            <span class="amount approved">₱<?php echo number_format($req['amount_approved'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="detail-section">
                        <label>Diagnosis/Reason:</label>
                        <p><?php echo nl2br(htmlspecialchars($req['diagnosis'])); ?></p>
                    </div>
                    
                    <?php if ($req['prescription']): ?>
                    <div class="detail-section">
                        <label>Prescription/Requirements:</label>
                        <p><?php echo nl2br(htmlspecialchars($req['prescription'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($req['hospital_clinic']): ?>
                    <div class="detail-section">
                        <label>Hospital/Clinic:</label>
                        <p><?php echo htmlspecialchars($req['hospital_clinic']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($req['remarks']): ?>
                    <div class="detail-section">
                        <label>Remarks:</label>
                        <p><?php echo nl2br(htmlspecialchars($req['remarks'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($req['status'] !== 'Pending' && $req['reviewed_by_name']): ?>
                    <div class="review-info">
                        <i class="fas fa-user-check"></i>
                        Reviewed by <?php echo htmlspecialchars($req['reviewed_by_name']); ?> 
                        on <?php echo $req['review_date'] ? date('M d, Y', strtotime($req['review_date'])) : 'N/A'; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($req['status'] === 'Pending'): ?>
                <div class="assistance-actions">
                    <button class="btn btn-success" onclick="approveRequest(<?php echo $req['assistance_id']; ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="btn btn-danger" onclick="rejectRequest(<?php echo $req['assistance_id']; ?>)">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button class="btn btn-info" onclick='viewRequest(<?php echo json_encode($req); ?>)'>
                        <i class="fas fa-eye"></i> View Full Details
                    </button>
                </div>
                <?php elseif ($req['status'] === 'Approved'): ?>
                <div class="assistance-actions">
                    <button class="btn btn-primary" onclick="markReleased(<?php echo $req['assistance_id']; ?>)">
                        <i class="fas fa-hand-holding"></i> Mark as Released
                    </button>
                </div>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-hand-holding-medical"></i>
            <h3>No Assistance Requests</h3>
            <p>No medical assistance requests found</p>
        </div>
    <?php endif; ?>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Approve Assistance Request</h2>
            <button class="close-btn" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <form action="actions/approve-assistance.php" method="POST" class="modal-body">
            <input type="hidden" name="assistance_id" id="approve_assistance_id">
            
            <div class="form-group">
                <label>Amount Approved *</label>
                <input type="number" name="amount_approved" class="form-control" step="0.01" required>
            </div>
            
            <div class="form-group">
                <label>Remarks</label>
                <textarea name="remarks" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Reject Assistance Request</h2>
            <button class="close-btn" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <form action="actions/reject-assistance.php" method="POST" class="modal-body">
            <input type="hidden" name="assistance_id" id="reject_assistance_id">
            
            <div class="form-group">
                <label>Reason for Rejection *</label>
                <textarea name="rejection_reason" class="form-control" rows="4" required></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.assistance-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.assistance-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-left: 4px solid #cbd5e0;
    transition: all 0.2s;
}

.assistance-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.assistance-card.status-pending {
    border-left-color: #ed8936;
}

.assistance-card.status-approved {
    border-left-color: #48bb78;
}

.assistance-card.status-rejected {
    border-left-color: #f56565;
}

.assistance-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e2e8f0;
}

.patient-info h3 {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
    font-size: 1.25rem;
}

.patient-meta {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    font-size: 0.875rem;
    color: #718096;
}

.patient-meta span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.assistance-details {
    margin-bottom: 1rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-item label {
    font-size: 0.75rem;
    color: #718096;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.detail-item span {
    color: #2d3748;
    font-size: 0.95rem;
}

.amount {
    font-size: 1.25rem;
    font-weight: 700;
    color: #ed8936;
}

.amount.approved {
    color: #48bb78;
}

.detail-section {
    margin-bottom: 1rem;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 8px;
}

.detail-section label {
    font-size: 0.875rem;
    color: #718096;
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}

.detail-section p {
    margin: 0;
    color: #2d3748;
    line-height: 1.6;
}

.review-info {
    background: #e6fffa;
    color: #234e52;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1rem;
}

.assistance-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}
</style>

<script>
function approveRequest(id) {
    document.getElementById('approve_assistance_id').value = id;
    openModal('approveModal');
}

function rejectRequest(id) {
    document.getElementById('reject_assistance_id').value = id;
    openModal('rejectModal');
}

function markReleased(id) {
    if (confirm('Mark this assistance as released?')) {
        window.location.href = 'actions/release-assistance.php?id=' + id;
    }
}

function viewRequest(request) {
    alert('View full details for: ' + request.first_name + ' ' + request.last_name);
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
</script>

<?php include '../../includes/footer.php'; ?>