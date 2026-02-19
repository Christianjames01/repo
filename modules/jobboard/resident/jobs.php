<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../config/session.php';

requireLogin();

if (!hasRole(['Resident'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'My Job Applications';
$current_user_id = getCurrentUserId();

// Get all applications - FIXED: Get company_logo from tbl_jobs instead of tbl_companies
$stmt = $conn->prepare("
    SELECT ja.*, j.job_title, j.job_type, j.location, j.company_name, j.company_logo
    FROM tbl_job_applications ja
    INNER JOIN tbl_jobs j ON ja.job_id = j.job_id
    WHERE ja.applicant_id = ?
    ORDER BY ja.application_date DESC
");

if (!$stmt) {
    die("Database error: Unable to prepare statement - " . $conn->error);
}

$stmt->bind_param("i", $current_user_id);
if (!$stmt->execute()) {
    die("Database error: Unable to execute query - " . $stmt->error);
}

$applications = $stmt->get_result();

include '../../../includes/header.php';
?>

<style>
.application-card {
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}

.application-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.company-logo-sm {
    width: 50px;
    height: 50px;
    object-fit: contain;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 5px;
    background: white;
}

.company-logo-placeholder-sm {
    width: 50px;
    height: 50px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #6c757d;
    margin: 0 auto;
}

.badge {
    padding: 6px 12px;
    font-weight: 500;
}

.empty-state {
    padding: 60px 20px;
    text-align: center;
}

.empty-state i {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">My Job Applications</h1>
            <p class="text-muted">Track your job application status</p>
        </div>
        <a href="browse-jobs.php" class="btn btn-primary">
            <i class="fas fa-search"></i> Browse Jobs
        </a>
    </div>

    <?php if ($applications->num_rows > 0): ?>
        <div class="row">
            <?php while ($app = $applications->fetch_assoc()): ?>
                <div class="col-12 mb-4">
                    <div class="card application-card">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-1 text-center">
                                    <?php if (!empty($app['company_logo'])): ?>
                                        <!-- FIXED: Changed path from uploads/companies/ to uploads/company_logos/ -->
                                        <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($app['company_logo']); ?>" 
                                             class="company-logo-sm" alt="Company Logo">
                                    <?php else: ?>
                                        <div class="company-logo-placeholder-sm">
                                            <i class="fas fa-building"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-5">
                                    <h5 class="mb-1"><?php echo htmlspecialchars($app['job_title']); ?></h5>
                                    <p class="text-muted mb-1"><?php echo htmlspecialchars($app['company_name'] ?? 'N/A'); ?></p>
                                    <div>
                                        <span class="badge bg-secondary me-1">
                                            <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($app['job_type']); ?>
                                        </span>
                                        <span class="badge bg-info">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($app['location']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-2 text-center">
                                    <small class="text-muted d-block">Applied on</small>
                                    <strong><?php echo date('M d, Y', strtotime($app['application_date'])); ?></strong>
                                </div>
                                <div class="col-md-2 text-center">
                                    <?php
                                    $status_class = '';
                                    switch($app['status']) {
                                        case 'Pending':
                                            $status_class = 'bg-warning text-dark';
                                            break;
                                        case 'Reviewed':
                                            $status_class = 'bg-info';
                                            break;
                                        case 'Shortlisted':
                                            $status_class = 'bg-primary';
                                            break;
                                        case 'Accepted':
                                            $status_class = 'bg-success';
                                            break;
                                        case 'Rejected':
                                            $status_class = 'bg-danger';
                                            break;
                                        case 'Withdrawn':
                                            $status_class = 'bg-secondary';
                                            break;
                                        default:
                                            $status_class = 'bg-secondary';
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_class; ?> fs-6">
                                        <?php echo htmlspecialchars($app['status']); ?>
                                    </span>
                                </div>
                                <div class="col-md-2 text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary mb-1" 
                                            data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $app['application_id']; ?>">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Modal -->
                <div class="modal fade" id="viewModal<?php echo $app['application_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Application Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- ADDED: Display company logo in modal -->
                                <?php if (!empty($app['company_logo'])): ?>
                                    <div class="text-center mb-3">
                                        <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($app['company_logo']); ?>" 
                                             style="max-width: 120px; max-height: 120px; object-fit: contain; border: 2px solid #dee2e6; border-radius: 8px; padding: 10px; background: #f8f9fa;" 
                                             alt="Company Logo">
                                    </div>
                                <?php endif; ?>

                                <h6>Job Position</h6>
                                <p><?php echo htmlspecialchars($app['job_title']); ?> at <?php echo htmlspecialchars($app['company_name'] ?? 'N/A'); ?></p>
                                
                                <h6>Application Date</h6>
                                <p><?php echo date('F d, Y h:i A', strtotime($app['application_date'])); ?></p>
                                
                                <h6>Status</h6>
                                <p><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($app['status']); ?></span></p>
                                
                                <h6>Cover Letter</h6>
                                <p><?php echo nl2br(htmlspecialchars($app['cover_letter'] ?? 'N/A')); ?></p>
                                
                                <?php if (!empty($app['resume_file'])): ?>
                                    <h6>Resume</h6>
                                    <p>
                                        <a href="../../../uploads/resumes/<?php echo htmlspecialchars($app['resume_file']); ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-download"></i> Download Resume
                                        </a>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($app['notes'])): ?>
                                    <h6>Employer Notes</h6>
                                    <div class="alert alert-info">
                                        <?php echo nl2br(htmlspecialchars($app['notes'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                <h4>No Applications Yet</h4>
                <p class="text-muted">You haven't applied for any jobs yet.</p>
                <a href="browse-jobs.php" class="btn btn-primary">
                    <i class="fas fa-search"></i> Browse Available Jobs
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../../includes/footer.php'; ?>