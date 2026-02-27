<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Financial Management';
$user_role = getCurrentUserRole();

// Only Super Admin and Staff can access
if (!in_array($user_role, ['Super Admin', 'Staff', 'Treasurer', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();
$current_year = date('Y');
$current_month = date('m');

// Get fiscal year from query or use current
$fiscal_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Get fund balance
$balance_stmt = $conn->prepare("SELECT current_balance, last_updated FROM tbl_fund_balance ORDER BY balance_id DESC LIMIT 1");
$balance_stmt->execute();
$balance_result = $balance_stmt->get_result();
$fund_data = $balance_result->fetch_assoc();
$current_balance = $fund_data ? $fund_data['current_balance'] : 0.00;
$balance_stmt->close();

// Get total allocated budget (reserved funds)
$allocated_budget_stmt = $conn->prepare("
    SELECT COALESCE(SUM(allocated_amount), 0) as total_allocated
    FROM tbl_budget_allocations
    WHERE status = 'Approved'
");
$allocated_budget_stmt->execute();
$allocated_result = $allocated_budget_stmt->get_result();
$total_allocated_budget = $allocated_result->fetch_assoc()['total_allocated'];
$allocated_budget_stmt->close();

// Calculate available balance (current balance + allocated budget that was deducted)
// Or if you want to show the actual available balance after reservations:
$available_balance = $current_balance; // This is already net of allocations

// Get total revenue for current year
$revenue_stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_revenue 
    FROM tbl_revenues 
    WHERE YEAR(transaction_date) = ? AND status = 'Verified'
");
$revenue_stmt->bind_param("i", $fiscal_year);
$revenue_stmt->execute();
$revenue_result = $revenue_stmt->get_result();
$total_revenue = $revenue_result->fetch_assoc()['total_revenue'];
$revenue_stmt->close();

// Get total expenses for current year
$expense_stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_expenses 
    FROM tbl_expenses 
    WHERE YEAR(expense_date) = ? AND status = 'Released'
");
$expense_stmt->bind_param("i", $fiscal_year);
$expense_stmt->execute();
$expense_result = $expense_stmt->get_result();
$total_expenses = $expense_result->fetch_assoc()['total_expenses'];
$expense_stmt->close();

// Get pending expenses count
$pending_stmt = $conn->prepare("
    SELECT COUNT(*) as pending_count 
    FROM tbl_expenses 
    WHERE status IN ('Pending', 'Approved')
");
$pending_stmt->execute();
$pending_result = $pending_stmt->get_result();
$pending_expenses = $pending_result->fetch_assoc()['pending_count'];
$pending_stmt->close();

// Get budget utilization for current fiscal year
$budget_stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(allocated_amount), 0) as total_allocated,
        COALESCE(SUM(spent_amount), 0) as total_spent,
        COALESCE(SUM(remaining_amount), 0) as total_remaining
    FROM tbl_budget_allocations
    WHERE fiscal_year = ? AND status = 'Approved'
");
$budget_stmt->bind_param("i", $fiscal_year);
$budget_stmt->execute();
$budget_result = $budget_stmt->get_result();
$budget_data = $budget_result->fetch_assoc();
$budget_stmt->close();

// Get document fees revenue for current year
$doc_fees_stmt = $conn->prepare("
    SELECT COALESCE(SUM(r.amount), 0) as doc_fees_total,
           COUNT(*) as doc_fees_count
    FROM tbl_revenues r
    JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
    WHERE rc.category_name = 'Document Fees' 
    AND YEAR(r.transaction_date) = ? 
    AND r.status = 'Verified'
");
$doc_fees_stmt->bind_param("i", $fiscal_year);
$doc_fees_stmt->execute();
$doc_fees_result = $doc_fees_stmt->get_result();
$doc_fees_data = $doc_fees_result->fetch_assoc();
$doc_fees_stmt->close();

// Get revenue by category (current year)
$revenue_by_category_stmt = $conn->prepare("
    SELECT rc.category_name, COALESCE(SUM(r.amount), 0) as total
    FROM tbl_revenue_categories rc
    LEFT JOIN tbl_revenues r ON rc.category_id = r.category_id 
        AND YEAR(r.transaction_date) = ? AND r.status = 'Verified'
    WHERE rc.is_active = 1
    GROUP BY rc.category_id, rc.category_name
    ORDER BY total DESC
    LIMIT 10
");
$revenue_by_category_stmt->bind_param("i", $fiscal_year);
$revenue_by_category_stmt->execute();
$revenue_by_category = $revenue_by_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$revenue_by_category_stmt->close();

// Get expenses by category (current year)
$expense_by_category_stmt = $conn->prepare("
    SELECT ec.category_name, COALESCE(SUM(e.amount), 0) as total
    FROM tbl_expense_categories ec
    LEFT JOIN tbl_expenses e ON ec.category_id = e.category_id 
        AND YEAR(e.expense_date) = ? AND e.status = 'Released'
    WHERE ec.is_active = 1
    GROUP BY ec.category_id, ec.category_name
    ORDER BY total DESC
    LIMIT 10
");
$expense_by_category_stmt->bind_param("i", $fiscal_year);
$expense_by_category_stmt->execute();
$expense_by_category = $expense_by_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$expense_by_category_stmt->close();

// Get monthly trend (last 12 months)
$monthly_stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(month_date, '%b %Y') as month_label,
        COALESCE(revenue, 0) as revenue,
        COALESCE(expenses, 0) as expenses
    FROM (
        SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n MONTH), '%Y-%m-01') as month_date
        FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
              UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11) months
    ) calendar
    LEFT JOIN (
        SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month, SUM(amount) as revenue
        FROM tbl_revenues WHERE status = 'Verified'
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m-01')
    ) r ON calendar.month_date = r.month
    LEFT JOIN (
        SELECT DATE_FORMAT(expense_date, '%Y-%m-01') as month, SUM(amount) as expenses
        FROM tbl_expenses WHERE status = 'Released'
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m-01')
    ) e ON calendar.month_date = e.month
    ORDER BY month_date ASC
");
$monthly_stmt->execute();
$monthly_trend = $monthly_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monthly_stmt->close();

// Recent transactions
$recent_stmt = $conn->prepare("
    (SELECT 'Revenue' as type, r.reference_number, rc.category_name, r.amount, 
            r.transaction_date as trans_date, r.source as details, r.status
     FROM tbl_revenues r
     JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
     ORDER BY r.created_at DESC LIMIT 5)
    UNION
    (SELECT 'Expense' as type, e.reference_number, ec.category_name, e.amount,
            e.expense_date as trans_date, e.payee as details, e.status
     FROM tbl_expenses e
     JOIN tbl_expense_categories ec ON e.category_id = ec.category_id
     ORDER BY e.created_at DESC LIMIT 5)
    ORDER BY trans_date DESC LIMIT 10
");
$recent_stmt->execute();
$recent_transactions = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_stmt->close();

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-coins"></i> Financial Management
        </h1>
        <p class="page-subtitle">Track revenues, expenses, and budget allocations</p>
    </div>
    <div class="header-actions">
        <select class="year-selector" onchange="window.location.href='?year='+this.value">
            <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $fiscal_year ? 'selected' : ''; ?>>
                    FY <?php echo $y; ?>
                </option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
    <div class="stat-card balance">
        <div class="stat-icon">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Available Fund Balance</div>
            <div class="stat-value">₱<?php echo number_format($current_balance, 2); ?></div>
            <div class="stat-meta">
                <i class="fas fa-clock"></i> 
                Last updated: <?php echo $fund_data ? date('M d, Y g:i A', strtotime($fund_data['last_updated'])) : 'N/A'; ?>
            </div>
            <?php if ($total_allocated_budget > 0): ?>
            <div class="stat-meta" style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.2);">
                <i class="fas fa-info-circle"></i> 
                ₱<?php echo number_format($total_allocated_budget, 2); ?> reserved in budgets
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-card revenue">
        <div class="stat-icon">
            <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Revenue (FY <?php echo $fiscal_year; ?>)</div>
            <div class="stat-value">₱<?php echo number_format($total_revenue, 2); ?></div>
            <div class="stat-meta">
                <a href="revenues.php" class="stat-link">View all revenues <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <div class="stat-card expense">
        <div class="stat-icon">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Total Expenses (FY <?php echo $fiscal_year; ?>)</div>
            <div class="stat-value">₱<?php echo number_format($total_expenses, 2); ?></div>
            <div class="stat-meta">
                <a href="expenses.php" class="stat-link">View all expenses <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <div class="stat-card pending">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Pending Expenses</div>
            <div class="stat-value"><?php echo $pending_expenses; ?></div>
            <div class="stat-meta">
                <?php if ($pending_expenses > 0): ?>
                    <a href="expenses.php?status=pending" class="stat-link">Review pending <i class="fas fa-arrow-right"></i></a>
                <?php else: ?>
                    No pending expenses
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions-bar">
    <a href="revenue-add.php" class="action-btn revenue">
        <i class="fas fa-plus-circle"></i> Add Revenue
    </a>
    <a href="expenses-add.php" class="action-btn expense">
        <i class="fas fa-minus-circle"></i> Add Expense
    </a>
    <a href="budget.php" class="action-btn budget">
        <i class="fas fa-chart-pie"></i> Budget Management
    </a>
    <?php if ($user_role === 'Super Admin'): ?>
    <a href="fund-balance.php" class="action-btn balance">
        <i class="fas fa-wallet"></i> Fund Balance
    </a>
    <?php endif; ?>
    <a href="reports.php" class="action-btn report">
        <i class="fas fa-file-chart"></i> Generate Report
    </a>
</div>

<!-- Charts Row -->
<div class="charts-row">
    <!-- Monthly Trend Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-line"></i> Revenue vs Expenses (Last 12 Months)</h3>
        </div>
        <div class="chart-container">
            <canvas id="monthlyTrendChart"></canvas>
        </div>
    </div>

    <!-- Budget Utilization -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-pie"></i> Budget Utilization (FY <?php echo $fiscal_year; ?>)</h3>
        </div>
        <div class="budget-summary">
            <div class="budget-item">
                <span class="budget-label">Allocated:</span>
                <span class="budget-amount">₱<?php echo number_format($budget_data['total_allocated'], 2); ?></span>
            </div>
            <div class="budget-item">
                <span class="budget-label">Spent:</span>
                <span class="budget-amount expense-color">₱<?php echo number_format($budget_data['total_spent'], 2); ?></span>
            </div>
            <div class="budget-item">
                <span class="budget-label">Remaining:</span>
                <span class="budget-amount revenue-color">₱<?php echo number_format($budget_data['total_remaining'], 2); ?></span>
            </div>
            <?php 
            $utilization_pct = $budget_data['total_allocated'] > 0 
                ? ($budget_data['total_spent'] / $budget_data['total_allocated']) * 100 
                : 0;
            ?>
            <div class="progress-bar">
                <div class="progress-fill <?php echo $utilization_pct > 90 ? 'danger' : ($utilization_pct > 75 ? 'warning' : ''); ?>" 
                     style="width: <?php echo min($utilization_pct, 100); ?>%">
                </div>
            </div>
            <div class="budget-percentage"><?php echo number_format($utilization_pct, 1); ?>% utilized</div>
        </div>
    </div>
</div>

<!-- Category Breakdown -->
<div class="charts-row">
    <!-- Revenue by Category -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-bar"></i> Revenue by Category</h3>
        </div>
        <div class="category-list">
            <?php foreach ($revenue_by_category as $cat): ?>
                <?php if ($cat['total'] > 0): ?>
                    <div class="category-item">
                        <div class="category-info">
                            <span class="category-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            <span class="category-amount">₱<?php echo number_format($cat['total'], 2); ?></span>
                        </div>
                        <?php 
                        $pct = $total_revenue > 0 ? ($cat['total'] / $total_revenue) * 100 : 0;
                        ?>
                        <div class="category-bar">
                            <div class="category-fill revenue-bg" style="width: <?php echo $pct; ?>%"></div>
                        </div>
                        <div class="category-pct"><?php echo number_format($pct, 1); ?>%</div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty(array_filter($revenue_by_category, fn($c) => $c['total'] > 0))): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No revenue recorded for <?php echo $fiscal_year; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Expenses by Category -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-bar"></i> Expenses by Category</h3>
        </div>
        <div class="category-list">
            <?php foreach ($expense_by_category as $cat): ?>
                <?php if ($cat['total'] > 0): ?>
                    <div class="category-item">
                        <div class="category-info">
                            <span class="category-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            <span class="category-amount">₱<?php echo number_format($cat['total'], 2); ?></span>
                        </div>
                        <?php 
                        $pct = $total_expenses > 0 ? ($cat['total'] / $total_expenses) * 100 : 0;
                        ?>
                        <div class="category-bar">
                            <div class="category-fill expense-bg" style="width: <?php echo $pct; ?>%"></div>
                        </div>
                        <div class="category-pct"><?php echo number_format($pct, 1); ?>%</div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty(array_filter($expense_by_category, fn($c) => $c['total'] > 0))): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No expenses recorded for <?php echo $fiscal_year; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="chart-card">
    <div class="chart-header">
        <h3><i class="fas fa-history"></i> Recent Transactions</h3>
        <a href="transactions.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Category</th>
                    <th>Details</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center">No transactions found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_transactions as $trans): ?>
                        <tr>
                            <td>
                                <span class="transaction-type <?php echo strtolower($trans['type']); ?>">
                                    <i class="fas fa-<?php echo $trans['type'] === 'Revenue' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                                    <?php echo $trans['type']; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($trans['reference_number']); ?></td>
                            <td><?php echo htmlspecialchars($trans['category_name']); ?></td>
                            <td><?php echo htmlspecialchars($trans['details']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($trans['trans_date'])); ?></td>
                            <td class="<?php echo $trans['type'] === 'Revenue' ? 'revenue-color' : 'expense-color'; ?>">
                                ₱<?php echo number_format($trans['amount'], 2); ?>
                            </td>
                            <td><?php echo getStatusBadge($trans['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Monthly Trend Chart
const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
const monthlyChart = new Chart(monthlyCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($monthly_trend, 'month_label')); ?>,
        datasets: [{
            label: 'Revenue',
            data: <?php echo json_encode(array_column($monthly_trend, 'revenue')); ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true
        }, {
            label: 'Expenses',
            data: <?php echo json_encode(array_column($monthly_trend, 'expenses')); ?>,
            borderColor: '#ef4444',
            backgroundColor: 'rgba(239, 68, 68, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>
<style>
/* Document Fees Card - Completely Neutral Gray */
.stat-card.doc-fees {
    border-left: 4px solid #6b7280; /* Gray accent */
}

.stat-card.doc-fees .stat-icon {
    background: #f3f4f6; /* Light gray */
    color: #6b7280; /* Gray icon */
}

.stat-card.doc-fees:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1);
}

.stat-card a.stat-link {
    font-weight: 600;
    transition: opacity 0.2s;
}

.stat-card a.stat-link:hover {
    opacity: 0.8;
    text-decoration: underline;
}

/* Fund Balance Action Button */
.action-btn.balance {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.action-btn.balance:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}
</style>

<?php include '../../includes/footer.php'; ?>