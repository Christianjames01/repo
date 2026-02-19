<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Health Records Management';

// Handle search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_verified = isset($_GET['verified']) ? $_GET['verified'] : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($filter_verified !== '') {
    $where_clauses[] = "r.is_verified = ?";
    $params[] = (int)$filter_verified;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$count_sql = "SELECT COUNT(*) as total 
              FROM tbl_residents r
              LEFT JOIN tbl_health_records hr ON r.resident_id = hr.resident_id
              WHERE $where_sql";
$stmt = $conn->prepare($count_sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);
$stmt->close();

// Get residents with health records
$sql = "SELECT 
            r.resident_id,
            r.first_name,
            r.last_name,
            r.email,
            r.contact_number,
            r.date_of_birth,
            r.gender,
            r.is_verified,
            hr.record_id,
            hr.blood_type,
            hr.height,
            hr.weight,
            hr.last_checkup_date,
            hr.philhealth_number,
            hr.pwd_id,
            hr.senior_citizen_id,
            hr.updated_at as health_record_updated
        FROM tbl_residents r
        LEFT JOIN tbl_health_records hr ON r.resident_id = hr.resident_id
        WHERE $where_sql
        ORDER BY r.last_name, r.first_name
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$residents = $stmt->get_result();
$stmt->close();

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-notes-medical"></i> Health Records Management</h1>
        <p class="page-subtitle">Manage resident health information and medical records</p>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <div class="search-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="verified" class="filter-select">
            <option value="">All Residents</option>
            <option value="1" <?php echo $filter_verified === '1' ? 'selected' : ''; ?>>Verified Only</option>
            <option value="0" <?php echo $filter_verified === '0' ? 'selected' : ''; ?>>Unverified Only</option>
        </select>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
        
        <?php if ($search || $filter_verified !== ''): ?>
            <a href="records.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Statistics Summary -->
<div class="stats-summary">
    <div class="stat-item">
        <i class="fas fa-users"></i>
        <div>
            <strong><?php echo number_format($total_records); ?></strong>
            <span>Total Residents</span>
        </div>
    </div>
</div>

<!-- Residents Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Resident Name</th>
                <th>Age/Gender</th>
                <th>Contact</th>
                <th>Blood Type</th>
                <th>PhilHealth</th>
                <th>Last Checkup</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($residents->num_rows > 0): ?>
                <?php while ($resident = $residents->fetch_assoc()): 
                    $age = $resident['date_of_birth'] ? floor((time() - strtotime($resident['date_of_birth'])) / 31556926) : 'N/A';
                    $has_health_record = !empty($resident['record_id']);
                ?>
                    <tr>
                        <td>
                            <div class="resident-info">
                                <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong>
                                <?php if (!$has_health_record): ?>
                                    <span class="badge badge-warning">No Health Record</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo $age; ?> / <?php echo $resident['gender'] ?: 'N/A'; ?></td>
                        <td>
                            <?php echo $resident['contact_number'] ?: 'N/A'; ?><br>
                            <small class="text-muted"><?php echo $resident['email'] ?: ''; ?></small>
                        </td>
                        <td>
                            <?php if ($resident['blood_type']): ?>
                                <span class="blood-type"><?php echo htmlspecialchars($resident['blood_type']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($resident['philhealth_number']): ?>
                                <i class="fas fa-check-circle text-success"></i> Yes
                            <?php else: ?>
                                <span class="text-muted">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($resident['last_checkup_date']): ?>
                                <?php echo date('M d, Y', strtotime($resident['last_checkup_date'])); ?>
                            <?php else: ?>
                                <span class="text-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($resident['is_verified']): ?>
                                <span class="badge badge-success">Verified</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Unverified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-primary" onclick="viewHealthRecord(<?php echo $resident['resident_id']; ?>)" title="View/Edit Health Record">
                                    <i class="fas fa-notes-medical"></i>
                                </button>
                                <button class="btn-icon btn-info" onclick="viewVaccinations(<?php echo $resident['resident_id']; ?>)" title="Vaccinations">
                                    <i class="fas fa-syringe"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="text-center">
                        <div class="no-data">
                            <i class="fas fa-inbox"></i>
                            <p>No residents found</p>
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
        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter_verified !== '' ? '&verified=' . $filter_verified : ''; ?>" class="page-link">
            <i class="fas fa-chevron-left"></i> Previous
        </a>
    <?php endif; ?>
    
    <span class="page-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
    
    <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter_verified !== '' ? '&verified=' . $filter_verified : ''; ?>" class="page-link">
            Next <i class="fas fa-chevron-right"></i>
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Health Record Modal -->
<div id="healthRecordModal" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h2 class="modal-title">Health Record</h2>
            <button class="close-btn" onclick="closeModal('healthRecordModal')">&times;</button>
        </div>
        <div class="modal-body" id="healthRecordContent">
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>
</div>

<script>
function viewHealthRecord(residentId) {
    document.getElementById('healthRecordModal').classList.add('show');
    document.getElementById('healthRecordContent').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch(`actions/get-health-record.php?resident_id=${residentId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('healthRecordContent').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('healthRecordContent').innerHTML = '<div class="error">Error loading health record</div>';
        });
}

function viewVaccinations(residentId) {
    window.location.href = `vaccinations.php?resident_id=${residentId}`;
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>