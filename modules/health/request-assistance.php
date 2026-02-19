<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Check verification status FIRST
$stmt = $conn->prepare("
    SELECT r.is_verified, r.id_photo, r.first_name, r.last_name, r.resident_id
    FROM tbl_users u
    JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

// If not verified, redirect to health verification page
if (!$user_data || $user_data['is_verified'] != 1) {
    header("Location: health-verification.php");
    exit();
}

// Continue with normal page - user is verified
$page_title = 'Request Medical Assistance';
$resident_id = $user_data['resident_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_type = trim($_POST['request_type']);
    $diagnosis = trim($_POST['diagnosis']);
    $prescription = trim($_POST['prescription']);
    $requested_assistance = trim($_POST['requested_assistance']);
    $estimated_amount = (float)$_POST['estimated_amount'];
    $priority = $_POST['priority'];
    
    // Handle file upload
    $supporting_documents = null;
    if (isset($_FILES['documents']) && $_FILES['documents']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../uploads/medical_assistance/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = pathinfo($_FILES['documents']['name'], PATHINFO_EXTENSION);
        $new_filename = 'med_assist_' . $resident_id . '_' . time() . '.' . $file_ext;
        $upload_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($_FILES['documents']['tmp_name'], $upload_path)) {
            $supporting_documents = 'uploads/medical_assistance/' . $new_filename;
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO tbl_medical_assistance 
        (resident_id, request_type, diagnosis, prescription, requested_assistance, 
         estimated_amount, priority, request_date, supporting_documents)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)");
    $stmt->bind_param("issssdss", $resident_id, $request_type, $diagnosis, $prescription, 
        $requested_assistance, $estimated_amount, $priority, $supporting_documents);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Medical assistance request submitted successfully! Your request will be reviewed by the barangay health office.";
        header("Location: request-assistance.php");
        exit;
    } else {
        $_SESSION['error_message'] = "Error submitting request. Please try again.";
    }
    $stmt->close();
}

// Get user's previous requests
$stmt = $conn->prepare("SELECT * FROM tbl_medical_assistance WHERE resident_id = ? ORDER BY request_date DESC LIMIT 10");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

// Get request statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
        IFNULL(SUM(approved_amount), 0) as total_received
    FROM tbl_medical_assistance 
    WHERE resident_id = ?
");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles - EXACT MATCH from view-incidents.php */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements - EXACT match */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
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

/* Alert Enhancements - EXACT match */
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
    color: #0f5132;
}

.alert-danger, .alert-error {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
    color: #842029;
}

.alert i {
    font-size: 1.1rem;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
    background: white;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
    line-height: 1.2;
}

.stat-label {
    font-size: 0.875rem;
    color: #718096;
    font-weight: 500;
    margin-top: 0.25rem;
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

.btn-icon {
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

/* Form Enhancements */
.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.625rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
    outline: none;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    grid-column: 1 / -1;
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
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
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
    margin-bottom: 0;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-lg);
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.modal-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #2d3748;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #718096;
    transition: all var(--transition-speed) ease;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.close-btn:hover {
    background: #e9ecef;
    color: #2d3748;
}

.modal-body {
    padding: 1.75rem;
    overflow-y: auto;
}

/* Info Cards */
.info-card {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.info-card label {
    font-size: 0.75rem;
    color: #718096;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    display: block;
}

.info-card strong, .info-card p {
    color: #2d3748;
    margin: 0;
}

.info-card.full-width {
    grid-column: 1 / -1;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

/* Timeline */
.request-timeline {
    position: relative;
    padding-left: 2rem;
    margin-top: 1.5rem;
}

.request-timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e2e8f0;
}

.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-dot {
    position: absolute;
    left: -2rem;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: white;
    border: 3px solid #cbd5e0;
    z-index: 1;
}

.timeline-dot.active {
    border-color: #4299e1;
    background: #4299e1;
}

.timeline-dot.success {
    border-color: #48bb78;
    background: #48bb78;
}

.timeline-content {
    background: #f7fafc;
    padding: 0.75rem 1rem;
    border-radius: 6px;
}

.timeline-date {
    font-size: 0.75rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.timeline-text {
    font-size: 0.875rem;
    color: #2d3748;
    font-weight: 500;
}

/* Responsive */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .form-grid, .info-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        flex-direction: column;
        text-align: center;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header - EXACT MATCH from view-incidents.php -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0 fw-bold">
                        <i class="fas fa-hand-holding-medical me-2"></i>
                        Request Medical Assistance
                    </h2>
                    <p class="text-muted mb-0 mt-1">Apply for financial medical assistance from the barangay</p>
                </div>
            </div>
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
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #4299e1;">
                    <i class="fas fa-file-medical"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #ed8936;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #48bb78;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['released']; ?></div>
                    <div class="stat-label">Released</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #9f7aea;">
                    <i class="fas fa-peso-sign"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">₱<?php echo number_format($stats['total_received'], 2); ?></div>
                    <div class="stat-label">Total Received</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Request Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5>
                <i class="fas fa-file-medical me-2"></i>New Assistance Request
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Type of Assistance *</label>
                        <select name="request_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="Medicine">Medicine</option>
                            <option value="Laboratory">Laboratory Tests</option>
                            <option value="Hospitalization">Hospitalization</option>
                            <option value="Surgery">Surgery</option>
                            <option value="Dialysis">Dialysis</option>
                            <option value="Chemotherapy">Chemotherapy</option>
                            <option value="Medical Supplies">Medical Supplies</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority Level *</label>
                        <select name="priority" class="form-control" required>
                            <option value="Normal">Normal</option>
                            <option value="Urgent">Urgent</option>
                            <option value="Emergency">Emergency</option>
                        </select>
                        <small class="text-muted">Select "Emergency" only for life-threatening situations</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estimated Amount (₱) *</label>
                        <input type="number" name="estimated_amount" class="form-control" step="0.01" min="0" required placeholder="0.00">
                        <small class="text-muted">Estimated cost of medical assistance needed</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Supporting Documents</label>
                        <input type="file" name="documents" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">Upload medical certificate, prescription, or hospital bill (optional)</small>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Diagnosis / Medical Condition *</label>
                        <textarea name="diagnosis" class="form-control" rows="3" required 
                            placeholder="Describe your medical condition or diagnosis..."></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Prescription / Doctor's Recommendation</label>
                        <textarea name="prescription" class="form-control" rows="2" 
                            placeholder="List prescribed medications or medical procedures (if any)"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Detailed Assistance Request *</label>
                        <textarea name="requested_assistance" class="form-control" rows="4" required 
                            placeholder="Please provide detailed information about the medical assistance you need. Include specific medications, procedures, or treatments..."></textarea>
                    </div>
                </div>
                
                <div class="alert alert-danger mt-3 mb-0">
                    <strong><i class="fas fa-info-circle me-2"></i>Important Notes:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Make sure all information provided is accurate and complete</li>
                        <li>Attach supporting documents (medical certificates, prescriptions, hospital bills)</li>
                        <li>Processing may take 3-5 business days</li>
                        <li>You will be notified once your request is reviewed</li>
                    </ul>
                </div>
                
                <div class="d-flex gap-2 mt-3 pt-3" style="border-top: 2px solid #e9ecef;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Request History -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-history me-2"></i>My Request History
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Request Date</th>
                            <th>Type</th>
                            <th>Amount Requested</th>
                            <th>Amount Approved</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($requests->num_rows > 0): ?>
                            <?php while ($req = $requests->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($req['request_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['request_type']); ?></strong>
                                        <?php if ($req['priority'] === 'Emergency'): ?>
                                            <span class="badge bg-danger ms-1">Emergency</span>
                                        <?php elseif ($req['priority'] === 'Urgent'): ?>
                                            <span class="badge bg-warning ms-1">Urgent</span>
                                        <?php endif; ?>
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
                                        <span class="badge bg-<?php 
                                            echo $req['status'] === 'Pending' ? 'warning' : 
                                                ($req['status'] === 'Approved' ? 'info' : 
                                                ($req['status'] === 'Released' ? 'success' : 'secondary')); 
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
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <p>No previous requests</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Request Modal -->
<div id="viewRequestModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Request Details</h2>
            <button class="close-btn" onclick="closeModal('viewRequestModal')">&times;</button>
        </div>
        <div class="modal-body" id="requestDetails">
            <!-- Content loaded by JavaScript -->
        </div>
    </div>
</div>

<script>
function viewRequest(req) {
    const modal = document.getElementById('viewRequestModal');
    const details = document.getElementById('requestDetails');
    
    let statusClass = req.status === 'Pending' ? 'bg-warning' : 
                     (req.status === 'Approved' ? 'bg-info' : 
                     (req.status === 'Released' ? 'bg-success' : 'bg-secondary'));
    
    let html = `
        <div style="border-bottom: 2px solid #e9ecef; padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; color: #2d3748;">${req.request_type}</h3>
                <span class="badge ${statusClass}">${req.status}</span>
            </div>
        </div>
        
        <div class="info-grid">
            <div class="info-card">
                <label>Request Date</label>
                <strong>${new Date(req.request_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</strong>
            </div>
            
            <div class="info-card">
                <label>Priority</label>
                <strong>${req.priority}</strong>
            </div>
            
            <div class="info-card">
                <label>Estimated Amount</label>
                <strong>₱${parseFloat(req.estimated_amount).toFixed(2)}</strong>
            </div>
            
            ${req.approved_amount ? `
            <div class="info-card">
                <label>Approved Amount</label>
                <strong class="text-success">₱${parseFloat(req.approved_amount).toFixed(2)}</strong>
            </div>
            ` : ''}
            
            <div class="info-card full-width">
                <label>Diagnosis</label>
                <p>${req.diagnosis || 'N/A'}</p>
            </div>
            
            ${req.prescription ? `
            <div class="info-card full-width">
                <label>Prescription</label>
                <p>${req.prescription}</p>
            </div>
            ` : ''}
            
            <div class="info-card full-width">
                <label>Requested Assistance</label>
                <p>${req.requested_assistance}</p>
            </div>
            
            ${req.remarks ? `
            <div class="info-card full-width">
                <label>Remarks/Notes</label>
                <p>${req.remarks}</p>
            </div>
            ` : ''}
        </div>
        
        ${req.status !== 'Pending' ? `
        <div class="request-timeline">
            <h4 style="margin-bottom: 1rem; color: #2d3748;">Request Timeline</h4>
            <div class="timeline-item">
                <div class="timeline-dot active"></div>
                <div class="timeline-content">
                    <div class="timeline-date">${new Date(req.request_date).toLocaleDateString()}</div>
                    <div class="timeline-text">Request submitted</div>
                </div>
            </div>
            
            ${req.approved_date ? `
            <div class="timeline-item">
                <div class="timeline-dot ${req.status === 'Approved' || req.status === 'Released' ? 'success' : ''}"></div>
                <div class="timeline-content">
                    <div class="timeline-date">${new Date(req.approved_date).toLocaleDateString()}</div>
                    <div class="timeline-text">Request approved</div>
                </div>
            </div>
            ` : ''}
            
            ${req.released_date ? `
            <div class="timeline-item">
                <div class="timeline-dot success"></div>
                <div class="timeline-content">
                    <div class="timeline-date">${new Date(req.released_date).toLocaleDateString()}</div>
                    <div class="timeline-text">Assistance released</div>
                </div>
            </div>
            ` : ''}
        </div>
        ` : ''}
    `;
    
    details.innerHTML = html;
    modal.classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    var alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>