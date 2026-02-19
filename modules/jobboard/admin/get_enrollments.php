<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';

if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    exit('Unauthorized');
}

$training_id = isset($_GET['training_id']) ? intval($_GET['training_id']) : 0;

if ($training_id <= 0) {
    echo '<div class="alert alert-danger">Invalid training ID</div>';
    exit();
}

// Fetch enrollments with resident details
$query = "SELECT te.*, 
          r.first_name, 
          r.middle_name, 
          r.last_name, 
          r.ext_name,
          r.email, 
          r.contact_number,
          r.phone,
          r.contact_no,
          r.address,
          r.purok,
          r.barangay,
          r.city,
          r.province,
          r.gender,
          r.date_of_birth,
          r.occupation
          FROM tbl_training_enrollments te
          JOIN tbl_residents r ON te.resident_id = r.resident_id
          WHERE te.training_id = ?
          ORDER BY te.enrollment_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $training_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo '<div class="alert alert-info text-center">
            <i class="fas fa-info-circle fa-2x mb-2"></i>
            <p class="mb-0">No enrollments yet for this training program.</p>
          </div>';
    exit();
}
?>

<div class="table-responsive">
    <table class="table table-hover table-bordered">
        <thead class="table-light">
            <tr>
                <th style="width: 50px;">#</th>
                <th>Resident Information</th>
                <th>Contact Details</th>
                <th>Location</th>
                <th style="width: 150px;">Enrolled On</th>
                <th style="width: 120px;">Status</th>
                <th style="width: 120px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $count = 1; while ($enrollment = $result->fetch_assoc()): ?>
            <tr>
                <td class="text-center"><?php echo $count++; ?></td>
                <td>
                    <div class="mb-1">
                        <strong class="text-primary">
                            <?php 
                            $name_parts = [];
                            if ($enrollment['first_name']) $name_parts[] = $enrollment['first_name'];
                            if ($enrollment['middle_name']) $name_parts[] = substr($enrollment['middle_name'], 0, 1) . '.';
                            if ($enrollment['last_name']) $name_parts[] = $enrollment['last_name'];
                            if ($enrollment['ext_name']) $name_parts[] = $enrollment['ext_name'];
                            echo htmlspecialchars(implode(' ', $name_parts) ?: 'N/A'); 
                            ?>
                        </strong>
                    </div>
                    <?php if ($enrollment['gender']): ?>
                        <small class="text-muted">
                            <i class="fas fa-<?php echo $enrollment['gender'] == 'Male' ? 'mars' : 'venus'; ?>"></i>
                            <?php echo htmlspecialchars($enrollment['gender']); ?>
                        </small>
                    <?php endif; ?>
                    <?php if ($enrollment['date_of_birth']): ?>
                        <small class="text-muted">
                            | Age: <?php 
                            $dob = new DateTime($enrollment['date_of_birth']);
                            $now = new DateTime();
                            echo $now->diff($dob)->y; 
                            ?>
                        </small>
                    <?php endif; ?>
                    <?php if ($enrollment['occupation']): ?>
                        <br><small class="text-muted">
                            <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($enrollment['occupation']); ?>
                        </small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php 
                    $contact = $enrollment['contact_number'] ?: $enrollment['phone'] ?: $enrollment['contact_no'];
                    ?>
                    <?php if ($enrollment['email']): ?>
                        <div class="mb-1">
                            <i class="fas fa-envelope text-muted"></i> 
                            <small><?php echo htmlspecialchars($enrollment['email']); ?></small>
                        </div>
                    <?php endif; ?>
                    <?php if ($contact): ?>
                        <div>
                            <i class="fas fa-phone text-muted"></i> 
                            <small><?php echo htmlspecialchars($contact); ?></small>
                        </div>
                    <?php endif; ?>
                    <?php if (!$enrollment['email'] && !$contact): ?>
                        <small class="text-muted">No contact info</small>
                    <?php endif; ?>
                </td>
                <td>
                    <small>
                        <?php 
                        $location_parts = [];
                        if ($enrollment['purok']) $location_parts[] = 'Purok ' . $enrollment['purok'];
                        if ($enrollment['barangay']) $location_parts[] = $enrollment['barangay'];
                        if ($enrollment['city']) $location_parts[] = $enrollment['city'];
                        if ($enrollment['province']) $location_parts[] = $enrollment['province'];
                        
                        if (!empty($location_parts)) {
                            echo htmlspecialchars(implode(', ', $location_parts));
                        } elseif ($enrollment['address']) {
                            echo htmlspecialchars($enrollment['address']);
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </small>
                </td>
                <td>
                    <small><?php echo date('M d, Y', strtotime($enrollment['enrollment_date'])); ?></small>
                    <br>
                    <small class="text-muted"><?php echo date('h:i A', strtotime($enrollment['enrollment_date'])); ?></small>
                </td>
                <td>
                    <?php
                    $status_class = '';
                    $status_icon = '';
                    $status_text = '';
                    switch($enrollment['status']) {
                        case 'pending':
                            $status_class = 'warning';
                            $status_icon = 'clock';
                            $status_text = 'Pending';
                            break;
                        case 'approved':
                            $status_class = 'success';
                            $status_icon = 'check-circle';
                            $status_text = 'Approved';
                            break;
                        case 'rejected':
                            $status_class = 'danger';
                            $status_icon = 'times-circle';
                            $status_text = 'Rejected';
                            break;
                        case 'completed':
                            $status_class = 'dark';
                            $status_icon = 'graduation-cap';
                            $status_text = 'Completed';
                            break;
                        default:
                            $status_class = 'secondary';
                            $status_icon = 'question';
                            $status_text = ucfirst($enrollment['status']);
                    }
                    ?>
                    <span class="badge bg-<?php echo $status_class; ?> w-100">
                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                        <?php echo $status_text; ?>
                    </span>
                    <?php if ($enrollment['completion_date']): ?>
                        <br><small class="text-muted">
                            <?php echo date('M d, Y', strtotime($enrollment['completion_date'])); ?>
                        </small>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="btn-group-vertical btn-group-sm w-100" role="group">
                        <?php if ($enrollment['status'] == 'pending'): ?>
                            <button onclick="updateEnrollmentStatus(<?php echo $enrollment['enrollment_id']; ?>, 'approved')" 
                                    class="btn btn-success btn-sm mb-1" title="Approve">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button onclick="updateEnrollmentStatus(<?php echo $enrollment['enrollment_id']; ?>, 'rejected')" 
                                    class="btn btn-danger btn-sm" title="Reject">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php elseif ($enrollment['status'] == 'approved'): ?>
                            <button onclick="updateEnrollmentStatus(<?php echo $enrollment['enrollment_id']; ?>, 'completed')" 
                                    class="btn btn-dark btn-sm" title="Mark as Completed">
                                <i class="fas fa-graduation-cap"></i> Complete
                            </button>
                        <?php elseif ($enrollment['status'] == 'rejected'): ?>
                            <button onclick="updateEnrollmentStatus(<?php echo $enrollment['enrollment_id']; ?>, 'approved')" 
                                    class="btn btn-success btn-sm" title="Approve">
                                <i class="fas fa-check"></i> Approve
                            </button>
                        <?php elseif ($enrollment['status'] == 'completed'): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-double"></i> Done
                            </span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Summary Section -->
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card bg-light">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-chart-pie"></i> Enrollment Summary</h6>
                <div class="row text-center">
                    <div class="col-md-3 mb-2">
                        <div class="p-3 bg-warning bg-opacity-10 rounded">
                            <h3 class="mb-0 text-warning">
                                <?php 
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_training_enrollments WHERE training_id = ? AND status = 'pending'");
                                $stmt->bind_param("i", $training_id);
                                $stmt->execute();
                                $r = $stmt->get_result()->fetch_assoc();
                                echo $r['count'];
                                ?>
                            </h3>
                            <small class="text-muted">Pending</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="p-3 bg-success bg-opacity-10 rounded">
                            <h3 class="mb-0 text-success">
                                <?php 
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_training_enrollments WHERE training_id = ? AND status = 'approved'");
                                $stmt->bind_param("i", $training_id);
                                $stmt->execute();
                                $r = $stmt->get_result()->fetch_assoc();
                                echo $r['count'];
                                ?>
                            </h3>
                            <small class="text-muted">Approved</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="p-3 bg-dark bg-opacity-10 rounded">
                            <h3 class="mb-0 text-dark">
                                <?php 
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_training_enrollments WHERE training_id = ? AND status = 'completed'");
                                $stmt->bind_param("i", $training_id);
                                $stmt->execute();
                                $r = $stmt->get_result()->fetch_assoc();
                                echo $r['count'];
                                ?>
                            </h3>
                            <small class="text-muted">Completed</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="p-3 bg-danger bg-opacity-10 rounded">
                            <h3 class="mb-0 text-danger">
                                <?php 
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_training_enrollments WHERE training_id = ? AND status = 'rejected'");
                                $stmt->bind_param("i", $training_id);
                                $stmt->execute();
                                $r = $stmt->get_result()->fetch_assoc();
                                echo $r['count'];
                                ?>
                            </h3>
                            <small class="text-muted">Rejected</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.table td {
    vertical-align: middle;
}
.btn-group-vertical .btn {
    font-size: 0.75rem;
}
</style>