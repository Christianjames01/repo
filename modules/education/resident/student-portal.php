<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = null;

// Get resident ID
if ($user_role === 'Resident') {
    $user_id = getCurrentUserId();
    $user_sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
    $user_data = fetchOne($conn, $user_sql, [$user_id], 'i');
    $resident_id = $user_data['resident_id'] ?? null;
}

$page_title = 'Student Portal';

// Get student records for this resident
$my_records_sql = "SELECT * FROM tbl_education_students WHERE resident_id = ? ORDER BY created_at DESC";
$my_records = $resident_id ? fetchAll($conn, $my_records_sql, [$resident_id], 'i') : [];

// Get available scholarships
$scholarships_sql = "SELECT * FROM tbl_education_scholarships 
                     WHERE status = 'active' 
                     AND (application_end IS NULL OR application_end >= CURDATE())
                     ORDER BY created_at DESC";
$scholarships = fetchAll($conn, $scholarships_sql);

include '../../../includes/header.php';
?>

<style>
.scholarship-card {
    transition: all 0.3s;
    border-radius: 12px;
}
.scholarship-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
}
.feature-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}
.status-timeline {
    position: relative;
    padding-left: 30px;
}
.status-timeline::before {
    content: '';
    position: absolute;
    left: 9px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}
.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}
.timeline-dot {
    position: absolute;
    left: -26px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 3px solid;
    background: white;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-user-graduate me-2 text-primary"></i>Student Portal
                    </h2>
                    <p class="text-muted mb-0">Manage your educational assistance and scholarship applications</p>
                </div>
                <a href="apply-scholarship.php" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i>Apply for Scholarship
                </a>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Welcome Section -->
    <?php if (empty($my_records)): ?>
        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="card-body text-white py-5">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3 class="text-white mb-3">Welcome to the Student Portal!</h3>
                        <p class="mb-4">Apply for scholarships, educational assistance, and track your applications all in one place.</p>
                        <a href="apply-scholarship.php" class="btn btn-light btn-lg">
                            <i class="fas fa-graduation-cap me-2"></i>Apply Now
                        </a>
                    </div>
                    <div class="col-md-4 text-center">
                        <i class="fas fa-graduation-cap" style="font-size: 120px; opacity: 0.3;"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- My Applications -->
        <div class="col-md-8 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-file-alt me-2 text-primary"></i>My Applications
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($my_records)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">You haven't submitted any applications yet</p>
                            <a href="apply-scholarship.php" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i>Submit Your First Application
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_records as $record): ?>
                            <div class="card mb-3 border">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <h6 class="mb-2">
                                                <?php echo htmlspecialchars($record['school_name']); ?>
                                            </h6>
                                            <div class="mb-2">
                                                <span class="badge bg-primary me-2">
                                                    <?php echo htmlspecialchars($record['grade_level']); ?>
                                                </span>
                                                <?php if ($record['course']): ?>
                                                    <span class="badge bg-secondary">
                                                        <?php echo htmlspecialchars($record['course']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted mb-2">
                                                <i class="fas fa-calendar me-2"></i>
                                                Applied: <?php echo formatDate($record['application_date']); ?>
                                            </div>
                                            <?php if ($record['scholarship_type']): ?>
                                                <div class="small">
                                                    <strong>Scholarship:</strong> <?php echo htmlspecialchars($record['scholarship_type']); ?>
                                                    <?php if ($record['scholarship_amount'] > 0): ?>
                                                        - ₱<?php echo number_format($record['scholarship_amount'], 2); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="mb-3">
                                                <?php
                                                $status = $record['scholarship_status'];
                                                if ($status == 'pending') {
                                                    echo '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending Review</span>';
                                                } elseif ($status == 'active') {
                                                    echo '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active Scholar</span>';
                                                } elseif ($status == 'rejected') {
                                                    echo '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Rejected</span>';
                                                } elseif ($status == 'expired') {
                                                    echo '<span class="badge bg-secondary"><i class="fas fa-hourglass-end me-1"></i>Expired</span>';
                                                }
                                                ?>
                                            </div>
                                            <a href="view-application.php?id=<?php echo $record['student_id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye me-1"></i>View Details
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Progress Timeline for Active Scholarships -->
                                    <?php if ($record['scholarship_status'] == 'active'): ?>
                                        <hr>
                                        <div class="status-timeline">
                                            <div class="timeline-item">
                                                <div class="timeline-dot border-success" style="background: #28a745;"></div>
                                                <strong class="text-success">Application Approved</strong>
                                                <div class="small text-muted">
                                                    <?php echo formatDate($record['approval_date']); ?>
                                                </div>
                                            </div>
                                            <?php if ($record['scholarship_start_date']): ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-dot border-primary" style="background: #007bff;"></div>
                                                    <strong>Scholarship Active</strong>
                                                    <div class="small text-muted">
                                                        Start: <?php echo formatDate($record['scholarship_start_date']); ?>
                                                        <?php if ($record['scholarship_end_date']): ?>
                                                            <br>End: <?php echo formatDate($record['scholarship_end_date']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-md-4">
            <!-- Available Scholarships -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-award me-2 text-warning"></i>Available Scholarships
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($scholarships)): ?>
                        <p class="text-muted text-center small">No active scholarships at the moment</p>
                    <?php else: ?>
                        <?php foreach ($scholarships as $scholarship): ?>
                            <div class="card scholarship-card mb-3 border-0 bg-light">
                                <div class="card-body">
                                    <h6 class="mb-2"><?php echo htmlspecialchars($scholarship['scholarship_name']); ?></h6>
                                    <div class="mb-2">
                                        <span class="badge bg-success">
                                            ₱<?php echo number_format($scholarship['amount'], 2); ?>
                                        </span>
                                        <?php if ($scholarship['slots']): ?>
                                            <span class="badge bg-info">
                                                <?php echo $scholarship['slots']; ?> slots
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($scholarship['application_end']): ?>
                                        <div class="small text-muted mb-2">
                                            <i class="fas fa-clock me-1"></i>
                                            Until: <?php echo formatDate($scholarship['application_end']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <a href="apply-scholarship.php?type=<?php echo $scholarship['scholarship_id']; ?>" 
                                       class="btn btn-sm btn-primary w-100">
                                        <i class="fas fa-paper-plane me-1"></i>Apply Now
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-link me-2 text-info"></i>Quick Links
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="request-assistance.php" class="btn btn-outline-primary">
                            <i class="fas fa-hand-holding-usd me-2"></i>Request Assistance
                        </a>
                        <a href="my-documents.php" class="btn btn-outline-info">
                            <i class="fas fa-folder me-2"></i>My Documents
                        </a>
                        <a href="scholarship-guide.php" class="btn btn-outline-secondary">
                            <i class="fas fa-book me-2"></i>Scholarship Guide
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
include '../../../includes/footer.php';
?>