<?php
/**
 * INSTRUCTIONS:
 * Save this file as: damage-assessment-view.php
 * Location: barangaylink/disasters/views/damage-assessment-view.php
 * 
 * Make sure to create the "views" folder first:
 * barangaylink/disasters/views/
 */
?>
<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-house-damage text-danger me-2"></i>Damage Assessment</h1>
            <p class="text-muted">Manage and track property damage assessments</p>
        </div>
        <div>
            <button class="btn btn-success me-2" onclick="showPrintAllModal()">
                <i class="fas fa-print me-2"></i>Print All
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssessmentModal">
                <i class="fas fa-plus me-2"></i>Add Assessment
            </button>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Assessments</p>
                            <h3 class="mb-0"><?php echo $total_assessments; ?></h3>
                        </div>
                        <div class="fs-1 text-primary"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Pending</p>
                            <h3 class="mb-0"><?php echo $pending_count; ?></h3>
                        </div>
                        <div class="fs-1 text-warning"><i class="fas fa-clock"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Completed</p>
                            <h3 class="mb-0"><?php echo $completed_count; ?></h3>
                        </div>
                        <div class="fs-1 text-success"><i class="fas fa-check-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1">Total Est. Cost</p>
                            <h3 class="mb-0">₱<?php echo number_format($total_cost, 2); ?></h3>
                        </div>
                        <div class="fs-1 text-info"><i class="fas fa-peso-sign"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assessments Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">Damage Assessments</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="assessmentsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Resident</th>
                            <th>Location</th>
                            <th>Disaster Type</th>
                            <th>Damage Type</th>
                            <th>Severity</th>
                            <th>Est. Cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessments as $assessment): ?>
                        <tr>
                            <td><?php echo formatDate($assessment['assessment_date']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($assessment['resident_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($assessment['address']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($assessment['location']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($assessment['disaster_type']); ?></span></td>
                            <td><?php echo htmlspecialchars($assessment['damage_type']); ?></td>
                            <td><?php echo getSeverityBadge($assessment['severity']); ?></td>
                            <td>₱<?php echo number_format($assessment['estimated_cost'], 2); ?></td>
                            <td><?php echo getStatusBadge($assessment['status']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewAssessment(<?php echo $assessment['assessment_id']; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-success" onclick="printAssessmentDirect(<?php echo $assessment['assessment_id']; ?>)" title="Print">
                                    <i class="fas fa-print"></i>
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="editAssessment(<?php echo $assessment['assessment_id']; ?>)" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteAssessment(<?php echo $assessment['assessment_id']; ?>)" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../modals/damage-assessment-modals.php'; ?>

<!-- Include JavaScript -->
<script>
    const assessmentsData = <?php echo json_encode($assessments); ?>;
    const userRole = '<?php echo $user_role; ?>';
</script>
<script src="js/damage-assessment.js"></script>

<!-- Print Styles -->
<style>
@media print {
    body * {
        visibility: hidden;
    }
    #printContent, #printContent * {
        visibility: visible;
    }
    #printAllContent, #printAllContent * {
        visibility: visible;
    }
    #printContent, #printAllContent {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .modal-header, .modal-footer, .btn, .alert {
        display: none !important;
    }
    table {
        page-break-inside: auto;
    }
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    thead {
        display: table-header-group;
    }
}
</style