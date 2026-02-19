<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Vaccination Records';

// Handle filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$vaccine_type = isset($_GET['vaccine_type']) ? $_GET['vaccine_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$resident_id = isset($_GET['resident_id']) ? (int)$_GET['resident_id'] : 0;

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 20;
$offset = ($page - 1) * $records_per_page;

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR v.vaccine_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($vaccine_type) {
    $where_clauses[] = "v.vaccine_type = ?";
    $params[] = $vaccine_type;
    $types .= "s";
}

if ($date_from) {
    $where_clauses[] = "v.vaccination_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $where_clauses[] = "v.vaccination_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($resident_id) {
    $where_clauses[] = "v.resident_id = ?";
    $params[] = $resident_id;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM tbl_vaccination_records v
              JOIN tbl_residents r ON v.resident_id = r.resident_id
              WHERE $where_sql";
$stmt = $conn->prepare($count_sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);
$stmt->close();

// Get vaccination records
$sql = "SELECT 
            v.*,
            r.first_name,
            r.last_name,
            r.date_of_birth,
            r.gender,
            u.username as created_by_name
        FROM tbl_vaccination_records v
        JOIN tbl_residents r ON v.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON v.created_by = u.user_id
        WHERE $where_sql
        ORDER BY v.vaccination_date DESC, v.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$vaccinations = $stmt->get_result();
$stmt->close();

// Get vaccine types for filter
$vaccine_types = $conn->query("SELECT DISTINCT vaccine_type FROM tbl_vaccination_records WHERE vaccine_type IS NOT NULL ORDER BY vaccine_type");

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_vaccinations,
    COUNT(DISTINCT v.resident_id) as unique_residents,
    SUM(CASE WHEN MONTH(v.vaccination_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(v.vaccination_date) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) as this_month
FROM tbl_vaccination_records v
JOIN tbl_residents r ON v.resident_id = r.resident_id
WHERE $where_sql";
$stmt = $conn->prepare($stats_sql);
if (!empty($params)) {
    // Remove the LIMIT and OFFSET params
    $stats_params = $params;
    array_pop($stats_params);
    array_pop($stats_params);
    $stats_types = substr($types, 0, -2);
    if (!empty($stats_params) && !empty($stats_types)) {
        $stmt->bind_param($stats_types, ...$stats_params);
    }
}
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<style>
.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-success {
    background: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}

.alert-error {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}

.alert i {
    font-size: 1.25rem;
}

.info-group {
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.info-group:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.info-group h4 {
    margin: 0 0 1rem 0;
    color: #2d3748;
    font-size: 0.95rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-row {
    display: flex;
    padding: 0.5rem 0;
    gap: 1rem;
}

.info-row label {
    font-weight: 500;
    color: #718096;
    min-width: 180px;
    flex-shrink: 0;
}

.info-row span {
    color: #2d3748;
    flex: 1;
}

.text-danger {
    color: #dc3545;
    font-weight: 500;
}

.text-warning {
    color: #ffc107;
    font-weight: 500;
}

.text-success {
    color: #28a745;
}

@media (max-width: 768px) {
    .info-row {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .info-row label {
        min-width: auto;
    }
}
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-syringe"></i> Vaccination Records</h1>
        <p class="page-subtitle">Track and manage vaccination records</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('addVaccinationModal')">
        <i class="fas fa-plus"></i> Record Vaccination
    </button>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #4299e1;">
            <i class="fas fa-syringe"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['total_vaccinations']); ?></div>
            <div class="stat-label">Total Vaccinations</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #48bb78;">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['unique_residents']); ?></div>
            <div class="stat-label">Vaccinated Residents</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #9f7aea;">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo number_format($stats['this_month']); ?></div>
            <div class="stat-label">This Month</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <div class="search-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search resident or vaccine..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="vaccine_type" class="filter-select">
            <option value="">All Vaccine Types</option>
            <?php while ($type = $vaccine_types->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($type['vaccine_type']); ?>" 
                    <?php echo $vaccine_type === $type['vaccine_type'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($type['vaccine_type']); ?>
                </option>
            <?php endwhile; ?>
        </select>
        
        <input type="date" name="date_from" class="filter-input" placeholder="From Date" value="<?php echo $date_from; ?>">
        <input type="date" name="date_to" class="filter-input" placeholder="To Date" value="<?php echo $date_to; ?>">
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
        
        <?php if ($search || $vaccine_type || $date_from || $date_to): ?>
            <a href="vaccinations.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Vaccinations Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Resident</th>
                <th>Vaccine Name</th>
                <th>Type</th>
                <th>Dose</th>
                <th>Brand</th>
                <th>Next Dose</th>
                <th>Administered By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($vaccinations->num_rows > 0): ?>
                <?php while ($vax = $vaccinations->fetch_assoc()): 
                    $age = $vax['date_of_birth'] ? floor((time() - strtotime($vax['date_of_birth'])) / 31556926) : 'N/A';
                    $is_complete = $vax['dose_number'] >= $vax['total_doses'];
                ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($vax['vaccination_date'])); ?></td>
                        <td>
                            <div class="resident-info">
                                <strong><?php echo htmlspecialchars($vax['first_name'] . ' ' . $vax['last_name']); ?></strong>
                                <small class="text-muted"><?php echo $age; ?> yrs, <?php echo $vax['gender']; ?></small>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($vax['vaccine_name']); ?></td>
                        <td>
                            <span class="badge badge-info"><?php echo htmlspecialchars($vax['vaccine_type'] ?: 'N/A'); ?></span>
                        </td>
                        <td>
                            <span class="dose-info <?php echo $is_complete ? 'complete' : ''; ?>">
                                Dose <?php echo $vax['dose_number']; ?>/<?php echo $vax['total_doses']; ?>
                                <?php if ($is_complete): ?>
                                    <i class="fas fa-check-circle text-success"></i>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($vax['vaccine_brand'] ?: 'N/A'); ?></td>
                        <td>
                            <?php if (!empty($vax['next_dose_date'])): ?>
                                <?php 
                                $next_date = strtotime($vax['next_dose_date']);
                                $today = strtotime(date('Y-m-d'));
                                $days_until = floor(($next_date - $today) / (60 * 60 * 24));
                                $is_overdue = $days_until < 0;
                                ?>
                                <div>
                                    <strong><?php echo date('M d, Y', $next_date); ?></strong>
                                    <?php if ($is_overdue): ?>
                                        <br><span class="badge badge-danger">
                                            <i class="fas fa-exclamation-triangle"></i> <?php echo abs($days_until); ?> days overdue
                                        </span>
                                    <?php elseif ($days_until <= 7): ?>
                                        <br><span class="badge badge-warning">
                                            <i class="fas fa-clock"></i> Due in <?php echo $days_until; ?> days
                                        </span>
                                    <?php else: ?>
                                        <br><span class="text-muted" style="font-size: 0.85rem;">In <?php echo $days_until; ?> days</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($vax['administered_by'] ?: 'N/A'); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-info" onclick='viewVaccination(<?php echo json_encode($vax); ?>)' title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon btn-primary" onclick='editVaccination(<?php echo json_encode($vax); ?>)' title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center">
                        <div class="no-data">
                            <i class="fas fa-syringe"></i>
                            <p>No vaccination records found</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($_GET)); ?>" class="page-link">
            <i class="fas fa-chevron-left"></i> Previous
        </a>
    <?php endif; ?>
    
    <span class="page-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
    
    <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($_GET)); ?>" class="page-link">
            Next <i class="fas fa-chevron-right"></i>
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Add Vaccination Modal -->
<div id="addVaccinationModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h2 class="modal-title">Record Vaccination</h2>
            <button class="close-btn" onclick="closeModal('addVaccinationModal')">&times;</button>
        </div>
        <form action="actions/add-vaccination.php" method="POST" class="modal-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Resident *</label>
                    <select name="resident_id" class="form-control" required>
                        <option value="">Select Resident</option>
                        <?php
                        $residents = $conn->query("SELECT resident_id, first_name, last_name FROM tbl_residents WHERE is_verified = 1 ORDER BY last_name, first_name");
                        while ($r = $residents->fetch_assoc()):
                        ?>
                            <option value="<?php echo $r['resident_id']; ?>">
                                <?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Vaccine Name *</label>
                    <input type="text" name="vaccine_name" class="form-control" required placeholder="e.g., COVID-19, Influenza">
                </div>
                
                <div class="form-group">
                    <label>Vaccine Type *</label>
                    <select name="vaccine_type" class="form-control" required>
                        <option value="">Select Type</option>
                        <option value="COVID-19">COVID-19</option>
                        <option value="Influenza">Influenza</option>
                        <option value="Measles">Measles</option>
                        <option value="Polio">Polio</option>
                        <option value="Hepatitis">Hepatitis</option>
                        <option value="Tetanus">Tetanus</option>
                        <option value="Pneumonia">Pneumonia</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Vaccination Date *</label>
                    <input type="date" name="vaccination_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Dose Number *</label>
                    <input type="number" name="dose_number" class="form-control" min="1" value="1" required>
                </div>
                
                <div class="form-group">
                    <label>Total Doses *</label>
                    <input type="number" name="total_doses" class="form-control" min="1" value="1" required>
                </div>
                
                <div class="form-group">
                    <label>Next Dose Date</label>
                    <input type="date" name="next_dose_date" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Vaccine Brand</label>
                    <input type="text" name="vaccine_brand" class="form-control" placeholder="e.g., Pfizer, Sinovac">
                </div>
                
                <div class="form-group">
                    <label>Batch Number</label>
                    <input type="text" name="batch_number" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Administered By *</label>
                    <input type="text" name="administered_by" class="form-control" required placeholder="Doctor/Nurse name">
                </div>
                
                <div class="form-group">
                    <label>Vaccination Site</label>
                    <input type="text" name="vaccination_site" class="form-control" placeholder="e.g., Barangay Health Center">
                </div>
                
                <div class="form-group full-width">
                    <label>Side Effects (if any)</label>
                    <textarea name="side_effects" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label>Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addVaccinationModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Record Vaccination
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function viewVaccination(vax) {
    const age = vax.date_of_birth ? Math.floor((new Date() - new Date(vax.date_of_birth)) / 31556926000) : 'N/A';
    const isComplete = vax.dose_number >= vax.total_doses;
    const nextDate = vax.next_dose_date ? new Date(vax.next_dose_date) : null;
    const isOverdue = nextDate && nextDate < new Date() && !isComplete;
    
    const modalHTML = `
        <div class="info-group">
            <h4>Patient Information</h4>
            <div class="info-row">
                <label>Name:</label>
                <span><strong>${vax.first_name} ${vax.last_name}</strong></span>
            </div>
            <div class="info-row">
                <label>Age:</label>
                <span>${age} years old</span>
            </div>
            <div class="info-row">
                <label>Gender:</label>
                <span>${vax.gender || 'N/A'}</span>
            </div>
        </div>
        
        <div class="info-group">
            <h4>Vaccine Information</h4>
            <div class="info-row">
                <label>Vaccine Name:</label>
                <span><strong>${vax.vaccine_name}</strong></span>
            </div>
            <div class="info-row">
                <label>Vaccine Type:</label>
                <span><span class="badge badge-info">${vax.vaccine_type || 'N/A'}</span></span>
            </div>
            <div class="info-row">
                <label>Vaccine Brand:</label>
                <span>${vax.vaccine_brand || 'N/A'}</span>
            </div>
            <div class="info-row">
                <label>Batch Number:</label>
                <span>${vax.batch_number || 'N/A'}</span>
            </div>
        </div>
        
        <div class="info-group">
            <h4>Vaccination Details</h4>
            <div class="info-row">
                <label>Vaccination Date:</label>
                <span>${new Date(vax.vaccination_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>
            <div class="info-row">
                <label>Dose:</label>
                <span>
                    Dose ${vax.dose_number} of ${vax.total_doses}
                    ${isComplete ? '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Complete</span>' : '<span class="badge badge-warning">Incomplete</span>'}
                </span>
            </div>
            ${vax.next_dose_date ? `
            <div class="info-row">
                <label>Next Dose Date:</label>
                <span class="${isOverdue ? 'text-danger' : 'text-warning'}">
                    ${new Date(vax.next_dose_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}
                    ${isOverdue ? '<i class="fas fa-exclamation-triangle"></i> Overdue' : ''}
                </span>
            </div>` : ''}
            <div class="info-row">
                <label>Administered By:</label>
                <span>${vax.administered_by || 'N/A'}</span>
            </div>
            <div class="info-row">
                <label>Vaccination Site:</label>
                <span>${vax.vaccination_site || 'N/A'}</span>
            </div>
        </div>
        
        ${vax.side_effects ? `
        <div class="info-group">
            <h4>Side Effects</h4>
            <div class="info-row">
                <span>${vax.side_effects}</span>
            </div>
        </div>` : ''}
        
        ${vax.remarks ? `
        <div class="info-group">
            <h4>Remarks</h4>
            <div class="info-row">
                <span>${vax.remarks}</span>
            </div>
        </div>` : ''}
        
        ${vax.created_by_name ? `
        <div class="info-group">
            <h4>Record Information</h4>
            <div class="info-row">
                <label>Recorded By:</label>
                <span>${vax.created_by_name}</span>
            </div>
            ${vax.created_at ? `
            <div class="info-row">
                <label>Record Date:</label>
                <span>${new Date(vax.created_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>` : ''}
        </div>` : ''}
    `;
    
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'viewVaccinationModal';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h2 class="modal-title">Vaccination Record Details</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                ${modalHTML}
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="this.closest('.modal').remove(); editVaccination(${JSON.stringify(vax).replace(/"/g, '&quot;')})">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    modal.onclick = function(event) {
        if (event.target === modal) {
            modal.remove();
        }
    };
}

function editVaccination(vax) {
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'editVaccinationModal';
    modal.innerHTML = `
        <div class="modal-content large">
            <div class="modal-header">
                <h2 class="modal-title">Edit Vaccination Record</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <form action="actions/update-vaccination.php" method="POST" class="modal-body">
                <input type="hidden" name="vaccination_id" value="${vax.vaccination_id}">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Resident *</label>
                        <input type="text" class="form-control" value="${vax.first_name} ${vax.last_name}" disabled>
                        <input type="hidden" name="resident_id" value="${vax.resident_id}">
                    </div>
                    
                    <div class="form-group">
                        <label>Vaccine Name *</label>
                        <input type="text" name="vaccine_name" class="form-control" required value="${vax.vaccine_name}">
                    </div>
                    
                    <div class="form-group">
                        <label>Vaccine Type *</label>
                        <select name="vaccine_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="COVID-19" ${vax.vaccine_type === 'COVID-19' ? 'selected' : ''}>COVID-19</option>
                            <option value="Influenza" ${vax.vaccine_type === 'Influenza' ? 'selected' : ''}>Influenza</option>
                            <option value="Measles" ${vax.vaccine_type === 'Measles' ? 'selected' : ''}>Measles</option>
                            <option value="Polio" ${vax.vaccine_type === 'Polio' ? 'selected' : ''}>Polio</option>
                            <option value="Hepatitis" ${vax.vaccine_type === 'Hepatitis' ? 'selected' : ''}>Hepatitis</option>
                            <option value="Tetanus" ${vax.vaccine_type === 'Tetanus' ? 'selected' : ''}>Tetanus</option>
                            <option value="Pneumonia" ${vax.vaccine_type === 'Pneumonia' ? 'selected' : ''}>Pneumonia</option>
                            <option value="Other" ${vax.vaccine_type === 'Other' ? 'selected' : ''}>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Vaccination Date *</label>
                        <input type="date" name="vaccination_date" class="form-control" required value="${vax.vaccination_date}">
                    </div>
                    
                    <div class="form-group">
                        <label>Dose Number *</label>
                        <input type="number" name="dose_number" class="form-control" min="1" value="${vax.dose_number}" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Total Doses *</label>
                        <input type="number" name="total_doses" class="form-control" min="1" value="${vax.total_doses}" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Next Dose Date</label>
                        <input type="date" name="next_dose_date" class="form-control" value="${vax.next_dose_date || ''}">
                    </div>
                    
                    <div class="form-group">
                        <label>Vaccine Brand</label>
                        <input type="text" name="vaccine_brand" class="form-control" value="${vax.vaccine_brand || ''}">
                    </div>
                    
                    <div class="form-group">
                        <label>Batch Number</label>
                        <input type="text" name="batch_number" class="form-control" value="${vax.batch_number || ''}">
                    </div>
                    
                    <div class="form-group">
                        <label>Administered By *</label>
                        <input type="text" name="administered_by" class="form-control" required value="${vax.administered_by || ''}">
                    </div>
                    
                    <div class="form-group">
                        <label>Vaccination Site</label>
                        <input type="text" name="vaccination_site" class="form-control" value="${vax.vaccination_site || ''}">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Side Effects (if any)</label>
                        <textarea name="side_effects" class="form-control" rows="2">${vax.side_effects || ''}</textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2">${vax.remarks || ''}</textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Vaccination
                    </button>
                </div>
            </form>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    modal.onclick = function(event) {
        if (event.target === modal) {
            modal.remove();
        }
    };
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>