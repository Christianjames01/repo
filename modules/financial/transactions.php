<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Transaction History';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

// Filters
$transaction_type = isset($_GET['type']) ? $_GET['type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 20;
$offset = ($page - 1) * $records_per_page;

// Build combined query for revenues and expenses
$revenue_where = ["r.status = 'Verified'"];
$expense_where = ["e.status = 'Released'"];
$params = [];
$types = '';

if ($date_from) {
    $revenue_where[] = "r.transaction_date >= ?";
    $expense_where[] = "e.expense_date >= ?";
    $params[] = $date_from;
    $params[] = $date_from;
    $types .= 'ss';
}

if ($date_to) {
    $revenue_where[] = "r.transaction_date <= ?";
    $expense_where[] = "e.expense_date <= ?";
    $params[] = $date_to;
    $params[] = $date_to;
    $types .= 'ss';
}

$revenue_where_sql = !empty($revenue_where) ? 'WHERE ' . implode(' AND ', $revenue_where) : '';
$expense_where_sql = !empty($expense_where) ? 'WHERE ' . implode(' AND ', $expense_where) : '';

// Get transactions based on type filter
if ($transaction_type === 'revenue') {
    $sql = "SELECT 'Revenue' as type, r.reference_number, rc.category_name, 
                   r.source as details, r.amount, r.transaction_date as trans_date,
                   r.payment_method, u.username as processed_by, r.created_at
            FROM tbl_revenues r
            JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
            LEFT JOIN tbl_users u ON r.received_by = u.user_id
            $revenue_where_sql";
            
    if ($search) {
        $sql .= " AND (r.reference_number LIKE ? OR r.source LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
} elseif ($transaction_type === 'expense') {
    $sql = "SELECT 'Expense' as type, e.reference_number, ec.category_name,
                   e.payee as details, e.amount, e.expense_date as trans_date,
                   e.payment_method, u.username as processed_by, e.created_at
            FROM tbl_expenses e
            JOIN tbl_expense_categories ec ON e.category_id = ec.category_id
            LEFT JOIN tbl_users u ON e.requested_by = u.user_id
            $expense_where_sql";
            
    if ($search) {
        $sql .= " AND (e.reference_number LIKE ? OR e.payee LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
} else {
    // Combined query
    $sql = "(SELECT 'Revenue' as type, r.reference_number, rc.category_name,
                    r.source as details, r.amount, r.transaction_date as trans_date,
                    r.payment_method, u.username as processed_by, r.created_at
             FROM tbl_revenues r
             JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
             LEFT JOIN tbl_users u ON r.received_by = u.user_id
             $revenue_where_sql";
             
    if ($search) {
        $sql .= " AND (r.reference_number LIKE ? OR r.source LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    $sql .= ") UNION (
             SELECT 'Expense' as type, e.reference_number, ec.category_name,
                    e.payee as details, e.amount, e.expense_date as trans_date,
                    e.payment_method, u.username as processed_by, e.created_at
             FROM tbl_expenses e
             JOIN tbl_expense_categories ec ON e.category_id = ec.category_id
             LEFT JOIN tbl_users u ON e.requested_by = u.user_id
             $expense_where_sql";
             
    if ($search) {
        $sql .= " AND (e.reference_number LIKE ? OR e.payee LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    $sql .= ")";
}

$sql .= " ORDER BY trans_date DESC, created_at DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total count (simplified - not exact but good enough)
$total_records = count($transactions);
$total_pages = ceil($total_records / $records_per_page);

// Calculate summary
$total_revenue = 0;
$total_expense = 0;
foreach ($transactions as $trans) {
    if ($trans['type'] === 'Revenue') {
        $total_revenue += $trans['amount'];
    } else {
        $total_expense += $trans['amount'];
    }
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-history"></i> Transaction History
        </h1>
        <p class="page-subtitle">Complete list of all financial transactions</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 1.5rem;">
    <div class="stat-card revenue">
        <div class="stat-icon">
            <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Revenue (Filtered)</div>
            <div class="stat-value">₱<?php echo number_format($total_revenue, 2); ?></div>
        </div>
    </div>
    <div class="stat-card expense">
        <div class="stat-icon">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Expense (Filtered)</div>
            <div class="stat-value">₱<?php echo number_format($total_expense, 2); ?></div>
        </div>
    </div>
    <div class="stat-card balance">
        <div class="stat-icon">
            <i class="fas fa-balance-scale"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Net Amount</div>
            <div class="stat-value" style="color: <?php echo ($total_revenue - $total_expense) >= 0 ? '#10b981' : '#ef4444'; ?>">
                ₱<?php echo number_format($total_revenue - $total_expense, 2); ?>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="chart-card" style="margin-bottom: 1.5rem;">
    <form method="GET" class="filter-form">
        <div class="filter-grid">
            <div class="form-group">
                <label>Transaction Type</label>
                <select name="type" class="form-control">
                    <option value="">All Transactions</option>
                    <option value="revenue" <?php echo $transaction_type === 'revenue' ? 'selected' : ''; ?>>Revenue Only</option>
                    <option value="expense" <?php echo $transaction_type === 'expense' ? 'selected' : ''; ?>>Expense Only</option>
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
                <input type="text" name="search" class="form-control" placeholder="Reference, details..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="form-group" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                <button type="submit" class="action-btn budget" style="flex: 1;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="transactions.php" class="action-btn report" style="flex: 1;">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Transaction Table -->
<div class="chart-card">
    <div class="chart-header">
        <h3><i class="fas fa-list"></i> All Transactions (<?php echo number_format(count($transactions)); ?>)</h3>
        <button class="action-btn report" onclick="exportToCSV()">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>
    <div class="table-responsive">
        <table class="data-table" id="transactionsTable">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Reference #</th>
                    <th>Category</th>
                    <th>Details</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Processed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="8" class="text-center">No transactions found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td>
                                <span class="transaction-type <?php echo strtolower($trans['type']); ?>">
                                    <i class="fas fa-<?php echo $trans['type'] === 'Revenue' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                                    <?php echo $trans['type']; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($trans['trans_date'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($trans['reference_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($trans['category_name']); ?></td>
                            <td><?php echo htmlspecialchars($trans['details']); ?></td>
                            <td class="<?php echo $trans['type'] === 'Revenue' ? 'revenue-color' : 'expense-color'; ?>">
                                <strong>₱<?php echo number_format($trans['amount'], 2); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($trans['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($trans['processed_by']); ?></td>
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
            $base_url = 'transactions.php?' . http_build_query($query_params) . '&page=';
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

.transaction-type {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.8rem;
}

.transaction-type.revenue {
    background: #d1fae5;
    color: #065f46;
}

.transaction-type.expense {
    background: #fee2e2;
    color: #991b1b;
}

.text-center {
    text-align: center;
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid #f3f4f6;
}

.page-link {
    padding: 0.5rem 0.875rem;
    border: 1px solid #d1d5db;
    background: white;
    color: #374151;
    text-decoration: none;
    border-radius: 6px;
    transition: all 0.2s;
}

.page-link:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.page-link.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}
</style>

<script>
function exportToCSV() {
    const table = document.getElementById('transactionsTable');
    let csv = [];
    
    // Headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push('"' + th.textContent.trim() + '"');
    });
    csv.push(headers.join(','));
    
    // Rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.querySelector('td[colspan]')) return; // Skip "no data" rows
        
        const row = [];
        tr.querySelectorAll('td').forEach(td => {
            // Clean the text content
            let text = td.textContent.trim();
            text = text.replace(/"/g, '""'); // Escape quotes
            row.push('"' + text + '"');
        });
        csv.push(row.join(','));
    });
    
    // Download
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'transactions_' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include '../../includes/footer.php'; ?>