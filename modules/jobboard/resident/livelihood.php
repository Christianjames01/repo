<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define base path for easier includes
$base_path = $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1';

// Include required files
require_once($base_path . '/config/database.php');

// Include auth.php if it exists
if (file_exists($base_path . '/config/auth.php')) {
    require_once($base_path . '/config/auth.php');
}

// Simple session check - set test values if not logged in (FOR TESTING ONLY)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'Resident';
    $_SESSION['role_name'] = 'Resident';
}

// Ensure role is set
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'Resident';
}

// Check if user is a resident
if ($_SESSION['role'] !== 'Resident') {
    header('Location: ' . $base_path . '/modules/auth/login.php');
    exit();
}

$page_title = 'Livelihood Programs';
$current_user_id = $_SESSION['user_id'];

// Handle program application
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply'])) {
    $program_id = intval($_POST['program_id']);
    $reason = trim($_POST['reason']);
    $household_income = trim($_POST['household_income']);
    
    // Check if already applied
    $check_stmt = $conn->prepare("SELECT * FROM tbl_livelihood_applications WHERE program_id = ? AND applicant_id = ?");
    $check_stmt->bind_param("ii", $program_id, $current_user_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows == 0) {
        $apply_stmt = $conn->prepare("
            INSERT INTO tbl_livelihood_applications (program_id, applicant_id, reason, household_income, application_date, status)
            VALUES (?, ?, ?, ?, NOW(), 'Pending')
        ");
        $apply_stmt->bind_param("iiss", $program_id, $current_user_id, $reason, $household_income);
        
        if ($apply_stmt->execute()) {
            $_SESSION['success_message'] = 'Application submitted successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to submit application.';
        }
        $apply_stmt->close();
    } else {
        $_SESSION['error_message'] = 'You have already applied for this program.';
    }
    $check_stmt->close();
    
    header('Location: livelihood.php');
    exit();
}

// Get active programs
$programs_query = "
    SELECT l.*,
    (SELECT COUNT(*) FROM tbl_livelihood_applications WHERE program_id = l.program_id AND applicant_id = ?) as has_applied,
    (SELECT COUNT(*) FROM tbl_livelihood_applications WHERE program_id = l.program_id AND status = 'Approved') as beneficiaries_count
    FROM tbl_livelihood_programs l
    WHERE l.status = 'Active'
    ORDER BY l.start_date ASC
";
$stmt = $conn->prepare($programs_query);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$programs = $stmt->get_result();
$stmt->close();

// Get my applications
$my_apps_query = "
    SELECT la.*, lp.program_name, lp.description
    FROM tbl_livelihood_applications la
    INNER JOIN tbl_livelihood_programs lp ON la.program_id = lp.program_id
    WHERE la.applicant_id = ?
    ORDER BY la.application_date DESC
";
$my_stmt = $conn->prepare($my_apps_query);
$my_stmt->bind_param("i", $current_user_id);
$my_stmt->execute();
$my_applications = $my_stmt->get_result();
$my_stmt->close();

include($base_path . '/includes/header.php');
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-hands-helping"></i> Livelihood Programs</h1>
            <p class="text-muted">Opportunities for sustainable income and business development</p>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php 
            echo $_SESSION['success_message']; 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php 
            echo $_SESSION['error_message']; 
            unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#programs">
                <i class="fas fa-list"></i> Available Programs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#applications">
                <i class="fas fa-file-alt"></i> My Applications
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Available Programs Tab -->
        <div class="tab-pane fade show active" id="programs">
            <?php if ($programs->num_rows > 0): ?>
                <div class="row">
                    <?php while ($program = $programs->fetch_assoc()): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100 program-card shadow-sm">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <?php echo htmlspecialchars($program['program_name']); ?>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($program['has_applied'] > 0): ?>
                                        <span class="badge bg-success mb-2">
                                            <i class="fas fa-check"></i> Applied
                                        </span>
                                    <?php endif; ?>

                                    <p class="card-text"><?php echo htmlspecialchars($program['description']); ?></p>

                                    <div class="program-details mb-3">
                                        <div class="mb-2">
                                            <i class="fas fa-users"></i>
                                            <strong>Target Beneficiaries:</strong> 
                                            <?php echo htmlspecialchars($program['target_beneficiaries']); ?>
                                        </div>

                                        <div class="mb-2">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <strong>Budget:</strong> 
                                            ₱<?php echo number_format($program['budget'], 2); ?>
                                        </div>

                                        <div class="mb-2">
                                            <i class="fas fa-hand-holding-usd"></i>
                                            <strong>Funding Source:</strong> 
                                            <?php echo htmlspecialchars($program['funding_source']); ?>
                                        </div>

                                        <div class="mb-2">
                                            <i class="fas fa-calendar"></i>
                                            <strong>Duration:</strong><br>
                                            <?php echo date('M d, Y', strtotime($program['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($program['end_date'])); ?>
                                        </div>

                                        <div class="mb-2">
                                            <i class="fas fa-user"></i>
                                            <strong>Coordinator:</strong> 
                                            <?php echo htmlspecialchars($program['coordinator']); ?>
                                        </div>
                                    </div>

                                    <button type="button" class="btn btn-sm btn-outline-dark mb-2 w-100" 
                                            data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $program['program_id']; ?>">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>

                                    <?php if ($program['has_applied'] > 0): ?>
                                        <button class="btn btn-secondary w-100" disabled>
                                            <i class="fas fa-check"></i> Already Applied
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-dark w-100" 
                                                data-bs-toggle="modal" data-bs-target="#applyModal<?php echo $program['program_id']; ?>">
                                            <i class="fas fa-paper-plane"></i> Apply Now
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- View Details Modal -->
                        <div class="modal fade" id="viewModal<?php echo $program['program_id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="fas fa-hands-helping"></i> 
                                            <?php echo htmlspecialchars($program['program_name']); ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <h6 class="fw-bold">Description</h6>
                                            <p><?php echo nl2br(htmlspecialchars($program['description'])); ?></p>
                                        </div>

                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Target Beneficiaries</h6>
                                                <p><?php echo htmlspecialchars($program['target_beneficiaries']); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Budget</h6>
                                                <p>₱<?php echo number_format($program['budget'], 2); ?></p>
                                            </div>
                                        </div>

                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Funding Source</h6>
                                                <p><?php echo htmlspecialchars($program['funding_source']); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Coordinator</h6>
                                                <p><?php echo htmlspecialchars($program['coordinator']); ?></p>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <h6 class="fw-bold">Duration</h6>
                                            <p>
                                                <?php echo date('F d, Y', strtotime($program['start_date'])); ?> - 
                                                <?php echo date('F d, Y', strtotime($program['end_date'])); ?>
                                            </p>
                                        </div>

                                        <?php if ($program['requirements']): ?>
                                        <div class="mb-3">
                                            <h6 class="fw-bold">Requirements</h6>
                                            <p><?php echo nl2br(htmlspecialchars($program['requirements'])); ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($program['benefits']): ?>
                                        <div class="mb-3">
                                            <h6 class="fw-bold">Benefits</h6>
                                            <p><?php echo nl2br(htmlspecialchars($program['benefits'])); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <?php if ($program['has_applied'] == 0): ?>
                                        <button type="button" class="btn btn-dark" 
                                                data-bs-dismiss="modal"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#applyModal<?php echo $program['program_id']; ?>">
                                            <i class="fas fa-paper-plane"></i> Apply Now
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Apply Modal -->
                        <div class="modal fade" id="applyModal<?php echo $program['program_id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Apply for <?php echo htmlspecialchars($program['program_name']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Why do you want to join this program? <span class="text-danger">*</span></label>
                                                <textarea name="reason" class="form-control" rows="4" required 
                                                          placeholder="Explain your interest and how this program will help you..."></textarea>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Monthly Household Income <span class="text-danger">*</span></label>
                                                <input type="text" name="household_income" class="form-control" required 
                                                       placeholder="e.g., ₱5,000 - ₱10,000">
                                            </div>

                                            <div class="alert alert-info">
                                                <small><strong>Note:</strong> Your application will be reviewed by the barangay officials. You may be contacted for additional information.</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="apply" class="btn btn-dark">
                                                <i class="fas fa-paper-plane"></i> Submit Application
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>No Programs Available</h5>
                    <p class="mb-0">There are currently no active livelihood programs. Please check back later.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- My Applications Tab -->
        <div class="tab-pane fade" id="applications">
            <?php if ($my_applications->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Program</th>
                                <th>Application Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($app = $my_applications->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($app['program_name']); ?></strong>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch($app['status']) {
                                            case 'Pending':
                                                $status_class = 'bg-warning';
                                                break;
                                            case 'Under Review':
                                                $status_class = 'bg-info';
                                                break;
                                            case 'Approved':
                                                $status_class = 'bg-success';
                                                break;
                                            case 'Rejected':
                                                $status_class = 'bg-danger';
                                                break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($app['status']); ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-dark" 
                                                data-bs-toggle="modal" data-bs-target="#viewApp<?php echo $app['application_id']; ?>">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>

                                <!-- View Application Modal -->
                                <div class="modal fade" id="viewApp<?php echo $app['application_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Application Details</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <h6 class="fw-bold">Program</h6>
                                                <p><?php echo htmlspecialchars($app['program_name']); ?></p>

                                                <h6 class="fw-bold">Application Date</h6>
                                                <p><?php echo date('F d, Y h:i A', strtotime($app['application_date'])); ?></p>

                                                <h6 class="fw-bold">Status</h6>
                                                <p><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($app['status']); ?></span></p>

                                                <h6 class="fw-bold">Reason for Application</h6>
                                                <p><?php echo nl2br(htmlspecialchars($app['reason'])); ?></p>

                                                <h6 class="fw-bold">Household Income</h6>
                                                <p><?php echo htmlspecialchars($app['household_income']); ?></p>

                                                <?php if (isset($app['admin_notes']) && $app['admin_notes']): ?>
                                                    <h6 class="fw-bold">Admin Notes</h6>
                                                    <div class="alert alert-info">
                                                        <?php echo nl2br(htmlspecialchars($app['admin_notes'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>No Applications Yet</h5>
                    <p class="mb-0">You haven't applied for any livelihood programs yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.program-card {
    transition: transform 0.2s, box-shadow 0.2s;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.program-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
}

.program-details {
    font-size: 0.9rem;
    color: #555;
}

.program-details i {
    color: #666;
    margin-right: 8px;
    width: 18px;
}

.nav-tabs {
    border-bottom: 2px solid #e0e0e0;
}

.nav-tabs .nav-link {
    color: #666;
    font-weight: 500;
    border: none;
    padding: 0.75rem 1.5rem;
}

.nav-tabs .nav-link:hover {
    color: #2c3e50;
    border: none;
    background-color: #f8f9fa;
}

.nav-tabs .nav-link.active {
    color: #2c3e50;
    font-weight: 600;
    background-color: transparent;
    border: none;
    border-bottom: 3px solid #2c3e50;
}

.table th {
    color: #2c3e50;
    font-weight: 600;
}

.btn-dark {
    background-color: #2c3e50;
    border-color: #2c3e50;
}

.btn-dark:hover {
    background-color: #1a252f;
    border-color: #1a252f;
}

.btn-outline-dark {
    color: #2c3e50;
    border-color: #2c3e50;
}

.btn-outline-dark:hover {
    background-color: #2c3e50;
    border-color: #2c3e50;
    color: white;
}
</style>

<?php include($base_path . '/includes/footer.php'); ?>