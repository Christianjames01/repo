<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Expense Management';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Staff', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'approve' && isset($_POST['expense_id'])) {
            $expense_id = intval($_POST['expense_id']);
            $stmt = $conn->prepare("UPDATE tbl_expenses SET status = 'Approved', approved_by = ?, approval_date = NOW() WHERE expense_id = ?");
            $stmt->bind_param("ii", $current_user_id, $expense_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Expense approved successfully!";
            }
            $stmt->close();
            header('Location: expenses.php');
            exit();
        } elseif ($_POST['action'] === 'release' && isset($_POST['expense_id'])) {
            $expense_id = intval($_POST['expense_id']);
            
            // Get expense amount
            $exp_stmt = $conn->prepare("SELECT amount FROM tbl_expenses WHERE expense_id = ?");
            $exp_stmt->bind_param("i", $expense_id);
            $exp_stmt->execute();
            $amount = $exp_stmt->get_result()->fetch_assoc()['amount'];
            $exp_stmt->close();
            
            // Update expense status
            $stmt = $conn->prepare("UPDATE tbl_expenses SET status = 'Released', released_by = ?, release_date = NOW() WHERE expense_id = ?");
            $stmt->bind_param("ii", $current_user_id, $expense_id);
            if ($stmt->execute()) {
                // Deduct from fund balance
                $update_balance = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = current_balance - ?, updated_by = ? ORDER BY balance_id DESC LIMIT 1");
                $update_balance->bind_param("di", $amount, $current_user_id);
                $update_balance->execute();
                $update_balance->close();
                
                $_SESSION['success_message'] = "Expense released successfully!";
            }
            $stmt->close();
            header('Location: expenses.php');
            exit();
        } elseif ($_POST['action'] === 'reject' && isset($_POST['expense_id'])) {
            $expense_id = intval($_POST['expense_id']);
            $stmt = $conn->prepare("UPDATE tbl_expenses SET status = 'Rejected' WHERE expense_id = ?");
            $stmt->bind_param("i", $expense_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Expense rejected!";
            }
            $stmt->close();
            header('Location: expenses.php');
            exit();
        } elseif ($_POST['action'] === 'cancel' && isset($_POST['expense_id'])) {
            $expense_id = intval($_POST['expense_id']);
            $stmt = $conn->prepare("UPDATE tbl_expenses SET status = 'Cancelled' WHERE expense_id = ?");
            $stmt->bind_param("i", $expense_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Expense cancelled!";
            }
            $stmt->close();
            header('Location: expenses.php');
            exit();
        }
    }
}

// Filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Build query
$where_clauses = [];
$params = [];
$types = '';

if ($status_filter) {
    $where_clauses[] = "e.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($category_filter > 0) {
    $where_clauses[] = "e.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

if ($date_from) {
    $where_clauses[] = "e.expense_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $where_clauses[] = "e.expense_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($search) {
    $where_clauses[] = "(e.reference_number LIKE ? OR e.payee LIKE ? OR e.description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM tbl_expenses e $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = ceil($total_records / $records_per_page);

// Get expenses
$sql = "SELECT e.*, ec.category_name,
        u1.username as requested_by_name,
        u2.username as approved_by_name,
        u3.username as released_by_name
        FROM tbl_expenses e
        LEFT JOIN tbl_expense_categories ec ON e.category_id = ec.category_id
        LEFT JOIN tbl_users u1 ON e.requested_by = u1.user_id
        LEFT JOIN tbl_users u2 ON e.approved_by = u2.user_id
        LEFT JOIN tbl_users u3 ON e.released_by = u3.user_id
        $where_sql
        ORDER BY e.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get categories for filter
$categories = fetchAll($conn, "SELECT * FROM tbl_expense_categories WHERE is_active = 1 ORDER BY category_name");

// Get summary stats
$total_pending = fetchOne($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM tbl_expenses WHERE status = 'Pending'")['total'];
$total_approved = fetchOne($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM tbl_expenses WHERE status = 'Approved'")['total'];
$total_released = fetchOne($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM tbl_expenses WHERE status = 'Released'")['total'];

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-arrow-up"></i> Expense Management
        </h1>
        <p class="page-subtitle">Track and manage all barangay expenses</p>
    </div>
    <div class="header-actions">
        <a href="expenses-add.php" class="action-btn expense">
            <i class="fas fa-plus-circle"></i> Add Expense
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 1.5rem;">
    <div class="stat-card pending">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Pending</div>
            <div class="stat-value">₱<?php echo number_format($total_pending, 2); ?></div>
        </div>
    </div>
    <div class="stat-card balance">
        <div class="stat-icon">
            <i class="fas fa-check"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Approved</div>
            <div class="stat-value">₱<?php echo number_format($total_approved, 2); ?></div>
        </div>
    </div>
    <div class="stat-card expense">
        <div class="stat-icon">
            <i class="fas fa-check-double"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Released</div>
            <div class="stat-value">₱<?php echo number_format($total_released, 2); ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="chart-card" style="margin-bottom: 1.5rem;">
    <form method="GET" class="filter-form">
        <div class="filter-grid">
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Released" <?php echo $status_filter === 'Released' ? 'selected' : ''; ?>>Released</option>
                    <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category" class="form-control">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
            </div>
            <div class="form-group">
                <label>Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
            </div>
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="search" class="form-control" placeholder="Reference, payee..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="form-group" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                <button type="submit" class="action-btn budget" style="flex: 1;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="expenses.php" class="action-btn report" style="flex: 1;">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Expense Table -->
<div class="chart-card">
    <div class="chart-header">
        <h3><i class="fas fa-list"></i> Expense Records (<?php echo number_format($total_records); ?> total)</h3>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference #</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Payee</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Requested By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="9" class="text-center">No expense records found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($exp['reference_number']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                            <td><?php echo htmlspecialchars($exp['category_name']); ?></td>
                            <td><?php echo htmlspecialchars($exp['payee']); ?></td>
                            <td class="expense-color"><strong>₱<?php echo number_format($exp['amount'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($exp['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($exp['requested_by_name']); ?></td>
                            <td><?php echo getStatusBadge($exp['status']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-icon" onclick='viewExpense(<?php echo json_encode($exp); ?>)' title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($exp['status'] === 'Pending'): ?>
                                        <button class="btn-icon success" onclick='openApproveModal(<?php echo json_encode($exp); ?>)' title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn-icon danger" onclick='openRejectModal(<?php echo json_encode($exp); ?>)' title="Reject">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php elseif ($exp['status'] === 'Approved'): ?>
                                        <button class="btn-icon success" onclick='openReleaseModal(<?php echo json_encode($exp); ?>)' title="Release Payment">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                        <button class="btn-icon warning" onclick='openCancelModal(<?php echo json_encode($exp); ?>)' title="Cancel">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php
            $query_params = $_GET;
            unset($query_params['page']);
            $base_url = 'expenses.php?' . http_build_query($query_params) . '&page=';
            ?>
            
            <?php if ($page > 1): ?>
                <a href="<?php echo $base_url . ($page - 1); ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="<?php echo $base_url . $i; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="<?php echo $base_url . ($page + 1); ?>" class="page-link">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- View Expense Modal -->
<div id="viewExpenseModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title">Expense Details</h2>
            <button class="close-btn" onclick="closeModal('viewExpenseModal')">&times;</button>
        </div>
        <div id="expenseDetails" class="details-grid"></div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: #d1fae5; border-bottom-color: #10b981;">
            <h2 class="modal-title" style="color: #065f46;">
                <i class="fas fa-check-circle"></i> Approve Expense
            </h2>
            <button class="close-btn" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem;">
            <p style="margin-bottom: 1.5rem; color: #374151;">Are you sure you want to approve this expense?</p>
            <div id="approveExpenseInfo" class="info-grid"></div>
            <form method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="expense_id" id="approve_expense_id">
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="action-btn budget" style="flex: 1;">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button type="button" class="action-btn report" onclick="closeModal('approveModal')" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: #fee2e2; border-bottom-color: #ef4444;">
            <h2 class="modal-title" style="color: #991b1b;">
                <i class="fas fa-times-circle"></i> Reject Expense
            </h2>
            <button class="close-btn" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem;">
            <div class="alert" style="background: #fef3c7; border-left-color: #f59e0b; margin-bottom: 1.5rem;">
                <i class="fas fa-exclamation-triangle" style="color: #92400e;"></i>
                <p style="margin: 0; color: #92400e;">This action cannot be undone. The expense will be marked as rejected.</p>
            </div>
            <div id="rejectExpenseInfo" class="info-grid"></div>
            <form method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="expense_id" id="reject_expense_id">
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="action-btn" style="flex: 1; background: #dc3545; color: white;">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button type="button" class="action-btn report" onclick="closeModal('rejectModal')" style="flex: 1;">
                        <i class="fas fa-arrow-left"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: #dbeafe; border-bottom-color: #3b82f6;">
            <h2 class="modal-title" style="color: #1e40af;">
                <i class="fas fa-money-bill-wave"></i> Release Payment
            </h2>
            <button class="close-btn" onclick="closeModal('releaseModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem;">
            <div class="alert" style="background: #dbeafe; border-left-color: #3b82f6; margin-bottom: 1.5rem;">
                <i class="fas fa-info-circle" style="color: #1e40af;"></i>
                <p style="margin: 0; color: #1e40af;">This will mark the payment as released and deduct from the fund balance.</p>
            </div>
            <div id="releaseExpenseInfo" class="info-grid"></div>
            <form method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="expense_id" id="release_expense_id">
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="action-btn budget" style="flex: 1;">
                        <i class="fas fa-check-double"></i> Release Payment
                    </button>
                    <button type="button" class="action-btn report" onclick="closeModal('releaseModal')" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: #f3f4f6; border-bottom-color: #9ca3af;">
            <h2 class="modal-title" style="color: #374151;">
                <i class="fas fa-ban"></i> Cancel Expense
            </h2>
            <button class="close-btn" onclick="closeModal('cancelModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem;">
            <div class="alert" style="background: #fef3c7; border-left-color: #f59e0b; margin-bottom: 1.5rem;">
                <i class="fas fa-exclamation-triangle" style="color: #92400e;"></i>
                <p style="margin: 0; color: #92400e;">This will cancel the approved expense. This action cannot be undone.</p>
            </div>
            <div id="cancelExpenseInfo" class="info-grid"></div>
            <form method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="expense_id" id="cancel_expense_id">
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="action-btn" style="flex: 1; background: #6b7280; color: white;">
                        <i class="fas fa-ban"></i> Cancel Expense
                    </button>
                    <button type="button" class="action-btn report" onclick="closeModal('cancelModal')" style="flex: 1;">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #374151;
    font-size: 0.9rem;
}

.form-control {
    width: 100%;
    padding: 0.625rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.95rem;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    justify-content: center;
}

.btn-icon {
    padding: 0.5rem 0.625rem;
    border: none;
    background: #f3f4f6;
    color: #374151;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.95rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    min-height: 32px;
}

.btn-icon:hover {
    background: #e5e7eb;
    transform: translateY(-1px);
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

.btn-icon.warning {
    background: #fef3c7;
    color: #92400e;
}

.btn-icon.warning:hover {
    background: #fde68a;
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

.alert {
    padding: 1rem;
    border-radius: 8px;
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    border-left: 4px solid;
}

.info-grid {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    display: grid;
    gap: 0.75rem;
}

.info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e5e7eb;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 0.9rem;
}

.info-value {
    color: #1f2937;
    font-weight: 500;
    text-align: right;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    max-width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.modal-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
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
    transition: color 0.2s;
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

.details-grid {
    padding: 1.5rem;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem;
    background: #f9fafb;
    border-radius: 6px;
    margin-bottom: 0.75rem;
}

.detail-label {
    font-weight: 600;
    color: #6b7280;
}

.detail-value {
    color: #1f2937;
    font-weight: 500;
    text-align: right;
}
</style>

<script>
function viewExpense(expense) {
    const modal = document.getElementById('viewExpenseModal');
    const details = document.getElementById('expenseDetails');
    
    details.innerHTML = `
        <div class="detail-item">
            <span class="detail-label">Reference Number:</span>
            <span class="detail-value">${expense.reference_number}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Category:</span>
            <span class="detail-value">${expense.category_name}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Amount:</span>
            <span class="detail-value expense-color"><strong>₱${parseFloat(expense.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Payee:</span>
            <span class="detail-value">${expense.payee}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Expense Date:</span>
            <span class="detail-value">${new Date(expense.expense_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Payment Method:</span>
            <span class="detail-value">${expense.payment_method}</span>
        </div>
        ${expense.invoice_number ? `
        <div class="detail-item">
            <span class="detail-label">Invoice Number:</span>
            <span class="detail-value">${expense.invoice_number}</span>
        </div>
        ` : ''}
        <div class="detail-item">
            <span class="detail-label">Requested By:</span>
            <span class="detail-value">${expense.requested_by_name}</span>
        </div>
        ${expense.approved_by_name ? `
        <div class="detail-item">
            <span class="detail-label">Approved By:</span>
            <span class="detail-value">${expense.approved_by_name}</span>
        </div>
        ` : ''}
        ${expense.released_by_name ? `
        <div class="detail-item">
            <span class="detail-label">Released By:</span>
            <span class="detail-value">${expense.released_by_name}</span>
        </div>
        ` : ''}
        <div class="detail-item" style="flex-direction: column; align-items: flex-start;">
            <span class="detail-label">Description:</span>
            <span class="detail-value" style="margin-top: 0.5rem;">${expense.description}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Status:</span>
            <span class="detail-value">${getStatusBadgeHTML(expense.status)}</span>
        </div>
    `;
    
    modal.classList.add('show');
}

function openApproveModal(expense) {
    document.getElementById('approve_expense_id').value = expense.expense_id;
    document.getElementById('approveExpenseInfo').innerHTML = `
        <div class="info-item">
            <span class="info-label">Reference:</span>
            <span class="info-value">${expense.reference_number}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Payee:</span>
            <span class="info-value">${expense.payee}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Amount:</span>
            <span class="info-value" style="color: #dc2626; font-weight: 700;">₱${parseFloat(expense.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Category:</span>
            <span class="info-value">${expense.category_name}</span>
        </div>
    `;
    document.getElementById('approveModal').classList.add('show');
}

function openRejectModal(expense) {
    document.getElementById('reject_expense_id').value = expense.expense_id;
    document.getElementById('rejectExpenseInfo').innerHTML = `
        <div class="info-item">
            <span class="info-label">Reference:</span>
            <span class="info-value">${expense.reference_number}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Payee:</span>
            <span class="info-value">${expense.payee}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Amount:</span>
            <span class="info-value" style="color: #dc2626; font-weight: 700;">₱${parseFloat(expense.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
    `;
    document.getElementById('rejectModal').classList.add('show');
}

function openReleaseModal(expense) {
    document.getElementById('release_expense_id').value = expense.expense_id;
    document.getElementById('releaseExpenseInfo').innerHTML = `
        <div class="info-item">
            <span class="info-label">Reference:</span>
            <span class="info-value">${expense.reference_number}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Payee:</span>
            <span class="info-value">${expense.payee}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Amount:</span>
            <span class="info-value" style="color: #dc2626; font-weight: 700;">₱${parseFloat(expense.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Payment Method:</span>
            <span class="info-value">${expense.payment_method}</span>
        </div>
    `;
    document.getElementById('releaseModal').classList.add('show');
}

function openCancelModal(expense) {
    document.getElementById('cancel_expense_id').value = expense.expense_id;
    document.getElementById('cancelExpenseInfo').innerHTML = `
        <div class="info-item">
            <span class="info-label">Reference:</span>
            <span class="info-value">${expense.reference_number}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Payee:</span>
            <span class="info-value">${expense.payee}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Amount:</span>
            <span class="info-value" style="color: #dc2626; font-weight: 700;">₱${parseFloat(expense.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
    `;
    document.getElementById('cancelModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Pending': '<span style="background: #fef3c7; color: #92400e; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Pending</span>',
        'Approved': '<span style="background: #dbeafe; color: #1e40af; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Approved</span>',
        'Released': '<span style="background: #d1fae5; color: #065f46; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Released</span>',
        'Rejected': '<span style="background: #fee2e2; color: #991b1b; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Rejected</span>',
        'Cancelled': '<span style="background: #f3f4f6; color: #374151; padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">Cancelled</span>'
    };
    return badges[status] || status;
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>