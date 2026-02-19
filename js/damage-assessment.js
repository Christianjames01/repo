/**
 * INSTRUCTIONS:
 * Save this file as: damage-assessment.js
 * Location: barangaylink/disasters/js/damage-assessment.js
 * 
 * Make sure to create the "js" folder first:
 * barangaylink/disasters/js/
 */

// Store current assessment for viewing
let currentAssessment = null;

// View Assessment Function
function viewAssessment(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    currentAssessment = assessment;
    
    // Populate view modal
    document.getElementById('view_resident_name').textContent = assessment.resident_name || 'N/A';
    document.getElementById('view_address').textContent = assessment.address || 'N/A';
    document.getElementById('view_assessment_date').textContent = formatDate(assessment.assessment_date);
    document.getElementById('view_location').textContent = assessment.location || 'N/A';
    document.getElementById('view_disaster_type').textContent = assessment.disaster_type || 'N/A';
    document.getElementById('view_damage_type').textContent = assessment.damage_type || 'N/A';
    document.getElementById('view_severity').innerHTML = getSeverityBadgeHTML(assessment.severity);
    document.getElementById('view_estimated_cost').textContent = '₱' + parseFloat(assessment.estimated_cost).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('view_status').innerHTML = getStatusBadgeHTML(assessment.status);
    document.getElementById('view_assessed_by').textContent = assessment.assessed_by_name || 'N/A';
    document.getElementById('view_description').textContent = assessment.description || 'No description provided';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('viewAssessmentModal'));
    modal.show();
}

// Edit Assessment Function
function editAssessment(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    // Populate edit modal
    document.getElementById('edit_assessment_id').value = assessment.assessment_id;
    document.getElementById('edit_resident_name').value = assessment.resident_name || '';
    document.getElementById('edit_assessment_date').value = assessment.assessment_date;
    document.getElementById('edit_disaster_type').value = assessment.disaster_type;
    document.getElementById('edit_location').value = assessment.location;
    document.getElementById('edit_damage_type').value = assessment.damage_type;
    document.getElementById('edit_severity').value = assessment.severity;
    document.getElementById('edit_estimated_cost').value = assessment.estimated_cost;
    document.getElementById('edit_status').value = assessment.status;
    document.getElementById('edit_description').value = assessment.description || '';
    
    // Handle assessed_by field based on user role
    if (userRole === 'Super Admin') {
        const assessedBySelect = document.getElementById('edit_assessed_by');
        if (assessedBySelect) {
            assessedBySelect.value = assessment.assessed_by || '';
        }
    } else {
        const assessedByReadonly = document.getElementById('edit_assessed_by_readonly');
        if (assessedByReadonly) {
            assessedByReadonly.value = assessment.assessed_by_name || 'N/A';
        }
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editAssessmentModal'));
    modal.show();
}

// Delete Assessment Function
function deleteAssessment(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    // Populate delete modal
    document.getElementById('delete_assessment_id').value = assessment.assessment_id;
    document.getElementById('delete_resident_name').textContent = assessment.resident_name || 'N/A';
    document.getElementById('delete_assessment_date').textContent = formatDate(assessment.assessment_date);
    document.getElementById('delete_location').textContent = assessment.location || 'N/A';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteAssessmentModal'));
    modal.show();
}

// Print Assessment Direct Function
function printAssessmentDirect(id) {
    const assessment = assessmentsData.find(a => a.assessment_id == id);
    if (!assessment) return;
    
    populatePrintModal(assessment);
    
    // Clear previous signature inputs
    document.getElementById('print_chairman_name').value = '';
    document.getElementById('print_officer_name').value = '';
    
    // Show modal
    const printModal = new bootstrap.Modal(document.getElementById('printModal'));
    printModal.show();
}

// Print Assessment from View Modal
function printAssessment() {
    if (!currentAssessment) return;
    
    populatePrintModal(currentAssessment);
    
    // Clear previous signature inputs
    document.getElementById('print_chairman_name').value = '';
    document.getElementById('print_officer_name').value = '';
    
    // Close view modal and show print modal
    const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewAssessmentModal'));
    if (viewModal) viewModal.hide();
    
    const printModal = new bootstrap.Modal(document.getElementById('printModal'));
    printModal.show();
}

// Populate Print Modal Helper Function
function populatePrintModal(assessment) {
    document.getElementById('print_assessment_id').textContent = '#' + assessment.assessment_id;
    document.getElementById('print_assessment_date').textContent = formatDate(assessment.assessment_date);
    document.getElementById('print_resident_name').textContent = assessment.resident_name || 'N/A';
    document.getElementById('print_address').textContent = assessment.address || 'N/A';
    document.getElementById('print_location').textContent = assessment.location || 'N/A';
    document.getElementById('print_disaster_type').textContent = assessment.disaster_type || 'N/A';
    document.getElementById('print_damage_type').textContent = assessment.damage_type || 'N/A';
    document.getElementById('print_severity').textContent = assessment.severity || 'N/A';
    document.getElementById('print_estimated_cost').textContent = '₱' + parseFloat(assessment.estimated_cost).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('print_status').textContent = assessment.status || 'N/A';
    document.getElementById('print_assessed_by').textContent = assessment.assessed_by_name || 'N/A';
    document.getElementById('print_date_generated').textContent = new Date().toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'});
    
    // Handle description section
    const descSection = document.getElementById('print_description_section');
    const descContent = document.getElementById('print_description');
    if (assessment.description && assessment.description.trim() !== '') {
        descContent.textContent = assessment.description;
        descSection.style.display = 'block';
    } else {
        descSection.style.display = 'none';
    }
}

// Print Single Assessment with Signatures
function printSingleAssessment() {
    // Get the entered names
    const chairmanName = document.getElementById('print_chairman_name').value.trim();
    const officerName = document.getElementById('print_officer_name').value.trim();
    
    // Update signature fields in print content
    const chairmanElements = document.querySelectorAll('.signature-chairman-name');
    chairmanElements.forEach(el => {
        el.textContent = chairmanName || '';
    });
    
    const officerElements = document.querySelectorAll('.signature-officer-name');
    officerElements.forEach(el => {
        // Use entered name or fall back to assessed_by name
        el.textContent = officerName || document.getElementById('print_assessed_by').textContent;
    });
    
    // Trigger print
    window.print();
}

// Show Print All Modal
function showPrintAllModal() {
    // Clear previous signature inputs
    document.getElementById('printall_prepared_by').value = '';
    document.getElementById('printall_drrm_officer').value = '';
    document.getElementById('printall_chairman_name').value = '';
    
    const printAllModal = new bootstrap.Modal(document.getElementById('printAllModal'));
    printAllModal.show();
}

// Print All Report with Signatures
function printAllReportWithSignatures() {
    // Get the entered names
    const preparedBy = document.getElementById('printall_prepared_by').value.trim();
    const drrmOfficer = document.getElementById('printall_drrm_officer').value.trim();
    const chairmanName = document.getElementById('printall_chairman_name').value.trim();
    
    // Update signature fields in print content
    const preparedByElements = document.querySelectorAll('.signature-prepared-by');
    preparedByElements.forEach(el => {
        el.textContent = preparedBy || '';
    });
    
    const drrmElements = document.querySelectorAll('.signature-drrm-officer');
    drrmElements.forEach(el => {
        el.textContent = drrmOfficer || '';
    });
    
    const chairmanElements = document.querySelectorAll('.signature-chairman-all');
    chairmanElements.forEach(el => {
        el.textContent = chairmanName || '';
    });
    
    // Trigger print
    window.print();
}

// Helper Functions
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'});
}

function getSeverityBadgeHTML(severity) {
    const colors = {
        'Low': 'success',
        'Medium': 'warning',
        'High': 'danger',
        'Critical': 'dark'
    };
    const color = colors[severity] || 'secondary';
    return `<span class="badge bg-${color}">${severity}</span>`;
}

function getStatusBadgeHTML(status) {
    const colors = {
        'Pending': 'warning',
        'In Progress': 'info',
        'Completed': 'success'
    };
    const color = colors[status] || 'secondary';
    return `<span class="badge bg-${color}">${status}</span>`;
}

// Initialize DataTable
document.addEventListener('DOMContentLoaded', function() {
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#assessmentsTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "Search assessments:",
                lengthMenu: "Show _MENU_ assessments per page"
            }
        });
    }
});