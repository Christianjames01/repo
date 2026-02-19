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

$page_title = 'Request Educational Assistance';

// Get student records for this resident
$students_sql = "SELECT * FROM tbl_education_students WHERE resident_id = ? ORDER BY created_at DESC";
$students = $resident_id ? fetchAll($conn, $students_sql, [$resident_id], 'i') : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    if (empty($_POST['student_id'])) $errors[] = "Please select a student";
    if (empty($_POST['assistance_type'])) $errors[] = "Assistance type is required";
    if (empty($_POST['requested_amount'])) $errors[] = "Amount is required";
    if (empty($_POST['purpose'])) $errors[] = "Purpose is required";
    
    if (empty($errors)) {
        $sql = "INSERT INTO tbl_education_assistance_requests (
            student_id, assistance_type, requested_amount, purpose, 
            supporting_documents, request_date, status
        ) VALUES (?, ?, ?, ?, ?, NOW(), 'pending')";
        
        $params = [
            $_POST['student_id'],
            $_POST['assistance_type'],
            $_POST['requested_amount'],
            $_POST['purpose'],
            $_POST['supporting_documents'] ?? null
        ];
        
        if (executeQuery($conn, $sql, $params, 'isdss')) {
            setMessage('Assistance request submitted successfully! Please wait for approval.', 'success');
            header('Location: student-portal.php');
            exit();
        } else {
            setMessage('Failed to submit request. Please try again.', 'error');
        }
    } else {
        foreach ($errors as $error) {
            setMessage($error, 'error');
        }
    }
}

// Get previous requests
$requests_sql = "SELECT ear.*, es.first_name, es.last_name, es.school_name
                 FROM tbl_education_assistance_requests ear
                 JOIN tbl_education_students es ON ear.student_id = es.student_id
                 WHERE es.resident_id = ?
                 ORDER BY ear.request_date DESC";
$previous_requests = $resident_id ? fetchAll($conn, $requests_sql, [$resident_id], 'i') : [];

include '../../../includes/header.php';
?>

<style>
.assistance-type-card {
    transition: all 0.3s;
    cursor: pointer;
    border: 2px solid #e9ecef;
    border-radius: 12px;
}
.assistance-type-card:hover {
    border-color: #007bff;
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.15);
}
.assistance-type-card.selected {
    border-color: #007bff;
    background: #f0f7ff;
}
.assistance-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.8rem;
    margin: 0 auto 1rem;
}
.request-card {
    border-left: 4px solid;
    border-radius: 8px;
}
.request-card.pending { border-left-color: #ffc107; }
.request-card.approved { border-left-color: #28a745; }
.request-card.rejected { border-left-color: #dc3545; }
.request-card.completed { border-left-color: #17a2b8; }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-hand-holding-usd me-2 text-success"></i>Request Educational Assistance
                    </h2>
                    <p class="text-muted mb-0">Apply for financial assistance for your education</p>
                </div>
                <a href="student-portal.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Portal
                </a>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <?php if (empty($students)): ?>
        <!-- No Student Records -->
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Student Records Found</h4>
                <p class="text-muted">You need to submit a scholarship application first before requesting assistance.</p>
                <a href="apply-scholarship.php" class="btn btn-primary mt-3">
                    <i class="fas fa-plus me-1"></i>Apply for Scholarship
                </a>
            </div>
        </div>
    <?php else: ?>
        
        <div class="row">
            <!-- Request Form -->
            <div class="col-md-8 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-file-alt me-2"></i>Assistance Request Form
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <!-- Select Student -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Select Student <span class="text-danger">*</span></label>
                                <select name="student_id" class="form-select" required>
                                    <option value="">Choose student...</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['student_id']; ?>">
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> - 
                                            <?php echo htmlspecialchars($student['school_name']); ?> 
                                            (<?php echo htmlspecialchars($student['grade_level']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Assistance Type -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Type of Assistance <span class="text-danger">*</span></label>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="assistance-type-card p-3 text-center" onclick="selectAssistanceType('Tuition Fee', this)">
                                            <div class="assistance-icon">
                                                <i class="fas fa-graduation-cap"></i>
                                            </div>
                                            <h6 class="mb-0">Tuition Fee</h6>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="assistance-type-card p-3 text-center" onclick="selectAssistanceType('School Supplies', this)">
                                            <div class="assistance-icon">
                                                <i class="fas fa-book"></i>
                                            </div>
                                            <h6 class="mb-0">School Supplies</h6>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="assistance-type-card p-3 text-center" onclick="selectAssistanceType('Uniform', this)">
                                            <div class="assistance-icon">
                                                <i class="fas fa-tshirt"></i>
                                            </div>
                                            <h6 class="mb-0">Uniform</h6>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="assistance-type-card p-3 text-center" onclick="selectAssistanceType('Books', this)">
                                            <div class="assistance-icon">
                                                <i class="fas fa-book-open"></i>
                                            </div>
                                            <h6 class="mb-0">Books</h6>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="assistance-type-card p-3 text-center" onclick="selectAssistanceType('Transportation', this)">
                                            <div class="assistance-icon">
                                                <i class="fas fa-bus"></i>
                                            </div>
                                            <h6 class="mb-0">Transportation</h6>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="assistance-type-card p-3 text-center" onclick="selectAssistanceType('Other', this)">
                                            <div class="assistance-icon">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </div>
                                            <h6 class="mb-0">Other</h6>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="assistance_type" id="assistanceType" required>
                            </div>

                            <!-- Amount Requested -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Amount Requested <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" name="requested_amount" class="form-control" 
                                           placeholder="0.00" required>
                                </div>
                                <small class="text-muted">Enter the amount you need for this assistance</small>
                            </div>

                            <!-- Purpose -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Purpose / Reason <span class="text-danger">*</span></label>
                                <textarea name="purpose" class="form-control" rows="4" 
                                          placeholder="Please explain in detail why you need this assistance and how it will be used..." required></textarea>
                            </div>

                            <!-- Supporting Documents -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Supporting Documents (Optional)</label>
                                <textarea name="supporting_documents" class="form-control" rows="3" 
                                          placeholder="List any documents you have to support this request (e.g., bills, receipts, quotations)"></textarea>
                                <small class="text-muted">You can upload actual documents later in the "My Documents" section</small>
                            </div>

                            <!-- Submit Button -->
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                                </button>
                                <a href="student-portal.php" class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-md-4">
                <!-- Guidelines -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="mb-0">
                            <i class="fas fa-info-circle me-2 text-info"></i>Assistance Guidelines
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <h6 class="text-primary">Eligibility:</h6>
                            <ul class="mb-3">
                                <li>Must be a registered student</li>
                                <li>Resident of the barangay</li>
                                <li>Financial need must be demonstrated</li>
                            </ul>

                            <h6 class="text-primary">Required Documents:</h6>
                            <ul class="mb-3">
                                <li>Certificate of Enrollment</li>
                                <li>Statement of Account (for tuition)</li>
                                <li>Quotation/Bills (for supplies)</li>
                                <li>Barangay Clearance</li>
                            </ul>

                            <h6 class="text-primary">Processing Time:</h6>
                            <p class="mb-0">Requests are typically processed within 5-10 working days.</p>
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="mb-0">
                            <i class="fas fa-phone me-2 text-success"></i>Need Help?
                        </h6>
                    </div>
                    <div class="card-body">
                        <p class="small mb-2">Contact the Education Office:</p>
                        <p class="mb-1">
                            <i class="fas fa-phone text-primary me-2"></i>
                            <strong><?php echo BARANGAY_CONTACT; ?></strong>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-envelope text-primary me-2"></i>
                            <strong><?php echo BARANGAY_EMAIL; ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Previous Requests -->
        <?php if (!empty($previous_requests)): ?>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>My Previous Requests
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($previous_requests as $request): ?>
                        <div class="card request-card <?php echo $request['status']; ?> mb-3">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h6 class="mb-2">
                                            <strong><?php echo htmlspecialchars($request['assistance_type']); ?></strong>
                                        </h6>
                                        <p class="small text-muted mb-2">
                                            For: <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?> - 
                                            <?php echo htmlspecialchars($request['school_name']); ?>
                                        </p>
                                        <p class="small mb-2">
                                            <strong>Purpose:</strong> <?php echo htmlspecialchars(substr($request['purpose'], 0, 100)); ?>...
                                        </p>
                                        <p class="small text-muted mb-0">
                                            <i class="fas fa-calendar me-1"></i>
                                            Requested: <?php echo date('F d, Y', strtotime($request['request_date'])); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <h5 class="text-primary mb-2">₱<?php echo number_format($request['requested_amount'], 2); ?></h5>
                                        <?php
                                        $status = $request['status'];
                                        if ($status == 'pending') {
                                            echo '<span class="badge bg-warning text-dark">Pending Review</span>';
                                        } elseif ($status == 'approved') {
                                            echo '<span class="badge bg-success">Approved - ₱' . number_format($request['approved_amount'], 2) . '</span>';
                                        } elseif ($status == 'rejected') {
                                            echo '<span class="badge bg-danger">Rejected</span>';
                                        } elseif ($status == 'completed') {
                                            echo '<span class="badge bg-info">Completed</span>';
                                        }
                                        ?>
                                        <?php if ($request['rejection_reason']): ?>
                                            <div class="mt-2">
                                                <small class="text-danger">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    <?php echo htmlspecialchars($request['rejection_reason']); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
function selectAssistanceType(type, element) {
    // Remove selected class from all cards
    document.querySelectorAll('.assistance-type-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Add selected class to clicked card
    element.classList.add('selected');
    
    // Set the hidden input value
    document.getElementById('assistanceType').value = type;
}
</script>

<?php
$conn->close();
include '../../../includes/footer.php';
?>