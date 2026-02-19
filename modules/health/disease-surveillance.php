<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Disease Surveillance';

// Handle filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$severity = isset($_GET['severity']) ? $_GET['severity'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(disease_name LIKE ? OR location LIKE ? OR reported_by LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($status) {
    $where_clauses[] = "status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($severity) {
    $where_clauses[] = "severity = ?";
    $params[] = $severity;
    $types .= "s";
}

if ($date_from) {
    $where_clauses[] = "report_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $where_clauses[] = "report_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Get surveillance records
$sql = "SELECT * FROM tbl_disease_surveillance WHERE $where_sql ORDER BY report_date DESC, created_at DESC";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as total FROM tbl_disease_surveillance")->fetch_assoc()['total'],
    'active' => $conn->query("SELECT COUNT(*) as total FROM tbl_disease_surveillance WHERE status = 'Active'")->fetch_assoc()['total'],
    'resolved' => $conn->query("SELECT COUNT(*) as total FROM tbl_disease_surveillance WHERE status = 'Resolved'")->fetch_assoc()['total'],
    'high_severity' => $conn->query("SELECT COUNT(*) as total FROM tbl_disease_surveillance WHERE severity = 'High' AND status = 'Active'")->fetch_assoc()['total']
];

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<style>
.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
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

.alert i {
    font-size: 1.25rem;
}

.surveillance-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.surveillance-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-left: 4px solid #cbd5e0;
    transition: all 0.2s;
}

.surveillance-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.surveillance-card.severity-high {
    border-left-color: #f56565;
    background: #fff5f5;
}

.surveillance-card.severity-medium {
    border-left-color: #ed8936;
    background: #fffaf0;
}

.surveillance-card.severity-low {
    border-left-color: #4299e1;
}

.surveillance-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e2e8f0;
}

.disease-name {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
    font-size: 1.25rem;
}

.surveillance-meta {
    display: flex;
    gap: 1.5rem;
    font-size: 0.875rem;
    color: #718096;
}

.surveillance-meta span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.badge-group {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.surveillance-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.detail-row {
    display: flex;
    flex-direction: column;
}

.detail-row.full-width {
    grid-column: 1 / -1;
}

.detail-row label {
    font-size: 0.75rem;
    color: #718096;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.detail-row span {
    color: #2d3748;
    font-size: 0.95rem;
}

.affected-count {
    font-weight: 700;
    color: #f56565;
    font-size: 1.1rem;
}

.surveillance-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.info-group {
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.info-group:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.info-group h4 {
    margin: 0 0 1rem 0;
    color: #2d3748;
    font-size: 0.95rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-row {
    display: flex;
    padding: 0.5rem 0;
    gap: 1rem;
}

.info-row label {
    font-weight: 500;
    color: #718096;
    min-width: 180px;
    flex-shrink: 0;
}

.info-row span {
    color: #2d3748;
    flex: 1;
}

.confirm-message {
    padding: 1.5rem;
    text-align: center;
}

.confirm-message i {
    font-size: 3rem;
    color: #48bb78;
    margin-bottom: 1rem;
}

.confirm-message h3 {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
}

.confirm-message p {
    color: #718096;
    margin: 0 0 1.5rem 0;
}

.confirm-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
}

@media (max-width: 768px) {
    .info-row {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .info-row label {
        min-width: auto;
    }
}
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-virus"></i> Disease Surveillance</h1>
        <p class="page-subtitle">Monitor and track disease outbreaks in the barangay</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('addReportModal')">
        <i class="fas fa-plus"></i> New Report
    </button>
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

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #4299e1;">
            <i class="fas fa-virus"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total Reports</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #f56565;">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
            <div class="stat-label">Active Cases</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #48bb78;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['resolved']); ?></div>
            <div class="stat-label">Resolved Cases</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #ed8936;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['high_severity']); ?></div>
            <div class="stat-label">High Severity Active</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <div class="search-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search disease, location..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="status" class="filter-select">
            <option value="">All Status</option>
            <option value="Active" <?php echo $status === 'Active' ? 'selected' : ''; ?>>Active</option>
            <option value="Monitoring" <?php echo $status === 'Monitoring' ? 'selected' : ''; ?>>Monitoring</option>
            <option value="Resolved" <?php echo $status === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
        </select>
        
        <select name="severity" class="filter-select">
            <option value="">All Severity</option>
            <option value="Low" <?php echo $severity === 'Low' ? 'selected' : ''; ?>>Low</option>
            <option value="Medium" <?php echo $severity === 'Medium' ? 'selected' : ''; ?>>Medium</option>
            <option value="High" <?php echo $severity === 'High' ? 'selected' : ''; ?>>High</option>
        </select>
        
        <input type="date" name="date_from" class="filter-input" placeholder="From Date" value="<?php echo $date_from; ?>">
        <input type="date" name="date_to" class="filter-input" placeholder="To Date" value="<?php echo $date_to; ?>">
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
        
        <?php if ($search || $status || $severity || $date_from || $date_to): ?>
            <a href="disease-surveillance.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Surveillance Records -->
<div class="surveillance-list">
    <?php if ($records->num_rows > 0): ?>
        <?php while ($record = $records->fetch_assoc()): ?>
            <div class="surveillance-card severity-<?php echo strtolower($record['severity']); ?>">
                <div class="surveillance-header">
                    <div>
                        <h3 class="disease-name"><?php echo htmlspecialchars($record['disease_name']); ?></h3>
                        <div class="surveillance-meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($record['location']); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($record['report_date'])); ?></span>
                        </div>
                    </div>
                    <div class="badge-group">
                        <span class="badge badge-<?php 
                            echo $record['severity'] === 'High' ? 'danger' : 
                                ($record['severity'] === 'Medium' ? 'warning' : 'info'); 
                        ?>">
                            <?php echo $record['severity']; ?> Severity
                        </span>
                        <span class="badge badge-<?php 
                            echo $record['status'] === 'Active' ? 'danger' : 
                                ($record['status'] === 'Monitoring' ? 'warning' : 'success'); 
                        ?>">
                            <?php echo $record['status']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="surveillance-details">
                    <div class="detail-row">
                        <label>Affected Count:</label>
                        <span class="affected-count"><?php echo $record['affected_count']; ?> person(s)</span>
                    </div>
                    
                    <?php if ($record['age_group']): ?>
                    <div class="detail-row">
                        <label>Age Group:</label>
                        <span><?php echo htmlspecialchars($record['age_group']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($record['symptoms']): ?>
                    <div class="detail-row">
                        <label>Symptoms:</label>
                        <span><?php echo htmlspecialchars($record['symptoms']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-row">
                        <label>Reported By:</label>
                        <span><?php echo htmlspecialchars($record['reported_by']); ?></span>
                    </div>
                    
                    <?php if ($record['actions_taken']): ?>
                    <div class="detail-row full-width">
                        <label>Actions Taken:</label>
                        <span><?php echo nl2br(htmlspecialchars($record['actions_taken'])); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($record['remarks']): ?>
                    <div class="detail-row full-width">
                        <label>Remarks:</label>
                        <span><?php echo nl2br(htmlspecialchars($record['remarks'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="surveillance-actions">
                    <button class="btn btn-sm btn-info" onclick='viewReport(<?php echo json_encode($record); ?>)'>
                        <i class="fas fa-eye"></i> View Details
                    </button>
                    <button class="btn btn-sm btn-primary" onclick='updateReport(<?php echo json_encode($record); ?>)'>
                        <i class="fas fa-edit"></i> Update
                    </button>
                    <?php if ($record['status'] !== 'Resolved'): ?>
                    <button class="btn btn-sm btn-success" onclick='confirmResolve(<?php echo json_encode($record); ?>)'>
                        <i class="fas fa-check"></i> Mark Resolved
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-virus"></i>
            <h3>No Disease Reports</h3>
            <p>No disease surveillance records found</p>
        </div>
    <?php endif; ?>
</div>

<!-- Add Report Modal -->
<div id="addReportModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h2 class="modal-title">New Disease Surveillance Report</h2>
            <button class="close-btn" onclick="closeModal('addReportModal')">&times;</button>
        </div>
        <form action="actions/add-disease-report.php" method="POST" class="modal-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Disease Name *</label>
                    <input type="text" name="disease_name" class="form-control" required placeholder="e.g., Dengue, COVID-19">
                </div>
                
                <div class="form-group">
                    <label>Report Date *</label>
                    <input type="date" name="report_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Location *</label>
                    <input type="text" name="location" class="form-control" required placeholder="e.g., Purok 1, Zone 2">
                </div>
                
                <div class="form-group">
                    <label>Affected Count *</label>
                    <input type="number" name="affected_count" class="form-control" required min="1" value="1">
                </div>
                
                <div class="form-group">
                    <label>Age Group</label>
                    <select name="age_group" class="form-control">
                        <option value="">Select Age Group</option>
                        <option value="0-5 years">0-5 years</option>
                        <option value="6-12 years">6-12 years</option>
                        <option value="13-18 years">13-18 years</option>
                        <option value="19-35 years">19-35 years</option>
                        <option value="36-60 years">36-60 years</option>
                        <option value="60+ years">60+ years</option>
                        <option value="Mixed">Mixed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Severity *</label>
                    <select name="severity" class="form-control" required>
                        <option value="">Select Severity</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status *</label>
                    <select name="status" class="form-control" required>
                        <option value="Active">Active</option>
                        <option value="Monitoring">Monitoring</option>
                        <option value="Resolved">Resolved</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Reported By *</label>
                    <input type="text" name="reported_by" class="form-control" required placeholder="Name of reporter">
                </div>
                
                <div class="form-group full-width">
                    <label>Symptoms</label>
                    <textarea name="symptoms" class="form-control" rows="2" placeholder="Common symptoms observed"></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label>Actions Taken</label>
                    <textarea name="actions_taken" class="form-control" rows="3" placeholder="Describe actions taken or planned"></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label>Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addReportModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Report
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function viewReport(report) {
    const severityClass = report.severity === 'High' ? 'danger' : 
                         (report.severity === 'Medium' ? 'warning' : 'info');
    const statusClass = report.status === 'Active' ? 'danger' : 
                       (report.status === 'Monitoring' ? 'warning' : 'success');
    
    const modalHTML = `
        <div class="info-group">
            <h4>Disease Information</h4>
            <div class="info-row">
                <label>Disease Name:</label>
                <span><strong>${report.disease_name}</strong></span>
            </div>
            <div class="info-row">
                <label>Report Date:</label>
                <span>${new Date(report.report_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>
            <div class="info-row">
                <label>Location:</label>
                <span><i class="fas fa-map-marker-alt"></i> ${report.location}</span>
            </div>
            <div class="info-row">
                <label>Severity:</label>
                <span><span class="badge badge-${severityClass}">${report.severity}</span></span>
            </div>
            <div class="info-row">
                <label>Status:</label>
                <span><span class="badge badge-${statusClass}">${report.status}</span></span>
            </div>
        </div>
        
        <div class="info-group">
            <h4>Affected Population</h4>
            <div class="info-row">
                <label>Affected Count:</label>
                <span class="affected-count">${report.affected_count} person(s)</span>
            </div>
            ${report.age_group ? `
            <div class="info-row">
                <label>Age Group:</label>
                <span>${report.age_group}</span>
            </div>` : ''}
            ${report.symptoms ? `
            <div class="info-row">
                <label>Common Symptoms:</label>
                <span>${report.symptoms}</span>
            </div>` : ''}
        </div>
        
        <div class="info-group">
            <h4>Response Information</h4>
            <div class="info-row">
                <label>Reported By:</label>
                <span>${report.reported_by}</span>
            </div>
            ${report.actions_taken ? `
            <div class="info-row">
                <label>Actions Taken:</label>
                <span>${report.actions_taken.replace(/\n/g, '<br>')}</span>
            </div>` : ''}
            ${report.remarks ? `
            <div class="info-row">
                <label>Remarks:</label>
                <span>${report.remarks.replace(/\n/g, '<br>')}</span>
            </div>` : ''}
        </div>
        
        ${report.created_at ? `
        <div class="info-group">
            <h4>Record Information</h4>
            <div class="info-row">
                <label>Record Created:</label>
                <span>${new Date(report.created_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'})}</span>
            </div>
            ${report.updated_at ? `
            <div class="info-row">
                <label>Last Updated:</label>
                <span>${new Date(report.updated_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'})}</span>
            </div>` : ''}
        </div>` : ''}
    `;
    
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'viewReportModal';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h2 class="modal-title">Disease Report Details</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                ${modalHTML}
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="this.closest('.modal').remove(); updateReport(${JSON.stringify(report).replace(/"/g, '&quot;')})">
                    <i class="fas fa-edit"></i> Update Report
                </button>
                ${report.status !== 'Resolved' ? `
                <button class="btn btn-success" onclick="this.closest('.modal').remove(); confirmResolve(${JSON.stringify(report).replace(/"/g, '&quot;')})">
                    <i class="fas fa-check"></i> Mark Resolved
                </button>` : ''}
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

function updateReport(report) {
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'updateReportModal';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h2 class="modal-title">Update Disease Report</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <form action="actions/update-disease-report.php" method="POST" class="modal-body">
                <input type="hidden" name="surveillance_id" value="${report.surveillance_id}">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Disease Name *</label>
                        <input type="text" name="disease_name" class="form-control" required value="${report.disease_name}">
                    </div>
                    
                    <div class="form-group">
                        <label>Report Date *</label>
                        <input type="date" name="report_date" class="form-control" required value="${report.report_date}">
                    </div>
                    
                    <div class="form-group">
                        <label>Location *</label>
                        <input type="text" name="location" class="form-control" required value="${report.location}">
                    </div>
                    
                    <div class="form-group">
                        <label>Affected Count *</label>
                        <input type="number" name="affected_count" class="form-control" required min="1" value="${report.affected_count}">
                    </div>
                    
                    <div class="form-group">
                        <label>Age Group</label>
                        <select name="age_group" class="form-control">
                            <option value="">Select Age Group</option>
                            <option value="0-5 years" ${report.age_group === '0-5 years' ? 'selected' : ''}>0-5 years</option>
                            <option value="6-12 years" ${report.age_group === '6-12 years' ? 'selected' : ''}>6-12 years</option>
                            <option value="13-18 years" ${report.age_group === '13-18 years' ? 'selected' : ''}>13-18 years</option>
                            <option value="19-35 years" ${report.age_group === '19-35 years' ? 'selected' : ''}>19-35 years</option>
                            <option value="36-60 years" ${report.age_group === '36-60 years' ? 'selected' : ''}>36-60 years</option>
                            <option value="60+ years" ${report.age_group === '60+ years' ? 'selected' : ''}>60+ years</option>
                            <option value="Mixed" ${report.age_group === 'Mixed' ? 'selected' : ''}>Mixed</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Severity *</label>
                        <select name="severity" class="form-control" required>
                            <option value="">Select Severity</option>
                            <option value="Low" ${report.severity === 'Low' ? 'selected' : ''}>Low</option>
                            <option value="Medium" ${report.severity === 'Medium' ? 'selected' : ''}>Medium</option>
                            <option value="High" ${report.severity === 'High' ? 'selected' : ''}>High</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="Active" ${report.status === 'Active' ? 'selected' : ''}>Active</option>
                            <option value="Monitoring" ${report.status === 'Monitoring' ? 'selected' : ''}>Monitoring</option>
                            <option value="Resolved" ${report.status === 'Resolved' ? 'selected' : ''}>Resolved</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Reported By *</label>
                        <input type="text" name="reported_by" class="form-control" required value="${report.reported_by}">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Symptoms</label>
                        <textarea name="symptoms" class="form-control" rows="2">${report.symptoms || ''}</textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Actions Taken</label>
                        <textarea name="actions_taken" class="form-control" rows="3">${report.actions_taken || ''}</textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2">${report.remarks || ''}</textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Report
                    </button>
                </div>
            </form>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    modal.onclick = function(event) {
        if (event.target === modal) {
            modal.remove();
        }
    };
}

function confirmResolve(report) {
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'confirmResolveModal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Resolution</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="confirm-message">
                    <i class="fas fa-check-circle"></i>
                    <h3>Mark Report as Resolved?</h3>
                    <p>Are you sure you want to mark this disease report as resolved?</p>
                    <div style="background: #f7fafc; padding: 1rem; border-radius: 8px; margin: 1rem 0; text-align: left;">
                        <strong>Disease:</strong> ${report.disease_name}<br>
                        <strong>Location:</strong> ${report.location}<br>
                        <strong>Affected:</strong> ${report.affected_count} person(s)<br>
                        <strong>Current Status:</strong> <span class="badge badge-${report.status === 'Active' ? 'danger' : 'warning'}">${report.status}</span>
                    </div>
                    <p style="font-size: 0.875rem; color: #718096;">
                        This will change the status to "Resolved" and indicate that the disease outbreak has been contained.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <div class="confirm-actions">
                    <button class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <form action="actions/resolve-disease-report.php" method="POST" style="display: inline;">
                        <input type="hidden" name="surveillance_id" value="${report.surveillance_id}">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Yes, Mark as Resolved
                        </button>
                    </form>
                </div>
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

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>