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

// Get student ID from URL
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get student record
$student_sql = "SELECT * FROM tbl_education_students WHERE student_id = ?";
$student = fetchOne($conn, $student_sql, [$student_id], 'i');

// Security check - residents can only view their own records
if ($user_role === 'Resident' && (!$student || $student['resident_id'] != $resident_id)) {
    setMessage('Unauthorized access', 'error');
    header('Location: student-portal.php');
    exit();
}

if (!$student) {
    setMessage('Application not found', 'error');
    header('Location: student-portal.php');
    exit();
}

// Get documents for this student
$docs_sql = "SELECT * FROM tbl_education_documents WHERE student_id = ? ORDER BY uploaded_at DESC";
$documents = fetchAll($conn, $docs_sql, [$student_id], 'i');

$page_title = 'View Application';

include '../../../includes/header.php';
?>

<style>
.info-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
}
.info-row {
    padding: 0.75rem 0;
    border-bottom: 1px solid #e9ecef;
}
.info-row:last-child {
    border-bottom: none;
}
.info-label {
    font-weight: 600;
    color: #6c757d;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}
.info-value {
    color: #495057;
    font-size: 1rem;
}
.status-badge-lg {
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    border-radius: 50px;
}
.timeline {
    position: relative;
    padding-left: 40px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 3px;
    background: #e9ecef;
}
.timeline-item {
    position: relative;
    padding-bottom: 2rem;
}
.timeline-dot {
    position: absolute;
    left: -31px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 4px solid;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
}
.timeline-dot i {
    font-size: 0.8rem;
}
.document-item {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    transition: all 0.3s;
}
.document-item:hover {
    border-color: #007bff;
    background: #f8f9fa;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-file-alt me-2 text-primary"></i>Application Details
                    </h2>
                    <p class="text-muted mb-0">Application ID: #<?php echo str_pad($student['student_id'], 5, '0', STR_PAD_LEFT); ?></p>
                </div>
                <div>
                    <a href="student-portal.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Portal
                    </a>
                    <?php if ($user_role !== 'Resident'): ?>
                        <a href="edit-student.php?id=<?php echo $student_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-1"></i>Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Information -->
        <div class="col-md-8">
            <!-- Status Overview -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body text-center py-4">
                    <h5 class="mb-3">Application Status</h5>
                    <?php
                    $status = $student['scholarship_status'];
                    if ($status == 'pending') {
                        echo '<span class="badge bg-warning text-dark status-badge-lg"><i class="fas fa-clock me-2"></i>Pending Review</span>';
                    } elseif ($status == 'active') {
                        echo '<span class="badge bg-success status-badge-lg"><i class="fas fa-check-circle me-2"></i>Active Scholar</span>';
                    } elseif ($status == 'rejected') {
                        echo '<span class="badge bg-danger status-badge-lg"><i class="fas fa-times-circle me-2"></i>Rejected</span>';
                    } elseif ($status == 'expired') {
                        echo '<span class="badge bg-secondary status-badge-lg"><i class="fas fa-hourglass-end me-2"></i>Expired</span>';
                    }
                    ?>
                    <p class="text-muted mt-3 mb-0">
                        <i class="fas fa-calendar me-1"></i>
                        Applied on: <?php echo formatDate($student['application_date']); ?>
                    </p>
                </div>
            </div>

            <!-- Personal Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-user me-2 text-primary"></i>Personal Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Full Name</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Birth Date</div>
                                <div class="info-value"><?php echo formatDate($student['birth_date']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['gender']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Contact Number</div>
                                <div class="info-value">
                                    <i class="fas fa-phone text-success me-1"></i>
                                    <?php echo htmlspecialchars($student['contact_number']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Email Address</div>
                                <div class="info-value">
                                    <?php if ($student['email']): ?>
                                        <i class="fas fa-envelope text-info me-1"></i>
                                        <?php echo htmlspecialchars($student['email']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="info-row">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['address']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Educational Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-school me-2 text-success"></i>Educational Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="info-row">
                                <div class="info-label">School Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['school_name']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">School Year</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['school_year']); ?></div>
                            </div>
                        </div>
                        <?php if ($student['school_address']): ?>
                            <div class="col-md-12">
                                <div class="info-row">
                                    <div class="info-label">School Address</div>
                                    <div class="info-value"><?php echo htmlspecialchars($student['school_address']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Grade Level / Year</div>
                                <div class="info-value">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($student['grade_level']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if ($student['course']): ?>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Course</div>
                                    <div class="info-value"><?php echo htmlspecialchars($student['course']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($student['gwa_grade']): ?>
                            <div class="col-md-12">
                                <div class="info-row">
                                    <div class="info-label">General Weighted Average (GWA)</div>
                                    <div class="info-value">
                                        <span class="badge bg-success" style="font-size: 1.1rem; padding: 0.5rem 1rem;">
                                            <?php echo number_format($student['gwa_grade'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Parent/Guardian Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-users me-2 text-info"></i>Parent/Guardian Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['parent_guardian_name']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Contact Number</div>
                                <div class="info-value">
                                    <i class="fas fa-phone text-success me-1"></i>
                                    <?php echo htmlspecialchars($student['parent_contact']); ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($student['parent_occupation']): ?>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Occupation</div>
                                    <div class="info-value"><?php echo htmlspecialchars($student['parent_occupation']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($student['monthly_income']): ?>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Monthly Family Income</div>
                                    <div class="info-value">₱<?php echo number_format($student['monthly_income'], 2); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Submitted Documents -->
            <?php if (!empty($documents)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-folder-open me-2 text-warning"></i>Submitted Documents
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($documents as $doc): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="document-item">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php
                                                $extension = pathinfo($doc['file_name'], PATHINFO_EXTENSION);
                                                if ($extension === 'pdf') {
                                                    echo '<i class="fas fa-file-pdf fa-2x text-danger"></i>';
                                                } else {
                                                    echo '<i class="fas fa-file-image fa-2x text-primary"></i>';
                                                }
                                                ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($doc['document_type']); ?></h6>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo formatDate($doc['uploaded_at']); ?>
                                                </small>
                                            </div>
                                            <div>
                                                <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-md-4">
            <!-- Scholarship Information -->
            <?php if ($student['scholarship_type']): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-award me-2 text-warning"></i>Scholarship Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <div class="info-label">Program</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['scholarship_type']); ?></div>
                        </div>
                        <?php if ($student['scholarship_amount'] > 0): ?>
                            <div class="info-row">
                                <div class="info-label">Amount</div>
                                <div class="info-value text-success fs-4">
                                    ₱<?php echo number_format($student['scholarship_amount'], 2); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($student['scholarship_start_date']): ?>
                            <div class="info-row">
                                <div class="info-label">Start Date</div>
                                <div class="info-value"><?php echo formatDate($student['scholarship_start_date']); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($student['scholarship_end_date']): ?>
                            <div class="info-row">
                                <div class="info-label">End Date</div>
                                <div class="info-value"><?php echo formatDate($student['scholarship_end_date']); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($student['approval_date']): ?>
                            <div class="info-row">
                                <div class="info-label">Approved On</div>
                                <div class="info-value text-success">
                                    <i class="fas fa-check-circle me-1"></i>
                                    <?php echo formatDate($student['approval_date']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Application Timeline -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2 text-primary"></i>Timeline
                    </h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-dot border-primary" style="background: #007bff;">
                                <i class="fas fa-paper-plane text-white"></i>
                            </div>
                            <strong>Application Submitted</strong>
                            <div class="small text-muted"><?php echo formatDate($student['application_date']); ?></div>
                        </div>

                        <?php if ($student['scholarship_status'] == 'active' && $student['approval_date']): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot border-success" style="background: #28a745;">
                                    <i class="fas fa-check text-white"></i>
                                </div>
                                <strong class="text-success">Approved</strong>
                                <div class="small text-muted"><?php echo formatDate($student['approval_date']); ?></div>
                            </div>
                        <?php elseif ($student['scholarship_status'] == 'rejected'): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot border-danger" style="background: #dc3545;">
                                    <i class="fas fa-times text-white"></i>
                                </div>
                                <strong class="text-danger">Rejected</strong>
                                <?php if ($student['remarks']): ?>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($student['remarks']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($student['scholarship_status'] == 'active' && $student['scholarship_start_date']): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot border-info" style="background: #17a2b8;">
                                    <i class="fas fa-graduation-cap text-white"></i>
                                </div>
                                <strong class="text-info">Scholarship Active</strong>
                                <div class="small text-muted"><?php echo formatDate($student['scholarship_start_date']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="my-documents.php" class="btn btn-outline-primary">
                            <i class="fas fa-upload me-2"></i>Upload Documents
                        </a>
                        <a href="request-assistance.php" class="btn btn-outline-success">
                            <i class="fas fa-hand-holding-usd me-2"></i>Request Assistance
                        </a>
                        <a href="student-portal.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Portal
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