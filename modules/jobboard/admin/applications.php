<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Job Applications';
$message = '';
$error = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $application_id = $_POST['application_id'];
    $new_status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    
    $stmt = $conn->prepare("UPDATE tbl_job_applications SET status = ?, notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE application_id = ?");
    $reviewed_by = getCurrentUserId();
    $stmt->bind_param("ssii", $new_status, $notes, $reviewed_by, $application_id);
    
    if ($stmt->execute()) {
        $message = "Application status updated successfully!";
    } else {
        $error = "Error updating status: " . $stmt->error;
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$job_filter = isset($_GET['job_id']) ? $_GET['job_id'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$query = "
    SELECT ja.*, j.job_title, j.company_name, j.company_logo, j.job_type, j.location,
           u.username, r.first_name, r.last_name, r.email as resident_email, r.contact_number
    FROM tbl_job_applications ja
    INNER JOIN tbl_jobs j ON ja.job_id = j.job_id
    LEFT JOIN tbl_users u ON ja.applicant_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE 1=1
";

if ($search) {
    $query .= " AND (ja.applicant_name LIKE '%" . $conn->real_escape_string($search) . "%' 
                OR j.job_title LIKE '%" . $conn->real_escape_string($search) . "%' 
                OR j.company_name LIKE '%" . $conn->real_escape_string($search) . "%')";
}

if ($job_filter) {
    $query .= " AND ja.job_id = " . intval($job_filter);
}

if ($status_filter) {
    $query .= " AND ja.status = '" . $conn->real_escape_string($status_filter) . "'";
}

$query .= " ORDER BY ja.application_date DESC";

$applications = $conn->query($query);

// Get jobs for filter
$jobs = $conn->query("SELECT job_id, job_title, company_name FROM tbl_jobs ORDER BY job_title");

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Reviewed' THEN 1 ELSE 0 END) as reviewed,
        SUM(CASE WHEN status = 'Shortlisted' THEN 1 ELSE 0 END) as shortlisted,
        SUM(CASE WHEN status = 'Accepted' THEN 1 ELSE 0 END) as accepted,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM tbl_job_applications
")->fetch_assoc();

include '../../../includes/header.php';
?>

<style>
.stats-card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stats-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.application-card {
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}

.application-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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

.company-logo-preview {
    max-width: 120px;
    max-height: 120px;
    object-fit: contain;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 10px;
    background: #f8f9fa;
}

.badge {
    padding: 6px 12px;
    font-weight: 500;
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-file-alt"></i> Job Applications</h2>
            <p class="text-muted">Manage and review job applications</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="manage-jobs.php" class="btn btn-outline-primary">
                <i class="fas fa-briefcase"></i> Manage Jobs
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-primary text-white me-3">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-muted">Total</h6>
                            <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-warning text-white me-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-muted">Pending</h6>
                            <h3 class="mb-0"><?php echo $stats['pending']; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-info text-white me-3">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-muted">Reviewed</h6>
                            <h3 class="mb-0"><?php echo $stats['reviewed']; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon" style="background: #6f42c1; color: white;">
                            <i class="fas fa-star"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-muted">Shortlisted</h6>
                            <h3 class="mb-0"><?php echo $stats['shortlisted']; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-success text-white me-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-muted">Accepted</h6>
                            <h3 class="mb-0"><?php echo $stats['accepted']; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-danger text-white me-3">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-muted">Rejected</h6>
                            <h3 class="mb-0"><?php echo $stats['rejected']; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search applicants or jobs..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="job_id" class="form-select">
                        <option value="">All Jobs</option>
                        <?php while ($job = $jobs->fetch_assoc()): ?>
                            <option value="<?php echo $job['job_id']; ?>" <?php echo $job_filter == $job['job_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($job['job_title']) . ' - ' . htmlspecialchars($job['company_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Reviewed" <?php echo $status_filter == 'Reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                        <option value="Shortlisted" <?php echo $status_filter == 'Shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                        <option value="Accepted" <?php echo $status_filter == 'Accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Applications List -->
    <?php if ($applications->num_rows > 0): ?>
        <div class="row">
            <?php while ($app = $applications->fetch_assoc()): ?>
                <div class="col-12 mb-3">
                    <div class="card application-card">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-1 text-center">
                                    <?php if (!empty($app['company_logo'])): ?>
                                        <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($app['company_logo']); ?>" 
                                             class="company-logo-sm" alt="Company Logo">
                                    <?php else: ?>
                                        <div class="company-logo-sm d-flex align-items-center justify-content-center">
                                            <i class="fas fa-building text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($app['applicant_name']); ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($app['email'] ?? $app['resident_email'] ?? 'N/A'); ?>
                                    </small><br>
                                    <small class="text-muted">
                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($app['phone'] ?? $app['contact_number'] ?? 'N/A'); ?>
                                    </small>
                                </div>
                                <div class="col-md-3">
                                    <strong><?php echo htmlspecialchars($app['job_title']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($app['company_name']); ?></small><br>
                                    <span class="badge bg-secondary">
                                        <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($app['job_type']); ?>
                                    </span>
                                </div>
                                <div class="col-md-2 text-center">
                                    <small class="text-muted d-block">Applied</small>
                                    <strong><?php echo date('M d, Y', strtotime($app['application_date'])); ?></strong>
                                </div>
                                <div class="col-md-2 text-center">
                                    <?php
                                    $status_class = match($app['status']) {
                                        'Pending' => 'bg-warning text-dark',
                                        'Reviewed' => 'bg-info',
                                        'Shortlisted' => 'bg-primary',
                                        'Accepted' => 'bg-success',
                                        'Rejected' => 'bg-danger',
                                        'Withdrawn' => 'bg-secondary',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($app['status']); ?>
                                    </span>
                                </div>
                                <div class="col-md-1 text-end">
                                    <button class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#viewModal<?php echo $app['application_id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View/Edit Application Modal -->
                <div class="modal fade" id="viewModal<?php echo $app['application_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title"><i class="fas fa-file-alt"></i> Application Details</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Company Logo -->
                                <?php if (!empty($app['company_logo'])): ?>
                                    <div class="text-center mb-3">
                                        <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($app['company_logo']); ?>" 
                                             class="company-logo-preview" alt="Company Logo">
                                    </div>
                                <?php endif; ?>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <h6>Applicant Name</h6>
                                        <p><?php echo htmlspecialchars($app['applicant_name']); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6>Email</h6>
                                        <p><?php echo htmlspecialchars($app['email'] ?? $app['resident_email'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6>Phone</h6>
                                        <p><?php echo htmlspecialchars($app['phone'] ?? $app['contact_number'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6>Application Date</h6>
                                        <p><?php echo date('F d, Y h:i A', strtotime($app['application_date'])); ?></p>
                                    </div>
                                </div>

                                <hr>

                                <div class="mb-3">
                                    <h6>Job Position</h6>
                                    <p><strong><?php echo htmlspecialchars($app['job_title']); ?></strong> at <?php echo htmlspecialchars($app['company_name']); ?></p>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($app['job_type']); ?></span>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($app['location']); ?></span>
                                </div>

                                <div class="mb-3">
                                    <h6>Cover Letter</h6>
                                    <div class="border rounded p-3 bg-light">
                                        <?php echo nl2br(htmlspecialchars($app['cover_letter'] ?? 'N/A')); ?>
                                    </div>
                                </div>

                                <?php if (!empty($app['resume_file'])): ?>
                                    <div class="mb-3">
                                        <h6>Resume</h6>
                                        <a href="../../../uploads/resumes/<?php echo htmlspecialchars($app['resume_file']); ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-download"></i> Download Resume
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <hr>

                                <!-- Update Status Form -->
                                <form method="POST">
                                    <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label"><strong>Update Status</strong></label>
                                            <select name="status" class="form-select" required>
                                                <option value="Pending" <?php echo $app['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="Reviewed" <?php echo $app['status'] == 'Reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                                <option value="Shortlisted" <?php echo $app['status'] == 'Shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                                                <option value="Accepted" <?php echo $app['status'] == 'Accepted' ? 'selected' : ''; ?>>Accepted</option>
                                                <option value="Rejected" <?php echo $app['status'] == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            </select>
                                        </div>
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label"><strong>Notes (Optional)</strong></label>
                                            <textarea name="notes" class="form-control" rows="3" placeholder="Add notes about this application..."><?php echo htmlspecialchars($app['notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <?php if (!empty($app['notes'])): ?>
                                        <div class="alert alert-info">
                                            <strong>Current Notes:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($app['notes'])); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-grid">
                                        <button type="submit" name="update_status" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Update Status & Notes
                                        </button>
                                    </div>
                                </form>
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
                <h4>No Applications Found</h4>
                <p class="text-muted">No job applications match your current filters.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../../includes/footer.php'; ?>