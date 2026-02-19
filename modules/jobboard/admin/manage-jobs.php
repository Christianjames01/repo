<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Manage Jobs';
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_job'])) {
        $job_title = trim($_POST['job_title']);
        $company_name = trim($_POST['company_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $job_type = $_POST['job_type'] ?? 'Full-time';
        $location = trim($_POST['location']);
        $salary_range = trim($_POST['salary_range'] ?? '');
        $description = trim($_POST['description']);
        $requirements = trim($_POST['requirements'] ?? '');
        $responsibilities = trim($_POST['responsibilities'] ?? '');
        $benefits = trim($_POST['benefits'] ?? '');
        $application_deadline = $_POST['application_deadline'] ?? null;
        $contact_email = trim($_POST['contact_email'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        
        // Handle company logo upload
        $company_logo = null;
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../../uploads/company_logos/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Check file size (max 2MB)
                if ($_FILES['company_logo']['size'] <= 2097152) {
                    $company_logo = 'logo_' . time() . '_' . uniqid() . '.' . $file_extension;
                    move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_dir . $company_logo);
                } else {
                    $error = "Company logo must be less than 2MB";
                }
            } else {
                $error = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
            }
        }
        
        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO tbl_jobs (job_title, company_name, company_logo, category, job_type, location, salary_range, description, requirements, responsibilities, benefits, application_deadline, contact_email, contact_phone, posted_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $posted_by = getCurrentUserId();
            $stmt->bind_param("ssssssssssssssi", $job_title, $company_name, $company_logo, $category, $job_type, $location, $salary_range, $description, $requirements, $responsibilities, $benefits, $application_deadline, $contact_email, $contact_phone, $posted_by);
            
            if ($stmt->execute()) {
                $message = "Job posted successfully!";
            } else {
                $error = "Error posting job: " . $stmt->error;
            }
        }
    } elseif (isset($_POST['update_job'])) {
        $job_id = $_POST['job_id'];
        $job_title = trim($_POST['job_title']);
        $company_name = trim($_POST['company_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $job_type = $_POST['job_type'] ?? 'Full-time';
        $location = trim($_POST['location']);
        $salary_range = trim($_POST['salary_range'] ?? '');
        $description = trim($_POST['description']);
        $requirements = trim($_POST['requirements'] ?? '');
        $responsibilities = trim($_POST['responsibilities'] ?? '');
        $benefits = trim($_POST['benefits'] ?? '');
        $application_deadline = $_POST['application_deadline'] ?? null;
        $contact_email = trim($_POST['contact_email'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $status = $_POST['status'];
        
        // Get current logo
        $current_logo_query = $conn->prepare("SELECT company_logo FROM tbl_jobs WHERE job_id = ?");
        $current_logo_query->bind_param("i", $job_id);
        $current_logo_query->execute();
        $current_logo_result = $current_logo_query->get_result()->fetch_assoc();
        $company_logo = $current_logo_result['company_logo'];
        
        // Handle company logo upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../../uploads/company_logos/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Check file size (max 2MB)
                if ($_FILES['company_logo']['size'] <= 2097152) {
                    // Delete old logo if exists
                    if ($company_logo && file_exists($upload_dir . $company_logo)) {
                        unlink($upload_dir . $company_logo);
                    }
                    
                    $company_logo = 'logo_' . time() . '_' . uniqid() . '.' . $file_extension;
                    move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_dir . $company_logo);
                } else {
                    $error = "Company logo must be less than 2MB";
                }
            } else {
                $error = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
            }
        }
        
        // Handle logo removal
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1') {
            if ($company_logo && file_exists('../../../uploads/company_logos/' . $company_logo)) {
                unlink('../../../uploads/company_logos/' . $company_logo);
            }
            $company_logo = null;
        }
        
        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE tbl_jobs SET job_title=?, company_name=?, company_logo=?, category=?, job_type=?, location=?, salary_range=?, description=?, requirements=?, responsibilities=?, benefits=?, application_deadline=?, contact_email=?, contact_phone=?, status=? WHERE job_id=?");
            $stmt->bind_param("sssssssssssssssi", $job_title, $company_name, $company_logo, $category, $job_type, $location, $salary_range, $description, $requirements, $responsibilities, $benefits, $application_deadline, $contact_email, $contact_phone, $status, $job_id);
            
            if ($stmt->execute()) {
                $message = "Job updated successfully!";
            } else {
                $error = "Error updating job: " . $stmt->error;
            }
        }
    } elseif (isset($_POST['delete_job'])) {
        $job_id = $_POST['job_id'];
        
        // Get logo to delete
        $logo_query = $conn->prepare("SELECT company_logo FROM tbl_jobs WHERE job_id = ?");
        $logo_query->bind_param("i", $job_id);
        $logo_query->execute();
        $logo_result = $logo_query->get_result()->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM tbl_jobs WHERE job_id=?");
        $stmt->bind_param("i", $job_id);
        
        if ($stmt->execute()) {
            // Delete logo file if exists
            if ($logo_result['company_logo'] && file_exists('../../../uploads/company_logos/' . $logo_result['company_logo'])) {
                unlink('../../../uploads/company_logos/' . $logo_result['company_logo']);
            }
            $message = "Job deleted successfully!";
        } else {
            $error = "Error deleting job: " . $stmt->error;
        }
    }
}

// Get all jobs
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "SELECT j.*, 
          (SELECT COUNT(*) FROM tbl_job_applications WHERE job_id = j.job_id) as application_count
          FROM tbl_jobs j
          WHERE 1=1";

if ($search) {
    $query .= " AND (j.job_title LIKE '%" . $conn->real_escape_string($search) . "%' OR j.company_name LIKE '%" . $conn->real_escape_string($search) . "%')";
}
if ($category_filter) {
    $query .= " AND j.category = '" . $conn->real_escape_string($category_filter) . "'";
}
if ($status_filter) {
    $query .= " AND j.status = '" . $conn->real_escape_string($status_filter) . "'";
}

$query .= " ORDER BY j.created_at DESC";
$jobs = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

// Get categories
$categories = $conn->query("SELECT DISTINCT category FROM tbl_jobs WHERE category IS NOT NULL AND category != ''")->fetch_all(MYSQLI_ASSOC);

include '../../../includes/header.php';
?>

<style>
.company-logo-preview {
    max-width: 150px;
    max-height: 150px;
    object-fit: contain;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 10px;
    background: #f8f9fa;
}

.logo-upload-area {
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    background: #f8f9fa;
    transition: all 0.3s ease;
}

.logo-upload-area:hover {
    border-color: #0d6efd;
    background: #e7f1ff;
}

.logo-preview-container {
    position: relative;
    display: inline-block;
}

.logo-remove-btn {
    position: absolute;
    top: -10px;
    right: -10px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.table-logo {
    width: 50px;
    height: 50px;
    object-fit: contain;
    border-radius: 4px;
    background: #f8f9fa;
    padding: 5px;
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-briefcase"></i> Manage Job Posts</h2>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addJobModal">
                <i class="fas fa-plus-circle"></i> Post New Job
            </button>
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

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search jobs..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                        <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Jobs List -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Logo</th>
                            <th>Job Title</th>
                            <th>Company</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Applications</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobs)): ?>
                            <tr>
                                <td colspan="10" class="text-center">No jobs found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($job['company_logo'])): ?>
                                            <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($job['company_logo']); ?>" 
                                                 class="table-logo" alt="Logo">
                                        <?php else: ?>
                                            <div class="table-logo d-flex align-items-center justify-content-center">
                                                <i class="fas fa-building text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($job['job_title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($job['category'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($job['job_type']); ?></span></td>
                                    <td><?php echo htmlspecialchars($job['location']); ?></td>
                                    <td><span class="badge bg-primary"><?php echo $job['application_count']; ?></span></td>
                                    <td><?php echo $job['application_deadline'] ? date('M d, Y', strtotime($job['application_deadline'])) : 'N/A'; ?></td>
                                    <td>
                                        <?php
                                        $badge_class = match($job['status']) {
                                            'active' => 'success',
                                            'closed' => 'danger',
                                            'draft' => 'secondary',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($job['status']); ?></span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewJobModal<?php echo $job['job_id']; ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editJobModal<?php echo $job['job_id']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteJobModal<?php echo $job['job_id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- View Job Modal -->
                                <div class="modal fade" id="viewJobModal<?php echo $job['job_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-info text-white">
                                                <h5 class="modal-title"><i class="fas fa-eye"></i> Job Details</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-12 mb-3">
                                                        <?php if (!empty($job['company_logo'])): ?>
                                                            <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($job['company_logo']); ?>" 
                                                                 class="company-logo-preview mb-3" alt="Company Logo">
                                                        <?php endif; ?>
                                                        <h4><?php echo htmlspecialchars($job['job_title']); ?></h4>
                                                        <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($job['status']); ?></span>
                                                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($job['job_type']); ?></span>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Company:</strong>
                                                        <p><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Category:</strong>
                                                        <p><?php echo htmlspecialchars($job['category'] ?? 'N/A'); ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Location:</strong>
                                                        <p><?php echo htmlspecialchars($job['location']); ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Salary Range:</strong>
                                                        <p><?php echo htmlspecialchars($job['salary_range'] ?: 'Not specified'); ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Application Deadline:</strong>
                                                        <p><?php echo $job['application_deadline'] ? date('F d, Y', strtotime($job['application_deadline'])) : 'N/A'; ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Total Applications:</strong>
                                                        <p><span class="badge bg-primary"><?php echo $job['application_count']; ?></span></p>
                                                    </div>
                                                    <div class="col-12 mb-3">
                                                        <strong>Job Description:</strong>
                                                        <p><?php echo nl2br(htmlspecialchars($job['description'])); ?></p>
                                                    </div>
                                                    <div class="col-12 mb-3">
                                                        <strong>Requirements:</strong>
                                                        <p><?php echo nl2br(htmlspecialchars($job['requirements'] ?? 'N/A')); ?></p>
                                                    </div>
                                                    <?php if (!empty($job['responsibilities'])): ?>
                                                    <div class="col-12 mb-3">
                                                        <strong>Responsibilities:</strong>
                                                        <p><?php echo nl2br(htmlspecialchars($job['responsibilities'])); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($job['benefits'])): ?>
                                                    <div class="col-12 mb-3">
                                                        <strong>Benefits:</strong>
                                                        <p><?php echo nl2br(htmlspecialchars($job['benefits'])); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Contact Email:</strong>
                                                        <p><?php echo htmlspecialchars($job['contact_email'] ?? 'N/A'); ?></p>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <strong>Contact Phone:</strong>
                                                        <p><?php echo htmlspecialchars($job['contact_phone'] ?? 'N/A'); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Edit Job Modal -->
                                <div class="modal fade" id="editJobModal<?php echo $job['job_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-warning">
                                                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Job</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" enctype="multipart/form-data">
                                                <div class="modal-body">
                                                    <input type="hidden" name="job_id" value="<?php echo $job['job_id']; ?>">
                                                    
                                                    <!-- Company Logo Upload -->
                                                    <div class="mb-4">
                                                        <label class="form-label"><i class="fas fa-image"></i> Company Logo</label>
                                                        <?php if (!empty($job['company_logo'])): ?>
                                                            <div class="mb-3">
                                                                <div class="logo-preview-container">
                                                                    <img src="../../../uploads/company_logos/<?php echo htmlspecialchars($job['company_logo']); ?>" 
                                                                         class="company-logo-preview" alt="Current Logo" id="currentLogo<?php echo $job['job_id']; ?>">
                                                                </div>
                                                                <div class="form-check mt-2">
                                                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="removeLogo<?php echo $job['job_id']; ?>">
                                                                    <label class="form-check-label text-danger" for="removeLogo<?php echo $job['job_id']; ?>">
                                                                        Remove current logo
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="logo-upload-area">
                                                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                                                            <p class="mb-2">Upload New Company Logo</p>
                                                            <input type="file" name="company_logo" class="form-control" accept="image/*">
                                                            <small class="text-muted">JPG, PNG, GIF, WEBP (Max 2MB)</small>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Job Title *</label>
                                                            <input type="text" name="job_title" class="form-control" value="<?php echo htmlspecialchars($job['job_title']); ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Company *</label>
                                                            <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($job['company_name'] ?? ''); ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Category *</label>
                                                            <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($job['category'] ?? ''); ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Job Type *</label>
                                                            <select name="job_type" class="form-select" required>
                                                                <option value="Full-time" <?php echo $job['job_type'] == 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                                                                <option value="Part-time" <?php echo $job['job_type'] == 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                                                                <option value="Contract" <?php echo $job['job_type'] == 'Contract' ? 'selected' : ''; ?>>Contract</option>
                                                                <option value="Temporary" <?php echo $job['job_type'] == 'Temporary' ? 'selected' : ''; ?>>Temporary</option>
                                                                <option value="Internship" <?php echo $job['job_type'] == 'Internship' ? 'selected' : ''; ?>>Internship</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Location *</label>
                                                            <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($job['location']); ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Salary Range</label>
                                                            <input type="text" name="salary_range" class="form-control" value="<?php echo htmlspecialchars($job['salary_range'] ?? ''); ?>" placeholder="e.g., 15,000 - 25,000">
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Application Deadline *</label>
                                                            <input type="date" name="application_deadline" class="form-control" value="<?php echo $job['application_deadline']; ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Status *</label>
                                                            <select name="status" class="form-select" required>
                                                                <option value="active" <?php echo $job['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="closed" <?php echo $job['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                                                <option value="draft" <?php echo $job['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-12 mb-3">
                                                            <label class="form-label">Job Description *</label>
                                                            <textarea name="description" class="form-control" rows="4" required><?php echo htmlspecialchars($job['description']); ?></textarea>
                                                        </div>
                                                        <div class="col-12 mb-3">
                                                            <label class="form-label">Requirements</label>
                                                            <textarea name="requirements" class="form-control" rows="4"><?php echo htmlspecialchars($job['requirements'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div class="col-12 mb-3">
                                                            <label class="form-label">Responsibilities</label>
                                                            <textarea name="responsibilities" class="form-control" rows="4"><?php echo htmlspecialchars($job['responsibilities'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div class="col-12 mb-3">
                                                            <label class="form-label">Benefits</label>
                                                            <textarea name="benefits" class="form-control" rows="3"><?php echo htmlspecialchars($job['benefits'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Contact Email</label>
                                                            <input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($job['contact_email'] ?? ''); ?>">
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Contact Phone</label>
                                                            <input type="text" name="contact_phone" class="form-control" value="<?php echo htmlspecialchars($job['contact_phone'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="update_job" class="btn btn-warning">Update Job</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Job Modal -->
                                <div class="modal fade" id="deleteJobModal<?php echo $job['job_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Job</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="job_id" value="<?php echo $job['job_id']; ?>">
                                                    <p>Are you sure you want to delete this job posting?</p>
                                                    <div class="alert alert-warning">
                                                        <strong>Job:</strong> <?php echo htmlspecialchars($job['job_title']); ?><br>
                                                        <strong>Company:</strong> <?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?><br>
                                                        <strong>Applications:</strong> <?php echo $job['application_count']; ?>
                                                    </div>
                                                    <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone and will also delete all applications for this job!</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="delete_job" class="btn btn-danger">Delete Job</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Job Modal -->
<div class="modal fade" id="addJobModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Post New Job</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <!-- Company Logo Upload -->
                    <div class="mb-4">
                        <label class="form-label"><i class="fas fa-image"></i> Company Logo (Optional)</label>
                        <div class="logo-upload-area">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                            <p class="mb-2">Click to upload company logo</p>
                            <input type="file" name="company_logo" class="form-control" accept="image/*">
                            <small class="text-muted">JPG, PNG, GIF, WEBP (Max 2MB)</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Job Title *</label>
                            <input type="text" name="job_title" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company *</label>
                            <input type="text" name="company_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g., IT, Healthcare, Sales" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Job Type *</label>
                            <select name="job_type" class="form-select" required>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Temporary">Temporary</option>
                                <option value="Internship">Internship</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Salary Range</label>
                            <input type="text" name="salary_range" class="form-control" placeholder="e.g., ₱15,000 - ₱25,000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Application Deadline *</label>
                            <input type="date" name="application_deadline" class="form-control" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Job Description *</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Describe the job role and what the position entails..." required></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Requirements</label>
                            <textarea name="requirements" class="form-control" rows="4" placeholder="List the qualifications and requirements..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Responsibilities</label>
                            <textarea name="responsibilities" class="form-control" rows="4" placeholder="List the key responsibilities..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Benefits</label>
                            <textarea name="benefits" class="form-control" rows="3" placeholder="List the benefits offered..."></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Email</label>
                            <input type="email" name="contact_email" class="form-control" placeholder="hr@company.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" name="contact_phone" class="form-control" placeholder="09XX XXX XXXX">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_job" class="btn btn-primary">Post Job</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>