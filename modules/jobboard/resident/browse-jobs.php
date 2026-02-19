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

$page_title = 'Browse Jobs';
$current_user_id = getCurrentUserId();

// Handle job application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {
    $job_id = intval($_POST['job_id']);
    $cover_letter = trim($_POST['cover_letter']);
    
    // Handle resume upload
    $resume_file = null;
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../../uploads/resumes/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
        $allowed_extensions = ['pdf', 'doc', 'docx'];
        
        if (in_array(strtolower($file_extension), $allowed_extensions)) {
            $resume_file = 'resume_' . $current_user_id . '_' . time() . '.' . $file_extension;
            move_uploaded_file($_FILES['resume']['tmp_name'], $upload_dir . $resume_file);
        }
    }
    
    // Get user details
    $stmt = $conn->prepare("
        SELECT u.username, u.resident_id, r.first_name, r.last_name, r.email, r.contact_number
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    
    $applicant_name = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
    if (empty($applicant_name)) {
        $applicant_name = $user_data['username'];
    }
    
    // Insert application
    $stmt = $conn->prepare("
        INSERT INTO tbl_job_applications 
        (job_id, applicant_id, applicant_name, email, phone, resume_file, cover_letter, application_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending')
    ");
    
    $stmt->bind_param(
        "iisssss",
        $job_id,
        $current_user_id,
        $applicant_name,
        $user_data['email'],
        $user_data['contact_number'],
        $resume_file,
        $cover_letter
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Application submitted successfully!";
        header("Location: browse-jobs.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to submit application. Please try again.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$job_type = isset($_GET['job_type']) ? trim($_GET['job_type']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';

// Build query - FIXED: Get company_logo directly from tbl_jobs
$query = "
    SELECT j.*, 
           (SELECT COUNT(*) FROM tbl_job_applications ja WHERE ja.job_id = j.job_id AND ja.applicant_id = ?) as has_applied
    FROM tbl_jobs j
    WHERE j.status = 'active'
    AND (j.application_deadline IS NULL OR j.application_deadline >= CURDATE())
";

$params = [$current_user_id];
$types = "i";

if (!empty($search)) {
    $query .= " AND (j.job_title LIKE ? OR j.description LIKE ? OR j.company_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($category)) {
    $query .= " AND j.category = ?";
    $params[] = $category;
    $types .= "s";
}

if (!empty($job_type)) {
    $query .= " AND j.job_type = ?";
    $params[] = $job_type;
    $types .= "s";
}

if (!empty($location)) {
    $query .= " AND j.location LIKE ?";
    $location_param = "%$location%";
    $params[] = $location_param;
    $types .= "s";
}

$query .= " ORDER BY j.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$jobs = $stmt->get_result();

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM tbl_jobs WHERE category IS NOT NULL AND category != '' AND status = 'active'";
$categories_result = $conn->query($categories_query);

include '../../../includes/header.php';
?>

<style>
.job-card {
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    height: 100%;
}

.job-card:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    transform: translateY(-5px);
}

.company-logo {
    width: 80px;
    height: 80px;
    object-fit: contain;
    border: 1px solid #dee2e6;
    border-radius: 12px;
    padding: 10px;
    background: white;
}

.company-logo-placeholder {
    width: 80px;
    height: 80px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #6c757d;
}

.filter-card {
    background: #f8f9fa;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}

.job-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

.job-meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.9rem;
    color: #6c757d;
}

.salary-badge {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 8px 15px;
    border-radius: 20px;
    font-weight: 600;
}

.deadline-badge {
    background: #fff3e0;
    color: #e65100;
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 0.85rem;
}

.already-applied {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 4px;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Browse Jobs</h1>
            <p class="text-muted">Find and apply for job opportunities</p>
        </div>
        <a href="jobs.php" class="btn btn-outline-primary">
            <i class="fas fa-clipboard-list"></i> My Applications
        </a>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card filter-card mb-4">
        <div class="card-body">
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control" placeholder="Search jobs..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php while ($cat = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>"
                                        <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="job_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="Full-time" <?php echo $job_type === 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                            <option value="Part-time" <?php echo $job_type === 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                            <option value="Contract" <?php echo $job_type === 'Contract' ? 'selected' : ''; ?>>Contract</option>
                            <option value="Temporary" <?php echo $job_type === 'Temporary' ? 'selected' : ''; ?>>Temporary</option>
                            <option value="Internship" <?php echo $job_type === 'Internship' ? 'selected' : ''; ?>>Internship</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="location" class="form-control" placeholder="Location" 
                               value="<?php echo htmlspecialchars($location); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Jobs Grid -->
    <?php if ($jobs->num_rows > 0): ?>
        <div class="row">
            <?php while ($job = $jobs->fetch_assoc()): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card job-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start mb-3">
                                <div class="me-3">
                                    <?php if (!empty($job['company_logo'])): ?>
                                        <!-- FIXED: Changed path from uploads/companies/ to uploads/company_logos/ -->
                                        <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($job['company_logo']); ?>" 
                                             class="company-logo" alt="Company Logo">
                                    <?php else: ?>
                                        <div class="company-logo-placeholder">
                                            <i class="fas fa-building"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1"><?php echo htmlspecialchars($job['job_title']); ?></h5>
                                    <p class="text-muted mb-0"><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></p>
                                </div>
                            </div>

                            <?php if ($job['has_applied'] > 0): ?>
                                <div class="already-applied">
                                    <i class="fas fa-check-circle text-primary"></i> 
                                    <strong>Already Applied</strong>
                                </div>
                            <?php endif; ?>

                            <div class="job-meta">
                                <div class="job-meta-item">
                                    <i class="fas fa-briefcase"></i>
                                    <span><?php echo htmlspecialchars($job['job_type']); ?></span>
                                </div>
                                <div class="job-meta-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($job['location']); ?></span>
                                </div>
                                <?php if (!empty($job['category'])): ?>
                                    <div class="job-meta-item">
                                        <i class="fas fa-tag"></i>
                                        <span><?php echo htmlspecialchars($job['category']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($job['salary_range'])): ?>
                                <div class="mt-3">
                                    <span class="salary-badge">
                                        <i class="fas fa-money-bill-wave"></i> 
                                        <?php echo htmlspecialchars($job['salary_range']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <p class="mt-3 mb-3" style="color: #555; line-height: 1.6;">
                                <?php 
                                $description = strip_tags($job['description']);
                                echo htmlspecialchars(substr($description, 0, 150)) . (strlen($description) > 150 ? '...' : ''); 
                                ?>
                            </p>

                            <?php if (!empty($job['application_deadline'])): ?>
                                <div class="mb-3">
                                    <span class="deadline-badge">
                                        <i class="fas fa-calendar-alt"></i> 
                                        Deadline: <?php echo date('M d, Y', strtotime($job['application_deadline'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1" 
                                        data-bs-toggle="modal" data-bs-target="#viewJobModal<?php echo $job['job_id']; ?>">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                                <?php if ($job['has_applied'] == 0): ?>
                                    <button type="button" class="btn btn-primary btn-sm flex-grow-1" 
                                            data-bs-toggle="modal" data-bs-target="#applyModal<?php echo $job['job_id']; ?>">
                                        <i class="fas fa-paper-plane"></i> Apply Now
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-sm flex-grow-1" disabled>
                                        <i class="fas fa-check"></i> Applied
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Job Modal -->
                <div class="modal fade" id="viewJobModal<?php echo $job['job_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><?php echo htmlspecialchars($job['job_title']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- FIXED: Show company logo in modal -->
                                <?php if (!empty($job['company_logo'])): ?>
                                    <div class="mb-3 text-center">
                                        <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($job['company_logo']); ?>" 
                                             style="max-width: 150px; max-height: 150px; object-fit: contain; border: 2px solid #dee2e6; border-radius: 8px; padding: 10px; background: #f8f9fa;" 
                                             alt="Company Logo">
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <h6>Company</h6>
                                    <p><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></p>
                                </div>

                                <div class="mb-3">
                                    <h6>Job Type & Location</h6>
                                    <p>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($job['job_type']); ?></span>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($job['location']); ?></span>
                                        <?php if (!empty($job['category'])): ?>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($job['category']); ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <?php if (!empty($job['salary_range'])): ?>
                                    <div class="mb-3">
                                        <h6>Salary Range</h6>
                                        <p><?php echo htmlspecialchars($job['salary_range']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <h6>Job Description</h6>
                                    <p><?php echo nl2br(htmlspecialchars($job['description'])); ?></p>
                                </div>

                                <?php if (!empty($job['requirements'])): ?>
                                    <div class="mb-3">
                                        <h6>Requirements</h6>
                                        <p><?php echo nl2br(htmlspecialchars($job['requirements'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($job['responsibilities'])): ?>
                                    <div class="mb-3">
                                        <h6>Responsibilities</h6>
                                        <p><?php echo nl2br(htmlspecialchars($job['responsibilities'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($job['benefits'])): ?>
                                    <div class="mb-3">
                                        <h6>Benefits</h6>
                                        <p><?php echo nl2br(htmlspecialchars($job['benefits'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($job['contact_email']) || !empty($job['contact_phone'])): ?>
                                    <div class="mb-3">
                                        <h6>Contact Information</h6>
                                        <?php if (!empty($job['contact_email'])): ?>
                                            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($job['contact_email']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($job['contact_phone'])): ?>
                                            <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($job['contact_phone']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($job['application_deadline'])): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-calendar-alt"></i> 
                                        <strong>Application Deadline:</strong> <?php echo date('F d, Y', strtotime($job['application_deadline'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if ($job['has_applied'] == 0): ?>
                                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal"
                                            data-bs-toggle="modal" data-bs-target="#applyModal<?php echo $job['job_id']; ?>">
                                        <i class="fas fa-paper-plane"></i> Apply for this Job
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Apply Modal -->
                <?php if ($job['has_applied'] == 0): ?>
                    <div class="modal fade" id="applyModal<?php echo $job['job_id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Apply for <?php echo htmlspecialchars($job['job_title']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="modal-body">
                                        <input type="hidden" name="job_id" value="<?php echo $job['job_id']; ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Resume (PDF, DOC, DOCX) *</label>
                                            <input type="file" name="resume" class="form-control" 
                                                   accept=".pdf,.doc,.docx" required>
                                            <small class="text-muted">Maximum file size: 5MB</small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Cover Letter *</label>
                                            <textarea name="cover_letter" class="form-control" rows="5" 
                                                      placeholder="Tell us why you're a great fit for this position..." required></textarea>
                                        </div>

                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> 
                                            Your contact information will be automatically included from your profile.
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
                <?php endif; ?>

            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-search fa-4x text-muted mb-3"></i>
                <h4>No Jobs Found</h4>
                <p class="text-muted">Try adjusting your search filters or check back later for new opportunities.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../../includes/footer.php'; ?>