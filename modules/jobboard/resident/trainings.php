<?php
/**
 * Skills Training Programs - Resident View
 * BarangayLink System
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define base path for easier includes
$base_path = $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1';

// Include database
require_once($base_path . '/config/database.php');

// IMPORTANT: Include auth.php BEFORE header.php since header needs those functions
if (file_exists($base_path . '/config/auth.php')) {
    require_once($base_path . '/config/auth.php');
}

// Get the actual resident_id from the database based on user_id
$user_id = $_SESSION['user_id'] ?? 1;

// Query to get resident_id from tbl_residents based on user session
// Assuming there's a link between users and residents (adjust as needed)
$resident_query = "SELECT resident_id FROM tbl_residents WHERE resident_id = ? LIMIT 1";
$resident_stmt = $conn->prepare($resident_query);
$resident_stmt->bind_param("i", $user_id);
$resident_stmt->execute();
$resident_result = $resident_stmt->get_result();

if ($resident_result->num_rows > 0) {
    $resident_data = $resident_result->fetch_assoc();
    $resident_id = $resident_data['resident_id'];
} else {
    // If no resident found, use user_id as fallback (for testing)
    $resident_id = $user_id;
}
$resident_stmt->close();

$success_message = '';
$error_message = '';

// Handle training enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_training'])) {
    $training_id = intval($_POST['training_id']);
    
    // Debug: Log the enrollment attempt
    error_log("Enrollment attempt - Training ID: $training_id, Resident ID: $resident_id");
    
    // Check if already enrolled
    $check_stmt = $conn->prepare("SELECT enrollment_id FROM tbl_training_enrollments WHERE training_id = ? AND resident_id = ?");
    $check_stmt->bind_param("ii", $training_id, $resident_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error_message = "You are already enrolled in this training program.";
    } else {
        // Check if training is still accepting enrollments
        $training_check = $conn->prepare("SELECT max_participants, (SELECT COUNT(*) FROM tbl_training_enrollments WHERE training_id = ? AND status != 'rejected') as enrolled FROM tbl_trainings WHERE training_id = ?");
        $training_check->bind_param("ii", $training_id, $training_id);
        $training_check->execute();
        $training_data = $training_check->get_result()->fetch_assoc();
        
        if ($training_data && $training_data['enrolled'] >= $training_data['max_participants']) {
            $error_message = "Sorry, this training is already full.";
        } else {
            // Enroll the resident with current timestamp
            $enroll_stmt = $conn->prepare("INSERT INTO tbl_training_enrollments (training_id, resident_id, status, enrollment_date) VALUES (?, ?, 'pending', NOW())");
            $enroll_stmt->bind_param("ii", $training_id, $resident_id);
            
            if ($enroll_stmt->execute()) {
                $enrollment_id = $enroll_stmt->insert_id;
                error_log("Enrollment successful - Enrollment ID: $enrollment_id");
                $success_message = "Successfully enrolled! Your enrollment is pending approval.";
            } else {
                error_log("Enrollment failed - Error: " . $enroll_stmt->error);
                $error_message = "Failed to enroll. Please try again. Error: " . $enroll_stmt->error;
            }
            $enroll_stmt->close();
        }
        $training_check->close();
    }
    $check_stmt->close();
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';

// Fetch available trainings
$available_trainings_query = "
    SELECT t.*, 
           COUNT(CASE WHEN te.status != 'rejected' THEN te.enrollment_id END) as enrolled_count,
           (SELECT COUNT(*) FROM tbl_training_enrollments WHERE training_id = t.training_id AND resident_id = ?) as is_enrolled
    FROM tbl_trainings t
    LEFT JOIN tbl_training_enrollments te ON t.training_id = te.training_id
    WHERE t.status = 'upcoming' 
    AND (t.registration_deadline IS NULL OR t.registration_deadline >= CURDATE())
";

if ($search) {
    $available_trainings_query .= " AND (t.title LIKE '%" . $conn->real_escape_string($search) . "%' OR t.training_provider LIKE '%" . $conn->real_escape_string($search) . "%')";
}
if ($category_filter) {
    $available_trainings_query .= " AND t.category = '" . $conn->real_escape_string($category_filter) . "'";
}

$available_trainings_query .= "
    GROUP BY t.training_id
    HAVING (t.max_participants IS NULL OR enrolled_count < t.max_participants)
    ORDER BY t.start_date ASC
";

$stmt = $conn->prepare($available_trainings_query);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$available_trainings = $stmt->get_result();
$stmt->close();

// Fetch user's enrollments
$my_trainings_query = "
    SELECT t.*, te.enrollment_id, te.enrollment_date, te.status as enrollment_status, te.completion_date, te.certificate_issued
    FROM tbl_training_enrollments te
    JOIN tbl_trainings t ON te.training_id = t.training_id
    WHERE te.resident_id = ?
    ORDER BY te.enrollment_date DESC
";

$my_stmt = $conn->prepare($my_trainings_query);
$my_stmt->bind_param("i", $resident_id);
$my_stmt->execute();
$my_trainings = $my_stmt->get_result();
$my_stmt->close();

// Get categories for filter
$categories = ['Technical Skills', 'Livelihood', 'Business Management', 'Computer Literacy', 'Vocational', 'Other'];

// Set page title for header
$page_title = "Skills Training Programs";

// Include header with sidebar
include($base_path . '/includes/header.php');
?>

<div class="container-fluid py-4">
    <!-- Debug Info (Remove in production) -->
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <strong>Debug Info:</strong> User ID: <?php echo $user_id; ?>, Resident ID: <?php echo $resident_id; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-graduation-cap"></i> Skills Training Programs</h2>
            <p class="text-muted">Enhance your skills and employability through our training programs</p>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4" id="trainingTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="available-tab" data-bs-toggle="tab" data-bs-target="#available" type="button">
                <i class="fas fa-list"></i> Available Trainings
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="my-trainings-tab" data-bs-toggle="tab" data-bs-target="#my-trainings" type="button">
                <i class="fas fa-user-graduate"></i> My Enrollments (<?php echo $my_trainings->num_rows; ?>)
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="trainingTabsContent">
        <!-- Available Trainings Tab -->
        <div class="tab-pane fade show active" id="available" role="tabpanel">
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <input type="text" name="search" class="form-control" placeholder="Search trainings..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Trainings Grid -->
            <div class="row">
                <?php if ($available_trainings->num_rows > 0): ?>
                    <?php while ($training = $available_trainings->fetch_assoc()): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100 shadow-sm training-card">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0"><?php echo htmlspecialchars($training['title'] ?? 'Untitled'); ?></h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($training['training_provider'] ?? 'N/A'); ?>
                                    </p>
                                    <?php if ($training['category']): ?>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($training['category']); ?>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ($training['duration']): ?>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars($training['duration']); ?>
                                    </p>
                                    <?php endif; ?>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-calendar"></i> 
                                        <?php 
                                        if (isset($training['start_date']) && isset($training['end_date'])) {
                                            echo date('M d, Y', strtotime($training['start_date'])) . ' - ' . date('M d, Y', strtotime($training['end_date']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </p>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($training['location'] ?? $training['venue'] ?? 'N/A'); ?>
                                    </p>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-users"></i> <strong>Slots:</strong> 
                                        <?php 
                                        $max = $training['max_participants'] ?? $training['slots'] ?? 0;
                                        $enrolled = $training['enrolled_count'] ?? 0;
                                        $available = $max - $enrolled;
                                        echo $available . ' / ' . $max . ' available';
                                        ?>
                                    </p>
                                    
                                    <?php if ($training['is_enrolled'] > 0): ?>
                                        <span class="badge bg-success mb-2"><i class="fas fa-check"></i> Already Enrolled</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <button class="btn btn-sm btn-info" onclick='viewTraining(<?php echo json_encode($training); ?>)'>
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                    
                                    <?php if ($training['is_enrolled'] > 0): ?>
                                        <button class="btn btn-sm btn-secondary" disabled>
                                            <i class="fas fa-check"></i> Enrolled
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to enroll in this training?');">
                                            <input type="hidden" name="training_id" value="<?php echo $training['training_id']; ?>">
                                            <button type="submit" name="enroll_training" class="btn btn-sm btn-primary">
                                                <i class="fas fa-user-plus"></i> Enroll
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle fa-3x mb-3"></i>
                            <h5>No Training Programs Available</h5>
                            <p>There are currently no open training programs. Please check back later.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- My Trainings Tab -->
        <div class="tab-pane fade" id="my-trainings" role="tabpanel">
            <div class="row">
                <?php if ($my_trainings->num_rows > 0): ?>
                    <div class="col-12">
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Training Title</th>
                                        <th>Category</th>
                                        <th>Duration</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Enrolled On</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Reset pointer
                                    $my_trainings->data_seek(0);
                                    $count = 1; 
                                    while ($enrollment = $my_trainings->fetch_assoc()): 
                                    ?>
                                    <tr>
                                        <td><?php echo $count++; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($enrollment['title']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-map-marker-alt"></i> 
                                                <?php echo htmlspecialchars($enrollment['location'] ?? $enrollment['venue'] ?? 'N/A'); ?>
                                            </small>
                                        </td>
                                        <td><?php echo htmlspecialchars($enrollment['category'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($enrollment['duration'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($enrollment['start_date'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($enrollment['end_date'])); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($enrollment['enrollment_date'])); ?>
                                            <br>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($enrollment['enrollment_date'])); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            $status_icon = '';
                                            switch($enrollment['enrollment_status']) {
                                                case 'pending':
                                                    $status_class = 'warning';
                                                    $status_icon = 'clock';
                                                    break;
                                                case 'approved':
                                                    $status_class = 'success';
                                                    $status_icon = 'check-circle';
                                                    break;
                                                case 'rejected':
                                                    $status_class = 'danger';
                                                    $status_icon = 'times-circle';
                                                    break;
                                                case 'completed':
                                                    $status_class = 'dark';
                                                    $status_icon = 'graduation-cap';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                                <?php echo ucfirst($enrollment['enrollment_status']); ?>
                                            </span>
                                            <?php if ($enrollment['completion_date']): ?>
                                                <br><small class="text-muted"><?php echo date('M d, Y', strtotime($enrollment['completion_date'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-info mb-1" onclick='viewEnrollmentDetails(<?php echo json_encode($enrollment); ?>)'>
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if ($enrollment['certificate_issued']): ?>
                                                <a href="download_certificate.php?id=<?php echo $enrollment['enrollment_id']; ?>" class="btn btn-sm btn-success">
                                                    <i class="fas fa-download"></i> Certificate
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle fa-3x mb-3"></i>
                            <h5>No Enrollments Yet</h5>
                            <p>You haven't enrolled in any training programs yet. Browse available trainings and enroll now!</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- View Training Details Modal -->
<div class="modal fade" id="viewTrainingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-book"></i> <span id="view_title"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Training Provider:</label>
                        <p id="view_provider"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Category:</label>
                        <p id="view_category"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Duration:</label>
                        <p id="view_duration"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Schedule:</label>
                        <p id="view_schedule"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Location/Venue:</label>
                        <p id="view_location"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Instructor:</label>
                        <p id="view_instructor"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Start Date:</label>
                        <p id="view_start_date"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">End Date:</label>
                        <p id="view_end_date"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Registration Deadline:</label>
                        <p id="view_deadline"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Available Slots:</label>
                        <p id="view_slots"></p>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="fw-bold">Description:</label>
                        <p id="view_description"></p>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="fw-bold">Requirements:</label>
                        <p id="view_requirements"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Contact Person:</label>
                        <p id="view_contact_person"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Contact Number:</label>
                        <p id="view_contact_number"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <form method="POST" class="d-inline" id="modal_enroll_form">
                    <input type="hidden" name="training_id" id="modal_training_id">
                    <button type="submit" name="enroll_training" class="btn btn-primary" id="enroll_btn" onclick="return confirm('Are you sure you want to enroll in this training?');">
                        <i class="fas fa-user-plus"></i> Enroll Now
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Enrollment Details Modal -->
<div class="modal fade" id="viewEnrollmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Enrollment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-12 mb-3">
                        <h5 id="enroll_title"></h5>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Training Provider:</strong> <span id="enroll_provider"></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Category:</strong> <span id="enroll_category"></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Duration:</strong> <span id="enroll_duration"></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Schedule:</strong> <span id="enroll_schedule"></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Location:</strong> <span id="enroll_location"></span>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Instructor:</strong> <span id="enroll_instructor"></span>
                    </div>
                    <div class="col-12 mb-2">
                        <strong>Description:</strong> <span id="enroll_description"></span>
                    </div>
                    <div class="col-12 mb-2">
                        <strong>Requirements:</strong> <span id="enroll_requirements"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentTrainingId = null;

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

function viewTraining(training) {
    if (!training || !training.training_id) return;
    
    currentTrainingId = training.training_id;
    document.getElementById('modal_training_id').value = training.training_id;
    
    document.getElementById('view_title').textContent = training.title || 'N/A';
    document.getElementById('view_provider').textContent = training.training_provider || 'N/A';
    document.getElementById('view_category').textContent = training.category || 'N/A';
    document.getElementById('view_duration').textContent = training.duration || 'N/A';
    document.getElementById('view_schedule').textContent = training.schedule || 'N/A';
    document.getElementById('view_location').textContent = training.location || training.venue || 'N/A';
    document.getElementById('view_instructor').textContent = training.instructor || 'N/A';
    document.getElementById('view_start_date').textContent = formatDate(training.start_date);
    document.getElementById('view_end_date').textContent = formatDate(training.end_date);
    document.getElementById('view_deadline').textContent = formatDate(training.registration_deadline);
    
    const max = training.max_participants || training.slots || 0;
    const enrolled = training.enrolled_count || 0;
    const available = max - enrolled;
    document.getElementById('view_slots').textContent = available + ' / ' + max + ' available';
    
    document.getElementById('view_description').textContent = training.description || 'N/A';
    document.getElementById('view_requirements').textContent = training.requirements || 'N/A';
    document.getElementById('view_contact_person').textContent = training.contact_person || 'N/A';
    document.getElementById('view_contact_number').textContent = training.contact_number || 'N/A';
    
    // Show/hide enroll button
    const enrollBtn = document.getElementById('enroll_btn');
    if (training.is_enrolled > 0) {
        enrollBtn.style.display = 'none';
    } else {
        enrollBtn.style.display = 'inline-block';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('viewTrainingModal'));
    modal.show();
}

function viewEnrollmentDetails(enrollment) {
    document.getElementById('enroll_title').textContent = enrollment.title || 'N/A';
    document.getElementById('enroll_provider').textContent = enrollment.training_provider || 'N/A';
    document.getElementById('enroll_category').textContent = enrollment.category || 'N/A';
    document.getElementById('enroll_duration').textContent = enrollment.duration || 'N/A';
    document.getElementById('enroll_schedule').textContent = enrollment.schedule || 'N/A';
    document.getElementById('enroll_location').textContent = enrollment.location || enrollment.venue || 'N/A';
    document.getElementById('enroll_instructor').textContent = enrollment.instructor || 'N/A';
    document.getElementById('enroll_description').textContent = enrollment.description || 'N/A';
    document.getElementById('enroll_requirements').textContent = enrollment.requirements || 'N/A';
    
    const modal = new bootstrap.Modal(document.getElementById('viewEnrollmentModal'));
    modal.show();
}
</script>

<style>
.training-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.training-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.15) !important;
}

.nav-tabs .nav-link {
    color: #666;
    font-weight: 500;
    border: none;
    padding: 0.75rem 1.5rem;
}

.nav-tabs .nav-link:hover {
    color: #0d6efd;
    border: none;
    background-color: #f8f9fa;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    font-weight: 600;
    background-color: transparent;
    border: none;
    border-bottom: 3px solid #0d6efd;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.card-header {
    font-weight: 600;
}
</style>

<?php 
// Free result sets
if ($available_trainings) $available_trainings->free();
if ($my_trainings) $my_trainings->free();

include($base_path . '/includes/footer.php'); 
?>