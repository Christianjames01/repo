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

// ── Purge any corrupt zero-ID rows left by failed inserts ──
$conn->query("DELETE FROM tbl_4ps_extended_details WHERE beneficiary_id = 0");
$conn->query("DELETE FROM tbl_4ps_beneficiaries WHERE beneficiary_id = 0");

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Main query with all details
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
WHERE b.beneficiary_id > 0";

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

$stmt_main = $conn->prepare($query);
if (!$stmt_main) { die("Query preparation error: " . $conn->error); }

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
if (!$beneficiaries_result) { die("Query execution error: " . $conn->error); }

// Statistics
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

// Missing extended details count
$missing_query = "
SELECT COUNT(*) as missing_count
FROM tbl_4ps_beneficiaries b
LEFT JOIN tbl_4ps_extended_details e ON b.beneficiary_id = e.beneficiary_id
WHERE e.beneficiary_id IS NULL";
$missing_result = $conn->query($missing_query);
$missing_data   = $missing_result->fetch_assoc();
$missing_count  = $missing_data['missing_count'];

/* ── Link the new CSS file ── */
$extra_css = '<link rel="stylesheet" href="4ps-beneficiaries.css?v=' . time() . '">';

include __DIR__ . '/../../includes/header.php';
?>

<!-- ═══════════════════════════════════════════
     PAGE HERO  — matches dashboard hero style
═══════════════════════════════════════════ -->
<div class="bps-hero">
    <div class="bps-hero__ring bps-hero__ring--1"></div>
    <div class="bps-hero__ring bps-hero__ring--2"></div>
    <div class="bps-hero__ring bps-hero__ring--3"></div>
    <div class="bps-hero__inner">
        <div class="bps-hero__left">
            <div class="bps-hero__icon"><i class="fas fa-users"></i></div>
            <div>
                <h1 class="bps-hero__title">Manage 4Ps Beneficiaries</h1>
                <p class="bps-hero__sub">View and manage all registered 4Ps beneficiaries</p>
            </div>
        </div>
        <a href="registration.php" class="btn btn-warning">
            <i class="fas fa-user-plus"></i> Register New Beneficiary
        </a>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════ -->
<div class="container-fluid">

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

    <!-- ── STAT CARDS ── -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total Beneficiaries</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                    <div class="stat-label">Active</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning"><i class="fas fa-pause-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['suspended']); ?></div>
                    <div class="stat-label">Suspended</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-info"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-content">
                    <div class="stat-value">₱<?php echo number_format($stats['total_monthly_grants'], 2); ?></div>
                    <div class="stat-label">Total Monthly Grants</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── FILTER CARD ── -->
    <div class="card shadow mb-4">
        <div class="card-body" style="padding:18px 22px !important;">
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
                        <option value="Active"    <?php echo $filter_status == 'Active'    ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive"  <?php echo $filter_status == 'Inactive'  ? 'selected' : ''; ?>>Inactive</option>
                        <option value="Suspended" <?php echo $filter_status == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                        <option value="Graduated" <?php echo $filter_status == 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="beneficiaries.php" class="btn btn-secondary w-100">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── TABLE CARD ── -->
    <div class="card shadow">
        <div class="card-header">
            <h5><i class="fas fa-table" style="opacity:.7"></i> Beneficiary Records</h5>
            <small><i class="fas fa-info-circle"></i> Click on a row to expand and view full details</small>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="beneficiariesTable">
                    <thead>
                        <tr>
                            <th width="50"><i class="fas fa-chevron-right" style="opacity:.5"></i></th>
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
                                    <strong style="font-family:'DM Mono',monospace;color:var(--db-indigo)">#<?php echo str_pad($row['beneficiary_id'],4,'0',STR_PAD_LEFT); ?></strong>
                                    <?php if ($row['ctrl_no']): ?>
                                        <br><small style="color:var(--db-muted);font-family:'DM Mono',monospace"><?php echo htmlspecialchars($row['ctrl_no']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['id_picture'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/uploads/4ps/<?php echo htmlspecialchars($row['id_picture']); ?>"
                                             alt="Photo" class="beneficiary-photo"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2244%22 height=%2244%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23e2e8f0%22/%3E%3Ctext x=%2250%25%22 y=%2255%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2212%22 fill=%22%2394a3b8%22%3E?%3C/text%3E%3C/svg%3E'">
                                    <?php else: ?>
                                        <div class="no-photo-placeholder"><i class="fas fa-user"></i></div>
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
                                <td><strong style="font-family:'DM Mono',monospace"><?php echo htmlspecialchars($row['household_id']); ?></strong></td>
                                <td>
                                    <?php
                                    $status_class = match($row['status']) {
                                        'Active'    => 'bg-success',
                                        'Inactive'  => 'bg-secondary',
                                        'Suspended' => 'bg-warning',
                                        'Graduated' => 'bg-info',
                                        default     => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>"><?php echo $row['status']; ?></span>
                                </td>
                                <td>
                                    <?php
                                    $compliance_class = match($row['compliance_status']) {
                                        'Compliant'     => 'bg-success',
                                        'Non-Compliant' => 'bg-danger',
                                        'Partial'       => 'bg-warning',
                                        default         => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?php echo $compliance_class; ?>"><?php echo $row['compliance_status']; ?></span>
                                </td>
                                <td><strong style="color:var(--db-teal)">₱<?php echo number_format($row['monthly_grant'], 2); ?></strong></td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($row['ext_status'] == 'EXISTS'): ?>
                                            <a href="view-application.php?id=<?php echo $row['beneficiary_id']; ?>"
                                               class="btn btn-sm btn-primary" title="View Application" target="_blank">
                                                <i class="fas fa-file-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="edit-beneficiary.php?id=<?php echo $row['beneficiary_id']; ?>"
                                           class="btn btn-sm <?php echo $row['ext_status'] == 'MISSING' ? 'btn-danger' : 'btn-warning'; ?>"
                                           title="<?php echo $row['ext_status'] == 'MISSING' ? 'Add Missing Details' : 'Edit'; ?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-danger"
                                                onclick="confirmDelete(<?php echo $row['beneficiary_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['full_name'])); ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- ── Expandable Detail Row (structure unchanged) ── -->
                            <tr class="detail-row" id="details-<?php echo $row['beneficiary_id']; ?>" style="display:none;">
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
                                                                <td><?php
                                                                    if ($row['birthday']) {
                                                                        $birthDate = new DateTime($row['birthday']);
                                                                        $today = new DateTime();
                                                                        echo $today->diff($birthDate)->y . ' years old';
                                                                    } else { echo 'N/A'; }
                                                                ?></td>
                                                            </tr>
                                                            <tr><th>Gender:</th>      <td><?php echo htmlspecialchars($row['gender']       ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Civil Status:</th><td><?php echo htmlspecialchars($row['civil_status']  ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Birthplace:</th>  <td><?php echo htmlspecialchars($row['birthplace']    ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Mobile Phone:</th><td><?php echo htmlspecialchars($row['mobile_phone']  ?? 'N/A'); ?></td></tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Address -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr><th width="40%">Complete Address:</th><td><?php echo htmlspecialchars($row['permanent_address'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Street:</th>  <td><?php echo htmlspecialchars($row['street']   ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Barangay:</th><td><?php echo htmlspecialchars($row['barangay'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Town/City:</th><td><?php echo htmlspecialchars($row['town']    ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Province:</th><td><?php echo htmlspecialchars($row['province'] ?? 'N/A'); ?></td></tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Father -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-male me-2"></i>Father's Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr><th width="40%">Full Name:</th><td><?php echo htmlspecialchars($row['father_full_name'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Address:</th>  <td><?php echo htmlspecialchars($row['father_address']   ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Education:</th><td><?php echo htmlspecialchars($row['father_education'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Monthly Income:</th><td>₱<?php echo number_format($row['father_income'] ?? 0, 2); ?></td></tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Mother -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-female me-2"></i>Mother's Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr><th width="40%">Full Name:</th><td><?php echo htmlspecialchars($row['mother_full_name'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Address:</th>  <td><?php echo htmlspecialchars($row['mother_address']   ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Education:</th><td><?php echo htmlspecialchars($row['mother_education'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Monthly Income:</th><td>₱<?php echo number_format($row['mother_income'] ?? 0, 2); ?></td></tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Academic -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-graduation-cap me-2"></i>Academic Information</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr><th width="40%">Secondary School:</th><td><?php echo htmlspecialchars($row['secondary_school'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Degree Program:</th><td><?php echo htmlspecialchars($row['degree_program'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Year Level:</th>    <td><?php echo htmlspecialchars($row['year_level']     ?? 'N/A'); ?></td></tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- 4Ps Details -->
                                                <div class="col-md-6 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-hands-helping me-2"></i>4Ps Program Details</h6>
                                                        <table class="table table-sm detail-table">
                                                            <tr><th width="40%">Grantee Name:</th>   <td><?php echo htmlspecialchars($row['grantee_name']); ?></td></tr>
                                                            <tr><th>Date Registered:</th><td><?php echo date('F d, Y', strtotime($row['date_registered'])); ?></td></tr>
                                                            <tr><th>Set Number:</th>     <td><?php echo htmlspecialchars($row['set_number'] ?? 'N/A'); ?></td></tr>
                                                            <tr><th>Remarks:</th>        <td><?php echo htmlspecialchars($row['remarks']    ?? 'N/A'); ?></td></tr>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- References -->
                                                <?php if ($row['reference_1'] || $row['reference_2'] || $row['reference_3']): ?>
                                                <div class="col-md-12 mb-4">
                                                    <div class="detail-section">
                                                        <h6 class="section-title"><i class="fas fa-address-book me-2"></i>Personal References</h6>
                                                        <table class="table table-sm detail-table">
                                                            <?php if ($row['reference_1']): ?><tr><th width="20%">Reference 1:</th><td><?php echo htmlspecialchars($row['reference_1']); ?></td></tr><?php endif; ?>
                                                            <?php if ($row['reference_2']): ?><tr><th>Reference 2:</th><td><?php echo htmlspecialchars($row['reference_2']); ?></td></tr><?php endif; ?>
                                                            <?php if ($row['reference_3']): ?><tr><th>Reference 3:</th><td><?php echo htmlspecialchars($row['reference_3']); ?></td></tr><?php endif; ?>
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
                                        <a href="beneficiaries.php" class="btn btn-primary"><i class="fas fa-redo me-2"></i>Clear Filters</a>
                                    <?php else: ?>
                                        <a href="registration.php" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Register First Beneficiary</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->


<!-- ── DELETE CONFIRMATION MODAL ── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash"></i> Confirm Deletion</h5>
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
                    <i class="fas fa-trash"></i> Delete Permanently
                </a>
            </div>
        </div>
    </div>
</div>


<script>
let deleteModal;

document.addEventListener('DOMContentLoaded', function () {
    const deleteModalEl = document.getElementById('deleteModal');
    if (deleteModalEl) {
        deleteModal = new bootstrap.Modal(deleteModalEl);
    }

    // Row expand / collapse
    document.querySelectorAll('.beneficiary-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.btn-group') || e.target.closest('a')) return;

            const id        = this.dataset.beneficiaryId;
            const detailRow = document.getElementById('details-' + id);
            const icon      = this.querySelector('.expand-btn i');
            const isOpen    = detailRow.style.display !== 'none';

            // Close all
            document.querySelectorAll('.detail-row').forEach(function (dr) { dr.style.display = 'none'; });
            document.querySelectorAll('.expand-btn i').forEach(function (ic) {
                ic.classList.remove('fa-chevron-down');
                ic.classList.add('fa-chevron-right');
            });

            if (!isOpen) {
                detailRow.style.display = 'table-row';
                icon.classList.replace('fa-chevron-right', 'fa-chevron-down');
            }
        });
        row.style.cursor = 'pointer';
    });
});

function confirmDelete(id, name) {
    document.getElementById('deleteBeneficiaryName').textContent = name;
    document.getElementById('confirmDeleteBtn').href = 'beneficiaries.php?delete=' + id;
    deleteModal.show();
}
</script>
<style>
    /* ============================================================
   4Ps BENEFICIARIES — UI matching Dashboard Redesign v2
   Theme: Deep Navy + Warm Amber · Sora + DM Mono
   Only visual/style changes — table structure untouched
   ============================================================ */

@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');

/* ── VARIABLES ── */
:root {
    --db-navy: #0d1b36;
    --db-navy-mid: #152849;
    --db-navy-light: #1c3461;
    --db-amber: #f59e0b;
    --db-amber-light: #fef3c7;
    --db-amber-dark: #b45309;
    --db-teal: #0d9488;
    --db-teal-light: #ccfbf1;
    --db-rose: #e11d48;
    --db-rose-light: #ffe4e6;
    --db-sky: #0ea5e9;
    --db-sky-light: #e0f2fe;
    --db-indigo: #6366f1;
    --db-indigo-light: #e0e7ff;
    --db-success: #10b981;
    --db-success-light: #d1fae5;
    --db-warning: #f59e0b;
    --db-warning-light: #fef3c7;
    --db-danger: #ef4444;
    --db-danger-light: #fee2e2;
    --db-info: #3b82f6;
    --db-info-light: #dbeafe;

    --db-bg: #eef2f7;
    --db-surf: #ffffff;
    --db-surf2: #f8fafc;
    --db-border: #e2e8f0;
    --db-text: #0f172a;
    --db-muted: #64748b;

    --db-radius: 14px;
    --db-radius-sm: 8px;
    --db-radius-lg: 20px;
    --db-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg: 0 8px 40px rgba(13,27,54,.14), 0 2px 8px rgba(13,27,54,.06);
}

/* ── BASE ── */
*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'Sora', sans-serif;
    background: var(--db-bg);
    color: var(--db-text);
    font-size: 13.5px;
    line-height: 1.6;
}

/* ── PAGE HERO (replaces plain h2 header) ── */
.bps-hero {
    background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #224090 100%);
    padding: 28px 36px;
    margin-bottom: 24px;
    border-radius: 0 0 var(--db-radius-lg) var(--db-radius-lg);
    position: relative;
    overflow: hidden;
}

.bps-hero__ring {
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.06);
    pointer-events: none;
}
.bps-hero__ring--1 { width:320px; height:320px; top:-140px; right:-80px; }
.bps-hero__ring--2 { width:200px; height:200px; top:-60px; right:60px; border-color:rgba(245,158,11,.12); }
.bps-hero__ring--3 { width:120px; height:120px; bottom:-50px; left:35%; border-color:rgba(13,148,136,.14); }

.bps-hero__inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.bps-hero__left { display:flex; align-items:center; gap:18px; }

.bps-hero__icon {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--db-teal), #0f766e);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #fff;
    flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(13,148,136,.35);
}

.bps-hero__title {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.4px;
    margin-bottom: 2px;
}

.bps-hero__sub {
    font-size: 13px;
    color: rgba(255,255,255,.55);
    margin: 0;
}

/* ── CONTAINER ── */
.container-fluid {
    padding: 0 24px 40px;
    max-width: 1400px;
    margin: 0 auto;
}

/* ── ALERTS ── */
.alert {
    border-radius: var(--db-radius);
    border: none;
    border-left: 4px solid;
    font-family: 'Sora', sans-serif;
    font-size: 13.5px;
    font-weight: 500;
    padding: 14px 18px;
    margin-bottom: 16px;
    animation: dbFadeUp .3s ease both;
}
.alert-success  { background:var(--db-success-light); color:#065f46; border-color:var(--db-success); }
.alert-danger   { background:var(--db-danger-light);  color:#7f1d1d; border-color:var(--db-danger);  }
.alert-warning  { background:var(--db-warning-light); color:#92400e; border-color:var(--db-warning); }

@keyframes dbFadeUp {
    from { opacity:0; transform:translateY(8px); }
    to   { opacity:1; transform:translateY(0);   }
}

/* ── STAT CARDS ── */
.row.mb-4 { margin-bottom: 24px !important; }

.stat-card {
    background: var(--db-surf);
    border-radius: var(--db-radius);
    padding: 20px 18px 16px;
    box-shadow: var(--db-shadow);
    border: 1px solid var(--db-border);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform .2s, box-shadow .2s;
    animation: dbFadeUp .35s ease both;
}
.stat-card:hover { transform:translateY(-3px); box-shadow:var(--db-shadow-lg); }

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    flex-shrink: 0;
}
.stat-icon.bg-primary { background: linear-gradient(135deg, var(--db-sky), #0284c7); }
.stat-icon.bg-success { background: linear-gradient(135deg, var(--db-success), #059669); }
.stat-icon.bg-warning { background: linear-gradient(135deg, var(--db-amber), var(--db-amber-dark)); }
.stat-icon.bg-info    { background: linear-gradient(135deg, var(--db-teal), #0f766e); }
.stat-icon.bg-danger  { background: linear-gradient(135deg, var(--db-rose), #be1239); }

.stat-value {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -1px;
    color: var(--db-text);
    line-height: 1;
    font-family: 'Sora', sans-serif;
}
.stat-label {
    font-size: 11px;
    color: var(--db-muted);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-top: 3px;
}

/* ── CARDS / PANELS ── */
.card {
    background: var(--db-surf);
    border-radius: var(--db-radius-lg) !important;
    border: 1px solid var(--db-border) !important;
    box-shadow: var(--db-shadow);
    margin-bottom: 18px;
    overflow: hidden;
    animation: dbFadeUp .35s ease both;
}

.card-header {
    padding: 18px 22px !important;
    border-bottom: 1px solid var(--db-border) !important;
    background: var(--db-surf) !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}

.card-header h5 {
    font-size: 15px;
    font-weight: 700;
    color: var(--db-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-header h5::before {
    content: '';
    display: inline-block;
    width: 4px;
    height: 18px;
    background: linear-gradient(to bottom, var(--db-teal), var(--db-sky));
    border-radius: 2px;
}

.card-header small {
    font-size: 11.5px;
    color: var(--db-muted);
    font-family: 'DM Mono', monospace;
}

.card-body { padding: 0 !important; }

/* Filter card body needs padding */
.card:has(.form-control) .card-body,
.card:has(.form-select) .card-body { padding: 18px 22px !important; }

/* ── FILTER FORM ── */
.form-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--db-text);
    margin-bottom: 5px;
    font-family: 'Sora', sans-serif;
}

.form-control, .form-select {
    border: 1.5px solid var(--db-border) !important;
    border-radius: var(--db-radius-sm) !important;
    font-family: 'Sora', sans-serif !important;
    font-size: 13px !important;
    color: var(--db-text) !important;
    background: var(--db-surf) !important;
    padding: 9px 13px !important;
    transition: all .18s !important;
    box-shadow: none !important;
}
.form-control:focus, .form-select:focus {
    border-color: var(--db-navy-light) !important;
    box-shadow: 0 0 0 3px rgba(28,52,97,.1) !important;
}
.form-control::placeholder { color: #94a3b8 !important; }

/* ── BUTTONS ── */
.btn {
    font-family: 'Sora', sans-serif !important;
    font-weight: 600 !important;
    border-radius: var(--db-radius-sm) !important;
    font-size: 13px !important;
    transition: all .18s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.btn-primary {
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light)) !important;
    border-color: transparent !important;
    color: #fff !important;
}
.btn-primary:hover {
    background: linear-gradient(135deg, var(--db-navy-light), #2748a0) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(13,27,54,.25) !important;
    color: #fff !important;
}

.btn-secondary {
    background: var(--db-surf2) !important;
    border-color: var(--db-border) !important;
    color: var(--db-text) !important;
}
.btn-secondary:hover {
    background: var(--db-border) !important;
    color: var(--db-text) !important;
}

.btn-warning {
    background: linear-gradient(135deg, var(--db-amber), var(--db-amber-dark)) !important;
    border-color: transparent !important;
    color: #fff !important;
}
.btn-warning:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245,158,11,.35) !important;
    color: #fff !important;
}

.btn-danger {
    background: linear-gradient(135deg, var(--db-rose), #be1239) !important;
    border-color: transparent !important;
    color: #fff !important;
}
.btn-danger:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(225,29,72,.35) !important;
    color: #fff !important;
}

.btn-sm {
    padding: 5px 11px !important;
    font-size: 11.5px !important;
}

/* ── TABLE ── */
.table-responsive { overflow-x: auto; }

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    font-family: 'Sora', sans-serif;
    margin: 0 !important;
}

.table thead tr {
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
}

.table thead th {
    color: rgba(255,255,255,.85) !important;
    font-family: 'DM Mono', monospace !important;
    font-size: 10px !important;
    font-weight: 500 !important;
    text-transform: uppercase !important;
    letter-spacing: .8px !important;
    padding: 12px 16px !important;
    white-space: nowrap !important;
    border: none !important;
    background: transparent !important;
}

.table tbody tr {
    border-bottom: 1px solid var(--db-border) !important;
    transition: background .12s;
}
.table tbody tr:last-child { border-bottom: none !important; }

.table tbody tr:hover,
.beneficiary-row:hover { background: #f0f6ff !important; }

.table tbody td {
    padding: 12px 16px !important;
    vertical-align: middle !important;
    border: none !important;
    color: var(--db-text) !important;
}

.table-hover > tbody > tr:hover > * {
    background-color: transparent;
}

/* Striped warning rows */
.table-warning { background-color: #fffbeb !important; }
.table-warning:hover { background-color: #fef3c7 !important; }

/* ── BADGES ── */
.badge {
    padding: 3px 10px !important;
    border-radius: 20px !important;
    font-family: 'DM Mono', monospace !important;
    font-size: 10px !important;
    font-weight: 500 !important;
    letter-spacing: .3px !important;
}

.bg-success  { background: var(--db-success-light) !important; color: #065f46 !important; }
.bg-secondary{ background: var(--db-surf2)         !important; color: var(--db-muted) !important; border:1px solid var(--db-border) !important; }
.bg-warning  { background: var(--db-warning-light) !important; color: #92400e !important; }
.bg-info     { background: var(--db-sky-light)     !important; color: #0369a1 !important; }
.bg-danger   { background: var(--db-danger-light)  !important; color: #7f1d1d !important; }
.bg-primary  { background: var(--db-indigo-light)  !important; color: #3730a3 !important; }

/* ── BENEFICIARY PHOTO ── */
.beneficiary-photo {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    object-fit: cover;
    border: 2px solid var(--db-border);
    transition: transform .2s, box-shadow .2s;
}
.beneficiary-photo:hover {
    transform: scale(1.12);
    box-shadow: var(--db-shadow-lg);
}

.no-photo-placeholder {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: var(--db-surf2);
    border: 2px solid var(--db-border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--db-muted);
    font-size: 18px;
}

/* ── EXPAND BUTTON ── */
.expand-btn {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    background: var(--db-surf2);
    border: 1px solid var(--db-border) !important;
    color: var(--db-muted);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    transition: all .15s;
    padding: 0 !important;
    text-decoration: none !important;
}
.expand-btn:hover {
    background: var(--db-navy);
    color: #fff;
    border-color: var(--db-navy) !important;
}
.beneficiary-row { cursor: pointer; }

/* ── DETAIL EXPANDED ROW ── */
.detail-row > td {
    background: #f0f4fb !important;
    padding: 20px 24px !important;
}

.detail-content {
    background: var(--db-surf);
    border-radius: var(--db-radius-lg);
    padding: 24px;
    box-shadow: var(--db-shadow-lg);
    border: 1px solid var(--db-border);
    animation: dbFadeUp .25s ease both;
}

.detail-section {
    background: var(--db-surf2);
    border-radius: var(--db-radius);
    padding: 18px;
    border: 1px solid var(--db-border);
    height: 100%;
    transition: box-shadow .2s;
}
.detail-section:hover { box-shadow: var(--db-shadow); }

.section-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--db-navy-light);
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--db-border);
    text-transform: uppercase;
    letter-spacing: .6px;
    display: flex;
    align-items: center;
}
.section-title i { color: var(--db-teal); }

.detail-table {
    margin-bottom: 0 !important;
    font-size: 12.5px !important;
}
.detail-table tr { border-bottom: 1px solid var(--db-border) !important; }
.detail-table tr:last-child { border-bottom: none !important; }

.detail-table th {
    background: transparent !important;
    color: var(--db-muted) !important;
    font-weight: 600 !important;
    font-family: 'DM Mono', monospace !important;
    font-size: 10.5px !important;
    text-transform: uppercase !important;
    letter-spacing: .4px !important;
    padding: 8px 6px !important;
}
.detail-table td {
    padding: 8px 6px !important;
    color: var(--db-text) !important;
    font-weight: 500 !important;
}

/* ── MODAL ── */
.modal-content {
    border-radius: var(--db-radius-lg) !important;
    border: none !important;
    box-shadow: var(--db-shadow-lg) !important;
    overflow: hidden;
    font-family: 'Sora', sans-serif;
}

.modal-header {
    padding: 18px 22px !important;
    border-bottom: none !important;
}

.modal-header.bg-danger {
    background: linear-gradient(135deg, #7f1d1d, var(--db-rose)) !important;
}

.modal-title {
    font-size: 15px !important;
    font-weight: 700 !important;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-body { padding: 22px !important; font-size: 13.5px !important; }

.modal-footer {
    padding: 14px 22px !important;
    border-top: 1px solid var(--db-border) !important;
    background: var(--db-surf2) !important;
}

.btn-close-white { filter: brightness(0) invert(1); }

/* ── EMPTY STATE ── */
.text-center.py-5 {
    padding: 48px 24px !important;
}
.fa-inbox { color: var(--db-border) !important; }

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--db-surf2); }
::-webkit-scrollbar-thumb { background: var(--db-border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--db-muted); }

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
    .bps-hero { padding: 20px; border-radius: 0; }
    .bps-hero__title { font-size: 18px; }
    .container-fluid { padding: 0 14px 32px; }
}
</style>

<?php
$stmt_main->close();
include __DIR__ . '/../../includes/footer.php';
?>