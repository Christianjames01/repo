<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireAnyRole(['Super Administrator', 'Barangay Treasurer']);

$page_title = 'Stock In';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_stock_in'])) {
    $item_id = intval($_POST['item_id']);
    $quantity = floatval($_POST['quantity']);
    $purpose = sanitizeInput($_POST['purpose']);
    $reference_no = sanitizeInput($_POST['reference_no']);
    $remarks = sanitizeInput($_POST['remarks']);
    
    // Insert transaction
    $sql = "INSERT INTO tbl_stock_transactions (item_id, transaction_type, quantity, purpose, reference_no, remarks, performed_by) 
            VALUES (?, 'Stock In', ?, ?, ?, ?, ?)";
    
    if (executeQuery($conn, $sql, [$item_id, $quantity, $purpose, $reference_no, $remarks, getCurrentUserId()], 'idsssi')) {
        // Update item quantity
        $update_sql = "UPDATE tbl_inventory_items SET quantity = quantity + ? WHERE item_id = ?";
        executeQuery($conn, $update_sql, [$quantity, $item_id], 'di');
        
        logActivity($conn, getCurrentUserId(), 'Stock in transaction', 'tbl_stock_transactions', $conn->insert_id);
        redirect('stock-in.php', 'Stock in recorded successfully', 'success');
    } else {
        setErrorMessage('Failed to record stock in');
    }
}

// Get items for dropdown
$items = fetchAll($conn, "SELECT * FROM tbl_inventory_items WHERE is_active = 1 ORDER BY item_name");

// Get recent stock in transactions
$sql = "SELECT st.*, i.item_name, i.unit, u.username 
        FROM tbl_stock_transactions st
        LEFT JOIN tbl_inventory_items i ON st.item_id = i.item_id
        LEFT JOIN tbl_users u ON st.performed_by = u.user_id
        WHERE st.transaction_type = 'Stock In'
        ORDER BY st.transaction_date DESC
        LIMIT 50";
$transactions = fetchAll($conn, $sql);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Record Stock In</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <?php echo getCSRFField(); ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Item <span class="text-danger">*</span></label>
                            <select name="item_id" class="form-select select2" required id="itemSelect">
                                <option value="">Select Item</option>
                                <?php foreach ($items as $item): ?>
                                <option value="<?php echo $item['item_id']; ?>" 
                                        data-unit="<?php echo htmlspecialchars($item['unit']); ?>"
                                        data-current="<?php echo $item['quantity']; ?>">
                                    <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                            <small class="text-muted">Current stock: <span id="currentStock">-</span></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Purpose <span class="text-danger">*</span></label>
                            <select name="purpose" class="form-select" required>
                                <option value="">Select Purpose</option>
                                <option value="Purchase">Purchase</option>
                                <option value="Donation">Donation</option>
                                <option value="Return">Return</option>
                                <option value="Transfer">Transfer</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reference No.</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="PO/DR Number">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                        </div>

                        <div class="d-grid">
                            <button type="submit" name="record_stock_in" class="btn btn-success">
                                <i class="fas fa-save me-2"></i>Record Stock In
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Recent Stock In Transactions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Purpose</th>
                                    <th>Reference</th>
                                    <th>Performed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No transactions yet</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $trans): ?>
                                    <tr>
                                        <td><?php echo formatDate($trans['transaction_date'], 'M d, Y h:i A'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($trans['item_name']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">
                                                +<?php echo number_format($trans['quantity'], 2); ?> <?php echo $trans['unit']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($trans['purpose']); ?></td>
                                        <td><?php echo htmlspecialchars($trans['reference_no'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($trans['username']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('itemSelect').addEventListener('change', function() {
    var option = this.options[this.selectedIndex];
    var current = option.getAttribute('data-current');
    var unit = option.getAttribute('data-unit');
    
    if (current) {
        document.getElementById('currentStock').textContent = current + ' ' + unit;
    } else {
        document.getElementById('currentStock').textContent = '-';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>