<?php
require_once('../../../config/config.php');

// Check if user is logged in
requireLogin();

$page_title = "View Report Details";

// Get report ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('Invalid report ID', 'danger');
    header('Location: my-reports.php');
    exit();
}

$issue_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Fetch report details - ensure user can only view their own reports
// Using only basic columns that should exist
$sql = "SELECT 
            w.issue_id,
            w.reporter_id,
            w.reporter_name,
            w.reporter_contact,
            w.issue_type,
            w.location,
            w.description,
            w.urgency,
            w.status,
            w.photo_path,
            w.created_at
        FROM tbl_waste_issues w
        WHERE w.issue_id = ? AND w.reporter_id = ?";

$report = fetchOne($conn, $sql, [$issue_id, $user_id], 'ii');

// Check if report exists and belongs to user
if (!$report) {
    setMessage('Report not found or you do not have permission to view it', 'danger');
    header('Location: my-reports.php');
    exit();
}

// Helper function for urgency badge if not in functions.php
if (!function_exists('getUrgencyBadge')) {
    function getUrgencyBadge($urgency) {
        $urgency = strtolower(trim($urgency));
        $badges = [
            'low' => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Low</span>',
            'medium' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
            'high' => '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
            'critical' => '<span class="badge bg-dark"><i class="fas fa-skull-crossbones me-1"></i>Critical</span>'
        ];
        
        return $badges[$urgency] ?? '<span class="badge bg-secondary">Unknown</span>';
    }
}

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="fas fa-file-alt me-2"></i>
            <?php echo $page_title; ?>
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="my-reports.php" class="btn btn-secondary me-2">
                <i class="fas fa-arrow-left me-1"></i>Back to Reports
            </a>
            <a href="report-issue.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i>New Report
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="row">
        <!-- Main Report Details -->
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info-circle me-2"></i>Report #<?php echo $report['issue_id']; ?>
                    </h6>
                    <div>
                        <?php echo getStatusBadge($report['status']); ?>
                        <?php echo getUrgencyBadge($report['urgency']); ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-exclamation-triangle me-2"></i>Issue Type
                            </h6>
                            <p class="font-weight-bold"><?php echo htmlspecialchars($report['issue_type']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-map-marker-alt me-2"></i>Location
                            </h6>
                            <p class="font-weight-bold"><?php echo htmlspecialchars($report['location']); ?></p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="text-muted mb-2">
                            <i class="fas fa-align-left me-2"></i>Description
                        </h6>
                        <p class="text-justify"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                    </div>

                    <?php if (!empty($report['photo_path'])): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">
                                <i class="fas fa-camera me-2"></i>Photo Evidence
                            </h6>
                            <div class="text-center">
                                <img src="../../../<?php echo htmlspecialchars($report['photo_path']); ?>" 
                                     alt="Issue Photo" 
                                     class="img-fluid rounded shadow"
                                     style="max-height: 500px; cursor: pointer;"
                                     data-bs-toggle="modal" 
                                     data-bs-target="#photoModal">
                                <p class="text-muted mt-2">
                                    <small><i class="fas fa-info-circle me-1"></i>Click to enlarge</small>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($report['status'] === 'resolved' || $report['status'] === 'closed'): ?>
                        <div class="alert alert-success">
                            <h6 class="alert-heading">
                                <i class="fas fa-check-circle me-2"></i>Status Update
                            </h6>
                            <p class="mb-0">
                                This issue has been marked as <strong><?php echo ucfirst($report['status']); ?></strong>.
                                Thank you for helping keep our barangay clean!
                            </p>
                        </div>
                    <?php elseif ($report['status'] === 'in progress'): ?>
                        <div class="alert alert-info">
                            <h6 class="alert-heading">
                                <i class="fas fa-spinner me-2"></i>Status Update
                            </h6>
                            <p class="mb-0">
                                Your issue is currently being addressed by our waste management team.
                                We'll update you once it has been resolved.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Timeline Card -->
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-history me-2"></i>Report Timeline
                    </h6>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <!-- Reported -->
                        <div class="timeline-item">
                            <div class="timeline-marker bg-primary"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1">
                                    <i class="fas fa-flag me-1"></i>Report Submitted
                                </h6>
                                <p class="text-muted mb-0">
                                    <?php echo formatDateTime($report['created_at']); ?>
                                </p>
                                <small class="text-muted">
                                    <?php echo timeAgo($report['created_at']); ?>
                                </small>
                            </div>
                        </div>

                        <!-- Status-based timeline items -->
                        <?php if ($report['status'] === 'in progress'): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-info"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">
                                        <i class="fas fa-cog me-1"></i>In Progress
                                    </h6>
                                    <p class="text-muted mb-0">
                                        Your issue is being actively addressed
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($report['status'] === 'resolved'): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-success"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">
                                        <i class="fas fa-check-circle me-1"></i>Issue Resolved
                                    </h6>
                                    <p class="text-muted mb-0">
                                        The reported issue has been resolved
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($report['status'] === 'closed'): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-secondary"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">
                                        <i class="fas fa-times-circle me-1"></i>Report Closed
                                    </h6>
                                    <p class="text-muted mb-0">
                                        This report has been closed
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Report Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info me-2"></i>Report Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small">Report ID</label>
                        <p class="font-weight-bold">#<?php echo $report['issue_id']; ?></p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Current Status</label>
                        <p><?php echo getStatusBadge($report['status']); ?></p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Urgency Level</label>
                        <p><?php echo getUrgencyBadge($report['urgency']); ?></p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Date Reported</label>
                        <p class="mb-0">
                            <i class="far fa-calendar me-1"></i>
                            <?php echo formatDate($report['created_at']); ?>
                        </p>
                        <small class="text-muted">
                            <?php echo timeAgo($report['created_at']); ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Reporter Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-user me-2"></i>Reporter Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small">Name</label>
                        <p class="mb-0">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($report['reporter_name']); ?>
                        </p>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Contact Number</label>
                        <p class="mb-0">
                            <i class="fas fa-phone me-1"></i>
                            <?php echo htmlspecialchars($report['reporter_contact']); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Status Help Card -->
            <div class="card shadow">
                <div class="card-header py-3 bg-info text-white">
                    <h6 class="m-0 font-weight-bold">
                        <i class="fas fa-question-circle me-2"></i>Need Help?
                    </h6>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        <strong>What happens next?</strong>
                    </p>
                    <ul class="mb-3">
                        <?php if ($report['status'] === 'pending'): ?>
                            <li>Your report is being reviewed by our waste management team</li>
                            <li>We typically respond within 24-48 hours</li>
                            <li>You'll be notified of any status changes</li>
                        <?php elseif ($report['status'] === 'in progress'): ?>
                            <li>Your issue is being actively addressed</li>
                            <li>Our team is working on resolving it</li>
                            <li>You'll receive an update once resolved</li>
                        <?php elseif ($report['status'] === 'resolved'): ?>
                            <li>Your issue has been resolved</li>
                            <li>Thank you for helping keep our barangay clean</li>
                        <?php elseif ($report['status'] === 'closed'): ?>
                            <li>This report has been closed</li>
                            <li>If you still have concerns, please submit a new report</li>
                        <?php endif; ?>
                    </ul>

                    <hr>

                    <p class="mb-2">
                        <strong>Contact Information:</strong>
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-phone-alt text-success me-2"></i>
                        (123) 456-7890
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-envelope text-primary me-2"></i>
                        waste@barangay.gov.ph
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Photo Modal -->
<?php if (!empty($report['photo_path'])): ?>
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoModalLabel">
                    <i class="fas fa-image me-2"></i>Issue Photo - Report #<?php echo $report['issue_id']; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img src="../../../<?php echo htmlspecialchars($report['photo_path']); ?>" 
                     alt="Issue Photo" 
                     class="img-fluid">
            </div>
            <div class="modal-footer">
                <a href="../../../<?php echo htmlspecialchars($report['photo_path']); ?>" 
                   download 
                   class="btn btn-primary">
                    <i class="fas fa-download me-1"></i>Download Photo
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* Timeline Styles */
.timeline {
    position: relative;
    padding-left: 40px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e3e6f0;
}

.timeline-item {
    position: relative;
    margin-bottom: 30px;
}

.timeline-item:last-child {
    margin-bottom: 0;
}

.timeline-marker {
    position: absolute;
    left: -35px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #e3e6f0;
}

.timeline-content {
    background: #f8f9fc;
    padding: 15px;
    border-radius: 8px;
    border-left: 3px solid #4e73df;
}

.timeline-content h6 {
    margin-bottom: 5px;
    color: #5a5c69;
}

.timeline-content p {
    margin-bottom: 5px;
    color: #858796;
}

/* Card hover effect */
.card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 1rem 3rem rgba(0,0,0,.175) !important;
}

/* Image hover effect */
img[data-bs-toggle="modal"] {
    transition: transform 0.2s ease-in-out;
}

img[data-bs-toggle="modal"]:hover {
    transform: scale(1.02);
}
</style>

<?php 
require_once '../../../includes/footer.php'; 
?>