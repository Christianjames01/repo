<?php
/**
 * Resident Filed Complaints Page
 * Path: modules/blotter/my-filed-complaints.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = getCurrentResidentId();
$user_id = getCurrentUserId();

if ($user_role !== 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

// Check if resident is verified
$verify_sql = "SELECT is_verified FROM tbl_residents WHERE resident_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("i", $resident_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();
$verify_data = $verify_result->fetch_assoc();
$verify_stmt->close();

if (!$verify_data || $verify_data['is_verified'] != 1) {
    header('Location: not-verified-blotter.php');
    exit();
}

$page_title = 'My Filed Complaints';

// Get blotter records filed BY this resident (as complainant only)
$sql = "SELECT b.*, 
        CONCAT(r.first_name, ' ', COALESCE(r.last_name, '')) as respondent_name,
        b.respondent_name as respondent_manual_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents r ON b.respondent_id = r.resident_id
        WHERE b.complainant_id = ?
        ORDER BY b.incident_date DESC, b.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$filed_complaints = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-file-signature me-2"></i>My Filed Complaints</h2>
                    <p class="text-muted">View complaints you have filed against others</p>
                </div>
                <a href="file-blotter.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>File New Complaint
                </a>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <?php
        $total_filed = count($filed_complaints);
        $pending_count = 0;
        $under_investigation = 0;
        $resolved_count = 0;

        foreach ($filed_complaints as $record) {
            if ($record['status'] == 'Pending') {
                $pending_count++;
            }
            if ($record['status'] == 'Under Investigation') {
                $under_investigation++;
            }
            if ($record['status'] == 'Resolved') {
                $resolved_count++;
            }
        }
        ?>
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?= $total_filed ?></h3>
                            <p class="mb-0 small">Total Filed</p>
                        </div>
                        <i class="fas fa-file-signature fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?= $pending_count ?></h3>
                            <p class="mb-0 small">Pending</p>
                        </div>
                        <i class="fas fa-clock fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?= $under_investigation ?></h3>
                            <p class="mb-0 small">Under Investigation</p>
                        </div>
                        <i class="fas fa-search fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?= $resolved_count ?></h3>
                            <p class="mb-0 small">Resolved</p>
                        </div>
                        <i class="fas fa-check-circle fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="btn-group w-100" role="group">
                        <a href="my-filed-complaints.php" class="btn btn-lg btn-primary">
                            <i class="fas fa-file-signature me-2"></i>My Filed Complaints
                        </a>
                        <a href="my-blotter.php" class="btn btn-lg btn-outline-primary">
                            <i class="fas fa-clipboard-list me-2"></i>All Cases (Involved)
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <?php if (empty($filed_complaints)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-file-signature fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No Filed Complaints</h5>
                            <p class="text-muted">You haven't filed any complaints yet.</p>
                            <a href="file-blotter.php" class="btn btn-primary mt-3">
                                <i class="fas fa-plus me-2"></i>File Your First Complaint
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Case No.</th>
                                        <th>Filed Date</th>
                                        <th>Incident Date</th>
                                        <th>Type</th>
                                        <th>Against</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filed_complaints as $record): 
                                        $respondent = $record['respondent_name'] ?: $record['respondent_manual_name'] ?: 'N/A';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($record['case_number'] ?? '#' . str_pad($record['blotter_id'], 5, '0', STR_PAD_LEFT)) ?></strong></td>
                                        <td><?= date('M d, Y', strtotime($record['created_at'])) ?></td>
                                        <td><?= date('M d, Y', strtotime($record['incident_date'])) ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($record['incident_type']) ?></span></td>
                                        <td><?= htmlspecialchars($respondent) ?></td>
                                        <td><?= htmlspecialchars(substr($record['description'], 0, 50)) ?>...</td>
                                        <td><?= getStatusBadge($record['status']) ?></td>
                                        <td>
                                            <a href="view-blotter.php?id=<?= $record['blotter_id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>