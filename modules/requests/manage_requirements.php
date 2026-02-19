<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

// Only allow Super Admin
if ($user_role !== 'Super Admin') {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'Remove Duplicate Requirements';

$duplicates_found = false;
$duplicates_removed = false;
$error_message = '';

// Check for duplicates
$check_sql = "SELECT 
                request_type_id, 
                requirement_name, 
                COUNT(*) as count,
                GROUP_CONCAT(requirement_id ORDER BY requirement_id) as ids
              FROM tbl_document_requirements
              GROUP BY request_type_id, requirement_name
              HAVING count > 1";

$check_result = $conn->query($check_sql);
$duplicates = [];

if ($check_result && $check_result->num_rows > 0) {
    $duplicates_found = true;
    while ($row = $check_result->fetch_assoc()) {
        $duplicates[] = $row;
    }
}

// Handle duplicate removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_duplicates'])) {
    try {
        $conn->begin_transaction();
        
        // Create temporary table with unique requirements
        $sql1 = "CREATE TEMPORARY TABLE temp_unique_requirements AS
                 SELECT MIN(requirement_id) as requirement_id
                 FROM tbl_document_requirements
                 GROUP BY request_type_id, requirement_name";
        
        if (!$conn->query($sql1)) {
            throw new Exception("Failed to create temporary table: " . $conn->error);
        }
        
        // Delete duplicates
        $sql2 = "DELETE FROM tbl_document_requirements
                 WHERE requirement_id NOT IN (
                     SELECT requirement_id FROM temp_unique_requirements
                 )";
        
        if (!$conn->query($sql2)) {
            throw new Exception("Failed to remove duplicates: " . $conn->error);
        }
        
        $removed_count = $conn->affected_rows;
        
        // Drop temporary table
        $sql3 = "DROP TEMPORARY TABLE temp_unique_requirements";
        $conn->query($sql3);
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Successfully removed $removed_count duplicate requirement(s)!";
        $duplicates_removed = true;
        
        // Refresh the page to show updated data
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
        error_log("Error removing duplicates: " . $e->getMessage());
    }
}

// Fetch all requirements grouped
$sql = "SELECT 
            r.request_type_id,
            rt.request_type_name,
            r.requirement_name,
            COUNT(*) as duplicate_count,
            GROUP_CONCAT(r.requirement_id ORDER BY r.requirement_id) as all_ids,
            MIN(r.requirement_id) as kept_id
        FROM tbl_document_requirements r
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        GROUP BY r.request_type_id, r.requirement_name
        ORDER BY rt.request_type_name, r.requirement_name";

$all_requirements = $conn->query($sql);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-broom me-2"></i>Remove Duplicate Requirements
                    </h2>
                    <p class="text-muted">Clean up duplicate requirements in the system</p>
                </div>
                <div>
                    <a href="requirements.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Requirements
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Duplicate Status -->
    <?php if ($duplicates_found): ?>
        <div class="card border-0 shadow-sm mb-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="text-warning mb-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>Duplicates Found
                        </h5>
                        <p class="mb-0">
                            Found <strong><?php echo count($duplicates); ?></strong> requirement(s) with duplicates. 
                            Click the button below to remove them.
                        </p>
                    </div>
                    <form method="POST" action="">
                        <button type="submit" name="remove_duplicates" class="btn btn-warning" 
                                onclick="return confirm('This will remove all duplicate requirements, keeping only the oldest entry for each. Continue?')">
                            <i class="fas fa-broom me-2"></i>Remove Duplicates
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Duplicates List -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-warning bg-opacity-10">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Requirements with Duplicates
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Document Type</th>
                                <th>Requirement Name</th>
                                <th>Duplicate Count</th>
                                <th>IDs Found</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($duplicates as $dup): ?>
                                <?php
                                // Get document type name
                                $type_sql = "SELECT request_type_name FROM tbl_request_types WHERE request_type_id = ?";
                                $type_stmt = $conn->prepare($type_sql);
                                $type_stmt->bind_param("i", $dup['request_type_id']);
                                $type_stmt->execute();
                                $type_result = $type_stmt->get_result();
                                $type_name = $type_result->fetch_assoc()['request_type_name'] ?? 'Unknown';
                                $type_stmt->close();
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars($type_name); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($dup['requirement_name']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-danger">
                                            <?php echo $dup['count']; ?> copies
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($dup['ids']); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm border-success">
            <div class="card-body text-center py-5">
                <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                <h4 class="text-success">No Duplicates Found</h4>
                <p class="text-muted mb-0">All requirements are unique. Your database is clean!</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- All Requirements Overview -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="fas fa-clipboard-list me-2"></i>All Requirements Overview
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Document Type</th>
                            <th>Requirement Name</th>
                            <th>Count</th>
                            <th>Status</th>
                            <th>IDs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($all_requirements && $all_requirements->num_rows > 0): ?>
                            <?php while ($req = $all_requirements->fetch_assoc()): ?>
                                <tr class="<?php echo $req['duplicate_count'] > 1 ? 'table-warning' : ''; ?>">
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars($req['request_type_name'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($req['requirement_name']); ?></strong></td>
                                    <td>
                                        <?php if ($req['duplicate_count'] > 1): ?>
                                            <span class="badge bg-warning text-dark">
                                                <?php echo $req['duplicate_count']; ?> copies
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Unique</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($req['duplicate_count'] > 1): ?>
                                            <span class="text-warning">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Needs Cleanup
                                            </span>
                                        <?php else: ?>
                                            <span class="text-success">
                                                <i class="fas fa-check-circle me-1"></i>Clean
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            if ($req['duplicate_count'] > 1) {
                                                echo "Keeping: #" . $req['kept_id'] . " | ";
                                                echo "Removing: " . str_replace($req['kept_id'] . ',', '', $req['all_ids']);
                                            } else {
                                                echo "#" . $req['all_ids'];
                                            }
                                            ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                    <p class="text-muted">No requirements found</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.border-warning {
    border-left: 4px solid #ffc107 !important;
}
.border-success {
    border-left: 4px solid #198754 !important;
}
</style>

<?php include '../../includes/footer.php'; ?>