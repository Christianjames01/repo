<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Resident']);

$page_title = 'Request Medical Assistance';
$user_id = $_SESSION['user_id'];

// Get resident ID
$stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$resident_id = $result['resident_id'];
$stmt->close();

// Get my assistance requests
$stmt = $conn->prepare("
    SELECT * FROM tbl_medical_assistance 
    WHERE resident_id = ? 
    ORDER BY request_date DESC
    LIMIT 20
");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$my_requests = $stmt->get_result();
$stmt->close();

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-hand-holding-medical"></i> Medical Assistance Request</h1>
        <p class="page-subtitle">Apply for financial medical assistance</p>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<!-- Request Form -->
<div class="form-container">
    <form action="actions/submit-assistance-request.php" method="POST" enctype="multipart/form-data" id="assistanceForm">
        <div class="form-grid">
            <div class="form-group">
                <label>Request Type *</label>
                <select name="request_type" class="form-control" required>
                    <option value="">Select Type</option>
                    <option value="Medicine">Medicine</option>
                    <option value="Laboratory">Laboratory</option>
                    <option value="Hospitalization">Hospitalization</option>
                    <option value="Surgery">Surgery</option>
                    <option value="Dialysis">Dialysis</option>
                    <option value="Chemotherapy">Chemotherapy</option>
                    <option value="Other">Other Medical Treatment</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Priority Level *</label>
                <select name="priority" class="form-control" required>
                    <option value="Normal">Normal</option>
                    <option value="Urgent">Urgent</option>
                    <option value="Emergency">Emergency</option>
                </select>
            </div>
            
            <div class="form-group full-width">
                <label>Medical Diagnosis/Condition *</label>
                <textarea name="diagnosis" class="form-control" rows="3" required 
                    placeholder="Describe your medical condition or diagnosis"></textarea>
            </div>
            
            <div class="form-group full-width">
                <label>Requested Assistance *</label>
                <textarea name="requested_assistance" class="form-control" rows="4" required 
                    placeholder="Describe what medical assistance you need (medications, procedures, etc.)"></textarea>
            </div>
            
            <div class="form-group">
                <label>Estimated Amount (₱) *</label>
                <input type="number" name="estimated_amount" class="form-control" step="0.01" min="0" required 
                    placeholder="0.00">
            </div>
            
            <div class="form-group">
                <label>Hospital/Clinic Name</label>
                <input type="text" name="hospital_name" class="form-control" 
                    placeholder="Where you're being treated">
            </div>
            
            <div class="form-group full-width">
                <label>Supporting Documents (Medical Certificate, Prescription, Bills, etc.)</label>
                <input type="file" name="documents[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png">
                <small class="text-muted">You can upload multiple files (PDF, JPG, PNG). Max 5MB each.</small>
            </div>
            
            <div class="form-group full-width">
                <label>Additional Notes</label>
                <textarea name="additional_notes" class="form-control" rows="3" 
                    placeholder="Any other information that might help process your request"></textarea>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
            <button type="reset" class="btn btn-secondary">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    </form>
</div>

<!-- My Requests -->
<div class="dashboard-section" style="margin-top: 3rem;">
    <div class="section-header">
        <h2><i class="fas fa-history"></i> My Assistance Requests</h2>
    </div>
    
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Request Date</th>
                    <th>Type</th>
                    <th>Priority</th>
                    <th>Estimated Amount</th>
                    <th>Approved Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($my_requests->num_rows > 0): ?>
                    <?php while ($req = $my_requests->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($req['request_date'])); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($req['request_type']); ?></span></td>
                            <td>
                                <span class="badge badge-<?php 
                                    echo $req['priority'] === 'Emergency' ? 'danger' : 
                                        ($req['priority'] === 'Urgent' ? 'warning' : 'secondary'); 
                                ?>">
                                    <?php echo $req['priority']; ?>
                                </span>
                            </td>
                            <td>₱<?php echo number_format($req['estimated_amount'], 2); ?></td>
                            <td>
                                <?php if ($req['approved_amount']): ?>
                                    <strong class="text-success">₱<?php echo number_format($req['approved_amount'], 2); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php 
                                    echo $req['status'] === 'Approved' ? 'success' : 
                                        ($req['status'] === 'Pending' ? 'warning' : 
                                        ($req['status'] === 'Released' ? 'info' : 'secondary')); 
                                ?>">
                                    <?php echo $req['status']; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-icon btn-info" onclick='viewRequest(<?php echo json_encode($req); ?>)' title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center">
                            <div class="no-data">
                                <i class="fas fa-inbox"></i>
                                <p>No requests yet</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Request Modal -->
<div id="viewRequestModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h2 class="modal-title">Request Details</h2>
            <button class="close-btn" onclick="closeModal('viewRequestModal')">&times;</button>
        </div>
        <div class="modal-body" id="requestDetails"></div>
    </div>
</div>

<style>
.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-success {
    background: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}

.alert-error {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid #e2e8f0;
}

.request-detail-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #e2e8f0;
}

.request-detail-row:last-child {
    border-bottom: none;
}

.request-detail-row label {
    font-weight: 600;
    color: #718096;
    width: 200px;
    flex-shrink: 0;
}

.request-detail-row span {
    color: #2d3748;
    flex: 1;
}
</style>

<script>
function viewRequest(req) {
    const details = document.getElementById('requestDetails');
    
    const statusClass = req.status === 'Approved' ? 'badge-success' : 
                       (req.status === 'Pending' ? 'badge-warning' : 
                       (req.status === 'Released' ? 'badge-info' : 'badge-secondary'));
    
    const priorityClass = req.priority === 'Emergency' ? 'badge-danger' : 
                         (req.priority === 'Urgent' ? 'badge-warning' : 'badge-secondary');
    
    details.innerHTML = `
        <div class="request-detail-row">
            <label>Request Date:</label>
            <span>${new Date(req.request_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
        </div>
        <div class="request-detail-row">
            <label>Request Type:</label>
            <span><span class="badge badge-info">${req.request_type}</span></span>
        </div>
        <div class="request-detail-row">
            <label>Priority:</label>
            <span><span class="badge ${priorityClass}">${req.priority}</span></span>
        </div>
        <div class="request-detail-row">
            <label>Status:</label>
            <span><span class="badge ${statusClass}">${req.status}</span></span>
        </div>
        <div class="request-detail-row">
            <label>Diagnosis:</label>
            <span>${req.diagnosis || 'N/A'}</span>
        </div>
        <div class="request-detail-row">
            <label>Requested Assistance:</label>
            <span style="white-space: pre-wrap;">${req.requested_assistance}</span>
        </div>
        <div class="request-detail-row">
            <label>Estimated Amount:</label>
            <span class="amount">₱${parseFloat(req.estimated_amount).toFixed(2)}</span>
        </div>
        ${req.approved_amount ? `
        <div class="request-detail-row">
            <label>Approved Amount:</label>
            <span class="amount approved">₱${parseFloat(req.approved_amount).toFixed(2)}</span>
        </div>` : ''}
        ${req.hospital_name ? `
        <div class="request-detail-row">
            <label>Hospital/Clinic:</label>
            <span>${req.hospital_name}</span>
        </div>` : ''}
        ${req.approved_date ? `
        <div class="request-detail-row">
            <label>Approved Date:</label>
            <span>${new Date(req.approved_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
        </div>` : ''}
        ${req.released_date ? `
        <div class="request-detail-row">
            <label>Released Date:</label>
            <span>${new Date(req.released_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
        </div>` : ''}
        ${req.remarks ? `
        <div class="request-detail-row">
            <label>Remarks:</label>
            <span style="white-space: pre-wrap;">${req.remarks}</span>
        </div>` : ''}
    `;
    
    document.getElementById('viewRequestModal').classList.add('show');
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