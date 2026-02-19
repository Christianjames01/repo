<?php
/**
 * Resident Blotter Page
 * Path: modules/blotter/my-blotter.php
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

// Check if resident is verified (including ID photo check)
$verify_sql = "SELECT is_verified, id_photo FROM tbl_residents WHERE resident_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("i", $resident_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();
$verify_data = $verify_result->fetch_assoc();
$verify_stmt->close();

if (!$verify_data || $verify_data['is_verified'] != 1 || empty($verify_data['id_photo'])) {
    header('Location: not-verified-blotter.php');
    exit();
}

$page_title = 'My Blotter Records';

// Get blotter records where resident is involved (complainant or respondent)
$sql = "SELECT b.*, 
        CONCAT(c.first_name, ' ', c.last_name) as complainant_name,
        CONCAT(r.first_name, ' ', COALESCE(r.last_name, '')) as respondent_name,
        b.respondent_name as respondent_manual_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents c ON b.complainant_id = c.resident_id
        LEFT JOIN tbl_residents r ON b.respondent_id = r.resident_id
        WHERE b.complainant_id = ? OR b.respondent_id = ?
        ORDER BY b.incident_date DESC, b.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $resident_id, $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$blotter_records = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    font-weight: 700;
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    height: 100%;
    text-align: center;
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.stat-icon {
    width: 64px;
    height: 64px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1rem;
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1a1a1a;
    line-height: 1;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
}

/* Table Enhancements */
.table {
    margin-bottom: 0;
}

.table thead th {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 700;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Enhanced Buttons */
.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn:active {
    transform: translateY(0);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stat-card {
        padding: 1.25rem;
        margin-bottom: 1rem;
    }
    
    .stat-value {
        font-size: 2rem;
    }
    
    .stat-icon {
        width: 56px;
        height: 56px;
        font-size: 1.5rem;
    }
    
    .table thead th {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
    
    .table tbody td {
        font-size: 0.875rem;
        padding: 0.75rem;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-clipboard-list me-2 text-primary"></i>
                        My Blotter Records
                    </h2>
                    <p class="text-muted mb-0">View all blotter cases where you are involved</p>
                </div>
                <a href="file-blotter.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>File a Blotter
                </a>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <?php
        $total_records = count($blotter_records);
        $as_complainant = 0;
        $as_respondent = 0;
        $pending_count = 0;
        $resolved_count = 0;

        foreach ($blotter_records as $record) {
            if ($record['complainant_id'] == $resident_id) {
                $as_complainant++;
            }
            if ($record['respondent_id'] == $resident_id) {
                $as_respondent++;
            }
            if ($record['status'] == 'Pending') {
                $pending_count++;
            }
            if ($record['status'] == 'Resolved') {
                $resolved_count++;
            }
        }
        ?>
        
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?= $total_records ?></div>
                <div class="stat-label">Total Cases</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="fas fa-user"></i>
                </div>
                <div class="stat-value text-info"><?= $as_complainant ?></div>
                <div class="stat-label">As Complainant</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value text-warning"><?= $pending_count ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value text-success"><?= $resolved_count ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
    </div>

    <!-- Blotter Records Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>
                            All Blotter Cases
                            <span class="badge bg-primary ms-2"><?= $total_records ?></span>
                        </h5>
                        <div>
                            <span class="badge bg-info me-2">
                                <i class="fas fa-user me-1"></i>Complainant
                            </span>
                            <span class="badge bg-danger">
                                <i class="fas fa-user-shield me-1"></i>Respondent
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($blotter_records)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <p>No Blotter Records Found</p>
                            <p class="text-muted mb-3">You don't have any blotter records yet.</p>
                            <a href="file-blotter.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>File Your First Blotter
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Case No.</th>
                                        <th>Role</th>
                                        <th>Incident Date</th>
                                        <th>Type</th>
                                        <th>Other Party</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blotter_records as $record): 
                                        $is_complainant = ($record['complainant_id'] == $resident_id);
                                        $role_badge = $is_complainant 
                                            ? '<span class="badge bg-info"><i class="fas fa-user me-1"></i>Complainant</span>'
                                            : '<span class="badge bg-danger"><i class="fas fa-user-shield me-1"></i>Respondent</span>';
                                        
                                        $other_party = $is_complainant 
                                            ? ($record['respondent_name'] ?: $record['respondent_manual_name'] ?: 'N/A')
                                            : $record['complainant_name'];
                                            
                                        $case_number = htmlspecialchars($record['case_number'] ?? '#' . str_pad($record['blotter_id'], 5, '0', STR_PAD_LEFT));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?= $case_number ?></strong>
                                        </td>
                                        <td><?= $role_badge ?></td>
                                        <td>
                                            <i class="far fa-calendar me-1 text-muted"></i>
                                            <small><?= date('M d, Y', strtotime($record['incident_date'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($record['incident_type']) ?></span>
                                        </td>
                                        <td><small><?= htmlspecialchars($other_party) ?></small></td>
                                        <td>
                                            <small><?= htmlspecialchars(substr($record['description'], 0, 50)) ?><?= strlen($record['description']) > 50 ? '...' : '' ?></small>
                                        </td>
                                        <td><?= getStatusBadge($record['status']) ?></td>
                                        <td class="text-center">
                                            <a href="view-blotter.php?id=<?= intval($record['blotter_id']) ?>" 
                                               class="btn btn-sm btn-primary"
                                               title="View Details">
                                                <i class="fas fa-eye me-1"></i>View
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

<script>
// Log for debugging
console.log('My Blotter page loaded');
console.log('Total records: <?= $total_records ?>');

// Add click event listener to view buttons for debugging
document.addEventListener('DOMContentLoaded', function() {
    const viewButtons = document.querySelectorAll('a[href*="view-blotter.php"]');
    console.log('Found ' + viewButtons.length + ' view buttons');
    
    viewButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            console.log('Clicking view button with href:', href);
            
            // Check if href is valid
            if (!href || href === '#' || href === '') {
                e.preventDefault();
                alert('Error: Invalid link. Please contact administrator.');
                return false;
            }
        });
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

<?php include '../../includes/footer.php'; ?>