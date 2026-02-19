<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Relief Inventory Management';
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_item') {
        $item_name = sanitizeInput($_POST['item_name']);
        $category = $_POST['item_category'];
        $unit = sanitizeInput($_POST['unit_of_measure']);
        $min_stock = intval($_POST['minimum_stock']);
        
        // Check if item name already exists
        $check_existing = fetchOne($conn, "SELECT item_id FROM tbl_relief_items WHERE LOWER(item_name) = LOWER(?)", [$item_name], 's');
        
        if ($check_existing) {
            $error_message = "Item '{$item_name}' already exists in the inventory!";
        } else {
            $sql = "INSERT INTO tbl_relief_items (item_name, item_category, unit_of_measure, minimum_stock) 
                    VALUES (?, ?, ?, ?)";
            if (executeQuery($conn, $sql, [$item_name, $category, $unit, $min_stock], 'sssi')) {
                $item_id = getLastInsertId($conn);
                // Initialize inventory with 0 quantity (only if not exists)
                $check_inv = fetchOne($conn, "SELECT inventory_id FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
                if (!$check_inv) {
                    executeQuery($conn, "INSERT INTO tbl_relief_inventory (item_id, quantity) VALUES (?, 0)", [$item_id], 'i');
                }
                $success_message = "Relief item added successfully!";
            } else {
                $error_message = "Failed to add item.";
            }
        }
    }
    
    elseif ($action === 'delete_item') {
        $item_id = intval($_POST['item_id']);
        
        // Check if there are any transactions or inventory records
        $check_inventory = fetchOne($conn, 
            "SELECT COALESCE(SUM(quantity), 0) as total_quantity FROM tbl_relief_inventory WHERE item_id = ?", 
            [$item_id], 'i');
        
        $check_transactions = fetchOne($conn, 
            "SELECT COUNT(*) as count FROM tbl_relief_transactions WHERE item_id = ?", 
            [$item_id], 'i');
        
        if ($check_inventory && $check_inventory['total_quantity'] > 0) {
            $error_message = "Cannot delete item with existing stock. Please distribute or remove all stock first.";
        } elseif ($check_transactions && $check_transactions['count'] > 0) {
            $error_message = "Cannot delete item with transaction history. This item has been used in distributions.";
        } else {
            // Delete inventory records first
            executeQuery($conn, "DELETE FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
            
            // Delete the item
            $sql = "DELETE FROM tbl_relief_items WHERE item_id = ?";
            if (executeQuery($conn, $sql, [$item_id], 'i')) {
                logActivity($conn, getCurrentUserId(), "Deleted relief item ID: $item_id");
                $success_message = "Relief item deleted successfully!";
            } else {
                $error_message = "Failed to delete item.";
            }
        }
    }
    
    elseif ($action === 'update_item') {
        $item_id = intval($_POST['item_id']);
        $item_name = sanitizeInput($_POST['item_name']);
        $category = $_POST['item_category'];
        $unit = sanitizeInput($_POST['unit_of_measure']);
        $min_stock = intval($_POST['minimum_stock']);
        
        // Check if item name already exists (excluding current item)
        $check_existing = fetchOne($conn, 
            "SELECT item_id FROM tbl_relief_items WHERE LOWER(item_name) = LOWER(?) AND item_id != ?", 
            [$item_name, $item_id], 'si');
        
        if ($check_existing) {
            $error_message = "Item name '{$item_name}' already exists!";
        } else {
            $sql = "UPDATE tbl_relief_items 
                    SET item_name = ?, item_category = ?, unit_of_measure = ?, minimum_stock = ?
                    WHERE item_id = ?";
            if (executeQuery($conn, $sql, [$item_name, $category, $unit, $min_stock, $item_id], 'sssii')) {
                logActivity($conn, getCurrentUserId(), "Updated relief item ID: $item_id");
                $success_message = "Relief item updated successfully!";
            } else {
                $error_message = "Failed to update item.";
            }
        }
    }
    
    elseif ($action === 'add_stock') {
        $item_id = intval($_POST['item_id']);
        $quantity = floatval($_POST['quantity']);
        $batch_number = sanitizeInput($_POST['batch_number'] ?? '');
        $expiry_date = $_POST['expiry_date'] ?? null;
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        $user_id = $_SESSION['user_id'];
        
        // Check if inventory record exists, if not create it
        $check_inv = fetchOne($conn, "SELECT inventory_id FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
        if (!$check_inv) {
            executeQuery($conn, "INSERT INTO tbl_relief_inventory (item_id, quantity) VALUES (?, 0)", [$item_id], 'i');
        }
        
        // Update inventory
        $sql = "UPDATE tbl_relief_inventory SET quantity = quantity + ? WHERE item_id = ?";
        if (executeQuery($conn, $sql, [$quantity, $item_id], 'di')) {
            // Record transaction
            $sql = "INSERT INTO tbl_relief_transactions (item_id, transaction_type, quantity, reference_type, remarks, performed_by) 
                    VALUES (?, 'In', ?, 'Donation/Purchase', ?, ?)";
            executeQuery($conn, $sql, [$item_id, $quantity, $remarks, $user_id], 'idsi');
            
            $success_message = "Stock added successfully!";
        } else {
            $error_message = "Failed to add stock.";
        }
    }
    elseif ($action === 'distribute_relief') {
        $distribution_date = $_POST['distribution_date'];
        $location = sanitizeInput($_POST['location']);
        $total_beneficiaries = intval($_POST['total_beneficiaries']);
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        $user_id = $_SESSION['user_id'];
        $items = $_POST['items'] ?? [];
        
        // Validate stock availability before processing
        $stock_errors = [];
        foreach ($items as $item_data) {
            if (empty($item_data['item_id']) || empty($item_data['quantity'])) continue;
            
            $item_id = intval($item_data['item_id']);
            $quantity = floatval($item_data['quantity']);
            
            // Check current stock
            $stock_check = fetchOne($conn, 
                "SELECT ri.item_name, COALESCE(SUM(inv.quantity), 0) as available_stock, ri.unit_of_measure
                 FROM tbl_relief_items ri
                 LEFT JOIN tbl_relief_inventory inv ON ri.item_id = inv.item_id
                 WHERE ri.item_id = ?
                 GROUP BY ri.item_id", 
                [$item_id], 'i');
            
            if ($stock_check) {
                $available = floatval($stock_check['available_stock']);
                if ($quantity > $available) {
                    $stock_errors[] = "{$stock_check['item_name']}: Requested {$quantity} {$stock_check['unit_of_measure']}, but only {$available} {$stock_check['unit_of_measure']} available in stock.";
                }
            }
        }
        
        // If there are stock errors, don't process the distribution
        if (!empty($stock_errors)) {
            $error_message = "Cannot complete distribution due to insufficient stock:\n" . implode("\n", $stock_errors);
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Create distribution record
                $sql = "INSERT INTO tbl_relief_distributions (distribution_date, location, total_beneficiaries, distributed_by, status, remarks) 
                        VALUES (?, ?, ?, ?, 'Completed', ?)";
                executeQuery($conn, $sql, [$distribution_date, $location, $total_beneficiaries, $user_id, $remarks], 'ssiis');
                $distribution_id = getLastInsertId($conn);
                
                // Process each item
                foreach ($items as $item_data) {
                    if (empty($item_data['item_id']) || empty($item_data['quantity'])) continue;
                    
                    $item_id = intval($item_data['item_id']);
                    $quantity = floatval($item_data['quantity']);
                    
                    // Deduct from inventory
                    $sql = "UPDATE tbl_relief_inventory SET quantity = quantity - ? WHERE item_id = ?";
                    executeQuery($conn, $sql, [$quantity, $item_id], 'di');
                    
                    // Record distribution item
                    $sql = "INSERT INTO tbl_relief_distribution_items (distribution_id, item_id, quantity_distributed) 
                            VALUES (?, ?, ?)";
                    executeQuery($conn, $sql, [$distribution_id, $item_id, $quantity], 'iid');
                    
                    // Record transaction
                    $sql = "INSERT INTO tbl_relief_transactions (item_id, transaction_type, quantity, reference_type, reference_id, performed_by) 
                            VALUES (?, 'Out', ?, 'Distribution', ?, ?)";
                    executeQuery($conn, $sql, [$item_id, $quantity, $distribution_id, $user_id], 'idii');
                }
                
                $conn->commit();
                $success_message = "Relief distribution completed successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Failed to process distribution: " . $e->getMessage();
            }
        }
    }
    
    // Redirect to clear POST
    if ($success_message || $error_message) {
        $_SESSION['temp_success'] = $success_message;
        $_SESSION['temp_error'] = $error_message;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get messages from session
if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

// Fetch evacuation centers
$centers_sql = "SELECT center_id, center_name, location, status 
                FROM tbl_evacuation_centers 
                WHERE status = 'Active' 
                ORDER BY center_name";
$evacuation_centers = fetchAll($conn, $centers_sql);

// Fetch inventory data with low stock alerts (GROUP BY to prevent duplicates)
$inventory_sql = "SELECT ri.*, 
                  COALESCE(SUM(inv.quantity), 0) as quantity, 
                  MAX(inv.location) as location, 
                  MAX(inv.expiry_date) as expiry_date,
                  CASE WHEN COALESCE(SUM(inv.quantity), 0) <= ri.minimum_stock THEN 1 ELSE 0 END as is_low_stock
                  FROM tbl_relief_items ri
                  LEFT JOIN tbl_relief_inventory inv ON ri.item_id = inv.item_id
                  GROUP BY ri.item_id
                  ORDER BY is_low_stock DESC, ri.item_category, ri.item_name";
$inventory = fetchAll($conn, $inventory_sql);

// Fetch recent transactions
$transactions_sql = "SELECT rt.*, ri.item_name, ri.unit_of_measure, u.username
                     FROM tbl_relief_transactions rt
                     JOIN tbl_relief_items ri ON rt.item_id = ri.item_id
                     JOIN tbl_users u ON rt.performed_by = u.user_id
                     ORDER BY rt.transaction_date DESC
                     LIMIT 10";
$recent_transactions = fetchAll($conn, $transactions_sql);

// Calculate statistics
$stats = [
    'total_items' => count($inventory),
    'distributions_this_month' => 0,
    'total_beneficiaries_this_month' => 0
];

// Check if total_beneficiaries column exists
$column_check = $conn->query("SHOW COLUMNS FROM tbl_relief_distributions LIKE 'total_beneficiaries'");
$has_beneficiaries_column = $column_check && $column_check->num_rows > 0;

if ($has_beneficiaries_column) {
    $distributions_sql = "SELECT COUNT(*) as count, COALESCE(SUM(total_beneficiaries), 0) as beneficiaries 
                          FROM tbl_relief_distributions 
                          WHERE MONTH(distribution_date) = MONTH(CURRENT_DATE()) 
                          AND YEAR(distribution_date) = YEAR(CURRENT_DATE())";
    $dist_result = fetchOne($conn, $distributions_sql);
    $stats['distributions_this_month'] = $dist_result['count'] ?? 0;
    $stats['total_beneficiaries_this_month'] = $dist_result['beneficiaries'] ?? 0;
} else {
    $distributions_sql = "SELECT COUNT(*) as count 
                          FROM tbl_relief_distributions 
                          WHERE MONTH(distribution_date) = MONTH(CURRENT_DATE()) 
                          AND YEAR(distribution_date) = YEAR(CURRENT_DATE())";
    $dist_result = fetchOne($conn, $distributions_sql);
    $stats['distributions_this_month'] = $dist_result['count'] ?? 0;
}

include '../../includes/header.php';
?>

<style>
.inventory-header {
    background: #f8f9fa;
    border-left: 4px solid #495057;
    color: #212529;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.inventory-header h1 {
    color: #212529;
    margin: 0 0 0.5rem 0;
}

.inventory-header p {
    color: #6c757d;
    margin: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.stat-card .stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #212529;
}

.stat-card .stat-label {
    color: #6c757d;
    font-size: 0.9rem;
}

.inventory-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.low-stock-alert {
    background: #fff9e6;
    border-left: 3px solid #ffc107;
}

.btn-action {
    padding: 0.5rem 1rem;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    background: white;
    color: #495057;
}

.btn-action:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

.btn-primary {
    background: #495057;
    color: white;
    border-color: #495057;
}

.btn-primary:hover {
    background: #343a40;
    border-color: #343a40;
}

.btn-success {
    background: #28a745;
    color: white;
    border-color: #28a745;
}

.btn-success:hover {
    background: #218838;
    border-color: #218838;
}

.btn-warning {
    background: #ffc107;
    color: #212529;
    border-color: #ffc107;
}

.btn-warning:hover {
    background: #e0a800;
    border-color: #e0a800;
}

.btn-danger {
    background: #dc3545;
    color: white;
    border-color: #dc3545;
}

.btn-danger:hover:not(:disabled) {
    background: #c82333;
    border-color: #bd2130;
}

.btn-danger:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.4);
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid #dee2e6;
}

.modal-content h2 {
    color: #212529;
    margin-top: 0;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #495057;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 6px;
}

.form-control:focus {
    outline: none;
    border-color: #495057;
}

.items-repeater {
    border: 1px solid #dee2e6;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.item-row {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 1rem;
    margin-bottom: 0.5rem;
    align-items: end;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    padding: 0.75rem;
    text-align: left;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    padding: 0.75rem;
    border-bottom: 1px solid #e9ecef;
    color: #212529;
}

.table tr:hover {
    background: #f8f9fa;
}

.badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}

.bg-info {
    background: #e7f1ff;
    color: #004085;
}

.bg-warning {
    background: #fff3cd;
    color: #856404;
}

.bg-success {
    background: #d4edda;
    color: #155724;
}

.bg-danger {
    background: #f8d7da;
    color: #721c24;
}

.alert {
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    border-left: 4px solid;
}

.alert-success {
    background: #d4edda;
    border-color: #28a745;
    color: #155724;
}

.alert-danger {
    background: #f8d7da;
    border-color: #dc3545;
    color: #721c24;
}

.alert-danger ul {
    margin: 0.5rem 0 0 0;
    padding-left: 1.5rem;
}

.stock-error {
    border-color: #dc3545 !important;
    background-color: #fff5f5 !important;
}

.stock-warning {
    color: #dc3545;
    font-size: 0.85rem;
    margin-top: 0.25rem;
    display: none;
}

.stock-warning.show {
    display: block;
}

.location-options {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.location-options button {
    padding: 0.5rem 1rem;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    background: white;
    cursor: pointer;
    transition: all 0.2s;
}

.location-options button.active {
    background: #495057;
    color: white;
    border-color: #495057;
}

.location-options button:hover:not(.active) {
    background: #f8f9fa;
}

#locationInput {
    display: block;
}

#centerSelect {
    display: none;
}

.location-input-group {
    position: relative;
}
#deleteDistributionItemModal .modal-content,
#cannotRemoveItemModal .modal-content {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

#deleteDistributionItemModal h2,
#cannotRemoveItemModal h2 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

#deleteDistributionItemModal .alert-warning {
    border-left: 4px solid #ffc107;
}

#cannotRemoveItemModal .alert-danger {
    border-left: 4px solid #dc3545;
}
</style>

<div class="inventory-header">
    <h1><i class="fas fa-boxes"></i> Relief Inventory Management</h1>
    <p>Track and manage disaster relief supplies</p>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-danger">
    <?php 
    // Check if error message contains newlines (multiple errors)
    if (strpos($error_message, "\n") !== false) {
        $errors = explode("\n", $error_message);
        echo "<strong>" . htmlspecialchars($errors[0]) . "</strong>";
        if (count($errors) > 1) {
            echo "<ul>";
            for ($i = 1; $i < count($errors); $i++) {
                if (!empty(trim($errors[$i]))) {
                    echo "<li>" . htmlspecialchars($errors[$i]) . "</li>";
                }
            }
            echo "</ul>";
        }
    } else {
        echo htmlspecialchars($error_message);
    }
    ?>
</div>
<?php endif; ?>

<!-- Statistics Dashboard -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_items']; ?></div>
        <div class="stat-label"><i class="fas fa-box"></i> Total Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['distributions_this_month']; ?></div>
        <div class="stat-label"><i class="fas fa-hands-helping"></i> Distributions This Month</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_beneficiaries_this_month']); ?></div>
        <div class="stat-label"><i class="fas fa-users"></i> Beneficiaries This Month</div>
    </div>
</div>

<!-- Action Buttons -->
<div style="margin-bottom: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
    <?php if ($user_role === 'Super Admin'): ?>
        <button class="btn-action btn-primary" onclick="openModal('addItemModal')">
            <i class="fas fa-plus"></i> Add New Item
        </button>
    <?php endif; ?>
    <button class="btn-action btn-warning" onclick="openModal('distributeModal')">
        <i class="fas fa-hand-holding-heart"></i> Distribute Relief
    </button>
    <a href="distribution-report.php" class="btn-action btn-primary" style="text-decoration: none; display: inline-flex; align-items: center;">
        <i class="fas fa-chart-bar"></i> View Reports
    </a>
</div>

<!-- Inventory Table -->
<div class="inventory-table">
    <table class="table">
        <thead>
            <tr>
                <th>Item Name</th>
                <th>Category</th>
                <th>Current Stock</th>
                <th>Unit</th>
                <th>Location</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $item): ?>
            <tr class="<?php echo $item['is_low_stock'] ? 'low-stock-alert' : ''; ?>">
                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                <td><span class="badge bg-info"><?php echo $item['item_category']; ?></span></td>
                <td><strong><?php echo number_format($item['quantity'] ?? 0, 2); ?></strong></td>
                <td><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                <td><?php echo htmlspecialchars($item['location'] ?? 'Main Warehouse'); ?></td>
                <td>
                    <?php if ($user_role === 'Super Admin' || $user_role === 'Staff'): ?>
                        <button class="btn-action btn-success btn-sm" onclick='addStockToItem(<?php echo json_encode($item); ?>)'>
                            <i class="fas fa-plus"></i> Add Stock
                        </button>
                        <button class="btn-action btn-warning btn-sm" onclick='editItem(<?php echo json_encode($item); ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-action btn-danger btn-sm" onclick='deleteItem(<?php echo json_encode($item); ?>)'>
                            <i class="fas fa-trash"></i>
                        </button>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Recent Transactions -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-history"></i> Recent Transactions</h3>
    <div class="inventory-table">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Performed By</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_transactions as $trans): ?>
                <tr>
                    <td><?php echo formatDateTime($trans['transaction_date']); ?></td>
                    <td><?php echo htmlspecialchars($trans['item_name']); ?></td>
                    <td>
                        <?php 
                        $badge_color = $trans['transaction_type'] === 'In' ? 'success' : 'danger';
                        echo "<span class='badge bg-{$badge_color}'>{$trans['transaction_type']}</span>";
                        ?>
                    </td>
                    <td><?php echo number_format($trans['quantity'], 2) . ' ' . $trans['unit_of_measure']; ?></td>
                    <td><?php echo htmlspecialchars($trans['username']); ?></td>
                    <td><?php echo htmlspecialchars($trans['remarks'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="modal">
    <div class="modal-content">
        <h2>Add New Relief Item</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_item">
            <div class="form-group">
                <label>Item Name *</label>
                <input type="text" name="item_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="item_category" class="form-control" required>
                    <option value="Food">Food</option>
                    <option value="Water">Water</option>
                    <option value="Medicine">Medicine</option>
                    <option value="Clothing">Clothing</option>
                    <option value="Hygiene">Hygiene</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Unit of Measure *</label>
                <input type="text" name="unit_of_measure" class="form-control" placeholder="kg, liters, pieces, etc." required>
            </div>
            <div class="form-group">
                <label>Minimum Stock Level *</label>
                <input type="number" name="minimum_stock" class="form-control" value="0" required>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-action btn-primary">Save Item</button>
                <button type="button" class="btn-action" onclick="closeModal('addItemModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Stock Modal -->
<div id="addStockModal" class="modal">
    <div class="modal-content">
        <h2>Add Stock</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_stock">
            <input type="hidden" name="item_id" id="stock_item_id">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" id="stock_item_name" class="form-control" readonly>
            </div>
            <div class="form-group">
                <label>Current Stock</label>
                <input type="text" id="stock_current" class="form-control" readonly>
            </div>
            <div class="form-group">
                <label>Quantity to Add *</label>
                <input type="number" step="0.01" name="quantity" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Batch Number</label>
                <input type="text" name="batch_number" class="form-control">
            </div>
            <div class="form-group" id="expiryDateGroup" style="display: none;">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date" id="expiryDateInput" class="form-control">
                <small class="text-muted">Only applicable for perishable items (Food, Water, Medicine)</small>
            </div>
            <div class="form-group">
                <label>Remarks</label>
                <textarea name="remarks" class="form-control" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-action btn-success">Add Stock</button>
                <button type="button" class="btn-action" onclick="closeModal('addStockModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editItemModal" class="modal">
    <div class="modal-content">
        <h2>Edit Relief Item</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" id="edit_item_id">
            <div class="form-group">
                <label>Item Name *</label>
                <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="item_category" id="edit_item_category" class="form-control" required>
                    <option value="Food">Food</option>
                    <option value="Water">Water</option>
                    <option value="Medicine">Medicine</option>
                    <option value="Clothing">Clothing</option>
                    <option value="Hygiene">Hygiene</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Unit of Measure *</label>
                <input type="text" name="unit_of_measure" id="edit_unit_of_measure" class="form-control" placeholder="kg, liters, pieces, etc." required>
            </div>
            <div class="form-group">
                <label>Minimum Stock Level *</label>
                <input type="number" name="minimum_stock" id="edit_minimum_stock" class="form-control" value="0" required>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-action btn-warning">Update Item</button>
                <button type="button" class="btn-action" onclick="closeModal('editItemModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Item Modal -->
<div id="deleteItemModal" class="modal">
    <div class="modal-content">
        <h2 style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> Delete Relief Item</h2>
        <form method="POST">
            <input type="hidden" name="action" value="delete_item">
            <input type="hidden" name="item_id" id="delete_item_id">
            <div class="alert alert-danger">
                <strong>Warning!</strong> This action cannot be undone.
            </div>
            <p>Are you sure you want to delete this relief item?</p>
            <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin: 1rem 0;">
                <p style="margin: 0;"><strong>Item Name:</strong> <span id="delete_item_name"></span></p>
                <p style="margin: 0.5rem 0 0 0;"><strong>Category:</strong> <span id="delete_item_category"></span></p>
                <p style="margin: 0.5rem 0 0 0;"><strong>Current Stock:</strong> <span id="delete_item_stock"></span></p>
            </div>
            <p style="font-size: 0.9rem; color: #6c757d;">
                <i class="fas fa-info-circle"></i> Note: You can only delete items with zero stock and no transaction history.
            </p>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-action btn-danger" id="confirmDeleteItemBtn">
                    <i class="fas fa-trash"></i> Delete Item
                </button>
                <button type="button" class="btn-action" onclick="closeModal('deleteItemModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Distribute Relief Modal -->
<div id="distributeModal" class="modal">
    <div class="modal-content">
        <h2>Distribute Relief Goods</h2>
        <form method="POST">
            <input type="hidden" name="action" value="distribute_relief">
            <div class="form-group">
                <label>Distribution Date *</label>
                <input type="date" name="distribution_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Location Type *</label>
                <div class="location-options">
                    <button type="button" class="active" onclick="toggleLocationType('custom')">
                        <i class="fas fa-map-marker-alt"></i> Custom Location
                    </button>
                    <button type="button" onclick="toggleLocationType('center')">
                        <i class="fas fa-home"></i> Evacuation Center
                    </button>
                </div>
            </div>
            <div class="form-group location-input-group">
                <label id="locationLabel">Location *</label>
                <input type="text" name="location" id="locationInput" class="form-control" placeholder="e.g., Barangay Hall, Community Center" required>
                <select name="location" id="centerSelect" class="form-control" style="display: none;">
                    <option value="">-- Select Evacuation Center --</option>
                    <?php foreach ($evacuation_centers as $center): ?>
                    <option value="<?php echo htmlspecialchars($center['center_name'] . ' - ' . $center['location']); ?>">
                        <?php echo htmlspecialchars($center['center_name'] . ' - ' . $center['location']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Total Beneficiaries *</label>
                <input type="number" name="total_beneficiaries" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Items to Distribute *</label>
                <div id="itemsContainer">
                    <div class="item-row">
                        <select name="items[0][item_id]" class="form-control item-select" required onchange="updateStockInfo(this, 0)">
                            <option value="">-- Select Item --</option>
                            <?php foreach ($inventory as $item): ?>
                            <option value="<?php echo $item['item_id']; ?>" data-stock="<?php echo $item['quantity'] ?? 0; ?>" data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                                <?php echo htmlspecialchars($item['item_name']) . ' (Stock: ' . number_format($item['quantity'] ?? 0, 2) . ' ' . $item['unit_of_measure'] . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="position: relative;">
                            <input type="number" step="0.01" name="items[0][quantity]" class="form-control quantity-input" placeholder="Quantity" required oninput="validateQuantity(this, 0)">
                            <div class="stock-warning" id="warning-0"></div>
                        </div>
                        <button type="button" class="btn-action" onclick="removeItemRow(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn-action btn-primary" onclick="addItemRow()">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>
            <div class="form-group">
                <label>Remarks</label>
                <textarea name="remarks" class="form-control" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-action btn-warning" onclick="return validateDistributionForm()">Complete Distribution</button>
                <button type="button" class="btn-action" onclick="closeModal('distributeModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Distribution Item Confirmation Modal -->
<div id="deleteDistributionItemModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <h2 style="color: #dc3545;">
            <i class="fas fa-exclamation-triangle"></i> Remove Item
        </h2>
        <div class="alert alert-warning" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
            <strong>Are you sure?</strong><br>
            Do you want to remove this item from the distribution?
        </div>
        <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin: 1rem 0;">
            <p style="margin: 0;"><strong>Item:</strong> <span id="delete_dist_item_name">-</span></p>
            <p style="margin: 0.5rem 0 0 0;"><strong>Quantity:</strong> <span id="delete_dist_item_quantity">-</span></p>
        </div>
        <p style="font-size: 0.9rem; color: #6c757d;">
            <i class="fas fa-info-circle"></i> This will only remove the item from this distribution form. No inventory changes will be made.
        </p>
        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button type="button" class="btn-action btn-danger" id="confirmDeleteDistItemBtn" onclick="confirmRemoveDistItem()">
                <i class="fas fa-trash"></i> Yes, Remove Item
            </button>
            <button type="button" class="btn-action" onclick="closeModal('deleteDistributionItemModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<!-- Cannot Remove Last Item Modal -->
<div id="cannotRemoveItemModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <h2 style="color: #dc3545;">
            <i class="fas fa-ban"></i> Cannot Remove Item
        </h2>
        <div class="alert alert-danger" style="background: #f8d7da; border-color: #dc3545; color: #721c24; border-left: 4px solid #dc3545;">
            <strong>Action Not Allowed</strong><br>
            At least one item is required for distribution.
        </div>
        <p style="color: #6c757d;">
            <i class="fas fa-info-circle"></i> You cannot remove the last item from the distribution. Please add more items before removing this one, or cancel the distribution if you don't want to proceed.
        </p>
        <div style="margin-top: 1.5rem;">
            <button type="button" class="btn-action btn-primary" onclick="closeModal('cannotRemoveItemModal')">
                <i class="fas fa-check"></i> I Understand
            </button>
        </div>
    </div>
</div>

<script>

    let itemRowToRemove = null;

    
const evacuationCenters = <?php echo json_encode($evacuation_centers); ?>;
const inventoryData = <?php echo json_encode($inventory); ?>;
let stockLimits = {};

// Initialize stock limits
inventoryData.forEach(item => {
    stockLimits[item.item_id] = {
        stock: parseFloat(item.quantity || 0),
        unit: item.unit_of_measure,
        name: item.item_name
    };
});

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
    // Reset validation when opening distribute modal
    if (modalId === 'distributeModal') {
        resetDistributionForm();
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function resetDistributionForm() {
    const warnings = document.querySelectorAll('.stock-warning');
    warnings.forEach(w => w.classList.remove('show'));
    const inputs = document.querySelectorAll('.quantity-input');
    inputs.forEach(i => i.classList.remove('stock-error'));
}

function updateStockInfo(selectElement, index) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const itemId = selectElement.value;
    const quantityInput = selectElement.closest('.item-row').querySelector('.quantity-input');
    
    // Reset the quantity input
    quantityInput.value = '';
    quantityInput.classList.remove('stock-error');
    
    // Update max attribute
    if (itemId && stockLimits[itemId]) {
        quantityInput.max = stockLimits[itemId].stock;
        quantityInput.setAttribute('data-stock', stockLimits[itemId].stock);
        quantityInput.setAttribute('data-unit', stockLimits[itemId].unit);
    }
    
    // Hide warning
    const warning = document.getElementById('warning-' + index);
    if (warning) {
        warning.classList.remove('show');
    }
}

function validateQuantity(inputElement, index) {
    const quantity = parseFloat(inputElement.value);
    const maxStock = parseFloat(inputElement.getAttribute('data-stock') || 0);
    const unit = inputElement.getAttribute('data-unit') || '';
    const warning = document.getElementById('warning-' + index);
    
    if (warning && quantity > maxStock) {
        warning.textContent = `⚠ Insufficient stock! Only ${maxStock.toFixed(2)} ${unit} available.`;
        warning.classList.add('show');
        inputElement.classList.add('stock-error');
    } else if (warning) {
        warning.classList.remove('show');
        inputElement.classList.remove('stock-error');
    }
}

function validateDistributionForm() {
    let isValid = true;
    const quantityInputs = document.querySelectorAll('.quantity-input');
    
    quantityInputs.forEach((input, index) => {
        const quantity = parseFloat(input.value || 0);
        const maxStock = parseFloat(input.getAttribute('data-stock') || 0);
        
        if (quantity > maxStock) {
            isValid = false;
            validateQuantity(input, index);
        }
    });
    
    if (!isValid) {
        alert('Cannot distribute relief: One or more items exceed available stock. Please check the quantities.');
        return false;
    }
    
    return true;
}

function addStockToItem(item) {
    document.getElementById('stock_item_id').value = item.item_id;
    document.getElementById('stock_item_name').value = item.item_name;
    document.getElementById('stock_current').value = parseFloat(item.quantity || 0).toFixed(2) + ' ' + item.unit_of_measure;
    
    // Show/hide expiry date based on item category
    const expiryDateGroup = document.getElementById('expiryDateGroup');
    const expiryDateInput = document.getElementById('expiryDateInput');
    const perishableCategories = ['Food', 'Water', 'Medicine'];
    
    if (perishableCategories.includes(item.item_category)) {
        expiryDateGroup.style.display = 'block';
        expiryDateInput.removeAttribute('disabled');
    } else {
        expiryDateGroup.style.display = 'none';
        expiryDateInput.setAttribute('disabled', 'disabled');
        expiryDateInput.value = ''; // Clear the value
    }
    
    openModal('addStockModal');
}

function editItem(item) {
    document.getElementById('edit_item_id').value = item.item_id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_item_category').value = item.item_category;
    document.getElementById('edit_unit_of_measure').value = item.unit_of_measure;
    document.getElementById('edit_minimum_stock').value = item.minimum_stock;
    
    openModal('editItemModal');
}

function deleteItem(item) {
    document.getElementById('delete_item_id').value = item.item_id;
    document.getElementById('delete_item_name').textContent = item.item_name;
    document.getElementById('delete_item_category').textContent = item.item_category;
    document.getElementById('delete_item_stock').textContent = parseFloat(item.quantity || 0).toFixed(2) + ' ' + item.unit_of_measure;
    
    // Disable delete button if there's stock
    const deleteBtn = document.getElementById('confirmDeleteItemBtn');
    const hasStock = parseFloat(item.quantity || 0) > 0;
    
    if (hasStock) {
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fas fa-ban"></i> Cannot Delete (Has Stock)';
        deleteBtn.style.opacity = '0.6';
        deleteBtn.style.cursor = 'not-allowed';
    } else {
        deleteBtn.disabled = false;
        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Item';
        deleteBtn.style.opacity = '1';
        deleteBtn.style.cursor = 'pointer';
    }
    
    openModal('deleteItemModal');
}

function toggleLocationType(type) {
    const buttons = document.querySelectorAll('.location-options button');
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.closest('button').classList.add('active');
    
    const locationInput = document.getElementById('locationInput');
    const centerSelect = document.getElementById('centerSelect');
    const locationLabel = document.getElementById('locationLabel');
    
    if (type === 'custom') {
        locationInput.style.display = 'block';
        centerSelect.style.display = 'none';
        locationInput.name = 'location';
        centerSelect.name = '';
        locationInput.required = true;
        centerSelect.required = false;
        locationLabel.textContent = 'Location *';
        locationInput.placeholder = 'e.g., Barangay Hall, Community Center';
    } else {
        locationInput.style.display = 'none';
        centerSelect.style.display = 'block';
        locationInput.name = '';
        centerSelect.name = 'location';
        locationInput.required = false;
        centerSelect.required = true;
        locationLabel.textContent = 'Evacuation Center *';
    }
}

let itemRowCount = 1;

function addItemRow() {
    const container = document.getElementById('itemsContainer');
    const newRow = document.createElement('div');
    newRow.className = 'item-row';
    newRow.innerHTML = `
        <select name="items[${itemRowCount}][item_id]" class="form-control item-select" required onchange="updateStockInfo(this, ${itemRowCount})">
            <option value="">-- Select Item --</option>
            <?php foreach ($inventory as $item): ?>
            <option value="<?php echo $item['item_id']; ?>" data-stock="<?php echo $item['quantity'] ?? 0; ?>" data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                <?php echo htmlspecialchars($item['item_name']) . ' (Stock: ' . number_format($item['quantity'] ?? 0, 2) . ' ' . $item['unit_of_measure'] . ')'; ?>
            </option>
            <?php endforeach; ?>
        </select>
        <div style="position: relative;">
            <input type="number" step="0.01" name="items[${itemRowCount}][quantity]" class="form-control quantity-input" placeholder="Quantity" required oninput="validateQuantity(this, ${itemRowCount})">
            <div class="stock-warning" id="warning-${itemRowCount}"></div>
        </div>
        <button type="button" class="btn-action" onclick="removeItemRow(this)">
            <i class="fas fa-trash"></i>
        </button>
    `;
    container.appendChild(newRow);
    itemRowCount++;
}

function removeItemRow(button) {
    const container = document.getElementById('itemsContainer');
    
    // Check if there's more than one item - show modal instead of alert
    if (container.children.length <= 1) {
        openModal('cannotRemoveItemModal');
        return;
    }
    
    // Get item details for the modal
    const itemRow = button.closest('.item-row');
    const selectElement = itemRow.querySelector('.item-select');
    const quantityInput = itemRow.querySelector('.quantity-input');
    
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const itemName = selectedOption.text || '-- Not selected --';
    const quantity = quantityInput.value || '0';
    const unit = quantityInput.getAttribute('data-unit') || '';
    
    // Update modal content
    document.getElementById('delete_dist_item_name').textContent = itemName;
    document.getElementById('delete_dist_item_quantity').textContent = quantity + (unit ? ' ' + unit : '');
    
    // Store reference to the button/row
    itemRowToRemove = button;
    
    // Show modal
    openModal('deleteDistributionItemModal');
}

// Close modal on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

function confirmRemoveDistItem() {
    if (itemRowToRemove) {
        const container = document.getElementById('itemsContainer');
        
        // Double check we're not removing the last item
        if (container.children.length > 1) {
            itemRowToRemove.closest('.item-row').remove();
        }
        
        // Reset the reference
        itemRowToRemove = null;
    }
    
    // Close modal
    closeModal('deleteDistributionItemModal');
}
</script>

<?php include '../../includes/footer.php'; ?>