<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Student Records';

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$grade_filter = isset($_GET['grade']) ? $_GET['grade'] : 'all';
$school_filter = isset($_GET['school']) ? $_GET['school'] : 'all';
$scholarship_filter = isset($_GET['scholarship']) ? $_GET['scholarship'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query
$sql = "SELECT es.*, 
        CONCAT(es.first_name, ' ', es.last_name) as full_name,
        r.resident_id, r.contact_number as resident_contact
        FROM tbl_education_students es
        LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id
        WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (es.first_name LIKE ? OR es.last_name LIKE ? OR es.school_name LIKE ? OR es.parent_guardian_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
}

if ($grade_filter !== 'all') {
    $sql .= " AND es.grade_level = ?";
    $params[] = $grade_filter;
    $types .= "s";
}

if ($school_filter !== 'all') {
    $sql .= " AND es.school_name = ?";
    $params[] = $school_filter;
    $types .= "s";
}

if ($scholarship_filter !== 'all') {
    $sql .= " AND es.scholarship_status = ?";
    $params[] = $scholarship_filter;
    $types .= "s";
}

if ($status_filter !== 'all') {
    $sql .= " AND es.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql .= " ORDER BY es.created_at DESC";

$students = !empty($params) ? fetchAll($conn, $sql, $params, $types) : fetchAll($conn, $sql);

// Get unique schools for filter
$schools_sql = "SELECT DISTINCT school_name FROM tbl_education_students ORDER BY school_name";
$schools = fetchAll($conn, $schools_sql);

// Get unique grade levels for filter
$grades_sql = "SELECT DISTINCT grade_level FROM tbl_education_students ORDER BY grade_level";
$grades = fetchAll($conn, $grades_sql);

// Statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN scholarship_status = 'active' THEN 1 END) as active_scholars,
    COUNT(CASE WHEN scholarship_status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_students,
    SUM(CASE WHEN scholarship_status = 'active' THEN scholarship_amount ELSE 0 END) as total_amount
    FROM tbl_education_students";
$stats = fetchOne($conn, $stats_sql);

include '../../includes/header.php';
?>

<style>
.stat-card {
    transition: all 0.3s;
    border-left: 4px solid;
    border-radius: 8px;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.record-card {
    transition: all 0.3s;
    border: 1px solid #e9ecef;
    border-radius: 10px;
}
.record-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-color: #007bff;
}
.student-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    font-weight: bold;
}
.info-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-value {
    font-size: 0.9rem;
    font-weight: 500;
    color: #495057;
}
.filter-badge {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
}
@media print {
    .no-print {
        display: none;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 no-print">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-folder-open me-2 text-primary"></i>Student Records
                    </h2>
                    <p class="text-muted mb-0">Complete database of all student records</p>
                </div>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <a href="add-student.php" class="btn btn-primary">
                        <i class="fas fa-user-plus me-1"></i>Add Student
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 no-print">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color: #0d6efd;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Total Students</p>
                            <h3 class="mb-0"><?php echo number_format($stats['total'] ?? 0); ?></h3>
                        </div>
                        <div class="text-primary opacity-50">
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color: #198754;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Active Scholars</p>
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['active_scholars'] ?? 0); ?></h3>
                        </div>
                        <div class="text-success opacity-50">
                            <i class="fas fa-graduation-cap fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color: #ffc107;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Pending</p>
                            <h3 class="mb-0 text-warning"><?php echo number_format($stats['pending'] ?? 0); ?></h3>
                        </div>
                        <div class="text-warning opacity-50">
                            <i class="fas fa-clock fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color: #6f42c1;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Total Scholarships</p>
                            <h3 class="mb-0" style="color: #6f42c1;">₱<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></h3>
                        </div>
                        <div style="color: #6f42c1; opacity: 0.5;">
                            <i class="fas fa-money-bill-wave fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by name, school..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select name="grade" class="form-select">
                        <option value="all">All Grade Levels</option>
                        <?php foreach ($grades as $grade): ?>
                            <option value="<?php echo htmlspecialchars($grade['grade_level']); ?>" 
                                    <?php echo $grade_filter === $grade['grade_level'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($grade['grade_level']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="school" class="form-select">
                        <option value="all">All Schools</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?php echo htmlspecialchars($school['school_name']); ?>" 
                                    <?php echo $school_filter === $school['school_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($school['school_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="scholarship" class="form-select">
                        <option value="all">All Scholarship Status</option>
                        <option value="active" <?php echo $scholarship_filter === 'active' ? 'selected' : ''; ?>>Active Scholars</option>
                        <option value="pending" <?php echo $scholarship_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="rejected" <?php echo $scholarship_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="expired" <?php echo $scholarship_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="all">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
            
            <!-- Active Filters Display -->
            <?php if (!empty($search) || $grade_filter !== 'all' || $school_filter !== 'all' || $scholarship_filter !== 'all' || $status_filter !== 'all'): ?>
                <div class="mt-3">
                    <span class="text-muted small me-2">Active Filters:</span>
                    <?php if (!empty($search)): ?>
                        <span class="badge filter-badge bg-primary me-1">
                            Search: "<?php echo htmlspecialchars($search); ?>"
                        </span>
                    <?php endif; ?>
                    <?php if ($grade_filter !== 'all'): ?>
                        <span class="badge filter-badge bg-info me-1">
                            Grade: <?php echo htmlspecialchars($grade_filter); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($school_filter !== 'all'): ?>
                        <span class="badge filter-badge bg-success me-1">
                            School: <?php echo htmlspecialchars($school_filter); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($scholarship_filter !== 'all'): ?>
                        <span class="badge filter-badge bg-warning me-1">
                            Scholarship: <?php echo ucfirst($scholarship_filter); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($status_filter !== 'all'): ?>
                        <span class="badge filter-badge bg-secondary me-1">
                            Status: <?php echo ucfirst($status_filter); ?>
                        </span>
                    <?php endif; ?>
                    <a href="student-records.php" class="btn btn-sm btn-outline-secondary ms-2">
                        <i class="fas fa-times me-1"></i>Clear All
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Records -->
    <?php if (empty($students)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-folder-open fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Student Records Found</h4>
                <p class="text-muted">Try adjusting your filters or search criteria</p>
                <a href="add-student.php" class="btn btn-primary mt-3">
                    <i class="fas fa-user-plus me-1"></i>Add First Student
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($students as $student): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card record-card h-100">
                        <div class="card-body">
                            <!-- Header -->
                            <div class="d-flex align-items-start mb-3">
                                <div class="student-avatar me-3">
                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </h5>
                                    <p class="text-muted small mb-0">
                                        ID: #<?php echo str_pad($student['student_id'], 5, '0', STR_PAD_LEFT); ?>
                                    </p>
                                </div>
                                <div>
                                    <?php if ($student['scholarship_status'] == 'active'): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-award me-1"></i>Scholar
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- School Info -->
                            <div class="mb-3">
                                <div class="info-label">School</div>
                                <div class="info-value mb-2">
                                    <i class="fas fa-school text-primary me-1"></i>
                                    <?php echo htmlspecialchars($student['school_name']); ?>
                                </div>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <div class="info-label">Grade Level</div>
                                        <div class="info-value">
                                            <?php echo htmlspecialchars($student['grade_level']); ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($student['course'])): ?>
                                        <div class="col-6">
                                            <div class="info-label">Course</div>
                                            <div class="info-value">
                                                <?php echo htmlspecialchars($student['course']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Academic Info -->
                            <?php if ($student['gwa_grade']): ?>
                                <div class="mb-3">
                                    <div class="info-label">GWA</div>
                                    <div class="info-value">
                                        <span class="badge bg-primary">
                                            <?php echo number_format($student['gwa_grade'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Scholarship Info -->
                            <?php if ($student['scholarship_type']): ?>
                                <div class="mb-3 p-2 bg-light rounded">
                                    <div class="info-label">Scholarship</div>
                                    <div class="info-value mb-1">
                                        <?php echo htmlspecialchars($student['scholarship_type']); ?>
                                    </div>
                                    <?php if ($student['scholarship_amount'] > 0): ?>
                                        <div class="text-success fw-bold">
                                            ₱<?php echo number_format($student['scholarship_amount'], 2); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Contact Info -->
                            <div class="mb-3">
                                <div class="info-label">Contact</div>
                                <div class="info-value small">
                                    <i class="fas fa-phone text-success me-1"></i>
                                    <?php echo htmlspecialchars($student['contact_number']); ?>
                                </div>
                            </div>

                            <!-- Parent Info -->
                            <div class="mb-3">
                                <div class="info-label">Parent/Guardian</div>
                                <div class="info-value small">
                                    <i class="fas fa-user text-info me-1"></i>
                                    <?php echo htmlspecialchars($student['parent_guardian_name']); ?>
                                </div>
                            </div>

                            <!-- Status Badges -->
                            <div class="mb-3">
                                <?php echo getStatusBadge($student['status']); ?>
                                
                                <?php
                                $scholarship_status = $student['scholarship_status'];
                                if ($scholarship_status == 'pending') {
                                    echo '<span class="badge bg-warning text-dark ms-1">Scholarship Pending</span>';
                                } elseif ($scholarship_status == 'active') {
                                    echo '<span class="badge bg-success ms-1">Active Scholar</span>';
                                } elseif ($scholarship_status == 'rejected') {
                                    echo '<span class="badge bg-danger ms-1">Rejected</span>';
                                } elseif ($scholarship_status == 'expired') {
                                    echo '<span class="badge bg-secondary ms-1">Expired</span>';
                                }
                                ?>
                            </div>

                            <!-- Application Date -->
                            <div class="mb-3">
                                <small class="text-muted">
                                    <i class="fas fa-calendar me-1"></i>
                                    Applied: <?php echo formatDate($student['application_date']); ?>
                                </small>
                            </div>

                            <!-- Actions -->
                            <div class="d-grid gap-2">
                                <a href="view-student.php?id=<?php echo $student['student_id']; ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye me-1"></i>View Full Record
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Info -->
        <div class="card border-0 shadow-sm mt-4 no-print">
            <div class="card-body text-center">
                <p class="text-muted mb-0">
                    Showing <?php echo count($students); ?> student record(s)
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$conn->close();
include '../../includes/footer.php';
?>