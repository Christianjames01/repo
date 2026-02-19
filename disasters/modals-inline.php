<?php
/**
 * Damage Assessment Modals - COMPLETE VERSION
 * Path: barangaylink/disasters/modals-inline.php
 * 
 * All modals with proper status field handling
 */
?>

<!-- Add Assessment Modal -->
<div class="modal fade" id="addAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Damage Assessment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_assessment">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Resident <span class="text-danger">*</span></label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">Select Resident</option>
                                <?php foreach ($residents as $resident): ?>
                                    <option value="<?php echo $resident['resident_id']; ?>">
                                        <?php echo htmlspecialchars($resident['full_name']); ?> - <?php echo htmlspecialchars($resident['address']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assessment Date <span class="text-danger">*</span></label>
                            <input type="date" name="assessment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Disaster Type <span class="text-danger">*</span></label>
                            <select name="disaster_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Typhoon">Typhoon</option>
                                <option value="Flood">Flood</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Fire">Fire</option>
                                <option value="Landslide">Landslide</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location <span class="text-danger">*</span></label>
                            <input type="text" name="location" class="form-control" placeholder="Specific location" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Damage Type <span class="text-danger">*</span></label>
                            <select name="damage_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Structural">Structural</option>
                                <option value="Property">Property</option>
                                <option value="Agricultural">Agricultural</option>
                                <option value="Infrastructure">Infrastructure</option>
                                <option value="Mixed">Mixed</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Severity <span class="text-danger">*</span></label>
                            <select name="severity" class="form-select" required>
                                <option value="">Select Severity</option>
                                <option value="Minor">Minor</option>
                                <option value="Moderate">Moderate</option>
                                <option value="Major">Major</option>
                                <option value="Severe">Severe</option>
                                <option value="Total Loss">Total Loss</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Estimated Cost (₱) <span class="text-danger">*</span></label>
                            <input type="number" name="estimated_cost" class="form-control" step="0.01" min="0" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                <option value="">Select Status</option>
                                <option value="Pending" selected>Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Verified">Verified</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Detailed description of damage"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Assessment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Assessment Modal -->
<div class="modal fade" id="editAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Damage Assessment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_assessment">
                    <input type="hidden" name="assessment_id" id="edit_assessment_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Resident</label>
                            <input type="text" id="edit_resident_name" class="form-control" readonly>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assessment Date <span class="text-danger">*</span></label>
                            <input type="date" name="assessment_date" id="edit_assessment_date" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Disaster Type <span class="text-danger">*</span></label>
                            <select name="disaster_type" id="edit_disaster_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Typhoon">Typhoon</option>
                                <option value="Flood">Flood</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Fire">Fire</option>
                                <option value="Landslide">Landslide</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location <span class="text-danger">*</span></label>
                            <input type="text" name="location" id="edit_location" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Damage Type <span class="text-danger">*</span></label>
                            <select name="damage_type" id="edit_damage_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="Structural">Structural</option>
                                <option value="Property">Property</option>
                                <option value="Agricultural">Agricultural</option>
                                <option value="Infrastructure">Infrastructure</option>
                                <option value="Mixed">Mixed</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Severity <span class="text-danger">*</span></label>
                            <select name="severity" id="edit_severity" class="form-select" required>
                                <option value="">Select Severity</option>
                                <option value="Minor">Minor</option>
                                <option value="Moderate">Moderate</option>
                                <option value="Major">Major</option>
                                <option value="Severe">Severe</option>
                                <option value="Total Loss">Total Loss</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Estimated Cost (₱) <span class="text-danger">*</span></label>
                            <input type="number" name="estimated_cost" id="edit_estimated_cost" class="form-control" step="0.01" min="0" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="">Select Status</option>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Verified">Verified</option>
                            </select>
                        </div>
                    </div>
                    
                    <?php if ($user_role === 'Super Admin'): ?>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Assessed By</label>
                            <select name="assessed_by" id="edit_assessed_by" class="form-select">
                                <option value="">Keep Current User</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Assessment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Assessment Modal -->
<div class="modal fade" id="viewAssessmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Assessment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Resident:</strong>
                        <p id="view_resident_name"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Assessment Date:</strong>
                        <p id="view_assessment_date"></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Disaster Type:</strong>
                        <p id="view_disaster_type"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Location:</strong>
                        <p id="view_location"></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Damage Type:</strong>
                        <p id="view_damage_type"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Severity:</strong>
                        <p id="view_severity"></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Estimated Cost:</strong>
                        <p id="view_estimated_cost"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong>
                        <p id="view_status"></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Assessed By:</strong>
                        <p id="view_assessed_by"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Created At:</strong>
                        <p id="view_created_at"></p>
                    </div>
                </div>
                
                <div class="mb-3">
                    <strong>Description:</strong>
                    <p id="view_description"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Print All Modal -->
<div class="modal fade" id="printAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-print me-2"></i>Print Options</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Filter by Status</label>
                    <select id="print_status_filter" class="form-select">
                        <option value="all">All Assessments</option>
                        <option value="Pending">Pending Only</option>
                        <option value="In Progress">In Progress Only</option>
                        <option value="Completed">Completed Only</option>
                        <option value="Verified">Verified Only</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Filter by Severity</label>
                    <select id="print_severity_filter" class="form-select">
                        <option value="all">All Severities</option>
                        <option value="Minor">Minor</option>
                        <option value="Moderate">Moderate</option>
                        <option value="Major">Major</option>
                        <option value="Severe">Severe</option>
                        <option value="Total Loss">Total Loss</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="printFiltered()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Store assessments data for JavaScript
const assessmentsData = <?php echo json_encode($assessments); ?>;

// Edit Assessment
function editAssessment(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    document.getElementById('edit_assessment_id').value = assessment.assessment_id;
    document.getElementById('edit_resident_name').value = assessment.resident_name;
    document.getElementById('edit_assessment_date').value = assessment.assessment_date;
    document.getElementById('edit_disaster_type').value = assessment.disaster_type;
    document.getElementById('edit_location').value = assessment.location;
    document.getElementById('edit_damage_type').value = assessment.damage_type;
    document.getElementById('edit_severity').value = assessment.severity;
    document.getElementById('edit_estimated_cost').value = assessment.estimated_cost;
    document.getElementById('edit_status').value = assessment.status; // CRITICAL: Set status value
    document.getElementById('edit_description').value = assessment.description || '';
    
    <?php if ($user_role === 'Super Admin'): ?>
    if (document.getElementById('edit_assessed_by')) {
        document.getElementById('edit_assessed_by').value = assessment.assessed_by || '';
    }
    <?php endif; ?>
    
    new bootstrap.Modal(document.getElementById('editAssessmentModal')).show();
}

// View Assessment
function viewAssessment(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    document.getElementById('view_resident_name').textContent = assessment.resident_name;
    document.getElementById('view_assessment_date').textContent = formatDate(assessment.assessment_date);
    document.getElementById('view_disaster_type').innerHTML = `<span class="badge bg-secondary">${assessment.disaster_type}</span>`;
    document.getElementById('view_location').textContent = assessment.location;
    document.getElementById('view_damage_type').textContent = assessment.damage_type;
    document.getElementById('view_severity').innerHTML = getSeverityBadge(assessment.severity);
    document.getElementById('view_estimated_cost').textContent = '₱' + parseFloat(assessment.estimated_cost).toLocaleString('en-PH', {minimumFractionDigits: 2});
    document.getElementById('view_status').innerHTML = getStatusBadge(assessment.status);
    document.getElementById('view_assessed_by').textContent = assessment.assessed_by_name || 'N/A';
    document.getElementById('view_created_at').textContent = formatDate(assessment.created_at);
    document.getElementById('view_description').textContent = assessment.description || 'No description provided';
    
    new bootstrap.Modal(document.getElementById('viewAssessmentModal')).show();
}

// Delete Assessment
function deleteAssessment(id) {
    if (confirm('Are you sure you want to delete this assessment? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_assessment">
            <input type="hidden" name="assessment_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Print single assessment
function printAssessmentDirect(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(generatePrintHTML([assessment]));
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

// Show print all modal
function showPrintAllModal() {
    new bootstrap.Modal(document.getElementById('printAllModal')).show();
}

// Print filtered assessments
function printFiltered() {
    const statusFilter = document.getElementById('print_status_filter').value;
    const severityFilter = document.getElementById('print_severity_filter').value;
    
    let filtered = assessmentsData.filter(a => {
        if (statusFilter !== 'all' && a.status !== statusFilter) return false;
        if (severityFilter !== 'all' && a.severity !== severityFilter) return false;
        return true;
    });
    
    if (filtered.length === 0) {
        alert('No assessments match the selected filters.');
        return;
    }
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(generatePrintHTML(filtered));
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
    
    bootstrap.Modal.getInstance(document.getElementById('printAllModal')).hide();
}

// Generate print HTML
function generatePrintHTML(assessments) {
    const totalCost = assessments.reduce((sum, a) => sum + parseFloat(a.estimated_cost), 0);
    
    let html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Damage Assessments Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .header h1 { margin: 0; }
                .header p { margin: 5px 0; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                th { background-color: #f4f4f4; }
                .summary { margin-top: 20px; }
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Damage Assessment Report</h1>
                <p>Generated on: ${new Date().toLocaleDateString()}</p>
                <p>Total Assessments: ${assessments.length} | Total Estimated Cost: ₱${totalCost.toLocaleString('en-PH', {minimumFractionDigits: 2})}</p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Resident</th>
                        <th>Location</th>
                        <th>Disaster</th>
                        <th>Damage Type</th>
                        <th>Severity</th>
                        <th>Cost</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>`;
    
    assessments.forEach(a => {
        html += `
            <tr>
                <td>${formatDate(a.assessment_date)}</td>
                <td>${a.resident_name}</td>
                <td>${a.location}</td>
                <td>${a.disaster_type}</td>
                <td>${a.damage_type}</td>
                <td>${a.severity}</td>
                <td>₱${parseFloat(a.estimated_cost).toLocaleString('en-PH', {minimumFractionDigits: 2})}</td>
                <td>${a.status}</td>
            </tr>`;
    });
    
    html += `
                </tbody>
            </table>
        </body>
        </html>`;
    
    return html;
}

// Helper functions
function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function getSeverityBadge(severity) {
    const colors = {
        'Minor': 'success',
        'Moderate': 'info',
        'Major': 'warning',
        'Severe': 'danger',
        'Total Loss': 'dark'
    };
    return `<span class="badge bg-${colors[severity] || 'secondary'}">${severity}</span>`;
}

function getStatusBadge(status) {
    const colors = {
        'Pending': 'warning',
        'In Progress': 'info',
        'Completed': 'success',
        'Verified': 'primary'
    };
    return `<span class="badge bg-${colors[status] || 'secondary'}">${status}</span>`;
}

// Initialize DataTable
$(document).ready(function() {
    $('#assessmentsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        language: {
            search: "Search assessments:"
        }
    });
});
</script>