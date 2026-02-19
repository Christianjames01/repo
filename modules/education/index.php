<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Education Assistance';

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN scholarship_status = 'active' THEN 1 ELSE 0 END) as active_scholars,
    SUM(CASE WHEN assistance_status = 'pending' THEN 1 ELSE 0 END) as pending_assistance,
    COUNT(DISTINCT student_id) as unique_students
    FROM tbl_education_students";
$stats = fetchOne($conn, $stats_sql);

// Get recent applications
$recent_sql = "SELECT es.*, r.first_name, r.last_name, r.contact_number
               FROM tbl_education_students es
               LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id
               ORDER BY es.application_date DESC
               LIMIT 10";
$recent_applications = fetchAll($conn, $recent_sql);

// Get scholarship summary
$scholarship_sql = "SELECT scholarship_type, COUNT(*) as count, SUM(scholarship_amount) as total_amount
                    FROM tbl_education_students
                    WHERE scholarship_status = 'active'
                    GROUP BY scholarship_type";
$scholarship_summary = fetchAll($conn, $scholarship_sql);

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
}

/* Quick Action Cards */
.quick-action-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
    border: 2px solid #e9ecef;
    border-radius: var(--border-radius);
    padding: 1rem;
    text-decoration: none;
    display: block;
    color: inherit;
}

.quick-action-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
    border-color: #0d6efd;
    color: inherit;
}

/* Table Enhancements */
.table {
    margin-bottom: 0;
}

.table thead th {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 700;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
    transform: scale(1.01);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Enhanced Buttons */
.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn:active {
    transform: translateY(0);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

/* Scholarship Summary Items */
.scholarship-item {
    padding: 1rem;
    border-bottom: 1px solid #e9ecef;
    transition: all var(--transition-speed) ease;
}

.scholarship-item:last-child {
    border-bottom: none;
}

.scholarship-item:hover {
    background: #f8f9fa;
    padding-left: 1.5rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-graduation-cap me-2 text-primary"></i>
                        Education Assistance Management
                    </h2>
                    <p class="text-muted mb-0">Manage student scholarships and educational support programs</p>
                </div>
                <div>
                    <a href="add-student.php" class="btn btn-primary">
                        <i class="fas fa-user-plus me-1"></i>Add Student
                    </a>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-chart-bar me-1"></i>Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total Students</p>
                            <h3 class="mb-0"><?php echo $stats['total_students'] ?? 0; ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-user-graduate fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Active Scholars</p>
                            <h3 class="mb-0 text-success"><?php echo $stats['active_scholars'] ?? 0; ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-certificate fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Pending Assistance</p>
                            <h3 class="mb-0 text-warning"><?php echo $stats['pending_assistance'] ?? 0; ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                            <i class="fas fa-clock fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Unique Students</p>
                            <h3 class="mb-0 text-info"><?php echo $stats['unique_students'] ?? 0; ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                            <i class="fas fa-users fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Quick Actions -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-3">
                        <a href="manage-students.php" class="quick-action-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-users text-primary me-2"></i>
                                    <strong>Manage Students</strong>
                                </div>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </a>
                        <a href="scholarships.php" class="quick-action-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-award text-success me-2"></i>
                                    <strong>Scholarship Programs</strong>
                                </div>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </a>
                        <a href="assistance-requests.php" class="quick-action-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-hand-holding-usd text-warning me-2"></i>
                                    <strong>Assistance Requests</strong>
                                </div>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </a>
                        <a href="student-records.php" class="quick-action-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-folder-open text-info me-2"></i>
                                    <strong>Student Records</strong>
                                </div>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Scholarship Summary -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2 text-success"></i>Scholarship Summary</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($scholarship_summary)): ?>
                        <div class="empty-state py-3">
                            <i class="fas fa-award" style="font-size: 2rem;"></i>
                            <p class="small mb-0">No active scholarships</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($scholarship_summary as $scholarship): ?>
                            <div class="scholarship-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($scholarship['scholarship_type']); ?></h6>
                                        <small class="text-muted">
                                            <i class="fas fa-users me-1"></i>
                                            <?php echo $scholarship['count']; ?> scholars
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <strong class="text-success">₱<?php echo number_format($scholarship['total_amount'], 2); ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Applications -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-file-alt me-2 text-primary"></i>Recent Applications</h5>
                        <a href="manage-students.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_applications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No applications yet</p>
                            <p class="text-muted small">Applications will appear here once students apply</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>School</th>
                                        <th>Grade Level</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_applications as $app): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></strong><br>
                                                <small class="text-muted">
                                                    <i class="fas fa-phone me-1"></i>
                                                    <?php echo htmlspecialchars($app['contact_number']); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['school_name']); ?></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($app['grade_level']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($app['scholarship_status'] == 'active'): ?>
                                                    <span class="badge bg-success">Scholarship</span>
                                                <?php elseif ($app['assistance_status'] == 'approved'): ?>
                                                    <span class="badge bg-info">Assistance</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">General</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status = $app['scholarship_status'] ?? $app['assistance_status'] ?? 'pending';
                                                echo getStatusBadge($status);
                                                ?>
                                            </td>
                                            <td>
                                                <small><?php echo formatDate($app['application_date']); ?></small>
                                            </td>
                                            <td>
                                                <a href="view-student.php?id=<?php echo $app['student_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php 
$conn->close();
include '../../includes/footer.php'; 
?>