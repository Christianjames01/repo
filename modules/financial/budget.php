<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Budget Management';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$current_year = date('Y');
$fiscal_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_budget') {
            $category_id = intval($_POST['category_id']);
            $allocated_amount = floatval($_POST['allocated_amount']);
            $notes = trim($_POST['notes']);
            
            $stmt = $conn->prepare("INSERT INTO tbl_budget_allocations (fiscal_year, category_id, allocated_amount, notes, created_by, status) VALUES (?, ?, ?, ?, ?, 'Draft')");
            $stmt->bind_param("iidsi", $fiscal_year, $category_id, $allocated_amount, $notes, $current_user_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Budget allocation added successfully!";
            }
            $stmt->close();
            header('Location: budget.php?year=' . $fiscal_year);
            exit();
            
        } elseif ($_POST['action'] === 'update_budget') {
            $allocation_id = intval($_POST['allocation_id']);
            $allocated_amount = floatval($_POST['allocated_amount']);
            $notes = trim($_POST['notes']);
            
            $stmt = $conn->prepare("UPDATE tbl_budget_allocations SET allocated_amount = ?, notes = ? WHERE allocation_id = ? AND status = 'Draft'");
            $stmt->bind_param("dsi", $allocated_amount, $notes, $allocation_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Budget updated successfully!";
            }
            $stmt->close();
            header('Location: budget.php?year=' . $fiscal_year);
            exit();
            
        } elseif ($_POST['action'] === 'approve_budget') {
            $allocation_id = intval($_POST['allocation_id']);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get the allocated amount
                $check_stmt = $conn->prepare("SELECT allocated_amount FROM tbl_budget_allocations WHERE allocation_id = ? AND status = 'Draft'");
                $check_stmt->bind_param("i", $allocation_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $budget = $result->fetch_assoc();
                $check_stmt->close();
                
                if (!$budget) {
                    throw new Exception("Budget allocation not found or already approved");
                }
                
                $allocated_amount = $budget['allocated_amount'];
                
                // Update budget status
                $stmt = $conn->prepare("UPDATE tbl_budget_allocations SET status = 'Approved', approved_by = ?, approval_date = NOW() WHERE allocation_id = ?");
                $stmt->bind_param("ii", $current_user_id, $allocation_id);
                $stmt->execute();
                $stmt->close();
                
                // Update fund balance (deduct allocated amount)
                $balance_stmt = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = current_balance - ?, updated_by = ?, last_updated = NOW() WHERE balance_id = (SELECT balance_id FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1)");
                $balance_stmt->bind_param("di", $allocated_amount, $current_user_id);
                $balance_stmt->execute();
                $balance_stmt->close();
                
                // Commit transaction
                $conn->commit();
                $_SESSION['success_message'] = "Budget approved and ₱" . number_format($allocated_amount, 2) . " reserved from fund balance!";
                
            } catch (Exception $e) {
                // Rollback on error
                $conn->rollback();
                $_SESSION['error_message'] = "Failed to approve budget: " . $e->getMessage();
            }
            
            header('Location: budget.php?year=' . $fiscal_year);
            exit();
            
        } elseif ($_POST['action'] === 'delete_budget') {
            $allocation_id = intval($_POST['allocation_id']);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get budget details before deletion
                $check_stmt = $conn->prepare("SELECT allocated_amount, status FROM tbl_budget_allocations WHERE allocation_id = ?");
                $check_stmt->bind_param("i", $allocation_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $budget = $result->fetch_assoc();
                $check_stmt->close();
                
                if (!$budget) {
                    throw new Exception("Budget allocation not found");
                }
                
                // Only allow deletion of Draft budgets
                if ($budget['status'] !== 'Draft') {
                    throw new Exception("Only draft budgets can be deleted");
                }
                
                // Delete the budget allocation
                $stmt = $conn->prepare("DELETE FROM tbl_budget_allocations WHERE allocation_id = ? AND status = 'Draft'");
                $stmt->bind_param("i", $allocation_id);
                $stmt->execute();
                $stmt->close();
                
                // Commit transaction
                $conn->commit();
                $_SESSION['success_message'] = "Budget allocation deleted successfully!";
                
            } catch (Exception $e) {
                // Rollback on error
                $conn->rollback();
                $_SESSION['error_message'] = "Failed to delete budget: " . $e->getMessage();
            }
            
            header('Location: budget.php?year=' . $fiscal_year);
            exit();
        }
    }
}

// Get budget allocations
$budgets = fetchAll($conn, "
    SELECT ba.*, ec.category_name, 
           u1.username as created_by_name,
           u2.username as approved_by_name
    FROM tbl_budget_allocations ba
    JOIN tbl_expense_categories ec ON ba.category_id = ec.category_id
    LEFT JOIN tbl_users u1 ON ba.created_by = u1.user_id
    LEFT JOIN tbl_users u2 ON ba.approved_by = u2.user_id
    WHERE ba.fiscal_year = ?
    ORDER BY ba.status, ec.category_name
", [$fiscal_year], 'i');

// Get categories not yet in budget
$used_categories = array_column($budgets, 'category_id');
$all_categories = fetchAll($conn, "SELECT * FROM tbl_expense_categories WHERE is_active = 1 ORDER BY category_name");
$available_categories = array_filter($all_categories, function($cat) use ($used_categories) {
    return !in_array($cat['category_id'], $used_categories);
});

// Calculate totals
$total_allocated = array_sum(array_column($budgets, 'allocated_amount'));
$total_spent = array_sum(array_column($budgets, 'spent_amount'));
$total_remaining = array_sum(array_column($budgets, 'remaining_amount'));

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-chart-pie"></i> Budget Management
        </h1>
        <p class="page-subtitle">Allocate and monitor budget for FY <?php echo $fiscal_year; ?></p>
    </div>
    <div class="header-actions">
        <select class="year-selector" onchange="window.location.href='budget.php?year='+this.value">
            <?php for ($y = $current_year + 1; $y >= $current_year - 5; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $fiscal_year ? 'selected' : ''; ?>>
                    FY <?php echo $y; ?>
                </option>
            <?php endfor; ?>
        </select>
        <?php if (!empty($available_categories)): ?>
            <button class="action-btn budget" onclick="openModal('addBudgetModal')">
                <i class="fas fa-plus-circle"></i> Add Budget Allocation
            </button>
        <?php endif; ?>
    </div>
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

<!-- Summary Cards -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 1.5rem;">
    <div class="stat-card balance">
        <div class="stat-icon">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Allocated</div>
            <div class="stat-value">₱<?php echo number_format($total_allocated, 2); ?></div>
        </div>
    </div>
    <div class="stat-card expense">
        <div class="stat-icon">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Spent</div>
            <div class="stat-value">₱<?php echo number_format($total_spent, 2); ?></div>
        </div>
    </div>
    <div class="stat-card revenue">
        <div class="stat-icon">
            <i class="fas fa-piggy-bank"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Remaining</div>
            <div class="stat-value">₱<?php echo number_format($total_remaining, 2); ?></div>
        </div>
    </div>
</div>

<!-- Budget Table -->
<div class="chart-card">
    <div class="chart-header">
        <h3><i class="fas fa-list"></i> Budget Allocations (<?php echo count($budgets); ?> categories)</h3>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Allocated Amount</th>
                    <th>Spent Amount</th>
                    <th>Remaining</th>
                    <th>Utilization</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($budgets)): ?>
                    <tr>
                        <td colspan="8" class="text-center">No budget allocations for FY <?php echo $fiscal_year; ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($budgets as $budget): 
                        $utilization_pct = $budget['allocated_amount'] > 0 ? ($budget['spent_amount'] / $budget['allocated_amount']) * 100 : 0;
                        $progress_class = $utilization_pct > 90 ? 'danger' : ($utilization_pct > 75 ? 'warning' : '');
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($budget['category_name']); ?></strong></td>
                            <td>₱<?php echo number_format($budget['allocated_amount'], 2); ?></td>
                            <td class="expense-color">₱<?php echo number_format($budget['spent_amount'], 2); ?></td>
                            <td class="revenue-color">₱<?php echo number_format($budget['remaining_amount'], 2); ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div class="progress-bar" style="flex: 1; height: 8px; margin: 0;">
                                        <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo min($utilization_pct, 100); ?>%"></div>
                                    </div>
                                    <span style="font-weight: 600; font-size: 0.85rem; color: #6b7280;">
                                        <?php echo number_format($utilization_pct, 1); ?>%
                                    </span>
                                </div>
                            </td>
                            <td><?php echo getStatusBadge($budget['status']); ?></td>
                            <td><?php echo htmlspecialchars($budget['created_by_name']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($budget['status'] === 'Draft'): ?>
                                        <button class="btn-icon" onclick='editBudget(<?php echo json_encode($budget); ?>)' title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon success" onclick='approveBudget(<?php echo json_encode($budget); ?>)' title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn-icon danger" onclick='deleteBudget(<?php echo json_encode($budget); ?>)' title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-icon" onclick='viewBudget(<?php echo json_encode($budget); ?>)' title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($budgets)): ?>
                <tfoot>
                    <tr style="background: #f9fafb; font-weight: 700;">
                        <td>TOTAL</td>
                        <td>₱<?php echo number_format($total_allocated, 2); ?></td>
                        <td class="expense-color">₱<?php echo number_format($total_spent, 2); ?></td>
                        <td class="revenue-color">₱<?php echo number_format($total_remaining, 2); ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Add Budget Modal -->
<div id="addBudgetModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-plus-circle"></i> Add Budget Allocation</h2>
            <button class="close-btn" onclick="closeModal('addBudgetModal')">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="add_budget">
            <div class="form-group">
                <label class="required">Category</label>
                <select name="category_id" class="form-control" required>
                    <option value="">Select Category</option>
                    <?php foreach ($available_categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>">
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="required">Allocated Amount</label>
                <div class="input-group">
                    <span class="input-prefix">₱</span>
                    <input type="number" name="allocated_amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this budget allocation..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addBudgetModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Save Budget
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Budget Modal -->
<div id="editBudgetModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-edit"></i> Edit Budget Allocation</h2>
            <button class="close-btn" onclick="closeModal('editBudgetModal')">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="update_budget">
            <input type="hidden" name="allocation_id" id="edit_allocation_id">
            <div class="form-group">
                <label>Category</label>
                <input type="text" id="edit_category_name" class="form-control" disabled>
            </div>
            <div class="form-group">
                <label class="required">Allocated Amount</label>
                <div class="input-group">
                    <span class="input-prefix">₱</span>
                    <input type="number" name="allocated_amount" id="edit_allocated_amount" class="form-control" step="0.01" min="0.01" required>
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('editBudgetModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Update Budget
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Budget Modal -->
<div id="viewBudgetModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-eye"></i> Budget Details</h2>
            <button class="close-btn" onclick="closeModal('viewBudgetModal')">&times;</button>
        </div>
        <div id="budgetDetails" class="modal-body"></div>
        <div class="modal-footer" style="border-top: 2px solid #f3f4f6; padding: 1rem 1.5rem;">
            <button type="button" class="btn-secondary" onclick="closeModal('viewBudgetModal')">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Approve Budget Modal -->
<div id="approveBudgetModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-check-circle"></i> Approve Budget</h2>
            <button class="close-btn" onclick="closeModal('approveBudgetModal')">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="approve_budget">
            <input type="hidden" name="allocation_id" id="approve_allocation_id">
            <div class="confirmation-message">
                <div class="confirmation-icon success">
                    <i class="fas fa-question-circle"></i>
                </div>
                <p>Are you sure you want to approve this budget allocation?</p>
                <div class="confirmation-details">
                    <div class="detail-row">
                        <span class="label">Category:</span>
                        <span class="value" id="approve_category_name"></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Amount:</span>
                        <span class="value" id="approve_amount"></span>
                    </div>
                </div>
                <p class="warning-text">
                    <i class="fas fa-info-circle"></i> Once approved, this budget cannot be modified or deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('approveBudgetModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-success">
                    <i class="fas fa-check"></i> Approve Budget
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Budget Modal -->
<div id="deleteBudgetModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-trash-alt"></i> Delete Budget</h2>
            <button class="close-btn" onclick="closeModal('deleteBudgetModal')">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="delete_budget">
            <input type="hidden" name="allocation_id" id="delete_allocation_id">
            <div class="confirmation-message">
                <div class="confirmation-icon danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p>Are you sure you want to delete this budget allocation?</p>
                <div class="confirmation-details">
                    <div class="detail-row">
                        <span class="label">Category:</span>
                        <span class="value" id="delete_category_name"></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Amount:</span>
                        <span class="value" id="delete_amount"></span>
                    </div>
                </div>
                <p class="warning-text danger">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone!
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteBudgetModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-danger">
                    <i class="fas fa-trash"></i> Delete Budget
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    max-width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 2px solid #f3f4f6;
}

.modal-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6b7280;
    transition: all 0.2s;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

.close-btn:hover {
    color: #1f2937;
    background: #f3f4f6;
}

.modal-form {
    padding: 1.5rem;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1.5rem;
    padding-top: 1rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #374151;
    font-size: 0.95rem;
}

.form-group label.required::after {
    content: ' *';
    color: #ef4444;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.2s;
    font-family: inherit;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-control:disabled {
    background: #f9fafb;
    color: #6b7280;
    cursor: not-allowed;
}

.input-group {
    position: relative;
    display: flex;
}

.input-prefix {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    font-weight: 600;
    pointer-events: none;
    z-index: 1;
}

.input-group .form-control {
    padding-left: 2rem;
}

.btn-primary, .btn-secondary, .btn-success, .btn-danger {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.3);
}

.details-grid {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.detail-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 0.9rem;
}

.detail-value {
    color: #1f2937;
    font-weight: 600;
    text-align: right;
}

.confirmation-message {
    text-align: center;
    padding: 1rem 0;
}

.confirmation-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2rem;
}

.confirmation-icon.success {
    background: #d1fae5;
    color: #059669;
}

.confirmation-icon.danger {
    background: #fee2e2;
    color: #dc2626;
}

.confirmation-message > p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 1.5rem;
}

.confirmation-details {
    background: #f9fafb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
}

.detail-row:not(:last-child) {
    border-bottom: 1px solid #e5e7eb;
}

.detail-row .label {
    font-weight: 600;
    color: #6b7280;
}

.detail-row .value {
    font-weight: 600;
    color: #1f2937;
}

.warning-text {
    background: #eff6ff;
    color: #1e40af;
    padding: 0.75rem 1rem;
    border-radius: 6px;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
}

.warning-text.danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    gap: 1rem;
    align-items: center;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    gap: 1rem;
    align-items: center;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    padding: 0.5rem;
    border: none;
    background: #f3f4f6;
    color: #374151;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-icon:hover {
    background: #e5e7eb;
}

.btn-icon.success {
    background: #d1fae5;
    color: #065f46;
}

.btn-icon.success:hover {
    background: #a7f3d0;
}

.btn-icon.danger {
    background: #fee2e2;
    color: #991b1b;
}

.btn-icon.danger:hover {
    background: #fecaca;
}

@media (max-width: 640px) {
    .modal-content {
        max-width: 95%;
        margin: 1rem;
    }
    
    .modal-footer {
        flex-direction: column;
    }
    
    .modal-footer button {
        width: 100%;
    }
}
</style>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.body.style.overflow = '';
}

function editBudget(budget) {
    document.getElementById('edit_allocation_id').value = budget.allocation_id;
    document.getElementById('edit_category_name').value = budget.category_name;
    document.getElementById('edit_allocated_amount').value = budget.allocated_amount;
    document.getElementById('edit_notes').value = budget.notes || '';
    openModal('editBudgetModal');
}

function approveBudget(budget) {
    document.getElementById('approve_allocation_id').value = budget.allocation_id;
    document.getElementById('approve_category_name').textContent = budget.category_name;
    document.getElementById('approve_amount').textContent = '₱' + parseFloat(budget.allocated_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    openModal('approveBudgetModal');
}

function deleteBudget(budget) {
    document.getElementById('delete_allocation_id').value = budget.allocation_id;
    document.getElementById('delete_category_name').textContent = budget.category_name;
    document.getElementById('delete_amount').textContent = '₱' + parseFloat(budget.allocated_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    openModal('deleteBudgetModal');
}

function viewBudget(budget) {
    const details = document.getElementById('budgetDetails');
    const utilization = budget.allocated_amount > 0 ? (budget.spent_amount / budget.allocated_amount * 100) : 0;
    
    details.innerHTML = `
        <div class="details-grid">
            <div class="detail-item">
                <span class="detail-label">Category:</span>
                <span class="detail-value">${budget.category_name}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Allocated Amount:</span>
                <span class="detail-value">₱${parseFloat(budget.allocated_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Spent Amount:</span>
                <span class="detail-value" style="color: #dc2626;">₱${parseFloat(budget.spent_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Remaining:</span>
                <span class="detail-value" style="color: #059669;">₱${parseFloat(budget.remaining_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Utilization:</span>
                <span class="detail-value">${utilization.toFixed(1)}%</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Status:</span>
                <span class="detail-value">${getStatusBadgeHTML(budget.status)}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Created By:</span>
                <span class="detail-value">${budget.created_by_name}</span>
            </div>
            ${budget.approved_by_name ? `
            <div class="detail-item">
                <span class="detail-label">Approved By:</span>
                <span class="detail-value">${budget.approved_by_name}</span>
            </div>
            ` : ''}
            ${budget.approval_date ? `
            <div class="detail-item">
                <span class="detail-label">Approval Date:</span>
                <span class="detail-value">${new Date(budget.approval_date).toLocaleDateString()}</span>
            </div>
            ` : ''}
            ${budget.notes ? `
            <div class="detail-item" style="flex-direction: column; align-items: flex-start;">
                <span class="detail-label">Notes:</span>
                <span class="detail-value" style="margin-top: 0.5rem; text-align: left;">${budget.notes}</span>
            </div>
            ` : ''}
        </div>
    `;
    
    openModal('viewBudgetModal');
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Draft': '<span style="background: #f3f4f6; color: #374151; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Draft</span>',
        'Approved': '<span style="background: #d1fae5; color: #065f46; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Approved</span>',
        'Rejected': '<span style="background: #fee2e2; color: #991b1b; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Rejected</span>'
    };
    return badges[status] || status;
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            openModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>