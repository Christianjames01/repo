<?php
require_once('../../../config/config.php');

// Check if user is logged in and has appropriate role
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Waste Collection Reports";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $collection_date = sanitize($_POST['collection_date']);
                $area_zone = sanitize($_POST['area_zone']);
                $waste_type = sanitize($_POST['waste_type']);
                $quantity_kg = sanitize($_POST['quantity_kg']);
                $collector_name = sanitize($_POST['collector_name']);
                $status = sanitize($_POST['status']);
                
                $sql = "INSERT INTO tbl_waste_collection_reports 
                        (collection_date, area_zone, waste_type, quantity_kg, collector_name, 
                         status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())";
                
                if (execute($conn, $sql, [
                    $collection_date, $area_zone, $waste_type, $quantity_kg, 
                    $collector_name, $status
                ], 'sssdss')) {
                    setMessage('Collection report added successfully!', 'success');
                } else {
                    setMessage('Error adding collection report.', 'danger');
                }
                header('Location: reports.php');
                exit();
                break;
                
            case 'edit':
                $id = (int)$_POST['id'];
                $collection_date = sanitize($_POST['collection_date']);
                $area_zone = sanitize($_POST['area_zone']);
                $waste_type = sanitize($_POST['waste_type']);
                $quantity_kg = sanitize($_POST['quantity_kg']);
                $collector_name = sanitize($_POST['collector_name']);
                $status = sanitize($_POST['status']);
                
                $sql = "UPDATE tbl_waste_collection_reports 
                        SET collection_date = ?, area_zone = ?, waste_type = ?, quantity_kg = ?, 
                            collector_name = ?, status = ? 
                        WHERE id = ?";
                
                if (execute($conn, $sql, [
                    $collection_date, $area_zone, $waste_type, $quantity_kg, 
                    $collector_name, $status, $id
                ], 'sssdssi')) {
                    setMessage('Collection report updated successfully!', 'success');
                } else {
                    setMessage('Error updating collection report.', 'danger');
                }
                header('Location: reports.php');
                exit();
                break;
                
            case 'delete':
                $id = (int)$_POST['id'];
                
                // Add validation
                if ($id <= 0) {
                    setMessage('Invalid report ID.', 'danger');
                    header('Location: reports.php');
                    exit();
                }
                
                $sql = "DELETE FROM tbl_waste_collection_reports WHERE id = ?";
                
                if (execute($conn, $sql, [$id], 'i')) {
                    setMessage('Collection report deleted successfully!', 'success');
                } else {
                    setMessage('Error deleting collection report.', 'danger');
                }
                header('Location: reports.php');
                exit();
                break;
        }
    }
}

// Filtering and pagination
$where_conditions = [];
$params = [];
$types = '';

// Filter by date range
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';

if ($date_from) {
    $where_conditions[] = "collection_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where_conditions[] = "collection_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Filter by area
$filter_area = isset($_GET['area']) ? sanitize($_GET['area']) : '';
if ($filter_area) {
    $where_conditions[] = "area_zone = ?";
    $params[] = $filter_area;
    $types .= 's';
}

// Filter by waste type
$filter_type = isset($_GET['waste_type']) ? sanitize($_GET['waste_type']) : '';
if ($filter_type) {
    $where_conditions[] = "waste_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

// Filter by status
$filter_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
if ($filter_status) {
    $where_conditions[] = "status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

// Build WHERE clause
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 20;
$offset = ($page - 1) * $records_per_page;

// Get total records
$count_sql = "SELECT COUNT(*) as count FROM tbl_waste_collection_reports $where_sql";
$total_records = fetchOne($conn, $count_sql, $params, $types)['count'] ?? 0;
$total_pages = ceil($total_records / $records_per_page);

// Get reports
$sql = "SELECT * FROM tbl_waste_collection_reports 
        $where_sql 
        ORDER BY collection_date DESC, created_at DESC 
        LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$reports = fetchAll($conn, $sql, $params, $types);

// Get distinct areas for filter dropdown
$areas = fetchAll($conn, "SELECT DISTINCT area_zone FROM tbl_waste_collection_reports WHERE area_zone IS NOT NULL AND area_zone != '' ORDER BY area_zone", [], '');

// Calculate statistics for current filters
$stats_sql = "SELECT 
                COUNT(*) as total_collections,
                COALESCE(SUM(quantity_kg), 0) as total_waste,
                COALESCE(AVG(quantity_kg), 0) as avg_waste,
                COALESCE(SUM(CASE WHEN waste_type = 'biodegradable' THEN quantity_kg ELSE 0 END), 0) as biodegradable_total,
                COALESCE(SUM(CASE WHEN waste_type = 'non-biodegradable' THEN quantity_kg ELSE 0 END), 0) as non_biodegradable_total,
                COALESCE(SUM(CASE WHEN waste_type = 'recyclable' THEN quantity_kg ELSE 0 END), 0) as recyclable_total,
                COALESCE(SUM(CASE WHEN waste_type = 'hazardous' THEN quantity_kg ELSE 0 END), 0) as hazardous_total
              FROM tbl_waste_collection_reports 
              $where_sql";
$stats = fetchOne($conn, $stats_sql, array_slice($params, 0, -2), rtrim($types, 'ii'));

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-clipboard-list me-2"></i><?php echo $page_title; ?></h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReportModal">
                <i class="fas fa-plus me-1"></i>Add Collection Report
            </button>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <?php if ($stats && $stats['total_collections'] > 0): ?>
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Collections</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_collections']); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clipboard-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Waste Collected</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_waste'], 2); ?> kg</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-weight fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Average per Collection</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['avg_waste'], 2); ?> kg</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Recyclable Collected</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['recyclable_total'], 2); ?> kg</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-recycle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Waste Type Breakdown -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Waste Type Breakdown
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="mb-2">
                                <i class="fas fa-leaf fa-2x text-success"></i>
                            </div>
                            <h5 class="text-success"><?php echo number_format($stats['biodegradable_total'], 2); ?> kg</h5>
                            <p class="text-muted mb-0">Biodegradable</p>
                            <small class="text-muted">
                                <?php 
                                $bio_percent = $stats['total_waste'] > 0 ? ($stats['biodegradable_total'] / $stats['total_waste']) * 100 : 0;
                                echo number_format($bio_percent, 1) . '%';
                                ?>
                            </small>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-2">
                                <i class="fas fa-trash fa-2x text-danger"></i>
                            </div>
                            <h5 class="text-danger"><?php echo number_format($stats['non_biodegradable_total'], 2); ?> kg</h5>
                            <p class="text-muted mb-0">Non-Biodegradable</p>
                            <small class="text-muted">
                                <?php 
                                $non_bio_percent = $stats['total_waste'] > 0 ? ($stats['non_biodegradable_total'] / $stats['total_waste']) * 100 : 0;
                                echo number_format($non_bio_percent, 1) . '%';
                                ?>
                            </small>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-2">
                                <i class="fas fa-recycle fa-2x text-info"></i>
                            </div>
                            <h5 class="text-info"><?php echo number_format($stats['recyclable_total'], 2); ?> kg</h5>
                            <p class="text-muted mb-0">Recyclable</p>
                            <small class="text-muted">
                                <?php 
                                $rec_percent = $stats['total_waste'] > 0 ? ($stats['recyclable_total'] / $stats['total_waste']) * 100 : 0;
                                echo number_format($rec_percent, 1) . '%';
                                ?>
                            </small>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-2">
                                <i class="fas fa-radiation fa-2x text-warning"></i>
                            </div>
                            <h5 class="text-warning"><?php echo number_format($stats['hazardous_total'], 2); ?> kg</h5>
                            <p class="text-muted mb-0">Hazardous</p>
                            <small class="text-muted">
                                <?php 
                                $haz_percent = $stats['total_waste'] > 0 ? ($stats['hazardous_total'] / $stats['total_waste']) * 100 : 0;
                                echo number_format($haz_percent, 1) . '%';
                                ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filters
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="reports.php" class="row g-3">
                <div class="col-md-3">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <label for="area" class="form-label">Area/Zone</label>
                    <select class="form-select" id="area" name="area">
                        <option value="">All Areas</option>
                        <?php foreach ($areas as $area): ?>
                            <option value="<?php echo htmlspecialchars($area['area_zone']); ?>" 
                                    <?php echo $filter_area === $area['area_zone'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($area['area_zone']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="waste_type" class="form-label">Waste Type</label>
                    <select class="form-select" id="waste_type" name="waste_type">
                        <option value="">All Types</option>
                        <option value="biodegradable" <?php echo $filter_type === 'biodegradable' ? 'selected' : ''; ?>>Biodegradable</option>
                        <option value="non-biodegradable" <?php echo $filter_type === 'non-biodegradable' ? 'selected' : ''; ?>>Non-Biodegradable</option>
                        <option value="recyclable" <?php echo $filter_type === 'recyclable' ? 'selected' : ''; ?>>Recyclable</option>
                        <option value="hazardous" <?php echo $filter_type === 'hazardous' ? 'selected' : ''; ?>>Hazardous</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>Apply Filters
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i>Clear Filters
                    </a>
                    <button type="button" class="btn btn-success" onclick="exportToCSV()">
                        <i class="fas fa-file-excel me-1"></i>Export to CSV
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reports Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                Collection Reports (<?php echo number_format($total_records); ?> total)
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($reports)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>No collection reports found. Add your first report to get started!
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="reportsTable">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Area/Zone</th>
                                <th>Waste Type</th>
                                <th>Quantity (kg)</th>
                                <th>Collector</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                            <tr>
                                <td><?php echo (int)$report['id']; ?></td>
                                <td><?php echo formatDate($report['collection_date']); ?></td>
                                <td><?php echo htmlspecialchars($report['area_zone']); ?></td>
                                <td>
                                    <?php 
                                    $type_badges = [
                                        'biodegradable' => 'success',
                                        'non-biodegradable' => 'danger',
                                        'recyclable' => 'info',
                                        'hazardous' => 'warning'
                                    ];
                                    $type_badge_class = $type_badges[$report['waste_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $type_badge_class; ?>">
                                        <?php echo ucfirst($report['waste_type']); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo number_format($report['quantity_kg'], 2); ?></td>
                                <td><?php echo htmlspecialchars($report['collector_name']); ?></td>
                                <td>
                                    <?php 
                                    $status_badges = [
                                        'completed' => 'success',
                                        'partial' => 'warning',
                                        'cancelled' => 'danger'
                                    ];
                                    $status_badge_class = $status_badges[$report['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_badge_class; ?>">
                                        <?php echo ucfirst($report['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" 
                                            onclick="viewReport(<?php echo htmlspecialchars(json_encode($report), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="editReport(<?php echo htmlspecialchars(json_encode($report), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                            data-id="<?php echo (int)$report['id']; ?>" 
                                            data-area="<?php echo htmlspecialchars($report['area_zone'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-date="<?php echo htmlspecialchars($report['collection_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Reports pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo buildQueryString(['page']); ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || abs($i - $page) <= 2): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo buildQueryString(['page']); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php elseif (abs($i - $page) == 3): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo buildQueryString(['page']); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Report Modal -->
<div class="modal fade" id="addReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Collection Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="reports.php">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collection Date *</label>
                            <input type="date" class="form-control" name="collection_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Area/Zone *</label>
                            <input type="text" class="form-control" name="area_zone" placeholder="e.g., Zone A" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Waste Type *</label>
                            <select class="form-select" name="waste_type" required>
                                <option value="">Select Type</option>
                                <option value="biodegradable">Biodegradable</option>
                                <option value="non-biodegradable">Non-Biodegradable</option>
                                <option value="recyclable">Recyclable</option>
                                <option value="hazardous">Hazardous</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity (kg) *</label>
                            <input type="number" class="form-control" name="quantity_kg" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collector Name *</label>
                            <input type="text" class="form-control" name="collector_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="completed">Completed</option>
                                <option value="partial">Partial</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Report Modal -->
<div class="modal fade" id="editReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Collection Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="reports.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collection Date *</label>
                            <input type="date" class="form-control" id="edit_collection_date" name="collection_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Area/Zone *</label>
                            <input type="text" class="form-control" id="edit_area_zone" name="area_zone" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Waste Type *</label>
                            <select class="form-select" id="edit_waste_type" name="waste_type" required>
                                <option value="">Select Type</option>
                                <option value="biodegradable">Biodegradable</option>
                                <option value="non-biodegradable">Non-Biodegradable</option>
                                <option value="recyclable">Recyclable</option>
                                <option value="hazardous">Hazardous</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity (kg) *</label>
                            <input type="number" class="form-control" id="edit_quantity_kg" name="quantity_kg" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Collector Name *</label>
                            <input type="text" class="form-control" id="edit_collector_name" name="collector_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="completed">Completed</option>
                                <option value="partial">Partial</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Report Modal -->
<div class="modal fade" id="viewReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Collection Report Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewReportContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="reports.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete this collection report?</p>
                    <p class="text-muted mb-1"><strong>Report ID:</strong> <span id="delete_report_id"></span></p>
                    <p class="text-muted mb-1"><strong>Area:</strong> <span id="delete_area"></span></p>
                    <p class="text-muted mb-3"><strong>Date:</strong> <span id="delete_date"></span></p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Delete Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.border-left-primary { border-left: 4px solid #4e73df !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-info { border-left: 4px solid #36b9cc !important; }
.border-left-warning { border-left: 4px solid #f6c23e !important; }
</style>

<script>
function escapeHtml(text) {
    if (!text) return '';
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'});
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
}

function editReport(report) {
    console.log('Edit report:', report); // Debug
    document.getElementById('edit_id').value = report.id;
    document.getElementById('edit_collection_date').value = report.collection_date;
    document.getElementById('edit_area_zone').value = report.area_zone;
    document.getElementById('edit_waste_type').value = report.waste_type;
    document.getElementById('edit_quantity_kg').value = report.quantity_kg;
    document.getElementById('edit_collector_name').value = report.collector_name || '';
    document.getElementById('edit_status').value = report.status;
    new bootstrap.Modal(document.getElementById('editReportModal')).show();
}

function viewReport(report) {
    console.log('View report:', report); // Debug
    const typeBadges = {'biodegradable': 'success', 'non-biodegradable': 'danger', 'recyclable': 'info', 'hazardous': 'warning'};
    const statusBadges = {'completed': 'success', 'partial': 'warning', 'cancelled': 'danger'};
    
    const content = `
        <div class="row">
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr><th width="40%">Report ID:</th><td>${report.id}</td></tr>
                    <tr><th>Collection Date:</th><td>${formatDate(report.collection_date)}</td></tr>
                    <tr><th>Area/Zone:</th><td>${escapeHtml(report.area_zone)}</td></tr>
                    <tr><th>Waste Type:</th><td><span class="badge bg-${typeBadges[report.waste_type] || 'secondary'}">${capitalizeFirst(report.waste_type)}</span></td></tr>
                    <tr><th>Quantity:</th><td><strong>${parseFloat(report.quantity_kg || 0).toFixed(2)} kg</strong></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr><th width="40%">Collector:</th><td>${escapeHtml(report.collector_name || 'N/A')}</td></tr>
                    <tr><th>Status:</th><td><span class="badge bg-${statusBadges[report.status] || 'secondary'}">${capitalizeFirst(report.status)}</span></td></tr>
                    <tr><th>Recorded:</th><td>${formatDateTime(report.created_at)}</td></tr>
                    ${report.remarks ? `<tr><th>Remarks:</th><td>${escapeHtml(report.remarks)}</td></tr>` : ''}
                </table>
            </div>
        </div>`;
    
    document.getElementById('viewReportContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewReportModal')).show();
}

function exportToCSV() {
    const table = document.getElementById('reportsTable');
    let csv = [];
    
    // Headers
    const headers = [];
    table.querySelectorAll('thead th').forEach((th, index) => {
        if (index < table.querySelectorAll('thead th').length - 1) {
            headers.push(th.textContent.trim());
        }
    });
    csv.push(headers.join(','));
    
    // Data rows
    table.querySelectorAll('tbody tr').forEach(row => {
        const rowData = [];
        row.querySelectorAll('td').forEach((td, index) => {
            if (index < row.querySelectorAll('td').length - 1) {
                let text = td.textContent.trim().replace(/"/g, '""');
                if (text.includes(',') || text.includes('"') || text.includes('\n')) {
                    text = '"' + text + '"';
                }
                rowData.push(text);
            }
        });
        csv.push(rowData.join(','));
    });
    
    // Download
    const blob = new Blob([csv.join('\n')], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'waste_collection_reports_' + new Date().toISOString().split('T')[0] + '.csv';
    link.click();
}

// Event delegation for delete buttons
document.addEventListener('DOMContentLoaded', function() {
    document.body.addEventListener('click', function(e) {
        if (e.target.closest('.delete-btn')) {
            const btn = e.target.closest('.delete-btn');
            const id = btn.getAttribute('data-id');
            const area = btn.getAttribute('data-area');
            const date = btn.getAttribute('data-date');
            
            console.log('Delete button clicked - ID:', id, 'Area:', area, 'Date:', date); // Debug
            
            if (!id || id === 'null' || id === 'undefined' || id === '0') {
                alert('Error: Invalid report ID. Please refresh the page and try again.');
                return;
            }
            
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_report_id').textContent = id;
            document.getElementById('delete_area').textContent = area || 'N/A';
            document.getElementById('delete_date').textContent = date || 'N/A';
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    });
});
</script>

<?php require_once '../../../includes/footer.php'; ?>   