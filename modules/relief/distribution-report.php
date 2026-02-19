<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin', 'Secretary']);

$page_title = 'Distribution Reports';

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$location_filter = $_GET['location'] ?? '';
$item_filter = $_GET['item'] ?? '';

// First, let's check what columns exist in the distributions table
$check_columns = $conn->query("SHOW COLUMNS FROM tbl_relief_distributions");
$columns = [];
while ($row = $check_columns->fetch_assoc()) {
    $columns[] = $row['Field'];
}

// Determine the correct date column name
$date_column = 'created_at'; // default fallback
if (in_array('distribution_date', $columns)) {
    $date_column = 'distribution_date';
} elseif (in_array('date', $columns)) {
    $date_column = 'date';
} elseif (in_array('created_at', $columns)) {
    $date_column = 'created_at';
}

// Check if total_beneficiaries column exists
$has_beneficiaries = in_array('total_beneficiaries', $columns);
$beneficiaries_column = $has_beneficiaries ? 'total_beneficiaries' : '0 as total_beneficiaries';

// Fetch all distributions with details
$where_clauses = ["1=1"];
$where_clauses_no_alias = ["1=1"]; // For queries without 'rd' alias
$params = [];
$types = '';

if (!empty($date_from)) {
    $where_clauses[] = "DATE(rd.$date_column) >= ?";
    $where_clauses_no_alias[] = "DATE($date_column) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_clauses[] = "DATE(rd.$date_column) <= ?";
    $where_clauses_no_alias[] = "DATE($date_column) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($location_filter)) {
    $where_clauses[] = "rd.location LIKE ?";
    $where_clauses_no_alias[] = "location LIKE ?";
    $params[] = "%{$location_filter}%";
    $types .= 's';
}

$where_sql = implode(' AND ', $where_clauses);
$where_sql_no_alias = implode(' AND ', $where_clauses_no_alias);

// Main distributions query
$distributions_sql = "SELECT rd.*, 
                      rd.$date_column as distribution_date,
                      rd.$beneficiaries_column,
                      u.username as distributed_by_name,
                      COUNT(DISTINCT rdi.item_id) as items_count
                      FROM tbl_relief_distributions rd
                      LEFT JOIN tbl_users u ON rd.distributed_by = u.user_id
                      LEFT JOIN tbl_relief_distribution_items rdi ON rd.distribution_id = rdi.distribution_id
                      WHERE $where_sql
                      GROUP BY rd.distribution_id
                      ORDER BY rd.$date_column DESC, rd.distribution_id DESC";

$distributions = !empty($params) ? fetchAll($conn, $distributions_sql, $params, $types) : fetchAll($conn, $distributions_sql);

// Fetch all unique locations for filter
$locations_sql = "SELECT DISTINCT location FROM tbl_relief_distributions ORDER BY location";
$locations = fetchAll($conn, $locations_sql);

// Fetch all items for filter
$items_sql = "SELECT item_id, item_name FROM tbl_relief_items ORDER BY item_name";
$items = fetchAll($conn, $items_sql);

// Calculate summary statistics
$total_distributions = count($distributions);
$total_beneficiaries = 0;
foreach ($distributions as $dist) {
    $total_beneficiaries += isset($dist['total_beneficiaries']) ? intval($dist['total_beneficiaries']) : 0;
}

// Get items distributed summary
$items_summary_sql = "SELECT ri.item_name, ri.unit_of_measure, ri.item_category,
                      SUM(rdi.quantity_distributed) as total_distributed
                      FROM tbl_relief_distribution_items rdi
                      JOIN tbl_relief_items ri ON rdi.item_id = ri.item_id
                      JOIN tbl_relief_distributions rd ON rdi.distribution_id = rd.distribution_id
                      WHERE $where_sql
                      GROUP BY rdi.item_id
                      ORDER BY total_distributed DESC";

$items_summary = !empty($params) ? fetchAll($conn, $items_summary_sql, $params, $types) : fetchAll($conn, $items_summary_sql);

// Get distributions by location
$location_summary_sql = "SELECT location, 
                         COUNT(*) as distribution_count,
                         " . ($has_beneficiaries ? "SUM(total_beneficiaries)" : "0") . " as beneficiary_count
                         FROM tbl_relief_distributions
                         WHERE $where_sql_no_alias
                         GROUP BY location
                         ORDER BY beneficiary_count DESC";

$location_summary = !empty($params) ? fetchAll($conn, $location_summary_sql, $params, $types) : fetchAll($conn, $location_summary_sql);

include '../../includes/header.php';
?>

<style>
.report-header {
    background: #f8f9fa;
    border-left: 4px solid #495057;
    color: #212529;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.report-header h1 {
    color: #212529;
    margin: 0 0 0.5rem 0;
}

.report-header p {
    color: #6c757d;
    margin: 0;
}

.filter-section {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    border: 1px solid #dee2e6;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.stat-card .stat-icon {
    font-size: 2rem;
    margin-bottom: 0.75rem;
    color: #6c757d;
}

.stat-card .stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #212529;
    margin-bottom: 0.5rem;
}

.stat-card .stat-label {
    color: #6c757d;
    font-size: 0.9rem;
}

.report-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #dee2e6;
    margin-bottom: 2rem;
}

.table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    padding: 1rem;
    text-align: left;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    padding: 1rem;
    border-bottom: 1px solid #e9ecef;
    color: #212529;
}

.table tbody tr:hover {
    background: #f8f9fa;
}

.table tbody tr:last-child td {
    border-bottom: none;
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
    background: white;
    color: #495057;
}

.btn:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
}

.btn-primary {
    background: #495057;
    color: white;
    border-color: #495057;
}

.btn-primary:hover {
    background: #343a40;
    border-color: #343a40;
}

.btn-success {
    background: #28a745;
    color: white;
    border-color: #28a745;
}

.btn-success:hover {
    background: #218838;
    border-color: #218838;
}

.btn-info {
    background: #6c757d;
    color: white;
    border-color: #6c757d;
}

.btn-info:hover {
    background: #5a6268;
    border-color: #545b62;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 6px;
}

.form-control:focus {
    outline: none;
    border-color: #495057;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #495057;
}

.badge {
    padding: 0.35rem 0.65rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}

.bg-success {
    background: #d4edda;
    color: #155724;
}

.bg-primary {
    background: #e7f1ff;
    color: #004085;
}

.bg-info {
    background: #e9ecef;
    color: #383d41;
}

.bg-warning {
    background: #fff3cd;
    color: #856404;
}

.section-title {
    color: #212529;
    margin: 2rem 0 1rem 0;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.no-data {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.4);
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    max-width: 800px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid #dee2e6;
}

.modal-header {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #dee2e6;
}

.modal-header h2 {
    margin: 0;
    color: #212529;
}

.info-grid {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.info-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.info-row:last-child {
    margin-bottom: 0;
}

.info-item strong {
    color: #495057;
    display: block;
    margin-bottom: 0.25rem;
}

.print-only {
    display: none;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .print-only {
        display: block;
    }
    
    .report-header {
        background: none;
        color: #212529;
        border: 2px solid #212529;
    }
    
    .stat-card {
        border: 1px solid #212529;
        break-inside: avoid;
    }
    
    body {
        background: white;
    }
}
</style>

<div class="report-header">
    <h1><i class="fas fa-chart-bar"></i> Distribution Reports</h1>
    <p>Comprehensive analysis of relief goods distribution</p>
</div>

<!-- Filter Section -->
<div class="filter-section no-print">
    <h5 style="margin-top: 0;"><i class="fas fa-filter"></i> Filter Reports</h5>
    <form method="GET" action="">
        <div class="filter-grid">
            <div>
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div>
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div>
                <label class="form-label">Location</label>
                <select name="location" class="form-control">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc['location']); ?>" 
                            <?php echo $location_filter === $loc['location'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['location']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; align-items: flex-end; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <a href="?" class="btn btn-success">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Summary Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-hands-helping"></i>
        </div>
        <div class="stat-value"><?php echo number_format($total_distributions); ?></div>
        <div class="stat-label">Total Distributions</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-value"><?php echo number_format($total_beneficiaries); ?></div>
        <div class="stat-label">Total Beneficiaries</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-box"></i>
        </div>
        <div class="stat-value"><?php echo count($items_summary); ?></div>
        <div class="stat-label">Items Distributed</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-map-marker-alt"></i>
        </div>
        <div class="stat-value"><?php echo count($location_summary); ?></div>
        <div class="stat-label">Locations Served</div>
    </div>
</div>

<!-- Action Buttons -->
<div style="margin-bottom: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;" class="no-print">
    <a href="inventory.php" class="btn btn-primary" style="text-decoration: none; display: inline-flex; align-items: center;">
        <i class="fas fa-arrow-left"></i> Back to Inventory
    </a>
    <button onclick="window.print()" class="btn btn-primary">
        <i class="fas fa-print"></i> Print Report
    </button>
    <button onclick="exportToExcel()" class="btn btn-success">
        <i class="fas fa-file-excel"></i> Export to Excel
    </button>
</div>

<!-- Distribution by Location -->
<?php if (!empty($location_summary)): ?>
<h3 class="section-title"><i class="fas fa-map-marked-alt"></i> Distribution by Location</h3>
<div class="report-table">
    <table class="table">
        <thead>
            <tr>
                <th>Location</th>
                <th>Distribution Count</th>
                <th>Total Beneficiaries</th>
                <th>Avg Beneficiaries per Distribution</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($location_summary as $loc): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($loc['location']); ?></strong></td>
                <td><span class="badge bg-primary"><?php echo number_format($loc['distribution_count']); ?></span></td>
                <td><span class="badge bg-success"><?php echo number_format($loc['beneficiary_count']); ?></span></td>
                <td><?php echo $loc['distribution_count'] > 0 ? number_format($loc['beneficiary_count'] / $loc['distribution_count'], 0) : '0'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Items Distributed Summary -->
<?php if (!empty($items_summary)): ?>
<h3 class="section-title"><i class="fas fa-boxes"></i> Items Distributed Summary</h3>
<div class="report-table">
    <table class="table">
        <thead>
            <tr>
                <th>Item Name</th>
                <th>Category</th>
                <th>Total Quantity Distributed</th>
                <th>Unit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items_summary as $item): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                <td><span class="badge bg-info"><?php echo htmlspecialchars($item['item_category']); ?></span></td>
                <td><strong><?php echo number_format($item['total_distributed'], 2); ?></strong></td>
                <td><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Detailed Distribution Records -->
<h3 class="section-title"><i class="fas fa-list"></i> Detailed Distribution Records</h3>
<?php if (!empty($distributions)): ?>
<div class="report-table">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Location</th>
                <th>Beneficiaries</th>
                <th>Items Count</th>
                <th>Distributed By</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($distributions as $dist): ?>
            <tr>
                <td><?php echo formatDate($dist['distribution_date']); ?></td>
                <td><strong><?php echo htmlspecialchars($dist['location']); ?></strong></td>
                <td><span class="badge bg-success"><?php echo number_format(isset($dist['total_beneficiaries']) ? $dist['total_beneficiaries'] : 0); ?></span></td>
                <td><span class="badge bg-info"><?php echo $dist['items_count']; ?> items</span></td>
                <td><?php echo htmlspecialchars($dist['distributed_by_name']); ?></td>
                <td class="no-print">
                    <button class="btn btn-info btn-sm" onclick="viewDistributionDetails(<?php echo $dist['distribution_id']; ?>)">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="no-data">
    <i class="fas fa-inbox" style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;"></i>
    <p>No distribution records found for the selected filters.</p>
</div>
<?php endif; ?>

<!-- Distribution Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-info-circle"></i> Distribution Details</h2>
        </div>
        <div id="detailsContent">
            <div style="text-align: center; padding: 2rem;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #6c757d;"></i>
                <p style="color: #6c757d; margin-top: 1rem;">Loading...</p>
            </div>
        </div>
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px solid #dee2e6; text-align: right;">
            <button class="btn btn-primary" onclick="closeModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<script>
function viewDistributionDetails(distributionId) {
    const modal = document.getElementById('detailsModal');
    const content = document.getElementById('detailsContent');
    
    // Show modal
    modal.classList.add('show');
    
    // Fetch details via AJAX
    fetch('get_distribution_details.php?id=' + distributionId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-item">
                                <strong>Distribution Date:</strong>
                                ${data.distribution.distribution_date}
                            </div>
                            <div class="info-item">
                                <strong>Location:</strong>
                                ${data.distribution.location}
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-item">
                                <strong>Total Beneficiaries:</strong>
                                ${data.distribution.total_beneficiaries || 0}
                            </div>
                            <div class="info-item">
                                <strong>Distributed By:</strong>
                                ${data.distribution.distributed_by_name}
                            </div>
                        </div>
                        ${data.distribution.remarks ? `
                            <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #dee2e6;">
                                <strong>Remarks:</strong><br>
                                <span style="color: #6c757d;">${data.distribution.remarks}</span>
                            </div>
                        ` : ''}
                    </div>
                    
                    <h4 style="margin: 1.5rem 0 1rem 0; color: #212529;">Items Distributed</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                
                data.items.forEach(item => {
                    html += `
                        <tr>
                            <td><strong>${item.item_name}</strong></td>
                            <td><span class="badge bg-info">${item.item_category}</span></td>
                            <td><strong>${parseFloat(item.quantity_distributed).toFixed(2)}</strong></td>
                            <td>${item.unit_of_measure}</td>
                        </tr>
                    `;
                });
                
                html += `
                        </tbody>
                    </table>
                `;
                
                content.innerHTML = html;
            } else {
                content.innerHTML = '<p style="color: #dc3545;">Error loading distribution details.</p>';
            }
        })
        .catch(error => {
            content.innerHTML = '<p style="color: #dc3545;">Error loading distribution details.</p>';
        });
}

function closeModal() {
    document.getElementById('detailsModal').classList.remove('show');
}

function exportToExcel() {
    // Prepare data for export
    let csv = 'Distribution Report\n';
    csv += 'Generated on: ' + new Date().toLocaleDateString() + '\n\n';
    csv += 'Date Range: <?php echo $date_from; ?> to <?php echo $date_to; ?>\n';
    <?php if ($location_filter): ?>
    csv += 'Location Filter: <?php echo addslashes($location_filter); ?>\n';
    <?php endif; ?>
    csv += '\n';
    
    csv += 'SUMMARY STATISTICS\n';
    csv += 'Total Distributions,<?php echo $total_distributions; ?>\n';
    csv += 'Total Beneficiaries,<?php echo $total_beneficiaries; ?>\n';
    csv += 'Items Distributed,<?php echo count($items_summary); ?>\n';
    csv += 'Locations Served,<?php echo count($location_summary); ?>\n\n';
    
    csv += 'DISTRIBUTION BY LOCATION\n';
    csv += 'Location,Distribution Count,Total Beneficiaries,Avg Beneficiaries\n';
    <?php foreach ($location_summary as $loc): ?>
    csv += '"<?php echo addslashes($loc['location']); ?>",';
    csv += '<?php echo $loc['distribution_count']; ?>,';
    csv += '<?php echo $loc['beneficiary_count']; ?>,';
    csv += '<?php echo $loc['distribution_count'] > 0 ? round($loc['beneficiary_count'] / $loc['distribution_count'], 0) : 0; ?>\n';
    <?php endforeach; ?>
    csv += '\n';
    
    csv += 'ITEMS DISTRIBUTED SUMMARY\n';
    csv += 'Item Name,Category,Quantity,Unit\n';
    <?php foreach ($items_summary as $item): ?>
    csv += '"<?php echo addslashes($item['item_name']); ?>",';
    csv += '"<?php echo addslashes($item['item_category']); ?>",';
    csv += '<?php echo $item['total_distributed']; ?>,';
    csv += '"<?php echo addslashes($item['unit_of_measure']); ?>"\n';
    <?php endforeach; ?>
    csv += '\n';
    
    csv += 'DETAILED DISTRIBUTION RECORDS\n';
    csv += 'Date,Location,Beneficiaries,Items Count,Distributed By\n';
    <?php foreach ($distributions as $dist): ?>
    csv += '"<?php echo formatDate($dist['distribution_date']); ?>",';
    csv += '"<?php echo addslashes($dist['location']); ?>",';
    csv += '<?php echo isset($dist['total_beneficiaries']) ? $dist['total_beneficiaries'] : 0; ?>,';
    csv += '<?php echo $dist['items_count']; ?>,';
    csv += '"<?php echo addslashes($dist['distributed_by_name']); ?>"\n';
    <?php endforeach; ?>
    
    // Create and download the file
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'distribution_report_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
}

// Close modal on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        closeModal();
    }
}
</script>

<?php include '../../includes/footer.php'; ?>