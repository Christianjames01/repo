<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';

requireLogin();
if (!hasRole(['Resident'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Job Details';
$current_user_id = getCurrentUserId();

if (!isset($_GET['id'])) {
    header('Location: jobs.php');
    exit();
}

$job_id = intval($_GET['id']);

// Get job details with company info
$stmt = $conn->prepare("
    SELECT j.*, c.company_name, c.company_logo,
    (SELECT COUNT(*) FROM tbl_job_applications WHERE job_id = j.job_id AND applicant_id = ?) as has_applied
    FROM tbl_jobs j
    LEFT JOIN tbl_companies c ON j.company_id = c.company_id
    WHERE j.job_id = ?
");
$stmt->bind_param("ii", $current_user_id, $job_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: jobs.php');
    exit();
}

$job = $result->fetch_assoc();

// Handle application submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply'])) {
    $cover_letter = $_POST['cover_letter'];
    $resume_file = null;

    // Handle resume upload
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] == 0) {
        $upload_dir = '../../../uploads/resumes/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['pdf', 'doc', 'docx'];
        
        if (in_array(strtolower($file_ext), $allowed_ext)) {
            $resume_file = uniqid() . '_' . basename($_FILES['resume']['name']);
            move_uploaded_file($_FILES['resume']['tmp_name'], $upload_dir . $resume_file);
        }
    }

    $insert_stmt = $conn->prepare("
        INSERT INTO tbl_job_applications (job_id, applicant_id, cover_letter, resume_file, application_date, status)
        VALUES (?, ?, ?, ?, NOW(), 'Pending')
    ");
    $insert_stmt->bind_param("iiss", $job_id, $current_user_id, $cover_letter, $resume_file);
    
    if ($insert_stmt->execute()) {
        $_SESSION['success_message'] = 'Application submitted successfully!';
        header('Location: my-applications.php');
        exit();
    } else {
        $error_message = 'Failed to submit application. Please try again.';
    }
}

include '../../../includes/header.php';
?>

<style>
.company-logo-large {
    width: 100px;
    height: 100px;
    object-fit: contain;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 10px;
    background: white;
}

.company-logo-placeholder-large {
    width: 100px;
    height: 100px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    color: #6c757d;
}

.job-description, .job-requirements, .job-benefits {
    line-height: 1.8;
}
</style>

<div class="container-fluid px-4 py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="jobs.php">Jobs</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($job['job_title']); ?></li>
        </ol>
    </nav>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex mb-4">
                        <?php if (!empty($job['company_logo'])): ?>
                            <img src="../../../uploads/companies/<?php echo htmlspecialchars($job['company_logo']); ?>" 
                                 class="company-logo-large me-3" 
                                 alt="<?php echo htmlspecialchars($job['company_name']); ?>"
                                 onerror="this.onerror=null; this.outerHTML='<div class=\'company-logo-placeholder-large me-3\'><i class=\'fas fa-building\'></i></div>';">
                        <?php else: ?>
                            <div class="company-logo-placeholder-large me-3">
                                <i class="fas fa-building"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h2 class="mb-2"><?php echo htmlspecialchars($job['job_title']); ?></h2>
                            <h5 class="text-muted mb-3"><?php echo htmlspecialchars($job['company_name'] ?? 'Company'); ?></h5>
                            <div class="mb-2">
                                <span class="badge bg-primary me-2">
                                    <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($job['job_type']); ?>
                                </span>
                                <span class="badge bg-info me-2">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['location']); ?>
                                </span>
                                <?php if (!empty($job['category'])): ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($job['category']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <h4 class="mb-3">Job Description</h4>
                    <div class="job-description">
                        <?php echo nl2br(htmlspecialchars($job['description'])); ?>
                    </div>

                    <?php if (!empty($job['requirements'])): ?>
                        <hr>
                        <h4 class="mb-3">Requirements</h4>
                        <div class="job-requirements">
                            <?php echo nl2br(htmlspecialchars($job['requirements'])); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($job['benefits'])): ?>
                        <hr>
                        <h4 class="mb-3">Benefits</h4>
                        <div class="job-benefits">
                            <?php echo nl2br(htmlspecialchars($job['benefits'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Job Summary</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($job['salary_range'])): ?>
                        <div class="mb-3">
                            <strong><i class="fas fa-money-bill-wave text-success"></i> Salary</strong>
                            <p class="mb-0"><?php echo htmlspecialchars($job['salary_range']); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <strong><i class="fas fa-calendar"></i> Posted Date</strong>
                        <p class="mb-0"><?php echo date('F d, Y', strtotime($job['posted_date'])); ?></p>
                    </div>

                    <?php if (!empty($job['contact_email'])): ?>
                        <div class="mb-3">
                            <strong><i class="fas fa-envelope"></i> Email</strong>
                            <p class="mb-0"><?php echo htmlspecialchars($job['contact_email']); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($job['contact_phone'])): ?>
                        <div class="mb-3">
                            <strong><i class="fas fa-phone"></i> Phone</strong>
                            <p class="mb-0"><?php echo htmlspecialchars($job['contact_phone']); ?></p>
                        </div>
                    <?php endif; ?>

                    <hr>

                    <?php if ($job['has_applied'] > 0): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> You have already applied for this position
                        </div>
                        <a href="my-applications.php" class="btn btn-outline-primary w-100">
                            View My Applications
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#applyModal">
                            <i class="fas fa-paper-plane"></i> Apply Now
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">About Company</h5>
                </div>
                <div class="card-body">
                    <p><strong><?php echo htmlspecialchars($job['company_name'] ?? 'Company'); ?></strong></p>
                    <p class="text-muted">Contact the employer for more company information.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Apply Modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Apply for <?php echo htmlspecialchars($job['job_title']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Cover Letter <span class="text-danger">*</span></label>
                        <textarea name="cover_letter" class="form-control" rows="8" required 
                                  placeholder="Explain why you're a good fit for this position..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Upload Resume (PDF, DOC, DOCX)</label>
                        <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">
                        <small class="text-muted">Max file size: 5MB</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="apply" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>