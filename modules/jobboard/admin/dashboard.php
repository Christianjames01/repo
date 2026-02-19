<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

// Check if user is logged in and has admin privileges
if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Job Board Dashboard';

// Get statistics
$stats = [];

// Total jobs
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_jobs WHERE status = 'active'");
$stmt->execute();
$stats['total_jobs'] = $stmt->get_result()->fetch_assoc()['total'];

// Total applications
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_job_applications");
$stmt->execute();
$stats['total_applications'] = $stmt->get_result()->fetch_assoc()['total'];

// Pending applications
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_job_applications WHERE status = 'pending'");
$stmt->execute();
$stats['pending_applications'] = $stmt->get_result()->fetch_assoc()['total'];

// Active trainings
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_trainings WHERE status = 'active'");
$stmt->execute();
$stats['active_trainings'] = $stmt->get_result()->fetch_assoc()['total'];

// Active livelihood programs
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_livelihood_programs WHERE status = 'active'");
$stmt->execute();
$stats['active_programs'] = $stmt->get_result()->fetch_assoc()['total'];

// Recent applications
// First, check what columns exist in tbl_job_applications
$recent_applications = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            ja.application_id,
            ja.application_date,
            ja.status,
            j.job_title,
            COALESCE(r.first_name, u.username) as first_name,
            COALESCE(r.last_name, '') as last_name
        FROM tbl_job_applications ja
        JOIN tbl_jobs j ON ja.job_id = j.job_id
        LEFT JOIN tbl_users u ON ja.user_id = u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        ORDER BY ja.application_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    // If the query fails, try alternative structure
    try {
        $stmt = $conn->prepare("
            SELECT 
                ja.application_id,
                ja.application_date,
                ja.status,
                j.job_title,
                ja.applicant_name as first_name,
                '' as last_name
            FROM tbl_job_applications ja
            JOIN tbl_jobs j ON ja.job_id = j.job_id
            ORDER BY ja.application_date DESC
            LIMIT 10
        ");
        $stmt->execute();
        $recent_applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e2) {
        // Log error and continue with empty applications
        error_log("Job applications query failed: " . $e2->getMessage());
    }
}

// Jobs by category
$stmt = $conn->prepare("
    SELECT 
        category,
        COUNT(*) as count
    FROM tbl_jobs
    WHERE status = 'active'
    GROUP BY category
");
$stmt->execute();
$jobs_by_category = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-briefcase"></i> Job Board Dashboard</h2>
            <p class="text-muted">Manage jobs, applications, trainings, and livelihood programs</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Active Jobs</h6>
                            <h2 class="mb-0"><?php echo $stats['total_jobs']; ?></h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                            <i class="fas fa-briefcase fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Total Applications</h6>
                            <h2 class="mb-0"><?php echo $stats['total_applications']; ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded">
                            <i class="fas fa-file-alt fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Pending Applications</h6>
                            <h2 class="mb-0"><?php echo $stats['pending_applications']; ?></h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                            <i class="fas fa-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Active Trainings</h6>
                            <h2 class="mb-0"><?php echo $stats['active_trainings']; ?></h2>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded">
                            <i class="fas fa-chalkboard-teacher fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Livelihood Programs</h6>
                            <h2 class="mb-0"><?php echo $stats['active_programs']; ?></h2>
                        </div>
                        <div class="bg-secondary bg-opacity-10 p-3 rounded">
                            <i class="fas fa-hands-helping fa-2x text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Applications -->
        <div class="col-md-8 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Recent Applications</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Job Title</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_applications)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No applications found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_applications as $app): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($app['job_title']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                            <td>
                                                <?php
                                                $badge_class = match($app['status']) {
                                                    'pending' => 'warning',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    default => 'secondary'
                                                };
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($app['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="applications.php?id=<?php echo $app['application_id']; ?>" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Jobs by Category -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Jobs by Category</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($jobs_by_category)): ?>
                        <p class="text-center text-muted">No jobs available</p>
                    <?php else: ?>
                        <?php foreach ($jobs_by_category as $cat): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?php echo htmlspecialchars($cat['category'] ?: 'Uncategorized'); ?></span>
                                    <strong><?php echo $cat['count']; ?></strong>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?php echo ($cat['count'] / $stats['total_jobs']) * 100; ?>%">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="manage-jobs.php?action=add" class="btn btn-primary w-100">
                                <i class="fas fa-plus-circle"></i> Post New Job
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="applications.php" class="btn btn-info w-100">
                                <i class="fas fa-clipboard-list"></i> View Applications
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="trainings.php?action=add" class="btn btn-success w-100">
                                <i class="fas fa-chalkboard-teacher"></i> Add Training
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="livelihood.php?action=add" class="btn btn-warning w-100">
                                <i class="fas fa-hands-helping"></i> Add Program
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>