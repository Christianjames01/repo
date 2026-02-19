<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

// Get student ID from URL
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'approve_scholarship':
            $sql = "UPDATE tbl_education_students 
                    SET scholarship_status = 'active', 
                        approved_by = ?, 
                        approval_date = NOW(),
                        scholarship_start_date = ?,
                        scholarship_end_date = ?,
                        scholarship_amount = ?
                    WHERE student_id = ?";
            $params = [
                getCurrentUserId(),
                $_POST['start_date'],
                $_POST['end_date'] ?? null,
                $_POST['scholarship_amount'],
                $student_id
            ];
            if (executeQuery($conn, $sql, $params, 'issdi')) {
                setMessage('Scholarship approved successfully', 'success');
            }
            break;
            
        case 'reject_scholarship':
            $sql = "UPDATE tbl_education_students 
                    SET scholarship_status = 'rejected',
                        remarks = ?
                    WHERE student_id = ?";
            executeQuery($conn, $sql, [$_POST['remarks'], $student_id], 'si');
            setMessage('Scholarship application rejected', 'info');
            break;
            
        case 'update_status':
            $sql = "UPDATE tbl_education_students SET status = ? WHERE student_id = ?";
            executeQuery($conn, $sql, [$_POST['status'], $student_id], 'si');
            setMessage('Student status updated', 'success');
            break;
            
        case 'add_note':
            $current_remarks = $_POST['current_remarks'] ?? '';
            $new_note = date('Y-m-d H:i') . ' - ' . getCurrentUserRole() . ': ' . $_POST['new_note'];
            $updated_remarks = trim($current_remarks . "\n" . $new_note);
            
            $sql = "UPDATE tbl_education_students SET remarks = ? WHERE student_id = ?";
            executeQuery($conn, $sql, [$updated_remarks, $student_id], 'si');
            setMessage('Note added successfully', 'success');
            break;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $student_id);
    exit();
}

// Get student record with resident info
$student_sql = "SELECT es.*, r.first_name as res_first_name, r.last_name as res_last_name,
                u.username as approved_by_username
                FROM tbl_education_students es
                LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id
                LEFT JOIN tbl_users u ON es.approved_by = u.user_id
                WHERE es.student_id = ?";
$student = fetchOne($conn, $student_sql, [$student_id], 'i');

if (!$student) {
    setMessage('Student not found', 'error');
    header('Location: manage-students.php');
    exit();
}

// Get documents for this student
$docs_sql = "SELECT * FROM tbl_education_documents WHERE student_id = ? ORDER BY uploaded_at DESC";
$documents = fetchAll($conn, $docs_sql, [$student_id], 'i');

// Get assistance requests
$assistance_sql = "SELECT * FROM tbl_education_assistance_requests WHERE student_id = ? ORDER BY request_date DESC";
$assistance_requests = fetchAll($conn, $assistance_sql, [$student_id], 'i');

$page_title = 'Student Details';

include '../../includes/header.php';
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
.action-card {
    transition: all 0.3s;
    cursor: pointer;
    border: 2px solid #e9ecef;
}
.action-card:hover {
    border-color: #007bff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-user-graduate me-2 text-primary"></i>Student Details
                    </h2>
                    <p class="text-muted mb-0">
                        Student ID: #<?php echo str_pad($student['student_id'], 5, '0', STR_PAD_LEFT); ?>
                        <?php if ($student['res_first_name']): ?>
                            | Resident: <?php echo htmlspecialchars($student['res_first_name'] . ' ' . $student['res_last_name']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <a href="manage-students.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                    <a href="add-student.php" class="btn btn-success">
                        <i class="fas fa-user-plus me-1"></i>Add New
                    </a>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="row">
        <!-- Main Information -->
        <div class="col-md-8">
            <!-- Status & Quick Actions -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6 text-center border-end">
                            <h6 class="text-muted mb-2">Application Status</h6>
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
                        </div>
                        <div class="col-md-6 text-center">
                            <h6 class="text-muted mb-2">Student Status</h6>
                            <?php echo getStatusBadge($student['status']); ?>
                        </div>
                    </div>

                    <?php if ($student['scholarship_status'] == 'pending'): ?>
                        <hr>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <div class="action-card p-3 text-center rounded" data-bs-toggle="modal" data-bs-target="#approveModal">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <h6 class="mb-0">Approve Scholarship</h6>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="action-card p-3 text-center rounded" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                                    <h6 class="mb-0">Reject Application</h6>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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
                        <div class="col-md-12">
                            <div class="info-row">
                                <div class="info-label">Full Name</div>
                                <div class="info-value">
                                    <h5 class="mb-0">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?>
                                    </h5>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">Birth Date</div>
                                <div class="info-value"><?php echo formatDate($student['birth_date']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['gender']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label">Age</div>
                                <div class="info-value">
                                    <?php
                                    $birthDate = new DateTime($student['birth_date']);
                                    $today = new DateTime();
                                    $age = $today->diff($birthDate)->y;
                                    echo $age . ' years old';
                                    ?>
                                </div>
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
                                <div class="info-value">
                                    <strong><?php echo htmlspecialchars($student['school_name']); ?></strong>
                                </div>
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
                                    <span class="badge bg-primary" style="font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($student['grade_level']); ?>
                                    </span>
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
                                        <span class="badge bg-success" style="font-size: 1.2rem; padding: 0.5rem 1.5rem;">
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
                                <div class="info-value">
                                    <strong><?php echo htmlspecialchars($student['parent_guardian_name']); ?></strong>
                                </div>
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
                            <i class="fas fa-folder-open me-2 text-warning"></i>Submitted Documents (<?php echo count($documents); ?>)
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
                                                <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                   download 
                                                   class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-download"></i>
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

            <!-- Assistance Requests -->
            <?php if (!empty($assistance_requests)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-hand-holding-usd me-2 text-success"></i>Assistance Requests History
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($assistance_requests as $request): ?>
                            <div class="card mb-3 border">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-2">
                                                <strong><?php echo htmlspecialchars($request['assistance_type']); ?></strong>
                                            </h6>
                                            <p class="small mb-2"><?php echo htmlspecialchars($request['purpose']); ?></p>
                                            <div class="small text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?php echo formatDate($request['request_date']); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <h5 class="text-primary mb-2">
                                                ₱<?php echo number_format($request['requested_amount'], 2); ?>
                                            </h5>
                                            <?php echo getStatusBadge($request['status']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
                            <div class="info-value">
                                <strong><?php echo htmlspecialchars($student['scholarship_type']); ?></strong>
                            </div>
                        </div>
                        <?php if ($student['scholarship_amount'] > 0): ?>
                            <div class="info-row">
                                <div class="info-label">Amount</div>
                                <div class="info-value text-success">
                                    <h4 class="mb-0">₱<?php echo number_format($student['scholarship_amount'], 2); ?></h4>
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
                        <?php if ($student['approved_by_username']): ?>
                            <div class="info-row">
                                <div class="info-label">Approved By</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['approved_by_username']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Timeline -->
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
                                <div class="small text-muted">
                                    <?php echo formatDate($student['approval_date']); ?>
                                    <?php if ($student['approved_by_username']): ?>
                                        <br>by <?php echo htmlspecialchars($student['approved_by_username']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif ($student['scholarship_status'] == 'rejected'): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot border-danger" style="background: #dc3545;">
                                    <i class="fas fa-times text-white"></i>
                                </div>
                                <strong class="text-danger">Rejected</strong>
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

            <!-- Admin Actions -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-cog me-2 text-secondary"></i>Admin Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <!-- Update Status -->
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
                            <i class="fas fa-edit me-2"></i>Update Status
                        </button>
                        
                        <!-- Add Note -->
                        <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#noteModal">
                            <i class="fas fa-sticky-note me-2"></i>Add Note
                        </button>
                        
                        <!-- View Documents -->
                        <?php if (!empty($documents)): ?>
                            <a href="#documents-section" class="btn btn-outline-warning">
                                <i class="fas fa-folder me-2"></i>View Documents (<?php echo count($documents); ?>)
                            </a>
                        <?php endif; ?>
                        
                        <!-- Print -->
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Record
                        </button>
                    </div>
                </div>
            </div>

            <!-- Remarks/Notes -->
            <?php if ($student['remarks']): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="mb-0">
                            <i class="fas fa-comment-alt me-2 text-warning"></i>Remarks & Notes
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="small" style="white-space: pre-wrap;"><?php echo htmlspecialchars($student['remarks']); ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Approve Scholarship</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve_scholarship">
                    
                    <div class="mb-3">
                        <label class="form-label">Scholarship Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" name="scholarship_amount" class="form-control" 
                                   value="<?php echo $student['scholarship_amount'] > 0 ? $student['scholarship_amount'] : ''; ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" 
                               value="<?php echo $student['scholarship_start_date'] ?? date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">End Date (Optional)</label>
                        <input type="date" name="end_date" class="form-control" 
                               value="<?php echo $student['scholarship_end_date'] ?? ''; ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Approve Scholarship
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject_scholarship">
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="remarks" class="form-control" rows="4" 
                                  placeholder="Please provide a clear reason for rejecting this application..." required></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        The student will be notified of this rejection and the reason provided.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-1"></i>Reject Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Update Student Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    
                    <div class="mb-3">
                        <label class="form-label">Student Status</label>
                        <select name="status" class="form-select" required>
                            <option value="active" <?php echo $student['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $student['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="graduated" <?php echo $student['status'] == 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="current_remarks" value="<?php echo htmlspecialchars($student['remarks'] ?? ''); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Add Note</label>
                        <textarea name="new_note" class="form-control" rows="4" 
                                  placeholder="Enter your note here..." required></textarea>
                        <small class="text-muted">
                            Note will be timestamped and attributed to you automatically.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$conn->close();
include '../../includes/footer.php';
?>