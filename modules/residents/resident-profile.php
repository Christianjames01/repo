<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

// Check if user has permission to view residents
$is_resident = ($user_role === 'Resident');
$is_tanod = ($user_role === 'Tanod' || $user_role === 'Barangay Tanod');
$can_edit = !$is_resident && !$is_tanod; // Tanod can view but not edit

// Redirect residents to dashboard
if ($is_resident) {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'View Resident';

// Get resident ID
$resident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$resident_id) {
    $_SESSION['error_message'] = 'Invalid resident ID.';
    header('Location: manage.php');
    exit();
}

// Fetch resident details with profile photo and ID photo
$sql = "SELECT * FROM tbl_residents WHERE resident_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$resident = $result->fetch_assoc();
$stmt->close();

if (!$resident) {
    $_SESSION['error_message'] = 'Resident not found.';
    header('Location: manage.php');
    exit();
}

// Calculate age
$age = 0;
if (!empty($resident['date_of_birth'])) {
    try {
        $dob = new DateTime($resident['date_of_birth']);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
    } catch (Exception $e) {
        $age = 0;
    }
}

// Get resident's document requests
$document_requests = [];
$sql = "SELECT r.*, rt.request_type_name 
        FROM tbl_requests r
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        WHERE r.resident_id = ?
        ORDER BY r.request_date DESC
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$document_requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get request statistics for this resident
$stats_sql = "SELECT 
    COUNT(*) as total_requests,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM tbl_requests WHERE resident_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$request_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<style>
.profile-photo {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}
.profile-initial {
    width: 120px;
    height: 120px;
    font-size: 48px;
    font-weight: bold;
}
.info-card {
    border-radius: 12px;
}
.stat-badge {
    padding: 0.5rem 1rem;
    border-radius: 8px;
}
.id-photo-display {
    max-width: 100%;
    max-height: 250px;
    border-radius: 8px;
    border: 2px solid #dee2e6;
    cursor: pointer;
    transition: all 0.3s ease;
    object-fit: contain;
}
.id-photo-display:hover {
    border-color: #0f4c75;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    transform: scale(1.02);
}
.id-photo-container {
    text-align: center;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}
.id-photo-placeholder {
    padding: 2rem 1rem;
    background: #e9ecef;
    border-radius: 8px;
    text-align: center;
    color: #6c757d;
}
.id-photo-placeholder i {
    font-size: 3rem;
    margin-bottom: 0.5rem;
    display: block;
    color: #adb5bd;
}
@media print {
    .no-print, .btn, .card-header, nav, aside, header, .sidebar {
        display: none !important;
    }
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Tanod View-Only Alert -->
    <?php if ($is_tanod): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i>
        <strong>View Only Mode:</strong> As a Tanod, you can view resident information but cannot make any modifications.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row mb-4 no-print">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="mb-0">
                    <i class="fas fa-user me-2"></i>Resident Profile
                </h2>
                <div class="btn-group">
                    <?php if ($can_edit): ?>
                    <a href="edit.php?id=<?php echo $resident_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-1"></i>Edit
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <a href="manage.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Profile Card -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm mb-4 info-card">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <?php if (!empty($resident['profile_photo']) && file_exists('../../uploads/profiles/' . $resident['profile_photo'])): ?>
                            <img src="../../uploads/profiles/<?php echo htmlspecialchars($resident['profile_photo']); ?>" 
                                 alt="Profile Photo" class="profile-photo">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center profile-initial">
                                <?php echo strtoupper(substr($resident['first_name'], 0, 1) . substr($resident['last_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></h4>
                    <p class="text-muted mb-3">Resident ID: #<?php echo str_pad($resident_id, 4, '0', STR_PAD_LEFT); ?></p>
                    
                    <div class="mb-3">
                        <?php if ($resident['is_verified']): ?>
                            <span class="badge bg-success fs-6">
                                <i class="fas fa-check-circle"></i> Verified Resident
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark fs-6">
                                <i class="fas fa-clock"></i> Pending Verification
                            </span>
                        <?php endif; ?>
                    </div>

                    <hr>

                    <div class="text-start">
                        <p class="mb-2">
                            <i class="fas fa-birthday-cake text-primary me-2"></i>
                            <strong>Age:</strong> <?php echo $age; ?> years old
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-venus-mars text-primary me-2"></i>
                            <strong>Gender:</strong> <?php echo htmlspecialchars($resident['gender'] ?? 'N/A'); ?>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-ring text-primary me-2"></i>
                            <strong>Civil Status:</strong> <?php echo htmlspecialchars($resident['civil_status'] ?? 'N/A'); ?>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-briefcase text-primary me-2"></i>
                            <strong>Occupation:</strong> <?php echo htmlspecialchars($resident['occupation'] ?? 'N/A'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- ID Photo Display Card -->
            <div class="card border-0 shadow-sm mb-4 info-card">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="fas fa-id-card me-2"></i>Identity Verification</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($resident['id_photo']) && file_exists('../../uploads/ids/' . $resident['id_photo'])): ?>
                        <div class="id-photo-container">
                            <p class="mb-2 small text-muted"><strong>Valid ID Photo:</strong></p>
                            <?php 
                            $id_path = '../../uploads/ids/' . $resident['id_photo'];
                            $file_ext = strtolower(pathinfo($id_path, PATHINFO_EXTENSION));
                            ?>
                            
                            <?php if ($file_ext == 'pdf'): ?>
                                <div class="id-photo-placeholder">
                                    <i class="fas fa-file-pdf"></i>
                                    <p class="mb-2 mt-2">PDF Document</p>
                                    <a href="../../uploads/ids/<?php echo htmlspecialchars($resident['id_photo']); ?>" 
                                       target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View PDF
                                    </a>
                                </div>
                            <?php else: ?>
                                <img src="../../uploads/ids/<?php echo htmlspecialchars($resident['id_photo']); ?>" 
                                     alt="ID Photo" class="id-photo-display" 
                                     data-bs-toggle="modal" data-bs-target="#idPhotoModal">
                                <p class="small text-muted mt-2">
                                    <i class="fas fa-info-circle"></i> Click to view full size
                                </p>
                            <?php endif; ?>
                            
                            <small class="text-muted d-block mt-2">
                                <i class="fas fa-calendar"></i> Uploaded: <?php echo date('M d, Y', filectime($id_path)); ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="id-photo-placeholder">
                            <i class="fas fa-id-card"></i>
                            <p class="mb-0 mt-2">No ID Photo Available</p>
                            <small class="text-muted">ID not uploaded during registration</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Statistics -->
            <div class="card border-0 shadow-sm mb-4 info-card">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Request Statistics</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Total Requests</span>
                        <span class="badge bg-primary"><?php echo $request_stats['total_requests']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Pending</span>
                        <span class="badge bg-warning text-dark"><?php echo $request_stats['pending']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Approved</span>
                        <span class="badge bg-info"><?php echo $request_stats['approved']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Released</span>
                        <span class="badge bg-success"><?php echo $request_stats['released']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted">Rejected</span>
                        <span class="badge bg-danger"><?php echo $request_stats['rejected']; ?></span>
                    </div>
                </div>
            </div>

            <?php if ($is_tanod): ?>
            <!-- Tanod Access Info -->
            <div class="card border-0 shadow-sm border-info mb-4">
                <div class="card-body">
                    <h6 class="text-info mb-3">
                        <i class="fas fa-shield-alt me-2"></i>Tanod Access
                    </h6>
                    <p class="small text-muted mb-2">
                        <i class="fas fa-check text-success me-2"></i>View resident information
                    </p>
                    <p class="small text-muted mb-2">
                        <i class="fas fa-check text-success me-2"></i>Print resident profile
                    </p>
                    <p class="small text-muted mb-0">
                        <i class="fas fa-times text-danger me-2"></i>Edit or modify records
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-8">
            <!-- Personal Information -->
            <div class="card border-0 shadow-sm mb-4 info-card">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="text-muted small mb-1">Full Name</label>
                            <p class="fw-bold fs-5">
                                <?php echo htmlspecialchars($resident['first_name'] . ' ' . 
                                    ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . 
                                    $resident['last_name']); ?>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Date of Birth</label>
                            <p class="fw-bold">
                                <i class="fas fa-calendar text-primary me-2"></i>
                                <?php echo !empty($resident['date_of_birth']) ? date('F d, Y', strtotime($resident['date_of_birth'])) : 'N/A'; ?>
                            </p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small mb-1">Gender</label>
                            <p class="fw-bold">
                                <i class="fas fa-<?php echo ($resident['gender'] ?? '') === 'Male' ? 'mars' : 'venus'; ?> text-primary me-2"></i>
                                <?php echo htmlspecialchars($resident['gender'] ?? 'N/A'); ?>
                            </p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small mb-1">Age</label>
                            <p class="fw-bold"><?php echo $age; ?> years</p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Civil Status</label>
                            <p class="fw-bold">
                                <i class="fas fa-heart text-primary me-2"></i>
                                <?php echo htmlspecialchars($resident['civil_status'] ?? 'N/A'); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Occupation</label>
                            <p class="fw-bold">
                                <i class="fas fa-briefcase text-primary me-2"></i>
                                <?php echo htmlspecialchars($resident['occupation'] ?? 'N/A'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="card border-0 shadow-sm mb-4 info-card">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-address-book me-2"></i>Contact Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Contact Number</label>
                            <p class="fw-bold">
                                <i class="fas fa-phone text-success me-2"></i>
                                <?php echo htmlspecialchars($resident['contact_number'] ?? 'Not Provided'); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Email Address</label>
                            <p class="fw-bold">
                                <i class="fas fa-envelope text-primary me-2"></i>
                                <?php echo htmlspecialchars($resident['email'] ?? 'Not Provided'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <label class="text-muted small mb-1">Residential Address</label>
                            <p class="fw-bold">
                                <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                <?php echo htmlspecialchars($resident['address'] ?? 'Not Provided'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Document Requests History -->
            <div class="card border-0 shadow-sm mb-4 info-card">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Document Request History</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($document_requests)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Date</th>
                                        <th>Document Type</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($document_requests as $req): ?>
                                    <tr>
                                        <td><strong>#<?php echo str_pad($req['request_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                        <td><small><?php echo date('M d, Y', strtotime($req['request_date'])); ?></small></td>
                                        <td><?php echo htmlspecialchars($req['request_type_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <small><?php 
                                                $purpose = $req['purpose'] ?? '';
                                                echo htmlspecialchars(strlen($purpose) > 30 ? substr($purpose, 0, 30) . '...' : $purpose); 
                                            ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = [
                                                'Pending' => 'warning',
                                                'Approved' => 'info',
                                                'Released' => 'success',
                                                'Rejected' => 'danger'
                                            ];
                                            $class = $status_class[$req['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $class; ?>">
                                                <?php echo htmlspecialchars($req['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($req['payment_status']): ?>
                                                <span class="badge bg-success"><i class="fas fa-check"></i> Paid</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No document requests found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Information -->
            <div class="card border-0 shadow-sm info-card">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Registration Date</label>
                            <p class="fw-bold">
                                <i class="fas fa-calendar-plus text-primary me-2"></i>
                                <?php echo !empty($resident['created_at']) ? date('F d, Y h:i A', strtotime($resident['created_at'])) : 'N/A'; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small mb-1">Last Updated</label>
                            <p class="fw-bold">
                                <i class="fas fa-calendar-check text-success me-2"></i>
                                <?php echo (!empty($resident['updated_at']) && $resident['updated_at'] != '0000-00-00 00:00:00') ? 
                                    date('F d, Y h:i A', strtotime($resident['updated_at'])) : 'Never'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ID Photo Modal -->
<div class="modal fade" id="idPhotoModal" tabindex="-1" aria-labelledby="idPhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="idPhotoModalLabel">
                    <i class="fas fa-id-card"></i> Valid ID Photo - Full View
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <?php if (!empty($resident['id_photo']) && file_exists('../../uploads/ids/' . $resident['id_photo'])): ?>
                    <img src="../../uploads/ids/<?php echo htmlspecialchars($resident['id_photo']); ?>" 
                         alt="ID Photo" style="max-width: 100%; height: auto; border-radius: 8px;">
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <?php if (!empty($resident['id_photo'])): ?>
                <a href="../../uploads/ids/<?php echo htmlspecialchars($resident['id_photo']); ?>" 
                   target="_blank" class="btn btn-primary">
                    <i class="fas fa-external-link-alt"></i> Open in New Tab
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php 
$conn->close();
include '../../includes/footer.php'; 
?>