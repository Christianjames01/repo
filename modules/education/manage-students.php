<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Manage Students';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $student_id = (int)$_POST['student_id'];
    
    switch ($_POST['action']) {
        case 'approve_scholarship':
            $sql = "UPDATE tbl_education_students 
                    SET scholarship_status = 'active', 
                        approved_by = ?, 
                        approval_date = NOW() 
                    WHERE student_id = ?";
            if (executeQuery($conn, $sql, [getCurrentUserId(), $student_id], 'ii')) {
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
            
        case 'delete':
            if (executeQuery($conn, "DELETE FROM tbl_education_students WHERE student_id = ?", [$student_id], 'i')) {
                setMessage('Student record deleted successfully', 'success');
            }
            break;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$scholarship_filter = isset($_GET['scholarship']) ? $_GET['scholarship'] : 'all';

$sql = "SELECT es.*, r.contact_number as resident_contact
        FROM tbl_education_students es
        LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id
        WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (es.first_name LIKE ? OR es.last_name LIKE ? OR es.school_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= "sss";
}

if ($status_filter !== 'all') {
    $sql .= " AND es.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($scholarship_filter !== 'all') {
    $sql .= " AND es.scholarship_status = ?";
    $params[] = $scholarship_filter;
    $types .= "s";
}

$sql .= " ORDER BY es.created_at DESC";

$students = !empty($params) ? fetchAll($conn, $sql, $params, $types) : fetchAll($conn, $sql);

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

.card-body {
    padding: 1.75rem;
}

/* Student Cards */
.student-card {
    transition: all var(--transition-speed) ease;
    border: 2px solid #e9ecef;
    border-radius: var(--border-radius);
    cursor: pointer;
}

.student-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
    border-color: #0d6efd;
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

.grade-badge {
    font-size: 0.85rem;
    padding: 0.35rem 0.75rem;
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

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-group .btn {
    box-shadow: none;
}

/* Form Enhancements */
.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
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

/* Modal Enhancements */
.modal-content {
    border: none;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-lg);
}

.modal-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
    padding: 1.5rem;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
    padding: 1.25rem 2rem;
    border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-users-class me-2 text-primary"></i>Student Management
                    </h2>
                    <p class="text-muted mb-0">View and manage all student records</p>
                </div>
                <div>
                    <a href="add-student.php" class="btn btn-primary">
                        <i class="fas fa-user-plus me-1"></i>Add Student
                    </a>
                    <a href="export-students.php" class="btn btn-success">
                        <i class="fas fa-file-excel me-1"></i>Export
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search students..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Scholarship</label>
                    <select name="scholarship" class="form-select">
                        <option value="all">All Scholarships</option>
                        <option value="active" <?php echo $scholarship_filter === 'active' ? 'selected' : ''; ?>>Active Scholars</option>
                        <option value="pending" <?php echo $scholarship_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="rejected" <?php echo $scholarship_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Students Grid -->
    <?php if (empty($students)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <p>No students found</p>
                    <a href="add-student.php" class="btn btn-primary mt-3">Add Your First Student</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($students as $student): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card student-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="mb-1">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </h5>
                                    <p class="text-muted small mb-0">ID: #<?php echo str_pad($student['student_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                </div>
                                <?php if ($student['scholarship_status'] == 'active'): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-award me-1"></i>Scholar
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-school me-2"></i><?php echo htmlspecialchars($student['school_name']); ?>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($student['grade_level']); ?>
                                    <?php if (!empty($student['course'])): ?>
                                        - <?php echo htmlspecialchars($student['course']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($student['contact_number']); ?>
                                </div>
                                <?php if ($student['gwa_grade']): ?>
                                    <div class="small">
                                        <span class="badge grade-badge bg-primary">
                                            GWA: <?php echo number_format($student['gwa_grade'], 2); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php echo getStatusBadge($student['status']); ?>
                                
                                <?php if ($student['scholarship_status'] == 'pending'): ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-clock me-1"></i>Scholarship Pending
                                    </span>
                                <?php elseif ($student['scholarship_status'] == 'active'): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check-circle me-1"></i>Active Scholar
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid gap-2">
                                <div class="btn-group" role="group">
                                    <a href="view-student.php?id=<?php echo $student['student_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i>View
                                    </a>
                                    <a href="edit-student.php?id=<?php echo $student['student_id']; ?>" 
                                       class="btn btn-sm btn-outline-info">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </a>
                                    <?php if ($student['scholarship_status'] == 'pending'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-success" 
                                                onclick="approveScholarship(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name'])); ?>')">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2 text-success"></i>Approve Scholarship
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="student_id" id="approveStudentId">
                    <input type="hidden" name="action" value="approve_scholarship">
                    <p>Are you sure you want to approve the scholarship for <strong id="approveStudentName"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveScholarship(id, name) {
    document.getElementById('approveStudentId').value = id;
    document.getElementById('approveStudentName').textContent = name;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

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