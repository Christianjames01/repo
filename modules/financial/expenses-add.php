<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Add Expense';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$current_year = date('Y');
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $allocation_id = isset($_POST['allocation_id']) && intval($_POST['allocation_id']) > 0 ? intval($_POST['allocation_id']) : null;
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $payee = isset($_POST['payee']) ? trim($_POST['payee']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $expense_date = isset($_POST['expense_date']) ? $_POST['expense_date'] : '';
    $invoice_number = isset($_POST['invoice_number']) ? trim($_POST['invoice_number']) : '';
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'Cash';
    $check_number = isset($_POST['check_number']) ? trim($_POST['check_number']) : '';
    
    // Validation
    if ($category_id <= 0) {
        $errors[] = "Please select an expense category";
    }
    if ($amount <= 0) {
        $errors[] = "Amount must be greater than zero";
    }
    if (empty($payee)) {
        $errors[] = "Payee is required";
    }
    if (empty($description)) {
        $errors[] = "Description is required";
    }
    if (empty($expense_date)) {
        $errors[] = "Expense date is required";
    }
    
    // Check budget if allocation is selected (only Super Admin can select allocations)
    if ($allocation_id && $amount > 0 && $user_role === 'Super Admin') {
        $budget_check = fetchOne($conn, "SELECT remaining_amount FROM tbl_budget_allocations WHERE allocation_id = ? AND status = 'Approved'", [$allocation_id], 'i');
        if ($budget_check && $amount > $budget_check['remaining_amount']) {
            $errors[] = "Amount exceeds budget remaining (₱" . number_format($budget_check['remaining_amount'], 2) . ")";
        }
    }
    
    if (empty($errors)) {
        // Generate reference number
        $year = date('Y');
        $month = date('m');
        $ref_prefix = "EXP-{$year}{$month}-";
        
        $last_ref_stmt = $conn->prepare("SELECT reference_number FROM tbl_expenses WHERE reference_number LIKE ? ORDER BY expense_id DESC LIMIT 1");
        $search_ref = $ref_prefix . '%';
        $last_ref_stmt->bind_param("s", $search_ref);
        $last_ref_stmt->execute();
        $last_ref_result = $last_ref_stmt->get_result();
        
        if ($last_ref_result->num_rows > 0) {
            $last_ref = $last_ref_result->fetch_assoc()['reference_number'];
            $last_num = intval(substr($last_ref, -4));
            $new_num = $last_num + 1;
        } else {
            $new_num = 1;
        }
        $last_ref_stmt->close();
        
        $reference_number = $ref_prefix . str_pad($new_num, 4, '0', STR_PAD_LEFT);
        
        // Insert expense
        if ($allocation_id && $user_role === 'Super Admin') {
            $stmt = $conn->prepare("INSERT INTO tbl_expenses (reference_number, category_id, allocation_id, amount, payee, description, expense_date, invoice_number, payment_method, check_number, requested_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("siidssssssi", $reference_number, $category_id, $allocation_id, $amount, $payee, $description, $expense_date, $invoice_number, $payment_method, $check_number, $current_user_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO tbl_expenses (reference_number, category_id, amount, payee, description, expense_date, invoice_number, payment_method, check_number, requested_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("sidssssssi", $reference_number, $category_id, $amount, $payee, $description, $expense_date, $invoice_number, $payment_method, $check_number, $current_user_id);
        }
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Expense added successfully! Reference: {$reference_number}";
            header('Location: expenses.php');
            exit();
        } else {
            $errors[] = "Error adding expense: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Get expense categories
$categories = fetchAll($conn, "SELECT * FROM tbl_expense_categories WHERE is_active = 1 ORDER BY category_name");

// Get budget allocations for current year - ONLY APPROVED BUDGETS and ONLY FOR SUPER ADMIN
$allocations = [];
if ($user_role === 'Super Admin') {
    $allocations = fetchAll($conn, "
        SELECT ba.*, ec.category_name 
        FROM tbl_budget_allocations ba
        JOIN tbl_expense_categories ec ON ba.category_id = ec.category_id
        WHERE ba.fiscal_year = ? AND ba.status = 'Approved' AND ba.remaining_amount > 0
        ORDER BY ec.category_name
    ", [$current_year], 'i');
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-minus-circle"></i> Add Expense
        </h1>
        <p class="page-subtitle">Record new barangay expense</p>
    </div>
    <div class="header-actions">
        <a href="expenses.php" class="action-btn report">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <ul style="margin: 0; padding-left: 1.5rem;">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="chart-card">
    <form method="POST" class="expense-form">
        <div class="form-grid">
            <div class="form-group">
                <label class="required">Expense Category</label>
                <select name="category_id" id="category_id" class="form-control" required onchange="filterBudgets()">
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-hint">Select the type of expense</small>
            </div>

            <?php if ($user_role === 'Super Admin' && !empty($allocations)): ?>
            <div class="form-group">
                <label>Budget Allocation (Optional)</label>
                <select name="allocation_id" id="allocation_id" class="form-control" onchange="updateBudgetInfo()">
                    <option value="0">No budget allocation</option>
                    <?php foreach ($allocations as $alloc): ?>
                        <option value="<?php echo $alloc['allocation_id']; ?>" 
                                data-category="<?php echo $alloc['category_id']; ?>"
                                data-remaining="<?php echo $alloc['remaining_amount']; ?>"
                                <?php echo (isset($_POST['allocation_id']) && $_POST['allocation_id'] == $alloc['allocation_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($alloc['category_name']); ?> - 
                            ₱<?php echo number_format($alloc['remaining_amount'], 2); ?> remaining
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-hint" id="budget-hint">Charge to approved budget (optional)</small>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="required">Amount (₱)</label>
                <input type="number" name="amount" id="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" value="<?php echo isset($_POST['amount']) ? $_POST['amount'] : ''; ?>" required>
                <small class="form-hint">Enter the expense amount</small>
            </div>

            <div class="form-group">
                <label class="required">Expense Date</label>
                <input type="date" name="expense_date" class="form-control" value="<?php echo isset($_POST['expense_date']) ? $_POST['expense_date'] : date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                <small class="form-hint">Date of expense</small>
            </div>

            <div class="form-group full-width">
                <label class="required">Payee</label>
                <input type="text" name="payee" class="form-control" placeholder="e.g., XYZ Hardware, Juan Dela Cruz" value="<?php echo isset($_POST['payee']) ? htmlspecialchars($_POST['payee']) : ''; ?>" required>
                <small class="form-hint">Name of person/company to be paid</small>
            </div>

            <div class="form-group">
                <label class="required">Payment Method</label>
                <select name="payment_method" id="payment_method" class="form-control" required onchange="toggleCheckNumber()">
                    <option value="Cash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                    <option value="Check" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Check') ? 'selected' : ''; ?>>Check</option>
                    <option value="Bank Transfer" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="GCash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'GCash') ? 'selected' : ''; ?>>GCash</option>
                    <option value="PayMaya" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'PayMaya') ? 'selected' : ''; ?>>PayMaya</option>
                    <option value="Other" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
                <small class="form-hint">How payment will be made</small>
            </div>

            <div class="form-group" id="check-number-group" style="display: none;">
                <label>Check Number</label>
                <input type="text" name="check_number" class="form-control" placeholder="Check number" value="<?php echo isset($_POST['check_number']) ? htmlspecialchars($_POST['check_number']) : ''; ?>">
                <small class="form-hint">Required for check payments</small>
            </div>

            <div class="form-group">
                <label>Invoice/Bill Number</label>
                <input type="text" name="invoice_number" class="form-control" placeholder="Optional invoice number" value="<?php echo isset($_POST['invoice_number']) ? htmlspecialchars($_POST['invoice_number']) : ''; ?>">
                <small class="form-hint">Invoice or billing reference</small>
            </div>

            <div class="form-group full-width">
                <label class="required">Description/Purpose</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Describe the expense purpose..." required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                <small class="form-hint">Detailed description of the expense</small>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="action-btn expense">
                <i class="fas fa-save"></i> Save Expense
            </button>
            <a href="expenses.php" class="action-btn report">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
</div>

<style>
.expense-form {
    padding: 1.5rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
}

.form-group label.required::after {
    content: ' *';
    color: #ef4444;
}

.form-control {
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-hint {
    color: #6b7280;
    font-size: 0.85rem;
    margin-top: 0.375rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1.5rem;
    border-top: 2px solid #f3f4f6;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    gap: 1rem;
    align-items: flex-start;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .action-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
function toggleCheckNumber() {
    const paymentMethod = document.getElementById('payment_method').value;
    const checkGroup = document.getElementById('check-number-group');
    
    if (paymentMethod === 'Check') {
        checkGroup.style.display = 'flex';
    } else {
        checkGroup.style.display = 'none';
    }
}

function filterBudgets() {
    <?php if ($user_role === 'Super Admin' && !empty($allocations)): ?>
    const categoryId = document.getElementById('category_id').value;
    const allocationSelect = document.getElementById('allocation_id');
    const options = allocationSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '0') {
            option.style.display = 'block';
            return;
        }
        
        const optionCategory = option.getAttribute('data-category');
        if (!categoryId || optionCategory === categoryId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Reset selection if hidden
    const selectedOption = allocationSelect.options[allocationSelect.selectedIndex];
    if (selectedOption && selectedOption.style.display === 'none') {
        allocationSelect.value = '0';
        updateBudgetInfo();
    }
    <?php endif; ?>
}

function updateBudgetInfo() {
    <?php if ($user_role === 'Super Admin' && !empty($allocations)): ?>
    const allocationSelect = document.getElementById('allocation_id');
    const selectedOption = allocationSelect.options[allocationSelect.selectedIndex];
    const budgetHint = document.getElementById('budget-hint');
    
    if (selectedOption.value !== '0') {
        const remaining = selectedOption.getAttribute('data-remaining');
        budgetHint.innerHTML = `Budget remaining: <strong style="color: #10b981;">₱${parseFloat(remaining).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong>`;
        budgetHint.style.color = '#059669';
    } else {
        budgetHint.innerHTML = 'Charge to approved budget (optional)';
        budgetHint.style.color = '#6b7280';
    }
    <?php endif; ?>
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleCheckNumber();
    filterBudgets();
    updateBudgetInfo();
});
</script>

<?php include '../../includes/footer.php'; ?>