<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../config/database.php';
require_once '../../../config/auth.php';

requireLogin();

if (!hasRole(['Resident'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'My Job Applications';
$current_user_id = getCurrentUserId();

// Get user info for header
$user_full_name = $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'Resident';

// Get all applications
$stmt = $conn->prepare("
    SELECT ja.*, j.job_title, j.job_type, j.location, c.company_name, c.company_logo
    FROM tbl_job_applications ja
    INNER JOIN tbl_jobs j ON ja.job_id = j.job_id
    LEFT JOIN tbl_companies c ON j.company_id = c.company_id
    WHERE ja.applicant_id = ?
    ORDER BY ja.application_date DESC
");

if (!$stmt) {
    die("Database error: Unable to prepare statement");
}

$stmt->bind_param("i", $current_user_id);
if (!$stmt->execute()) {
    die("Database error: Unable to execute query");
}

$applications = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Barangay Management</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/barangaylink1/assets/css/sidebar-layout.css">
    
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
</head>
<body class="sidebar-open">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="/barangaylink1/uploads/officials/brgy.png" alt="Brgy Centro">
            </div>
            <span class="sidebar-title">Brgy Centro</span>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Main</div>
                <div class="nav-item">
                    <a href="/barangaylink1/modules/dashboard/index.php" class="nav-link">
                        <i class="fas fa-th-large"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Jobs & Livelihood</div>
                <div class="nav-item">
                    <a href="/barangaylink1/modules/jobboard/resident/jobs.php" class="nav-link">
                        <i class="fas fa-search"></i>
                        <span>Browse Jobs</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="/barangaylink1/modules/jobboard/resident/my-applications.php" class="nav-link active">
                        <i class="fas fa-clipboard-list"></i>
                        <span>My Job Applications</span>
                    </a>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Account</div>
                <div class="nav-item">
                    <a href="/barangaylink1/modules/residents/profile.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>Profile</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="/barangaylink1/modules/auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </nav>
    </aside>
    
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <div class="header-right">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user_full_name, 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container-fluid px-4 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-clipboard-list me-2"></i>My Job Applications
                    </h1>
                    <p class="text-muted mb-0">Track the status of your job applications</p>
                </div>
                <a href="jobs.php" class="btn btn-primary">
                    <i class="fas fa-search me-2"></i> Browse Jobs
                </a>
            </div>

            <?php if ($applications->num_rows > 0): ?>
                <div class="row">
                    <?php while ($app = $applications->fetch_assoc()): ?>
                        <div class="col-12 mb-3">
                            <div class="card application-card">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <!-- Company Logo -->
                                        <div class="col-md-1 text-center mb-3 mb-md-0">
                                            <?php if ($app['company_logo']): ?>
                                                <img src="../../../uploads/companies/<?php echo htmlspecialchars($app['company_logo']); ?>" 
                                                     class="company-logo-sm" alt="Company Logo">
                                            <?php else: ?>
                                                <div class="company-logo-placeholder-sm">
                                                    <i class="fas fa-building"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Job Info -->
                                        <div class="col-md-5 mb-3 mb-md-0">
                                            <h5 class="mb-1"><?php echo htmlspecialchars($app['job_title']); ?></h5>
                                            <p class="text-muted mb-2"><?php echo htmlspecialchars($app['company_name']); ?></p>
                                            <div>
                                                <span class="badge bg-secondary me-1">
                                                    <i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($app['job_type']); ?>
                                                </span>
                                                <span class="badge bg-info">
                                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($app['location']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <!-- Application Date -->
                                        <div class="col-md-2 text-center mb-3 mb-md-0">
                                            <small class="text-muted d-block">Applied on</small>
                                            <strong><?php echo date('M d, Y', strtotime($app['application_date'])); ?></strong>
                                        </div>
                                        
                                        <!-- Status -->
                                        <div class="col-md-2 text-center mb-3 mb-md-0">
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
                                        
                                        <!-- Actions -->
                                        <div class="col-md-2 text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $app['application_id']; ?>">
                                                <i class="fas fa-eye me-1"></i> View Details
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
                                        <h5 class="modal-title">
                                            <i class="fas fa-file-alt me-2"></i>Application Details
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-4">
                                            <h6 class="fw-bold text-primary">Job Position</h6>
                                            <p class="mb-0"><?php echo htmlspecialchars($app['job_title']); ?> at <strong><?php echo htmlspecialchars($app['company_name']); ?></strong></p>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <h6 class="fw-bold text-primary">Application Date</h6>
                                            <p class="mb-0"><?php echo date('F d, Y h:i A', strtotime($app['application_date'])); ?></p>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <h6 class="fw-bold text-primary">Status</h6>
                                            <p><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($app['status']); ?></span></p>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <h6 class="fw-bold text-primary">Cover Letter</h6>
                                            <div class="p-3 bg-light rounded">
                                                <?php echo nl2br(htmlspecialchars($app['cover_letter'])); ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($app['resume_file']): ?>
                                            <div class="mb-4">
                                                <h6 class="fw-bold text-primary">Resume</h6>
                                                <a href="../../../uploads/resumes/<?php echo htmlspecialchars($app['resume_file']); ?>" 
                                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="fas fa-download me-1"></i> Download Resume
                                                </a>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($app['notes']): ?>
                                            <div class="mb-4">
                                                <h6 class="fw-bold text-primary">Employer Notes</h6>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <?php echo nl2br(htmlspecialchars($app['notes'])); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                            <i class="fas fa-times me-1"></i> Close
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <!-- Empty State -->
                <div class="card">
                    <div class="card-body empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4 class="mb-3">No Applications Yet</h4>
                        <p class="text-muted mb-4">You haven't applied for any jobs yet. Start browsing available opportunities!</p>
                        <a href="jobs.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-search me-2"></i> Browse Available Jobs
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Sidebar Toggle Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const body = document.body;
            
            const sidebarState = localStorage.getItem('sidebarCollapsed');
            
            if (sidebarState === 'true') {
                body.classList.add('sidebar-collapsed');
            } else {
                body.classList.remove('sidebar-collapsed');
            }
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    body.classList.toggle('sidebar-collapsed');
                    const isCollapsed = body.classList.contains('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', isCollapsed);
                });
            }
        });
    </script>
</body>
</html>