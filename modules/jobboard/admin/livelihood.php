<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Livelihood Programs';
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_program'])) {
        $program_name = trim($_POST['program_name']);
        $description = trim($_POST['description']);
        $target_beneficiaries = trim($_POST['target_beneficiaries']);
        $budget = floatval($_POST['budget']);
        $funding_source = trim($_POST['funding_source']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $coordinator = trim($_POST['coordinator']);
        $requirements = trim($_POST['requirements']);
        $benefits = trim($_POST['benefits']);
        
        $stmt = $conn->prepare("INSERT INTO tbl_livelihood_programs (program_name, description, target_beneficiaries, budget, funding_source, start_date, end_date, coordinator, requirements, benefits, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        $stmt->bind_param("sssdssssss", $program_name, $description, $target_beneficiaries, $budget, $funding_source, $start_date, $end_date, $coordinator, $requirements, $benefits);
        
        if ($stmt->execute()) {
            $message = "Livelihood program added successfully!";
        } else {
            $error = "Error adding program.";
        }
        $stmt->close();
    } elseif (isset($_POST['edit_program'])) {
        $program_id = intval($_POST['program_id']);
        $program_name = trim($_POST['program_name']);
        $description = trim($_POST['description']);
        $target_beneficiaries = trim($_POST['target_beneficiaries']);
        $budget = floatval($_POST['budget']);
        $funding_source = trim($_POST['funding_source']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $coordinator = trim($_POST['coordinator']);
        $requirements = trim($_POST['requirements']);
        $benefits = trim($_POST['benefits']);
        $status = $_POST['status'];
        
        $stmt = $conn->prepare("UPDATE tbl_livelihood_programs SET program_name=?, description=?, target_beneficiaries=?, budget=?, funding_source=?, start_date=?, end_date=?, coordinator=?, requirements=?, benefits=?, status=? WHERE program_id=?");
        $stmt->bind_param("sssdsssssssi", $program_name, $description, $target_beneficiaries, $budget, $funding_source, $start_date, $end_date, $coordinator, $requirements, $benefits, $status, $program_id);
        
        if ($stmt->execute()) {
            $message = "Livelihood program updated successfully!";
        } else {
            $error = "Error updating program.";
        }
        $stmt->close();
    } elseif (isset($_POST['delete_program'])) {
        $program_id = intval($_POST['program_id']);
        $stmt = $conn->prepare("DELETE FROM tbl_livelihood_programs WHERE program_id=?");
        $stmt->bind_param("i", $program_id);
        
        if ($stmt->execute()) {
            $message = "Program deleted successfully!";
        } else {
            $error = "Error deleting program.";
        }
        $stmt->close();
    }
}

// Get all programs
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "SELECT p.*
          FROM tbl_livelihood_programs p
          WHERE 1=1";

if ($search) {
    $query .= " AND p.program_name LIKE '%" . $conn->real_escape_string($search) . "%'";
}
if ($status_filter) {
    $query .= " AND p.status = '" . $conn->real_escape_string($status_filter) . "'";
}

$query .= " ORDER BY p.created_at DESC";
$programs = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

include '../../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-hands-helping"></i> Livelihood Programs Management</h2>
            <p class="text-muted">Manage community livelihood programs and initiatives</p>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                <i class="fas fa-plus-circle"></i> Add Program
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search programs..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Completed" <?php echo $status_filter == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo $status_filter == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Programs Grid -->
    <div class="row">
        <?php if (empty($programs)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>No Programs Found</h5>
                    <p class="mb-0">No livelihood programs found. Click "Add Program" to create one.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($programs as $program): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 border-0 shadow-sm program-card">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><?php echo htmlspecialchars($program['program_name']); ?></h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-2">
                                <i class="fas fa-users"></i> <strong>Target:</strong> <?php echo htmlspecialchars($program['target_beneficiaries']); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="fas fa-money-bill-wave"></i> <strong>Budget:</strong> ₱<?php echo number_format($program['budget'], 2); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="fas fa-hand-holding-usd"></i> <strong>Funding:</strong> <?php echo htmlspecialchars($program['funding_source']); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="fas fa-calendar"></i> <strong>Duration:</strong><br>
                                <?php echo date('M d, Y', strtotime($program['start_date'])); ?> - 
                                <?php echo date('M d, Y', strtotime($program['end_date'])); ?>
                            </p>
                            <p class="text-muted mb-2">
                                <i class="fas fa-user"></i> <strong>Coordinator:</strong> <?php echo htmlspecialchars($program['coordinator']); ?>
                            </p>
                            <p class="mb-0">
                                <?php
                                $badge_class = match($program['status']) {
                                    'Active' => 'success',
                                    'Completed' => 'secondary',
                                    'Cancelled' => 'danger',
                                    default => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($program['status']); ?></span>
                            </p>
                        </div>
                        <div class="card-footer bg-transparent border-top">
                            <div class="btn-group w-100" role="group">
                                <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#viewProgramModal<?php echo $program['program_id']; ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editProgramModal<?php echo $program['program_id']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteProgramModal<?php echo $program['program_id']; ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Program Modal -->
                <div class="modal fade" id="viewProgramModal<?php echo $program['program_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-eye"></i> Program Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <h4><?php echo htmlspecialchars($program['program_name']); ?></h4>
                                        <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $program['status']; ?></span>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <h6 class="fw-bold">Description</h6>
                                        <p><?php echo nl2br(htmlspecialchars($program['description'])); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="fw-bold">Target Beneficiaries</h6>
                                        <p><?php echo htmlspecialchars($program['target_beneficiaries']); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="fw-bold">Budget</h6>
                                        <p>₱<?php echo number_format($program['budget'], 2); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="fw-bold">Funding Source</h6>
                                        <p><?php echo htmlspecialchars($program['funding_source']); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="fw-bold">Duration</h6>
                                        <p><?php echo date('M d, Y', strtotime($program['start_date'])); ?> - <?php echo date('M d, Y', strtotime($program['end_date'])); ?></p>
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <h6 class="fw-bold">Coordinator</h6>
                                        <p><?php echo htmlspecialchars($program['coordinator']); ?></p>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <h6 class="fw-bold">Requirements</h6>
                                        <p><?php echo nl2br(htmlspecialchars($program['requirements'])); ?></p>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <h6 class="fw-bold">Benefits</h6>
                                        <p><?php echo nl2br(htmlspecialchars($program['benefits'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Program Modal -->
                <div class="modal fade" id="editProgramModal<?php echo $program['program_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Program</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Program Name *</label>
                                            <input type="text" name="program_name" class="form-control" value="<?php echo htmlspecialchars($program['program_name']); ?>" required>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Description *</label>
                                            <textarea name="description" class="form-control" rows="3" required><?php echo htmlspecialchars($program['description']); ?></textarea>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Target Beneficiaries *</label>
                                            <input type="text" name="target_beneficiaries" class="form-control" value="<?php echo htmlspecialchars($program['target_beneficiaries']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Budget *</label>
                                            <input type="number" name="budget" class="form-control" step="0.01" min="0" value="<?php echo $program['budget']; ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Funding Source *</label>
                                            <input type="text" name="funding_source" class="form-control" value="<?php echo htmlspecialchars($program['funding_source']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Status *</label>
                                            <select name="status" class="form-select" required>
                                                <option value="Active" <?php echo $program['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="Completed" <?php echo $program['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="Cancelled" <?php echo $program['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Start Date *</label>
                                            <input type="date" name="start_date" class="form-control" value="<?php echo $program['start_date']; ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">End Date *</label>
                                            <input type="date" name="end_date" class="form-control" value="<?php echo $program['end_date']; ?>" required>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Program Coordinator *</label>
                                            <input type="text" name="coordinator" class="form-control" value="<?php echo htmlspecialchars($program['coordinator']); ?>" required>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Requirements *</label>
                                            <textarea name="requirements" class="form-control" rows="3" required><?php echo htmlspecialchars($program['requirements']); ?></textarea>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Benefits *</label>
                                            <textarea name="benefits" class="form-control" rows="3" required><?php echo htmlspecialchars($program['benefits']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="edit_program" class="btn btn-dark">
                                        <i class="fas fa-save"></i> Update Program
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Program Modal -->
                <div class="modal fade" id="deleteProgramModal<?php echo $program['program_id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Program</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                    <p>Are you sure you want to delete this program?</p>
                                    <div class="alert alert-warning">
                                        <strong>Program:</strong> <?php echo htmlspecialchars($program['program_name']); ?>
                                    </div>
                                    <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="delete_program" class="btn btn-danger">
                                        <i class="fas fa-trash"></i> Delete Program
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add Livelihood Program</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">Program Name *</label>
                            <input type="text" name="program_name" class="form-control" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Target Beneficiaries *</label>
                            <input type="text" name="target_beneficiaries" class="form-control" placeholder="e.g., Low-income families" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Budget *</label>
                            <input type="number" name="budget" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Funding Source *</label>
                            <input type="text" name="funding_source" class="form-control" required>
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
                            <label class="form-label">Program Coordinator *</label>
                            <input type="text" name="coordinator" class="form-control" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Requirements *</label>
                            <textarea name="requirements" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Benefits *</label>
                            <textarea name="benefits" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_program" class="btn btn-dark">
                        <i class="fas fa-plus"></i> Add Program
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.program-card {
    transition: transform 0.2s, box-shadow 0.2s;
    border-radius: 8px;
}

.program-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.12) !important;
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
    border-color: #dee2e6;
}

.btn-outline-dark:hover {
    background-color: #2c3e50;
    border-color: #2c3e50;
    color: white;
}

.bg-dark {
    background-color: #2c3e50 !important;
}
</style>

<?php include '../../../includes/footer.php'; ?>