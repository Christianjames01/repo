<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Add Revenue';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $source = isset($_POST['source']) ? trim($_POST['source']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $transaction_date = isset($_POST['transaction_date']) ? $_POST['transaction_date'] : '';
    $receipt_number = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : '';
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'Cash';
    
    // Validation
    if ($category_id <= 0) {
        $errors[] = "Please select a revenue category";
    }
    if ($amount <= 0) {
        $errors[] = "Amount must be greater than zero";
    }
    if (empty($source)) {
        $errors[] = "Revenue source is required";
    }
    if (empty($transaction_date)) {
        $errors[] = "Transaction date is required";
    }
    
    if (empty($errors)) {
        // Generate reference number
        $year = date('Y');
        $month = date('m');
        $ref_prefix = "REV-{$year}{$month}-";
        
        // Get last reference number
        $last_ref_stmt = $conn->prepare("SELECT reference_number FROM tbl_revenues WHERE reference_number LIKE ? ORDER BY revenue_id DESC LIMIT 1");
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
        
        // Insert revenue
        $stmt = $conn->prepare("INSERT INTO tbl_revenues (reference_number, category_id, amount, source, description, transaction_date, receipt_number, payment_method, received_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
        $stmt->bind_param("sidsssssi", $reference_number, $category_id, $amount, $source, $description, $transaction_date, $receipt_number, $payment_method, $current_user_id);
        
        if ($stmt->execute()) {
            $success = true;
            $_SESSION['success_message'] = "Revenue added successfully! Reference: {$reference_number}";
            header('Location: revenues.php');
            exit();
        } else {
            $errors[] = "Error adding revenue: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Get revenue categories
$categories = fetchAll($conn, "SELECT * FROM tbl_revenue_categories WHERE is_active = 1 ORDER BY category_name");

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-plus-circle"></i> Add Revenue
        </h1>
        <p class="page-subtitle">Record new barangay revenue</p>
    </div>
    <div class="header-actions">
        <a href="revenues.php" class="action-btn report">
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
    <form method="POST" class="revenue-form">
        <div class="form-grid">
            <div class="form-group">
                <label class="required">Revenue Category</label>
                <select name="category_id" class="form-control" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-hint">Select the type of revenue</small>
            </div>

            <div class="form-group">
                <label class="required">Amount (₱)</label>
                <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" value="<?php echo isset($_POST['amount']) ? $_POST['amount'] : ''; ?>" required>
                <small class="form-hint">Enter the revenue amount</small>
            </div>

            <div class="form-group">
                <label class="required">Transaction Date</label>
                <input type="date" name="transaction_date" class="form-control" value="<?php echo isset($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                <small class="form-hint">Date when revenue was received</small>
            </div>

            <div class="form-group">
                <label class="required">Payment Method</label>
                <select name="payment_method" class="form-control" required>
                    <option value="Cash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                    <option value="Check" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Check') ? 'selected' : ''; ?>>Check</option>
                    <option value="Bank Transfer" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="GCash" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'GCash') ? 'selected' : ''; ?>>GCash</option>
                    <option value="PayMaya" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'PayMaya') ? 'selected' : ''; ?>>PayMaya</option>
                    <option value="Other" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
                <small class="form-hint">How the payment was received</small>
            </div>

            <div class="form-group full-width">
                <label class="required">Revenue Source</label>
                <input type="text" name="source" class="form-control" placeholder="e.g., Juan Dela Cruz, ABC Corporation" value="<?php echo isset($_POST['source']) ? htmlspecialchars($_POST['source']) : ''; ?>" required>
                <small class="form-hint">Name of the person or entity</small>
            </div>

            <div class="form-group full-width">
                <label>Receipt Number</label>
                <input type="text" name="receipt_number" class="form-control" placeholder="Optional receipt/OR number" value="<?php echo isset($_POST['receipt_number']) ? htmlspecialchars($_POST['receipt_number']) : ''; ?>">
                <small class="form-hint">Official receipt number (if applicable)</small>
            </div>

            <div class="form-group full-width">
                <label>Description/Notes</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Additional details about this revenue..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                <small class="form-hint">Any additional information</small>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="action-btn revenue">
                <i class="fas fa-save"></i> Save Revenue
            </button>
            <a href="revenues.php" class="action-btn report">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
</div>

<style>
.revenue-form {
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

.alert-danger i {
    margin-top: 0.25rem;
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

<?php include '../../includes/footer.php'; ?>