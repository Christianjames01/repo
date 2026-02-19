<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Skills Training Programs';
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_training'])) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $training_provider = trim($_POST['training_provider']);
        $category = trim($_POST['category']);
        $duration = trim($_POST['duration']);
        $schedule = trim($_POST['schedule']);
        $venue = trim($_POST['venue']);
        $location = trim($_POST['location']);
        $slots = intval($_POST['slots']);
        $max_participants = intval($_POST['max_participants']);
        $requirements = trim($_POST['requirements']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $registration_deadline = $_POST['registration_deadline'];
        $contact_person = trim($_POST['contact_person']);
        $contact_number = trim($_POST['contact_number']);
        $instructor = trim($_POST['instructor']);
        
        $stmt = $conn->prepare("INSERT INTO tbl_trainings (title, description, training_provider, category, duration, schedule, venue, location, slots, max_participants, requirements, start_date, end_date, registration_deadline, contact_person, contact_number, instructor, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming')");
        $stmt->bind_param("ssssssssiisssssss", $title, $description, $training_provider, $category, $duration, $schedule, $venue, $location, $slots, $max_participants, $requirements, $start_date, $end_date, $registration_deadline, $contact_person, $contact_number, $instructor);
        
        if ($stmt->execute()) {
            $message = "Training program added successfully!";
        } else {
            $error = "Error adding training program: " . $stmt->error;
        }
    } elseif (isset($_POST['update_training'])) {
        $training_id = $_POST['training_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $training_provider = trim($_POST['training_provider']);
        $category = trim($_POST['category']);
        $duration = trim($_POST['duration']);
        $schedule = trim($_POST['schedule']);
        $venue = trim($_POST['venue']);
        $location = trim($_POST['location']);
        $slots = intval($_POST['slots']);
        $max_participants = intval($_POST['max_participants']);
        $requirements = trim($_POST['requirements']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $registration_deadline = $_POST['registration_deadline'];
        $contact_person = trim($_POST['contact_person']);
        $contact_number = trim($_POST['contact_number']);
        $instructor = trim($_POST['instructor']);
        $status = $_POST['status'];
        
        $stmt = $conn->prepare("UPDATE tbl_trainings SET title=?, description=?, training_provider=?, category=?, duration=?, schedule=?, venue=?, location=?, slots=?, max_participants=?, requirements=?, start_date=?, end_date=?, registration_deadline=?, contact_person=?, contact_number=?, instructor=?, status=? WHERE training_id=?");
        $stmt->bind_param("ssssssssiisssssssi", $title, $description, $training_provider, $category, $duration, $schedule, $venue, $location, $slots, $max_participants, $requirements, $start_date, $end_date, $registration_deadline, $contact_person, $contact_number, $instructor, $status, $training_id);
        
        if ($stmt->execute()) {
            $message = "Training program updated successfully!";
        } else {
            $error = "Error updating training program: " . $stmt->error;
        }
    } elseif (isset($_POST['delete_training'])) {
        $training_id = $_POST['training_id'];
        
        // First delete related enrollments
        $delete_enrollments = $conn->prepare("DELETE FROM tbl_training_enrollments WHERE training_id=?");
        $delete_enrollments->bind_param("i", $training_id);
        $delete_enrollments->execute();
        
        // Then delete the training
        $stmt = $conn->prepare("DELETE FROM tbl_trainings WHERE training_id=?");
        $stmt->bind_param("i", $training_id);
        
        if ($stmt->execute()) {
            $message = "Training program deleted successfully!";
        } else {
            $error = "Error deleting training program: " . $stmt->error;
        }
    } elseif (isset($_POST['update_enrollment_status'])) {
        $enrollment_id = $_POST['enrollment_id'];
        $new_status = $_POST['enrollment_status'];
        
        $stmt = $conn->prepare("UPDATE tbl_training_enrollments SET status=? WHERE enrollment_id=?");
        $stmt->bind_param("si", $new_status, $enrollment_id);
        
        if ($stmt->execute()) {
            $message = "Enrollment status updated successfully!";
        } else {
            $error = "Error updating enrollment status: " . $stmt->error;
        }
    }
}

// Get all trainings with enrollment counts
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "SELECT t.*, 
          COUNT(te.enrollment_id) as total_enrollments,
          SUM(CASE WHEN te.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
          SUM(CASE WHEN te.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
          SUM(CASE WHEN te.status = 'completed' THEN 1 ELSE 0 END) as completed_count
          FROM tbl_trainings t
          LEFT JOIN tbl_training_enrollments te ON t.training_id = te.training_id
          WHERE 1=1";

if ($search) {
    $query .= " AND (t.title LIKE '%" . $conn->real_escape_string($search) . "%' OR t.training_provider LIKE '%" . $conn->real_escape_string($search) . "%')";
}
if ($category_filter) {
    $query .= " AND t.category = '" . $conn->real_escape_string($category_filter) . "'";
}
if ($status_filter) {
    $query .= " AND t.status = '" . $conn->real_escape_string($status_filter) . "'";
}

$query .= " GROUP BY t.training_id ORDER BY t.start_date DESC";

$result = $conn->query($query);

if (!$result) {
    $error = "Database error: " . $conn->error;
    $trainings = [];
} else {
    $trainings = $result->fetch_all(MYSQLI_ASSOC);
}

// Get categories
$categories = ['Technical Skills', 'Livelihood', 'Business Management', 'Computer Literacy', 'Vocational', 'Other'];

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-chalkboard-teacher"></i> Skills Training Programs</h2>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTrainingModal">
                <i class="fas fa-plus-circle"></i> Add Training Program
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search trainings..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="upcoming" <?php echo $status_filter == 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="ongoing" <?php echo $status_filter == 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Trainings Grid -->
    <div class="row">
        <?php if (empty($trainings)): ?>
            <div class="col-12">
                <div class="alert alert-info">No training programs found</div>
            </div>
        <?php else: ?>
            <?php foreach ($trainings as $training): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><?php echo htmlspecialchars($training['title'] ?? 'Untitled'); ?></h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-2">
                                <i class="fas fa-building"></i> <?php echo htmlspecialchars($training['training_provider'] ?? 'N/A'); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($training['category'] ?? 'N/A'); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="fas fa-clock"></i> <?php echo htmlspecialchars($training['duration'] ?? 'N/A'); ?>
                            </p>
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
                                $enrolled = $training['approved_count'] ?? 0;
                                echo $enrolled . ' / ' . $max;
                                ?>
                            </p>
                            
                            <!-- Enrollment Stats -->
                            <div class="enrollment-stats mt-3 mb-3">
                                <small class="d-block">
                                    <span class="badge bg-warning">Pending: <?php echo $training['pending_count'] ?? 0; ?></span>
                                    <span class="badge bg-success">Approved: <?php echo $training['approved_count'] ?? 0; ?></span>
                                    <span class="badge bg-dark">Completed: <?php echo $training['completed_count'] ?? 0; ?></span>
                                </small>
                            </div>

                            <p class="mb-2">
                                <?php
                                $status = $training['status'] ?? 'upcoming';
                                $badge_class = match($status) {
                                    'upcoming' => 'info',
                                    'ongoing' => 'success',
                                    'completed' => 'secondary',
                                    'cancelled' => 'danger',
                                    default => 'info'
                                };
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($status); ?></span>
                            </p>
                        </div>
                        <div class="card-footer bg-transparent">
                            <button class="btn btn-sm btn-info" onclick='viewTraining(<?php echo json_encode($training); ?>)'>
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-sm btn-success" onclick="viewEnrollments(<?php echo $training['training_id']; ?>, '<?php echo htmlspecialchars($training['title'], ENT_QUOTES); ?>')">
                                <i class="fas fa-users"></i> Enrollments
                            </button>
                            <button class="btn btn-sm btn-warning" onclick='editTraining(<?php echo json_encode($training); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteTraining(<?php echo $training['training_id'] ?? 0; ?>, '<?php echo htmlspecialchars($training['title'] ?? '', ENT_QUOTES); ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Training Modal -->
<div class="modal fade" id="addTrainingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Training Program</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Training Title *</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Training Provider *</label>
                            <input type="text" name="training_provider" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration *</label>
                            <input type="text" name="duration" class="form-control" placeholder="e.g., 2 weeks" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Schedule *</label>
                            <input type="text" name="schedule" class="form-control" placeholder="e.g., Mon-Fri, 9AM-5PM" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Venue *</label>
                            <input type="text" name="venue" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Instructor</label>
                            <input type="text" name="instructor" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Available Slots *</label>
                            <input type="number" name="slots" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Participants *</label>
                            <input type="number" name="max_participants" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Deadline *</label>
                            <input type="date" name="registration_deadline" class="form-control" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Requirements *</label>
                            <textarea name="requirements" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_training" class="btn btn-primary">Add Training</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Training Modal -->
<div class="modal fade" id="viewTrainingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Training Program Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <h4 id="view_title" class="text-primary"></h4>
                    </div>
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
                        <label class="fw-bold">Venue:</label>
                        <p id="view_venue"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Location:</label>
                        <p id="view_location"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Instructor:</label>
                        <p id="view_instructor"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Available Slots:</label>
                        <p id="view_slots"></p>
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
                        <p id="view_reg_deadline"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Status:</label>
                        <p id="view_status"></p>
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="fw-bold">Description:</label>
                        <p id="view_description"></p>
                    </div>
                    <div class="col-md-12 mb-3">
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
            </div>
        </div>
    </div>
</div>

<!-- Edit Training Modal -->
<div class="modal fade" id="editTrainingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Training Program</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="training_id" id="edit_training_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Training Title *</label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Training Provider *</label>
                            <input type="text" name="training_provider" id="edit_provider" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category" id="edit_category" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration *</label>
                            <input type="text" name="duration" id="edit_duration" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Schedule *</label>
                            <input type="text" name="schedule" id="edit_schedule" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Venue *</label>
                            <input type="text" name="venue" id="edit_venue" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" id="edit_location" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Instructor</label>
                            <input type="text" name="instructor" id="edit_instructor" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Available Slots *</label>
                            <input type="number" name="slots" id="edit_slots" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Participants *</label>
                            <input type="number" name="max_participants" id="edit_max_participants" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Deadline *</label>
                            <input type="date" name="registration_deadline" id="edit_reg_deadline" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="upcoming">Upcoming</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description *</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Requirements *</label>
                            <textarea name="requirements" id="edit_requirements" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" name="contact_person" id="edit_contact_person" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" id="edit_contact_number" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_training" class="btn btn-warning">Update Training</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Training Modal -->
<div class="modal fade" id="deleteTrainingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Training Program</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="training_id" id="delete_training_id">
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Warning!</strong> This action cannot be undone and will delete all enrollments.
                    </div>
                    <p>Are you sure you want to delete this training program?</p>
                    <p class="fw-bold" id="delete_training_title"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_training" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Enrollments Modal -->
<div class="modal fade" id="enrollmentsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-users"></i> Training Enrollments - <span id="enrollments_training_title"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="enrollments_content">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
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
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

function getStatusBadge(status) {
    const badges = {
        'upcoming': 'info',
        'ongoing': 'success',
        'completed': 'secondary',
        'cancelled': 'danger'
    };
    const badgeClass = badges[status] || 'info';
    return `<span class="badge bg-${badgeClass}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
}

function viewTraining(training) {
    if (!training || !training.training_id) return;
    
    document.getElementById('view_title').textContent = training.title || 'N/A';
    document.getElementById('view_provider').textContent = training.training_provider || 'N/A';
    document.getElementById('view_category').textContent = training.category || 'N/A';
    document.getElementById('view_duration').textContent = training.duration || 'N/A';
    document.getElementById('view_schedule').textContent = training.schedule || 'N/A';
    document.getElementById('view_venue').textContent = training.venue || 'N/A';
    document.getElementById('view_location').textContent = training.location || 'N/A';
    document.getElementById('view_instructor').textContent = training.instructor || 'N/A';
    document.getElementById('view_slots').textContent = training.max_participants || training.slots || '0';
    document.getElementById('view_start_date').textContent = formatDate(training.start_date);
    document.getElementById('view_end_date').textContent = formatDate(training.end_date);
    document.getElementById('view_reg_deadline').textContent = formatDate(training.registration_deadline);
    document.getElementById('view_status').innerHTML = getStatusBadge(training.status);
    document.getElementById('view_description').textContent = training.description || 'N/A';
    document.getElementById('view_requirements').textContent = training.requirements || 'N/A';
    document.getElementById('view_contact_person').textContent = training.contact_person || 'N/A';
    document.getElementById('view_contact_number').textContent = training.contact_number || 'N/A';
    
    const modal = new bootstrap.Modal(document.getElementById('viewTrainingModal'));
    modal.show();
}

function editTraining(training) {
    if (!training || !training.training_id) return;
    
    document.getElementById('edit_training_id').value = training.training_id;
    document.getElementById('edit_title').value = training.title || '';
    document.getElementById('edit_provider').value = training.training_provider || '';
    document.getElementById('edit_category').value = training.category || '';
    document.getElementById('edit_duration').value = training.duration || '';
    document.getElementById('edit_schedule').value = training.schedule || '';
    document.getElementById('edit_venue').value = training.venue || '';
    document.getElementById('edit_location').value = training.location || '';
    document.getElementById('edit_instructor').value = training.instructor || '';
    document.getElementById('edit_slots').value = training.slots || '';
    document.getElementById('edit_max_participants').value = training.max_participants || '';
    document.getElementById('edit_start_date').value = training.start_date || '';
    document.getElementById('edit_end_date').value = training.end_date || '';
    document.getElementById('edit_reg_deadline').value = training.registration_deadline || '';
    document.getElementById('edit_status').value = training.status || 'upcoming';
    document.getElementById('edit_description').value = training.description || '';
    document.getElementById('edit_requirements').value = training.requirements || '';
    document.getElementById('edit_contact_person').value = training.contact_person || '';
    document.getElementById('edit_contact_number').value = training.contact_number || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editTrainingModal'));
    modal.show();
}

function deleteTraining(id, title) {
    if (!id || id <= 0) return;
    
    document.getElementById('delete_training_id').value = id;
    document.getElementById('delete_training_title').textContent = title || 'this training program';
    
    const modal = new bootstrap.Modal(document.getElementById('deleteTrainingModal'));
    modal.show();
}

function viewEnrollments(trainingId, trainingTitle) {
    document.getElementById('enrollments_training_title').textContent = trainingTitle;
    document.getElementById('enrollments_content').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    
    const modal = new bootstrap.Modal(document.getElementById('enrollmentsModal'));
    modal.show();
    
    // Fetch enrollments via AJAX
    fetch('get_enrollments.php?training_id=' + trainingId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('enrollments_content').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('enrollments_content').innerHTML = '<div class="alert alert-danger">Error loading enrollments</div>';
        });
}

function updateEnrollmentStatus(enrollmentId, status) {
    if (confirm('Are you sure you want to update this enrollment status?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="enrollment_id" value="${enrollmentId}">
            <input type="hidden" name="enrollment_status" value="${status}">
            <input type="hidden" name="update_enrollment_status" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<style>
.card {
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-5px);
}
.enrollment-stats .badge {
    margin-right: 5px;
    margin-bottom: 5px;
}
</style>

<?php include '../../../includes/footer.php'; ?>