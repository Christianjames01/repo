<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

$is_resident = ($user_role === 'Resident');
$is_tanod = ($user_role === 'Tanod' || $user_role === 'Barangay Tanod');
$can_edit = !$is_resident && !$is_tanod;

if ($is_resident) {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'View Resident';

// Get resident ID with improved validation
$resident_id = 0;

if (isset($_GET['id'])) {
    $resident_id = intval($_GET['id']);
    
    if ($resident_id <= 0) {
        $_SESSION['error_message'] = 'Invalid resident ID format. ID must be a positive number.';
        header('Location: manage.php');
        exit();
    }
} else {
    $_SESSION['error_message'] = 'Resident ID is required. Please select a resident from the list.';
    header('Location: manage.php');
    exit();
}

$sql = "SELECT * FROM tbl_residents WHERE resident_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$resident = $result->fetch_assoc();
$stmt->close();

if (!$resident) {
    $_SESSION['error_message'] = 'Resident not found. The resident may have been deleted.';
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

// Get document requests
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

// Get request statistics
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
.profile-photo { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; }
.profile-initial { width: 120px; height: 120px; font-size: 48px; font-weight: bold; }
.info-card { border-radius: 12px; }
.info-row { border-bottom: 1px solid #e9ecef; padding: 0.75rem 0; }
.info-row:last-child { border-bottom: none; }
.info-label { font-weight: 600; color: #6c757d; font-size: 0.875rem; margin-bottom: 0.25rem; }
.info-value { color: #2d3748; font-size: 1rem; }

@media print {
    @page {
        size: A4 portrait;
        margin: 0.5cm;
    }
    
    body {
        font-size: 9pt;
        line-height: 1.3;
    }
    
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    
    .print-only {
        padding: 10px !important;
    }
    
    .print-only h5 {
        font-size: 9pt;
        margin: 1px 0;
        line-height: 1.2;
    }
    
    .print-only h4 {
        font-size: 11pt;
        margin: 2px 0;
        font-weight: bold;
    }
    
    .print-only h6 {
        font-size: 9pt;
        font-weight: bold;
        border-bottom: 1px solid #000;
        padding: 3px 0;
        margin: 8px 0 4px 0;
    }
    
    .print-only table {
        margin-bottom: 8px;
        font-size: 8.5pt;
    }
    
    .print-only td {
        padding: 2px 5px !important;
        line-height: 1.3;
    }
    
    .print-only strong {
        font-weight: 600;
    }
}

.print-only { display: none; }
</style>

<!-- Print Form -->
<div class="print-only" style="padding: 15px 20px;">
    <div style="text-align: center; margin-bottom: 15px;">
        <h5 style="margin: 0; font-size: 10pt;">Republic of the Philippines</h5>
        <h5 style="margin: 0; font-size: 10pt;">Barangay Centro</h5>
        <h4 style="margin: 5px 0; font-size: 12pt; font-weight: bold;">RESIDENT BIO-PROFILE</h4>
    </div>

    <!-- Main Content Box -->
    <div style="border: 2px solid #000; padding: 20px; border-radius: 5px;">
        <!-- Personal Information -->
        <div style="margin-bottom: 18px;">
            <div style="background: #000; color: #fff; padding: 5px 10px; font-weight: bold; font-size: 10pt; margin-bottom: 12px; text-align: center;">
                PERSONAL INFORMATION
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
                <tr>
                    <td style="padding: 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: inline-block; width: 100px;">Full Name:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: inline-block; flex: 1; min-width: 400px; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ?? '') . ' ' . $resident['last_name'] . ' ' . ($resident['ext_name'] ?? '')) ?>
                        </span>
                    </td>
                </tr>
            </table>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
                <tr>
                    <td style="width: 33%; padding: 6px 8px 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Date of Birth:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['date_of_birth'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 17%; padding: 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Age:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= $age ?>
                        </span>
                    </td>
                    <td style="width: 50%; padding: 6px 0 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Birthplace:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['birthplace'] ?? 'N/A') ?>
                        </span>
                    </td>
                </tr>
            </table>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 33%; padding: 6px 8px 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Gender:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['gender'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 33%; padding: 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Civil Status:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['civil_status'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 34%; padding: 6px 0 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Occupation:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['occupation'] ?? 'N/A') ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>

         <!-- Contact Information -->
        <div style="margin-bottom: 18px;">
            <div style="background: #000; color: #fff; padding: 5px 10px; font-weight: bold; font-size: 10pt; margin-bottom: 12px; text-align: center;">
                CONTACT INFORMATION
            </div>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 40%; padding: 6px 8px 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Contact Number:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['contact_number'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 60%; padding: 6px 0 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Email Address:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['email'] ?? 'N/A') ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>

         <!-- Address Information -->
        <div style="margin-bottom: 18px;">
            <div style="background: #000; color: #fff; padding: 5px 10px; font-weight: bold; font-size: 10pt; margin-bottom: 12px; text-align: center;">
                ADDRESS INFORMATION
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
                <tr>
                    <td style="width: 50%; padding: 6px 8px 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">House/Building No.:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['permanent_address'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 50%; padding: 6px 0 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Street:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['street'] ?? 'N/A') ?>
                        </span>
                    </td>
                </tr>
            </table>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
                <tr>
                    <td style="width: 33%; padding: 6px 8px 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Barangay:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['barangay'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 34%; padding: 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Municipality/City:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['town'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td style="width: 33%; padding: 6px 0 6px 8px;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Province:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['province'] ?? 'N/A') ?>
                        </span>
                    </td>
                </tr>
            </table>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 6px 0;">
                        <span style="font-size: 9pt; font-weight: 600; display: block;">Complete Residential Address:</span>
                        <span style="font-size: 9pt; border-bottom: 1px solid #000; display: block; padding: 2px 8px;">
                            <?= htmlspecialchars($resident['address'] ?? 'N/A') ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Signature Section -->
    <div style="margin-top: 30px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; padding: 10px; vertical-align: top; text-align: center;">
                    <div style="font-size: 9pt; margin-bottom: 40px;">Prepared by:</div>
                    <div style="border-top: 2px solid #000; display: inline-block; min-width: 220px; padding-top: 3px;">
                        <div style="font-size: 10pt; font-weight: bold; margin-top: 2px;">Christian James B. Ortouste</div>
                        <div style="font-size: 8pt; color: #666;">Staff Name & Signature</div>
                        <div style="font-size: 8pt; color: #666;">Staff</div>
                    </div>
                </td>
                <td style="width: 50%; padding: 10px; vertical-align: top; text-align: center;">
                    <div style="font-size: 9pt; margin-bottom: 40px;">Verified by:</div>
                    <div style="border-top: 2px solid #000; display: inline-block; min-width: 220px; padding-top: 3px;">
                        <div style="font-size: 10pt; font-weight: bold; margin-top: 2px;">Elijah Pen Ompad</div>
                        <div style="font-size: 8pt; color: #666;">Barangay Official Name & Signature</div>
                        <div style="font-size: 8pt; color: #666;">Brgy. Captain</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

   <div style="text-align: center; margin-top: 25px; padding-top: 10px; border-top: 1px solid #ccc;">
        <div style="font-size: 7pt; color: #666; line-height: 1.4;">
            <div>Document Generated: <?= date('F d, Y h:i A') ?></div>
            <div>BarangayLink Management System | Resident ID: #<?= str_pad($resident_id, 4, '0', STR_PAD_LEFT) ?></div>
            <div style="font-style: italic; margin-top: 3px;">This is a computer-generated document and requires no signature if electronically verified.</div>
        </div>
    </div>
</div>

<div class="container-fluid py-4">
    <?php if ($is_tanod): ?>
    <div class="alert alert-info no-print">
        <i class="fas fa-info-circle"></i> <strong>View Only Mode:</strong> As a Tanod, you can view but not edit.
    </div>
    <?php endif; ?>

    <div class="row mb-4 no-print">
        <div class="col-md-12">
            <div class="d-flex justify-content-between">
                <h2><i class="fas fa-user"></i> Resident Profile</h2>
                <div class="btn-group">
                    <?php if ($can_edit): ?>
                    <a href="edit.php?id=<?= $resident_id ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn btn-success"><i class="fas fa-print"></i> Print</button>
                    <a href="manage.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <!-- Profile Card -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-body text-center">
                    <?php if (!empty($resident['profile_photo']) && file_exists('../../uploads/profiles/' . $resident['profile_photo'])): ?>
                        <img src="../../uploads/profiles/<?= htmlspecialchars($resident['profile_photo']) ?>" alt="Profile" class="profile-photo">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center profile-initial">
                            <?= strtoupper(substr($resident['first_name'], 0, 1) . substr($resident['last_name'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <h4 class="mt-3"><?= htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) ?></h4>
                    <p class="text-muted">ID: #<?= str_pad($resident_id, 4, '0', STR_PAD_LEFT) ?></p>
                    <?php if ($resident['is_verified']): ?>
                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php else: ?>
                        <span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Stats -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-header bg-white">
                    <h6><i class="fas fa-chart-bar"></i> Request Statistics</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total</span><span class="badge bg-primary"><?= $request_stats['total_requests'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Pending</span><span class="badge bg-warning"><?= $request_stats['pending'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Approved</span><span class="badge bg-info"><?= $request_stats['approved'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Released</span><span class="badge bg-success"><?= $request_stats['released'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Rejected</span><span class="badge bg-danger"><?= $request_stats['rejected'] ?></span>
                    </div>
                </div>
            </div>

            <!-- ID Photo Display Card -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-header bg-white">
                    <h6><i class="fas fa-id-card"></i> Identity Verification</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($resident['id_photo']) && file_exists('../../uploads/ids/' . $resident['id_photo'])): ?>
                        <div class="id-photo-container">
                            <p class="mb-2"><strong>Valid ID Photo:</strong></p>
                            <?php 
                            $id_path = '../../uploads/ids/' . $resident['id_photo'];
                            $file_ext = strtolower(pathinfo($id_path, PATHINFO_EXTENSION));
                            ?>
                            
                            <?php if ($file_ext == 'pdf'): ?>
                                <div class="text-center p-3 bg-light rounded">
                                    <i class="fas fa-file-pdf fa-3x text-danger mb-2"></i>
                                    <p class="mb-2">PDF Document</p>
                                    <a href="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" 
                                       target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View PDF
                                    </a>
                                </div>
                            <?php else: ?>
                                <img src="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" 
                                     alt="ID Photo" 
                                     style="max-width: 100%; height: auto; border-radius: 8px; cursor: pointer;"
                                     data-bs-toggle="modal" data-bs-target="#idPhotoModal">
                                <p class="text-muted small mt-2">
                                    <i class="fas fa-info-circle"></i> Click to view full size
                                </p>
                            <?php endif; ?>
                            
                            <small class="text-muted d-block mt-2">
                                Uploaded: <?= file_exists($id_path) ? date('M d, Y', filectime($id_path)) : 'N/A' ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-3 bg-light rounded">
                            <i class="fas fa-id-card fa-3x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No ID uploaded</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <!-- Personal Information -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-header bg-white">
                    <h5><i class="fas fa-user-circle"></i> Personal Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Last Name</div>
                                <div class="info-value"><?= htmlspecialchars($resident['last_name']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">First Name</div>
                                <div class="info-value"><?= htmlspecialchars($resident['first_name']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Middle Name</div>
                                <div class="info-value"><?= htmlspecialchars($resident['middle_name'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-row">
                                <div class="info-label">Extension</div>
                                <div class="info-value"><?= htmlspecialchars($resident['ext_name'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-calendar text-primary"></i> Date of Birth</div>
                                <div class="info-value"><?= !empty($resident['date_of_birth']) ? date('F d, Y', strtotime($resident['date_of_birth'])) : 'N/A' ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-birthday-cake text-primary"></i> Age</div>
                                <div class="info-value"><?= $age ?> years old</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-map-marker-alt text-primary"></i> Birthplace</div>
                                <div class="info-value"><?= htmlspecialchars($resident['birthplace'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-venus-mars text-primary"></i> Gender</div>
                                <div class="info-value"><?= htmlspecialchars($resident['gender'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-heart text-primary"></i> Civil Status</div>
                                <div class="info-value"><?= htmlspecialchars($resident['civil_status'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-briefcase text-primary"></i> Occupation</div>
                                <div class="info-value"><?= htmlspecialchars($resident['occupation'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-header bg-white">
                    <h5><i class="fas fa-address-book"></i> Contact Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-phone text-success"></i> Contact Number</div>
                                <div class="info-value"><?= htmlspecialchars($resident['contact_number'] ?? 'Not Provided') ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-envelope text-primary"></i> Email Address</div>
                                <div class="info-value"><?= htmlspecialchars($resident['email'] ?? 'Not Provided') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Address Information -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-header bg-white">
                    <h5><i class="fas fa-map-marked-alt"></i> Address Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-home text-info"></i> Permanent Address</div>
                                <div class="info-value"><?= htmlspecialchars($resident['permanent_address'] ?? 'Not Provided') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Street</div>
                                <div class="info-value"><?= htmlspecialchars($resident['street'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Barangay</div>
                                <div class="info-value"><?= htmlspecialchars($resident['barangay'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Town/City</div>
                                <div class="info-value"><?= htmlspecialchars($resident['town'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Province</div>
                                <div class="info-value"><?= htmlspecialchars($resident['province'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="info-row">
                                <div class="info-label"><i class="fas fa-map text-danger"></i> Complete Residential Address</div>
                                <div class="info-value"><?= htmlspecialchars($resident['address'] ?? 'Not Provided') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Document Requests -->
            <div class="card border-0 shadow-sm no-print">
                <div class="card-header bg-white">
                    <h5><i class="fas fa-file-alt"></i> Document Request History</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($document_requests)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Document</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($document_requests as $req): ?>
                                    <tr>
                                        <td>#<?= str_pad($req['request_id'], 5, '0', STR_PAD_LEFT) ?></td>
                                        <td><?= date('M d, Y', strtotime($req['request_date'])) ?></td>
                                        <td><?= htmlspecialchars($req['request_type_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(substr($req['purpose'] ?? '', 0, 30)) ?></td>
                                        <td>
                                            <?php
                                            $badge_class = ['Pending' => 'warning', 'Approved' => 'info', 'Released' => 'success', 'Rejected' => 'danger'][$req['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $badge_class ?>"><?= $req['status'] ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted"></i>
                            <p class="text-muted mt-2">No document requests</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ID Photo Modal (View Full Size) -->
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
                    <img src="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" 
                         alt="ID Photo" style="max-width: 100%; height: auto;">
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <a href="../../uploads/ids/<?= htmlspecialchars($resident['id_photo'])   ?>" 
                   download class="btn btn-primary">
                    <i class="fas fa-download"></i> Download
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php 
$conn->close();
include '../../includes/footer.php'; 
?>