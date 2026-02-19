<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$user_id = getCurrentUserId();
$page_title = 'Recycling Programs';

// Handle program enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_program'])) {
    $program_id = (int)$_POST['program_id'];
    $resident_id = fetchOne($conn, "SELECT resident_id FROM tbl_users WHERE user_id = ?", [$user_id], 'i')['resident_id'] ?? null;
    
    if ($resident_id) {
        // Check if already enrolled
        $existing = fetchOne($conn, 
            "SELECT participant_id FROM tbl_recycling_participants 
             WHERE program_id = ? AND resident_id = ?",
            [$program_id, $resident_id], 'ii'
        );
        
        if ($existing) {
            setMessage('You are already enrolled in this program!', 'warning');
        } else {
            $result = executeQuery($conn,
                "INSERT INTO tbl_recycling_participants (program_id, resident_id, enrollment_date, status) 
                 VALUES (?, ?, NOW(), 'active')",
                [$program_id, $resident_id], 'ii'
            );
            
            if ($result) {
                setMessage('Successfully enrolled in the recycling program!', 'success');
                logActivity($conn, $user_id, "Enrolled in recycling program ID: $program_id", 'tbl_recycling_participants', $conn->insert_id);
            } else {
                setMessage('Failed to enroll. Please try again.', 'error');
            }
        }
    }
    
    header('Location: recycling.php');
    exit();
}

// Get all active programs
$programs = fetchAll($conn, 
    "SELECT * FROM tbl_recycling_programs 
     WHERE status = 'active' 
     ORDER BY created_at DESC"
);

// Get user's enrolled programs
$resident_id = fetchOne($conn, "SELECT resident_id FROM tbl_users WHERE user_id = ?", [$user_id], 'i')['resident_id'] ?? null;
$enrolled_programs = [];
if ($resident_id) {
    $enrolled_programs = fetchAll($conn,
        "SELECT rp.*, p.program_name, p.program_type 
         FROM tbl_recycling_participants rp
         JOIN tbl_recycling_programs p ON rp.program_id = p.program_id
         WHERE rp.resident_id = ? AND rp.status = 'active'
         ORDER BY rp.enrollment_date DESC",
        [$resident_id], 'i'
    );
}

include '../../../includes/header.php';
?>

<style>
.program-card {
    border-left: 4px solid #28a745;
    transition: all 0.3s;
    height: 100%;
}
.program-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}
.badge-program {
    font-size: 0.75rem;
    padding: 0.35em 0.65em;
}
.enrolled-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 1;
}
.incentive-item {
    background: #f8f9fa;
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-bottom: 0.5rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-recycle me-2 text-success"></i>Recycling Programs
                    </h2>
                    <p class="text-muted mb-0">Join our recycling programs and help save the environment</p>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- My Enrolled Programs -->
    <?php if (!empty($enrolled_programs)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-success text-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-check-circle me-2"></i>My Enrolled Programs
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Program</th>
                                        <th>Type</th>
                                        <th>Enrolled Since</th>
                                        <th>Contribution</th>
                                        <th>Points</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_programs as $enrolled): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($enrolled['program_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($enrolled['program_type']); ?></span>
                                            </td>
                                            <td><?php echo formatDate($enrolled['enrollment_date']); ?></td>
                                            <td>
                                                <strong><?php echo number_format($enrolled['total_weight_kg'], 2); ?> kg</strong>
                                                <br><small class="text-muted"><?php echo $enrolled['total_items']; ?> items</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-star me-1"></i><?php echo $enrolled['points_earned']; ?> points
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Available Programs -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h4 class="mb-3">
                <i class="fas fa-list me-2"></i>Available Programs
            </h4>
        </div>
    </div>

    <?php if (empty($programs)): ?>
        <div class="text-center py-5">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h4 class="text-muted">No programs available</h4>
            <p class="text-muted">Check back later for new recycling programs.</p>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($programs as $program): 
                // Check if user is enrolled
                $is_enrolled = false;
                if ($resident_id) {
                    $check = fetchOne($conn,
                        "SELECT participant_id FROM tbl_recycling_participants 
                         WHERE program_id = ? AND resident_id = ? AND status = 'active'",
                        [$program['program_id'], $resident_id], 'ii'
                    );
                    $is_enrolled = !empty($check);
                }
            ?>
                <div class="col-md-6 mb-4">
                    <div class="card program-card border-0 shadow-sm position-relative">
                        <?php if ($is_enrolled): ?>
                            <span class="badge bg-success enrolled-badge">
                                <i class="fas fa-check-circle me-1"></i>Enrolled
                            </span>
                        <?php endif; ?>
                        
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <?php echo htmlspecialchars($program['program_name']); ?>
                                    </h5>
                                    <span class="badge badge-program bg-primary">
                                        <?php echo htmlspecialchars($program['program_type']); ?>
                                    </span>
                                </div>
                                <div class="text-success" style="font-size: 2rem;">
                                    <i class="fas fa-recycle"></i>
                                </div>
                            </div>

                            <p class="text-muted">
                                <?php echo nl2br(htmlspecialchars($program['description'])); ?>
                            </p>

                            <!-- FIX 1: accepted_materials -> recyclable_items -->
                            <div class="mb-3">
                                <h6 class="mb-2"><i class="fas fa-box-open me-2 text-success"></i>Accepted Materials</h6>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($program['recyclable_items'])); ?></p>
                            </div>

                            <?php if ($program['collection_points']): ?>
                                <div class="mb-3">
                                    <h6 class="mb-2"><i class="fas fa-map-marker-alt me-2 text-danger"></i>Collection Points</h6>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($program['collection_points'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($program['schedule']): ?>
                                <div class="mb-3">
                                    <h6 class="mb-2"><i class="fas fa-calendar-alt me-2 text-primary"></i>Schedule</h6>
                                    <p class="mb-0"><?php echo htmlspecialchars($program['schedule']); ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- FIX 2: incentives -> incentive_type -->
                            <?php if ($program['incentive_type']): ?>
                                <div class="mb-3">
                                    <h6 class="mb-2"><i class="fas fa-gift me-2 text-warning"></i>Incentives</h6>
                                    <div class="incentive-item">
                                        <small><?php echo nl2br(htmlspecialchars($program['incentive_type'])); ?></small>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- FIX 3: Removed the entire 'requirements' block (column does not exist) -->

                            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                <div>
                                    <?php if ($program['contact_person']): ?>
                                        <small class="text-muted d-block">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($program['contact_person']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if ($program['contact_number']): ?>
                                        <small class="text-muted d-block">
                                            <i class="fas fa-phone me-1"></i>
                                            <?php echo htmlspecialchars($program['contact_number']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if (!$is_enrolled): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                            <button type="submit" name="enroll_program" class="btn btn-success">
                                                <i class="fas fa-user-plus me-1"></i>Enroll Now
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-success p-2">
                                            <i class="fas fa-check-circle me-1"></i>You're Enrolled
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Benefits of Recycling -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm bg-light">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-seedling me-2 text-success"></i>Benefits of Recycling
                    </h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-globe-asia fa-3x text-primary mb-3"></i>
                                <h6>Protects Environment</h6>
                                <small class="text-muted">Reduces pollution and conserves natural resources</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-bolt fa-3x text-warning mb-3"></i>
                                <h6>Saves Energy</h6>
                                <small class="text-muted">Recycling uses less energy than producing new materials</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-coins fa-3x text-success mb-3"></i>
                                <h6>Earn Incentives</h6>
                                <small class="text-muted">Get rewards and points for your contributions</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-users fa-3x text-info mb-3"></i>
                                <h6>Community Impact</h6>
                                <small class="text-muted">Help build a cleaner, greener barangay</small>
                            </div>
                        </div>
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