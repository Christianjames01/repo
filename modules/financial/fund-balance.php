<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Fund Balance Management';
$user_role = getCurrentUserRole();

// Only Super Admin can access
if ($user_role !== 'Super Admin') {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'set_balance') {
            $new_balance = floatval($_POST['new_balance']);
            $notes = trim($_POST['notes']);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Check if there's an existing balance
                $check_stmt = $conn->prepare("SELECT balance_id, current_balance FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $existing = $result->fetch_assoc();
                $check_stmt->close();
                
                if ($existing) {
                    // Update existing balance
                    $stmt = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = ?, updated_by = ?, last_updated = NOW() WHERE balance_id = ?");
                    $stmt->bind_param("dii", $new_balance, $current_user_id, $existing['balance_id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    $action_type = 'Balance Updated';
                    $old_balance = $existing['current_balance'];
                } else {
                    // Insert new balance
                    $stmt = $conn->prepare("INSERT INTO tbl_fund_balance (current_balance, updated_by, last_updated) VALUES (?, ?, NOW())");
                    $stmt->bind_param("di", $new_balance, $current_user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $action_type = 'Initial Balance Set';
                    $old_balance = 0;
                }
                
                // Log the transaction
                $log_stmt = $conn->prepare("INSERT INTO tbl_balance_history (action_type, old_balance, new_balance, amount_changed, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $amount_changed = $new_balance - $old_balance;
                $log_stmt->bind_param("sdddsi", $action_type, $old_balance, $new_balance, $amount_changed, $notes, $current_user_id);
                $log_stmt->execute();
                $log_stmt->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Fund balance updated successfully to ₱" . number_format($new_balance, 2);
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Failed to update balance: " . $e->getMessage();
            }
            
            header('Location: fund-balance.php');
            exit();
            
        } elseif ($_POST['action'] === 'adjust_balance') {
            $adjustment_type = $_POST['adjustment_type']; // 'add' or 'deduct'
            $adjustment_amount = floatval($_POST['adjustment_amount']);
            $notes = trim($_POST['notes']);
            
            $conn->begin_transaction();
            
            try {
                // Get current balance
                $check_stmt = $conn->prepare("SELECT balance_id, current_balance FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $existing = $result->fetch_assoc();
                $check_stmt->close();
                
                if (!$existing) {
                    throw new Exception("No existing balance found. Please set initial balance first.");
                }
                
                $old_balance = $existing['current_balance'];
                
                if ($adjustment_type === 'add') {
                    $new_balance = $old_balance + $adjustment_amount;
                    $action_type = 'Manual Addition';
                    $amount_changed = $adjustment_amount;
                } else {
                    $new_balance = $old_balance - $adjustment_amount;
                    $action_type = 'Manual Deduction';
                    $amount_changed = -$adjustment_amount;
                }
                
                // Check for negative balance
                if ($new_balance < 0) {
                    throw new Exception("Adjustment would result in negative balance. Current balance: ₱" . number_format($old_balance, 2));
                }
                
                // Update balance
                $stmt = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = ?, updated_by = ?, last_updated = NOW() WHERE balance_id = ?");
                $stmt->bind_param("dii", $new_balance, $current_user_id, $existing['balance_id']);
                $stmt->execute();
                $stmt->close();
                
                // Log the transaction
                $log_stmt = $conn->prepare("INSERT INTO tbl_balance_history (action_type, old_balance, new_balance, amount_changed, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $log_stmt->bind_param("sdddsi", $action_type, $old_balance, $new_balance, $amount_changed, $notes, $current_user_id);
                $log_stmt->execute();
                $log_stmt->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Balance adjusted successfully. New balance: ₱" . number_format($new_balance, 2);
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Failed to adjust balance: " . $e->getMessage();
            }
            
            header('Location: fund-balance.php');
            exit();
        }
    }
}

// Get current balance
$balance_stmt = $conn->prepare("SELECT * FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
$balance_stmt->execute();
$balance_result = $balance_stmt->get_result();
$current_fund = $balance_result->fetch_assoc();
$balance_stmt->close();

// Get balance history
$history_stmt = $conn->prepare("
    SELECT bh.*, u.username as performed_by
    FROM tbl_balance_history bh
    LEFT JOIN tbl_users u ON bh.created_by = u.user_id
    ORDER BY bh.created_at DESC
    LIMIT 50
");
$history_stmt->execute();
$history = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$history_stmt->close();

// Get total budget allocations
$budget_stmt = $conn->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total_allocated FROM tbl_budget_allocations WHERE status = 'Approved'");
$budget_stmt->execute();
$budget_result = $budget_stmt->get_result();
$total_allocated = $budget_result->fetch_assoc()['total_allocated'];
$budget_stmt->close();

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-money-bill-wave"></i> Fund Balance Management
        </h1>
        <p class="page-subtitle">Manage and track barangay fund balance</p>
    </div>
    <div class="header-actions">
        <button class="action-btn budget" onclick="openModal('adjustBalanceModal')">
            <i class="fas fa-exchange-alt"></i> Adjust Balance
        </button>
        <button class="action-btn revenue" onclick="openModal('setBalanceModal')">
            <i class="fas fa-edit"></i> Set Balance
        </button>
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

<!-- Current Balance Card -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-bottom: 2rem;">
    <div class="stat-card balance" style="grid-column: span 2;">
        <div class="stat-icon" style="font-size: 3rem;">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Current Fund Balance</div>
            <div class="stat-value" style="font-size: 2.5rem;">
                ₱<?php echo number_format($current_fund ? $current_fund['current_balance'] : 0, 2); ?>
            </div>
            <div class="stat-meta" style="margin-top: 1rem;">
                <i class="fas fa-clock"></i> 
                Last updated: <?php echo $current_fund ? date('M d, Y g:i A', strtotime($current_fund['last_updated'])) : 'Not set'; ?>
            </div>
            <?php if ($total_allocated > 0): ?>
            <div class="stat-meta" style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.2);">
                <i class="fas fa-chart-pie"></i> 
                ₱<?php echo number_format($total_allocated, 2); ?> allocated in budgets
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Balance History -->
<div class="chart-card">
    <div class="chart-header">
        <h3><i class="fas fa-history"></i> Balance History (Last 50 Transactions)</h3>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Action Type</th>
                    <th>Old Balance</th>
                    <th>New Balance</th>
                    <th>Change</th>
                    <th>Notes</th>
                    <th>Performed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="7" class="text-center">No balance history found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $record): ?>
                        <tr>
                            <td><?php echo date('M d, Y g:i A', strtotime($record['created_at'])); ?></td>
                            <td>
                                <span class="badge-status" style="
                                    background: <?php 
                                        echo strpos($record['action_type'], 'Addition') !== false ? '#d1fae5' : 
                                             (strpos($record['action_type'], 'Deduction') !== false ? '#fee2e2' : '#f3f4f6'); 
                                    ?>;
                                    color: <?php 
                                        echo strpos($record['action_type'], 'Addition') !== false ? '#065f46' : 
                                             (strpos($record['action_type'], 'Deduction') !== false ? '#991b1b' : '#374151'); 
                                    ?>;
                                ">
                                    <?php echo htmlspecialchars($record['action_type']); ?>
                                </span>
                            </td>
                            <td>₱<?php echo number_format($record['old_balance'], 2); ?></td>
                            <td>₱<?php echo number_format($record['new_balance'], 2); ?></td>
                            <td class="<?php echo $record['amount_changed'] >= 0 ? 'revenue-color' : 'expense-color'; ?>">
                                <?php echo $record['amount_changed'] >= 0 ? '+' : ''; ?>₱<?php echo number_format(abs($record['amount_changed']), 2); ?>
                            </td>
                            <td><?php echo htmlspecialchars($record['notes'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($record['performed_by']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Set Balance Modal -->
<div id="setBalanceModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-edit"></i> Set Fund Balance</h2>
            <button class="close-btn" onclick="closeModal('setBalanceModal')">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="set_balance">
            
            <?php if ($current_fund): ?>
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 1rem; margin-bottom: 1.5rem; border-radius: 6px;">
                <div style="display: flex; gap: 0.5rem; align-items: start;">
                    <i class="fas fa-exclamation-triangle" style="color: #856404; margin-top: 0.2rem;"></i>
                    <div style="color: #856404; font-size: 0.9rem;">
                        <strong>Warning:</strong> This will replace the current balance of 
                        <strong>₱<?php echo number_format($current_fund['current_balance'], 2); ?></strong> 
                        with a new value. Use "Adjust Balance" for adding or deducting amounts.
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label class="required">New Balance Amount</label>
                <div class="input-group">
                    <span class="input-prefix">₱</span>
                    <input type="number" name="new_balance" class="form-control" step="0.01" min="0" 
                           placeholder="0.00" value="<?php echo $current_fund ? $current_fund['current_balance'] : ''; ?>" required>
                </div>
                <small style="color: #6b7280; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                    Enter the exact balance amount
                </small>
            </div>
            
            <div class="form-group">
                <label class="required">Notes / Reason</label>
                <textarea name="notes" class="form-control" rows="3" 
                          placeholder="Explain the reason for this balance change..." required></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('setBalanceModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Set Balance
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Balance Modal -->
<div id="adjustBalanceModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-exchange-alt"></i> Adjust Fund Balance</h2>
            <button class="close-btn" onclick="closeModal('adjustBalanceModal')">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="adjust_balance">
            
            <?php if ($current_fund): ?>
            <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 1rem; margin-bottom: 1.5rem; border-radius: 6px;">
                <div style="color: #1e40af; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Current Balance:</strong> ₱<?php echo number_format($current_fund['current_balance'], 2); ?>
                </div>
            </div>
            <?php else: ?>
            <div style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 1rem; margin-bottom: 1.5rem; border-radius: 6px;">
                <div style="color: #991b1b; font-size: 0.9rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>No balance set.</strong> Please set initial balance first.
                </div>
            </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label class="required">Adjustment Type</label>
                <select name="adjustment_type" class="form-control" required <?php echo !$current_fund ? 'disabled' : ''; ?>>
                    <option value="">Select adjustment type</option>
                    <option value="add">Add to Balance</option>
                    <option value="deduct">Deduct from Balance</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="required">Adjustment Amount</label>
                <div class="input-group">
                    <span class="input-prefix">₱</span>
                    <input type="number" name="adjustment_amount" class="form-control" step="0.01" min="0.01" 
                           placeholder="0.00" required <?php echo !$current_fund ? 'disabled' : ''; ?>>
                </div>
            </div>
            
            <div class="form-group">
                <label class="required">Notes / Reason</label>
                <textarea name="notes" class="form-control" rows="3" 
                          placeholder="Explain the reason for this adjustment..." required <?php echo !$current_fund ? 'disabled' : ''; ?>></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('adjustBalanceModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-primary" <?php echo !$current_fund ? 'disabled' : ''; ?>>
                    <i class="fas fa-check"></i> Apply Adjustment
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
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
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
    color: #9ca3af;
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

.btn-primary, .btn-secondary {
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

.btn-primary:hover:not(:disabled) {
    background: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
}

.btn-primary:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
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

.badge-status {
    padding: 0.25rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-block;
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