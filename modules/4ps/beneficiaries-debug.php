<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$page_title = 'Manage 4Ps Beneficiaries';
$success_message = isset($_GET['success']) ? $_GET['success'] : '';
$error_message = '';

// Handle delete
if (isset($_GET['delete'])) {
    $beneficiary_id = intval($_GET['delete']);
    $conn->begin_transaction();
    
    try {
        $delete_ext = "DELETE FROM tbl_4ps_extended_details WHERE beneficiary_id = ?";
        $stmt_ext = $conn->prepare($delete_ext);
        $stmt_ext->bind_param("i", $beneficiary_id);
        $stmt_ext->execute();
        
        $delete_main = "DELETE FROM tbl_4ps_beneficiaries WHERE beneficiary_id = ?";
        $stmt_main = $conn->prepare($delete_main);
        $stmt_main->bind_param("i", $beneficiary_id);
        $stmt_main->execute();
        
        $conn->commit();
        header("Location: beneficiaries.php?success=Beneficiary deleted successfully");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting: " . $e->getMessage();
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Main query with all details - using prepared statement to avoid conflicts
$query = "
SELECT 
    b.beneficiary_id,
    b.household_id,
    b.grantee_name,
    b.date_registered,
    b.status,
    b.compliance_status,
    b.monthly_grant,
    b.set_number,
    b.remarks,
    b.created_at,
    e.detail_id,
    e.first_name,
    e.last_name,
    e.middle_name,
    e.ext_name,
    e.birthday,
    e.gender,
    e.civil_status,
    e.mobile_phone,
    e.permanent_address,
    e.street,
    e.barangay,
    e.town,
    e.province,
    e.birthplace,
    e.id_picture,
    e.ctrl_no,
    e.father_full_name,
    e.father_address,
    e.father_education,
    e.father_income,
    e.mother_full_name,
    e.mother_address,
    e.mother_education,
    e.mother_income,
    e.secondary_school,
    e.degree_program,
    e.year_level,
    e.reference_1,
    e.reference_2,
    e.reference_3,
    CASE 
        WHEN e.detail_id IS NULL THEN 'MISSING'
        WHEN e.first_name IS NULL OR e.first_name = '' THEN 'EMPTY'
        ELSE 'EXISTS'
    END as ext_status,
    CASE 
        WHEN e.first_name IS NOT NULL AND e.first_name != '' THEN
            CONCAT(e.last_name, ', ', e.first_name, 
                   CASE WHEN e.middle_name != '' THEN CONCAT(' ', SUBSTRING(e.middle_name, 1, 1), '.') ELSE '' END,
                   CASE WHEN e.ext_name != '' THEN CONCAT(' ', e.ext_name) ELSE '' END)
        ELSE b.grantee_name
    END as full_name
FROM tbl_4ps_beneficiaries b
LEFT JOIN tbl_4ps_extended_details e ON b.beneficiary_id = e.beneficiary_id
WHERE 1=1";

if ($filter_status) {
    $query .= " AND b.status = ?";
}

if ($search) {
    $query .= " AND (
        e.first_name LIKE ? OR 
        e.last_name LIKE ? OR
        b.household_id LIKE ? OR
        b.grantee_name LIKE ?
    )";
}

$query .= " ORDER BY b.created_at DESC";

// Prepare and execute query properly
$stmt_main = $conn->prepare($query);

if (!$stmt_main) {
    die("Query preparation error: " . $conn->error);
}

// Bind parameters if any
if ($filter_status && $search) {
    $search_param = "%{$search}%";
    $stmt_main->bind_param("sssss", $filter_status, $search_param, $search_param, $search_param, $search_param);
} elseif ($filter_status) {
    $stmt_main->bind_param("s", $filter_status);
} elseif ($search) {
    $search_param = "%{$search}%";
    $stmt_main->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
}

$stmt_main->execute();
$beneficiaries_result = $stmt_main->get_result();

if (!$beneficiaries_result) {
    die("Query execution error: " . $conn->error);
}

// Get statistics
$stats_query = "
SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN status = 'Suspended' THEN 1 ELSE 0 END) as suspended,
    SUM(CASE WHEN status = 'Graduated' THEN 1 ELSE 0 END) as graduated,
    SUM(CASE WHEN status = 'Active' THEN monthly_grant ELSE 0 END) as total_monthly_grants
FROM tbl_4ps_beneficiaries";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Count missing extended details
$missing_query = "
SELECT COUNT(*) as missing_count
FROM tbl_4ps_beneficiaries b
LEFT JOIN tbl_4ps_extended_details e ON b.beneficiary_id = e.beneficiary_id
WHERE e.beneficiary_id IS NULL";
$missing_result = $conn->query($missing_query);
$missing_data = $missing_result->fetch_assoc();
$missing_count = $missing_data['missing_count'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-6">
            <h2><i class="fas fa-users me-2"></i>Manage 4Ps Beneficiaries</h2>
            <p class="text-muted">View and manage all registered 4Ps beneficiaries</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="registration.php" class="btn btn-primary">
                <i class="fas fa-user-plus me-2"></i>Register New Beneficiary
            </a>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($missing_count > 0): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <h5><i class="fas fa-exclamation-triangle me-2"></i>Data Quality Alert</h5>
        <p class="mb-2">
            <strong><?php echo $missing_count; ?></strong> beneficiary record(s) are missing extended details.
        </p>
        <p class="mb-0">
            <small>Click the <span class="badge bg-danger">Add Details</span> button to complete their information.</small>
        </p>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total Beneficiaries</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                    <div class="stat-label">Active</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning">
                    <i class="fas fa-pause-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['suspended']); ?></div>
                    <div class="stat-label">Suspended</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon bg-info">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">₱<?php echo number_format($stats['total_monthly_grants'], 2); ?></div>
                    <div class="stat-label">Total Monthly Grants</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Search by name or household ID">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter by Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="Active" <?php echo $filter_status == 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $filter_status == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="Suspended" <?php echo $filter_status == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                        <option value="Graduated" <?php echo $filter_status == 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="beneficiaries-debug.php" class="btn btn-secondary w-100">
                        <i class="fas fa-redo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card shadow">
        <div class="card-header bg-white">
            <h5 class="mb-0">Beneficiary Records</h5>
            <small class="text-muted">
                <i class="fas fa-info-circle"></i> Click on a row to expand and view full details
            </small>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="beneficiariesTable">
                    <thead>
                        <tr>
                            <th width="50">
                                <i class="fas fa-chevron-right text-muted"></i>
                            </th>
                            <th>ID</th>
                            <th>Photo</th>
                            <th>Beneficiary Name</th>
                            <th>Household ID</th>
                            <th>Status</th>
                            <th>Compliance</th>
                            <th>Monthly Grant</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($beneficiaries_result && $beneficiaries_result->num_rows > 0): ?>
                            <?php while ($row = $beneficiaries_result->fetch_assoc()): ?>
                            <tr class="beneficiary-row <?php echo $row['ext_status'] == 'MISSING' ? 'table-warning' : ''; ?>" 
                                data-beneficiary-id="<?php echo $row['beneficiary_id']; ?>">
                                <td>
                                    <button class="btn btn-sm btn-link expand-btn" type="button">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </td>
                                <td>
                                    <strong><?php echo $row['beneficiary_id']; ?></strong>
                                    <?php if ($row['ctrl_no']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['ctrl_no']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['id_picture'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/uploads/4ps/<?php echo htmlspecialchars($row['id_picture']); ?>" 
                                             alt="Photo" 
                                             class="beneficiary-photo"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2250%22 height=%2250%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23ddd%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2216%22 fill=%22%23999%22%3ENo Photo%3C/text%3E%3C/svg%3E'">
                                    <?php else: ?>
                                        <div class="no-photo-placeholder">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                                    <?php if ($row['ext_status'] == 'MISSING'): ?>
                                        <br><span class="badge bg-danger">Missing Details</span>
                                    <?php elseif ($row['ext_status'] == 'EMPTY'): ?>
                                        <br><span class="badge bg-warning">Incomplete</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($row['household_id']); ?></strong></td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    switch($row['status']) {
                                        case 'Active': $status_class = 'success'; break;
                                        case 'Inactive': $status_class = 'secondary'; break;
                                        case 'Suspended': $status_class = 'warning'; break;
                                        case 'Graduated': $status_class = 'info'; break;
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $compliance_class = '';
                                    switch($row['compliance_status']) {
                                        case 'Compliant': $compliance_class = 'success'; break;
                                        case 'Non-Compliant': $compliance_class = 'danger'; break;
                                        case 'Partial': $compliance_class = 'warning'; break;
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $compliance_class; ?>">
                                        <?php echo $row['compliance_status']; ?>
                                    </span>
                                </td>
                                <td>₱<?php echo number_format($row['monthly_grant'], 2); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($row['ext_status'] == 'EXISTS'): ?>
                                            <a href="view-application.php?id=<?php echo $row['beneficiary_id']; ?>" 
                                               class="btn btn-sm btn-primary" 
                                               title="View Application" 
                                               target="_blank">
                                                <i class="fas fa-file-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="edit-beneficiary.php?id=<?php echo $row['beneficiary_id']; ?>" 
                                           class="btn btn-sm <?php echo $row['ext_status'] == 'MISSING' ? 'btn-danger' : 'btn-warning'; ?>"
                                           title="<?php echo $row['ext_status'] == 'MISSING' ? 'Add Missing Details' : 'Edit'; ?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <button type="button" 
                                                class="btn btn-sm btn-danger" 
                                                onclick="confirmDelete(<?php echo $row['beneficiary_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['full_name'])); ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Expandable Detail Row -->
                            <tr class="detail-row" id="details-<?php echo $row['beneficiary_id']; ?>" style="display: none;">
                                <td colspan="9">
                                    <div class="detail-content">
                                        <?php if ($row['ext_status'] == 'MISSING' || $row['ext_status'] == 'EMPTY'): ?>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>Extended details are missing for this beneficiary.</strong>
                                                <a href="edit-beneficiary.php?id=<?php echo $row['beneficiary_id']; ?>" class="btn btn-sm btn-warning ms-3">
                                                    <i class="fas fa-edit"></i> Add Details Now
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="row">
                                                <!-- Personal Information -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr>
                                                                <th width="40%">Full Name:</th>
                                                                <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['ext_name'] ?? '')); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Birthday:</th>
                                                                <td><?php echo $row['birthday'] ? date('F d, Y', strtotime($row['birthday'])) : 'N/A'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Age:</th>
                                                                <td>
                                                                    <?php 
                                                                    if ($row['birthday']) {
                                                                        $birthDate = new DateTime($row['birthday']);
                                                                        $today = new DateTime();
                                                                        $age = $today->diff($birthDate)->y;
                                                                        echo $age . ' years old';
                                                                    } else {
                                                                        echo 'N/A';
                                                                    }
                                                                    ?>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <th>Gender:</th>
                                                                <td><?php echo htmlspecialchars($row['gender'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Civil Status:</th>
                                                                <td><?php echo htmlspecialchars($row['civil_status'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Birthplace:</th>
                                                                <td><?php echo htmlspecialchars($row['birthplace'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Mobile Phone:</th>
                                                                <td><?php echo htmlspecialchars($row['mobile_phone'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Address Information -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr>
                                                                <th width="40%">Complete Address:</th>
                                                                <td><?php echo htmlspecialchars($row['permanent_address'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Street:</th>
                                                                <td><?php echo htmlspecialchars($row['street'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Barangay:</th>
                                                                <td><?php echo htmlspecialchars($row['barangay'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Town/City:</th>
                                                                <td><?php echo htmlspecialchars($row['town'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Province:</th>
                                                                <td><?php echo htmlspecialchars($row['province'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Family Background - Father -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-male me-2"></i>Father's Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr>
                                                                <th width="40%">Full Name:</th>
                                                                <td><?php echo htmlspecialchars($row['father_full_name'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Address:</th>
                                                                <td><?php echo htmlspecialchars($row['father_address'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Education:</th>
                                                                <td><?php echo htmlspecialchars($row['father_education'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Monthly Income:</th>
                                                                <td>₱<?php echo number_format($row['father_income'] ?? 0, 2); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Family Background - Mother -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-female me-2"></i>Mother's Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr>
                                                                <th width="40%">Full Name:</th>
                                                                <td><?php echo htmlspecialchars($row['mother_full_name'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Address:</th>
                                                                <td><?php echo htmlspecialchars($row['mother_address'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Education:</th>
                                                                <td><?php echo htmlspecialchars($row['mother_education'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Monthly Income:</th>
                                                                <td>₱<?php echo number_format($row['mother_income'] ?? 0, 2); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Academic Information -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-graduation-cap me-2"></i>Academic Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr>
                                                                <th width="40%">Secondary School:</th>
                                                                <td><?php echo htmlspecialchars($row['secondary_school'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Degree Program:</th>
                                                                <td><?php echo htmlspecialchars($row['degree_program'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Year Level:</th>
                                                                <td><?php echo htmlspecialchars($row['year_level'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- 4Ps Program Details -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-hands-helping me-2"></i>4Ps Program Details</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr>
                                                                <th width="40%">Grantee Name:</th>
                                                                <td><?php echo htmlspecialchars($row['grantee_name']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Date Registered:</th>
                                                                <td><?php echo date('F d, Y', strtotime($row['date_registered'])); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Set Number:</th>
                                                                <td><?php echo htmlspecialchars($row['set_number'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Remarks:</th>
                                                                <td><?php echo htmlspecialchars($row['remarks'] ?? 'N/A'); ?></td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Personal References -->
                                                <?php if ($row['reference_1'] || $row['reference_2'] || $row['reference_3']): ?>
                                                <div class="col-md-12 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-address-book me-2"></i>Personal References</h6>
                                                        <table class="table table-sm detail-table">
                                                            <?php if ($row['reference_1']): ?>
                                                            <tr>
                                                                <th width="20%">Reference 1:</th>
                                                                <td><?php echo htmlspecialchars($row['reference_1']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if ($row['reference_2']): ?>
                                                            <tr>
                                                                <th>Reference 2:</th>
                                                                <td><?php echo htmlspecialchars($row['reference_2']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if ($row['reference_3']): ?>
                                                            <tr>
                                                                <th>Reference 3:</th>
                                                                <td><?php echo htmlspecialchars($row['reference_3']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                        </table>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                    <p class="text-muted mb-3">No beneficiaries found</p>
                                    <?php if ($filter_status || $search): ?>
                                        <a href="beneficiaries.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-2"></i>Clear Filters
                                        </a>
                                    <?php else: ?>
                                        <a href="registration.php" class="btn btn-primary">
                                            <i class="fas fa-user-plus me-2"></i>Register First Beneficiary
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this beneficiary?</p>
                <p class="mb-0"><strong id="deleteBeneficiaryName"></strong></p>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <small>This action cannot be undone. All related records will be permanently deleted.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="fas fa-trash me-2"></i>Delete Permanently
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let deleteModal;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap modal
    const deleteModalEl = document.getElementById('deleteModal');
    if (deleteModalEl) {
        deleteModal = new bootstrap.Modal(deleteModalEl);
    }
    
    // Handle row expansion
    document.querySelectorAll('.beneficiary-row').forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't expand if clicking on action buttons
            if (e.target.closest('.btn-group') || e.target.closest('a')) {
                return;
            }
            
            const beneficiaryId = this.dataset.beneficiaryId;
            const detailRow = document.getElementById('details-' + beneficiaryId);
            const expandBtn = this.querySelector('.expand-btn i');
            
            if (detailRow.style.display === 'none') {
                // Close all other expanded rows
                document.querySelectorAll('.detail-row').forEach(dr => {
                    dr.style.display = 'none';
                });
                document.querySelectorAll('.expand-btn i').forEach(icon => {
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-right');
                });
                
                // Open this row
                detailRow.style.display = 'table-row';
                expandBtn.classList.remove('fa-chevron-right');
                expandBtn.classList.add('fa-chevron-down');
            } else {
                // Close this row
                detailRow.style.display = 'none';
                expandBtn.classList.remove('fa-chevron-down');
                expandBtn.classList.add('fa-chevron-right');
            }
        });
        
        // Add hover effect
        row.style.cursor = 'pointer';
    });
});

function confirmDelete(beneficiaryId, beneficiaryName) {
    document.getElementById('deleteBeneficiaryName').textContent = beneficiaryName;
    document.getElementById('confirmDeleteBtn').href = 'beneficiaries.php?delete=' + beneficiaryId;
    deleteModal.show();
}
</script>

<style>
.stat-card {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: bold;
    color: #2d3748;
}

.stat-label {
    color: #718096;
    font-size: 0.875rem;
}

.card {
    border: none;
    border-radius: 10px;
}

.table th {
    background: #f7fafc;
    color: #2d3748;
    font-weight: 600;
    font-size: 0.875rem;
}

.beneficiary-photo {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    object-fit: cover;
    border: 2px solid #e2e8f0;
}

.no-photo-placeholder {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #a0aec0;
    font-size: 1.5rem;
}

.beneficiary-row:hover {
    background-color: #f7fafc !important;
}

.expand-btn {
    color: #4a5568;
    padding: 0;
    text-decoration: none;
}

.expand-btn:hover {
    color: #2d3748;
}

.detail-row td {
    background: #f8f9fa;
    padding: 2rem;
}

.detail-content {
    background: white;
    border-radius: 10px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.detail-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    height: 100%;
}

.section-title {
    color: #2d3748;
    font-weight: 600;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e2e8f0;
}

.detail-table {
    margin-bottom: 0;
}

.detail-table tr {
    border-bottom: 1px solid #e2e8f0;
}

.detail-table tr:last-child {
    border-bottom: none;
}

.detail-table th {
    background: transparent;
    color: #4a5568;
    font-weight: 600;
    padding: 0.75rem 0.5rem;
}

.detail-table td {
    padding: 0.75rem 0.5rem;
    color: #2d3748;
}

.table-warning {
    background-color: #fff3cd !important;
}

.table-warning:hover {
    background-color: #ffe69c !important;
}

.badge {
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    font-size: 0.75rem;
}

.btn-group .btn {
    margin-right: 2px;
}

@media print {
    .detail-content {
        box-shadow: none;
        border: 1px solid #e2e8f0;
    }
}
</style>

<?php 
$stmt_main->close();
include __DIR__ . '/../../includes/footer.php'; 
?>