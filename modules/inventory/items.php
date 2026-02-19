<?php
// Include files in correct order
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

// Check authentication and role
requireAnyRole(['Super Administrator', 'Barangay Treasurer']);

$page_title = 'Manage Inventory Items';

// Handle add/edit item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_item'])) {
    $item_name = sanitizeInput($_POST['item_name']);
    $category = sanitizeInput($_POST['category']);
    $description = sanitizeInput($_POST['description']);
    $unit = sanitizeInput($_POST['unit']);
    $reorder_level = floatval($_POST['reorder_level']);
    $unit_cost = floatval($_POST['unit_cost']);
    $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : NULL;
    
    if (isset($_POST['item_id']) && !empty($_POST['item_id'])) {
        // Update
        $item_id = intval($_POST['item_id']);
        $sql = "UPDATE tbl_inventory_items SET item_name=?, category=?, description=?, unit=?, reorder_level=?, unit_cost=?, supplier_id=? WHERE item_id=?";
        executeQuery($conn, $sql, [$item_name, $category, $description, $unit, $reorder_level, $unit_cost, $supplier_id, $item_id], 'sssddiii');
        redirect('items.php', 'Item updated successfully', 'success');
    } else {
        // Insert
        $sql = "INSERT INTO tbl_inventory_items (item_name, category, description, unit, quantity, reorder_level, unit_cost, supplier_id) 
                VALUES (?, ?, ?, ?, 0, ?, ?, ?)";
        executeQuery($conn, $sql, [$item_name, $category, $description, $unit, $reorder_level, $unit_cost, $supplier_id], 'sssddii');
        logActivity($conn, getCurrentUserId(), 'Added inventory item', 'tbl_inventory_items', $conn->insert_id, $item_name);
        redirect('items.php', 'Item added successfully', 'success');
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $item_id = intval($_GET['delete']);
    $sql = "UPDATE tbl_inventory_items SET is_active = 0 WHERE item_id = ?";
    executeQuery($conn, $sql, [$item_id], 'i');
    redirect('items.php', 'Item deactivated successfully', 'success');
}

// Get all items
$sql = "SELECT i.*, s.supplier_name 
        FROM tbl_inventory_items i
        LEFT JOIN tbl_suppliers s ON i.supplier_id = s.supplier_id
        WHERE i.is_active = 1
        ORDER BY i.item_name";
$items = fetchAll($conn, $sql);

// Get suppliers for dropdown
$suppliers = fetchAll($conn, "SELECT * FROM tbl_suppliers WHERE status = 'Active' ORDER BY supplier_name");

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="mb-0">
                    <i class="fas fa-boxes me-2"></i>Inventory Items
                </h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                    <i class="fas fa-plus me-1"></i>Add New Item
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Total Items</h6>
                    <h2><?php echo count($items); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Low Stock Items</h6>
                    <h2 class="text-danger">
                        <?php 
                        $low_stock = array_filter($items, function($item) {
                            return $item['quantity'] <= $item['reorder_level'];
                        });
                        echo count($low_stock);
                        ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Total Value</h6>
                    <h2>
                        <?php 
                        $total_value = array_sum(array_map(function($item) {
                            return $item['quantity'] * $item['unit_cost'];
                        }, $items));
                        echo formatCurrency($total_value);
                        ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Categories</h6>
                    <h2><?php echo count(array_unique(array_column($items, 'category'))); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover data-table">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Unit</th>
                                    <th>Quantity</th>
                                    <th>Reorder Level</th>
                                    <th>Unit Cost</th>
                                    <th>Total Value</th>
                                    <th>Supplier</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $item['quantity'] <= $item['reorder_level'] ? 'danger' : 'success'; ?>">
                                            <?php echo number_format($item['quantity'], 2); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($item['reorder_level'], 2); ?></td>
                                    <td><?php echo formatCurrency($item['unit_cost']); ?></td>
                                    <td><?php echo formatCurrency($item['quantity'] * $item['unit_cost']); ?></td>
                                    <td><?php echo htmlspecialchars($item['supplier_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        if ($item['quantity'] == 0) {
                                            echo '<span class="badge bg-dark">Out of Stock</span>';
                                        } elseif ($item['quantity'] <= $item['reorder_level']) {
                                            echo '<span class="badge bg-danger">Low Stock</span>';
                                        } else {
                                            echo '<span class="badge bg-success">In Stock</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?delete=<?php echo $item['item_id']; ?>" 
                                           class="btn btn-sm btn-danger"
                                           onclick="return confirm('Delete this item?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="item_id">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" id="item_name" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" id="category" class="form-select" required>
                                <option value="">Select</option>
                                <option value="Office Supplies">Office Supplies</option>
                                <option value="Medical Supplies">Medical Supplies</option>
                                <option value="Relief Goods">Relief Goods</option>
                                <option value="Equipment">Equipment</option>
                                <option value="Maintenance Supplies">Maintenance Supplies</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                            <input type="text" name="unit" id="unit" class="form-control" placeholder="pcs, box, kg" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Reorder Level <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="reorder_level" id="reorder_level" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit Cost <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="unit_cost" id="unit_cost" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="supplier_id" class="form-select">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['supplier_id']; ?>">
                                <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_item" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editItem(item) {
    document.getElementById('modalTitle').textContent = 'Edit Item';
    document.getElementById('item_id').value = item.item_id;
    document.getElementById('item_name').value = item.item_name;
    document.getElementById('category').value = item.category;
    document.getElementById('description').value = item.description;
    document.getElementById('unit').value = item.unit;
    document.getElementById('reorder_level').value = item.reorder_level;
    document.getElementById('unit_cost').value = item.unit_cost;
    document.getElementById('supplier_id').value = item.supplier_id || '';
    
    new bootstrap.Modal(document.getElementById('addItemModal')).show();
}

// Reset form when modal closes
document.getElementById('addItemModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').textContent = 'Add New Item';
    this.querySelector('form').reset();
});
</script>

<?php include '../../includes/footer.php'; ?>