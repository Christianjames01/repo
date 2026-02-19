<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Financial Reports';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Staff', 'Admin'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_year = date('Y');
$report_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
$report_month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$report_type = isset($_GET['type']) ? $_GET['type'] : 'summary';

// Get data based on report type
$where_year = "YEAR(transaction_date) = ?";
$where_year_expense = "YEAR(expense_date) = ?";
$params_year = [$report_year];
$types_year = 'i';

if ($report_month > 0) {
    $where_year = "YEAR(transaction_date) = ? AND MONTH(transaction_date) = ?";
    $where_year_expense = "YEAR(expense_date) = ? AND MONTH(expense_date) = ?";
    $params_year = [$report_year, $report_month];
    $types_year = 'ii';
}

// Summary Report Data
if ($report_type === 'summary') {
    // Revenue by category
    $revenue_by_cat = fetchAll($conn, "
        SELECT rc.category_name, COALESCE(SUM(r.amount), 0) as total
        FROM tbl_revenue_categories rc
        LEFT JOIN tbl_revenues r ON rc.category_id = r.category_id 
            AND $where_year AND r.status = 'Verified'
        WHERE rc.is_active = 1
        GROUP BY rc.category_id, rc.category_name
        HAVING total > 0
        ORDER BY total DESC
    ", $params_year, $types_year);
    
    // Expenses by category
    $expenses_by_cat = fetchAll($conn, "
        SELECT ec.category_name, COALESCE(SUM(e.amount), 0) as total
        FROM tbl_expense_categories ec
        LEFT JOIN tbl_expenses e ON ec.category_id = e.category_id 
            AND $where_year_expense AND e.status = 'Released'
        WHERE ec.is_active = 1
        GROUP BY ec.category_id, ec.category_name
        HAVING total > 0
        ORDER BY total DESC
    ", $params_year, $types_year);
    
    $total_revenue = array_sum(array_column($revenue_by_cat, 'total'));
    $total_expenses = array_sum(array_column($expenses_by_cat, 'total'));
    $net_income = $total_revenue - $total_expenses;
}

// Detailed Report Data
elseif ($report_type === 'detailed') {
    // Get all revenues
    $revenues = fetchAll($conn, "
        SELECT r.*, rc.category_name, u.username as received_by_name
        FROM tbl_revenues r
        JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
        LEFT JOIN tbl_users u ON r.received_by = u.user_id
        WHERE $where_year AND r.status = 'Verified'
        ORDER BY r.transaction_date DESC
    ", $params_year, $types_year);
    
    // Get all expenses
    $expenses = fetchAll($conn, "
        SELECT e.*, ec.category_name, u.username as requested_by_name
        FROM tbl_expenses e
        JOIN tbl_expense_categories ec ON e.category_id = ec.category_id
        LEFT JOIN tbl_users u ON e.requested_by = u.user_id
        WHERE $where_year_expense AND e.status = 'Released'
        ORDER BY e.expense_date DESC
    ", $params_year, $types_year);
}

// Budget Report Data
elseif ($report_type === 'budget') {
    $budget_data = fetchAll($conn, "
        SELECT ba.*, ec.category_name
        FROM tbl_budget_allocations ba
        JOIN tbl_expense_categories ec ON ba.category_id = ec.category_id
        WHERE ba.fiscal_year = ? AND ba.status = 'Approved'
        ORDER BY ec.category_name
    ", [$report_year], 'i');
    
    $total_budget_allocated = array_sum(array_column($budget_data, 'allocated_amount'));
    $total_budget_spent = array_sum(array_column($budget_data, 'spent_amount'));
    $total_budget_remaining = array_sum(array_column($budget_data, 'remaining_amount'));
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-file-chart"></i> Financial Reports
        </h1>
        <p class="page-subtitle">Generate and export financial reports</p>
    </div>
    <div class="header-actions">
        <button class="action-btn revenue" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- Report Filters -->
<div class="chart-card no-print" style="margin-bottom: 1.5rem;">
    <form method="GET" class="filter-form">
        <div class="filter-grid">
            <div class="form-group">
                <label>Report Type</label>
                <select name="type" class="form-control">
                    <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                    <option value="detailed" <?php echo $report_type === 'detailed' ? 'selected' : ''; ?>>Detailed Transactions</option>
                    <option value="budget" <?php echo $report_type === 'budget' ? 'selected' : ''; ?>>Budget Report</option>
                </select>
            </div>
            <div class="form-group">
                <label>Year</label>
                <select name="year" class="form-control">
                    <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $report_year ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Month (Optional)</label>
                <select name="month" class="form-control">
                    <option value="0">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $report_month ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <button type="submit" class="action-btn budget" style="flex: 1;">
                    <i class="fas fa-search"></i> Generate Report
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Report Header (Print) -->
<div class="report-header">
    <div class="report-logo">
        <img src="<?php echo $base_url; ?>/uploads/officials/brgy.png" alt="<?php echo BARANGAY_NAME; ?>" style="width: 80px; height: 80px;">
    </div>
    <div class="report-title-section">
        <h2 style="margin: 0; font-size: 1.5rem;">Republic of the Philippines</h2>
        <h3 style="margin: 0.25rem 0; font-size: 1.125rem;"><?php echo MUNICIPALITY; ?></h3>
        <h1 style="margin: 0.5rem 0; font-size: 1.75rem; font-weight: 700;"><?php echo BARANGAY_NAME; ?></h1>
        <p style="margin: 0.5rem 0 0 0; color: #6b7280;">Financial Report</p>
    </div>
</div>

<div class="report-info">
    <div>
        <strong>Report Type:</strong> 
        <?php 
        $type_names = ['summary' => 'Summary Report', 'detailed' => 'Detailed Transactions', 'budget' => 'Budget Report'];
        echo $type_names[$report_type]; 
        ?>
    </div>
    <div>
        <strong>Period:</strong> 
        <?php 
        if ($report_month > 0) {
            echo date('F', mktime(0, 0, 0, $report_month, 1)) . ' ' . $report_year;
        } else {
            echo 'Year ' . $report_year;
        }
        ?>
    </div>
    <div><strong>Generated:</strong> <?php echo date('F j, Y g:i A'); ?></div>
</div>

<?php if ($report_type === 'summary'): ?>
    <!-- Summary Report -->
    <div class="chart-card">
        <h3 class="section-title"><i class="fas fa-chart-bar"></i> Income Statement</h3>
        
        <div class="report-table">
            <table class="data-table">
                <thead>
                    <tr>
                        <th colspan="2" style="background: #d1fae5; color: #065f46;">REVENUE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenue_by_cat as $cat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                            <td class="text-right">₱<?php echo number_format($cat['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f9fafb; font-weight: 700;">
                        <td>Total Revenue</td>
                        <td class="text-right revenue-color">₱<?php echo number_format($total_revenue, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="report-table" style="margin-top: 2rem;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th colspan="2" style="background: #fee2e2; color: #991b1b;">EXPENSES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses_by_cat as $cat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                            <td class="text-right">₱<?php echo number_format($cat['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f9fafb; font-weight: 700;">
                        <td>Total Expenses</td>
                        <td class="text-right expense-color">₱<?php echo number_format($total_expenses, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="net-income" style="margin-top: 2rem; padding: 1.5rem; background: #f9fafb; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0 0 0.5rem 0; color: #6b7280;">Net Income</h3>
            <div style="font-size: 2rem; font-weight: 700; color: <?php echo $net_income >= 0 ? '#10b981' : '#ef4444'; ?>;">
                ₱<?php echo number_format($net_income, 2); ?>
            </div>
            <p style="margin: 0.5rem 0 0 0; color: #6b7280; font-size: 0.9rem;">
                <?php echo $net_income >= 0 ? 'Surplus' : 'Deficit'; ?>
            </p>
        </div>
    </div>

<?php elseif ($report_type === 'detailed'): ?>
    <!-- Detailed Report -->
    <div class="chart-card">
        <h3 class="section-title"><i class="fas fa-arrow-down"></i> Revenue Transactions</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Category</th>
                        <th>Source</th>
                        <th>Amount</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($revenues)): ?>
                        <tr><td colspan="6" class="text-center">No revenue transactions</td></tr>
                    <?php else: ?>
                        <?php 
                        $revenue_total = 0;
                        foreach ($revenues as $rev): 
                            $revenue_total += $rev['amount'];
                        ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($rev['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($rev['reference_number']); ?></td>
                                <td><?php echo htmlspecialchars($rev['category_name']); ?></td>
                                <td><?php echo htmlspecialchars($rev['source']); ?></td>
                                <td class="text-right">₱<?php echo number_format($rev['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($rev['payment_method']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f9fafb; font-weight: 700;">
                            <td colspan="4">TOTAL REVENUE</td>
                            <td class="text-right revenue-color">₱<?php echo number_format($revenue_total, 2); ?></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="chart-card" style="margin-top: 2rem;">
        <h3 class="section-title"><i class="fas fa-arrow-up"></i> Expense Transactions</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Category</th>
                        <th>Payee</th>
                        <th>Amount</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="6" class="text-center">No expense transactions</td></tr>
                    <?php else: ?>
                        <?php 
                        $expense_total = 0;
                        foreach ($expenses as $exp): 
                            $expense_total += $exp['amount'];
                        ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                                <td><?php echo htmlspecialchars($exp['reference_number']); ?></td>
                                <td><?php echo htmlspecialchars($exp['category_name']); ?></td>
                                <td><?php echo htmlspecialchars($exp['payee']); ?></td>
                                <td class="text-right">₱<?php echo number_format($exp['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($exp['payment_method']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f9fafb; font-weight: 700;">
                            <td colspan="4">TOTAL EXPENSES</td>
                            <td class="text-right expense-color">₱<?php echo number_format($expense_total, 2); ?></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($report_type === 'budget'): ?>
    <!-- Budget Report -->
    <div class="chart-card">
        <h3 class="section-title"><i class="fas fa-chart-pie"></i> Budget Allocation & Utilization</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Allocated</th>
                        <th>Spent</th>
                        <th>Remaining</th>
                        <th>Utilization %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($budget_data)): ?>
                        <tr><td colspan="5" class="text-center">No budget allocations</td></tr>
                    <?php else: ?>
                        <?php foreach ($budget_data as $budget): 
                            $utilization = $budget['allocated_amount'] > 0 ? ($budget['spent_amount'] / $budget['allocated_amount']) * 100 : 0;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($budget['category_name']); ?></td>
                                <td class="text-right">₱<?php echo number_format($budget['allocated_amount'], 2); ?></td>
                                <td class="text-right">₱<?php echo number_format($budget['spent_amount'], 2); ?></td>
                                <td class="text-right">₱<?php echo number_format($budget['remaining_amount'], 2); ?></td>
                                <td class="text-right"><?php echo number_format($utilization, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f9fafb; font-weight: 700;">
                            <td>TOTAL</td>
                            <td class="text-right">₱<?php echo number_format($total_budget_allocated, 2); ?></td>
                            <td class="text-right expense-color">₱<?php echo number_format($total_budget_spent, 2); ?></td>
                            <td class="text-right revenue-color">₱<?php echo number_format($total_budget_remaining, 2); ?></td>
                            <td class="text-right">
                                <?php echo $total_budget_allocated > 0 ? number_format(($total_budget_spent / $total_budget_allocated) * 100, 1) : '0.0'; ?>%
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Report Footer (Print) -->
<div class="report-footer">
    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">Prepared by</div>
            <div class="signature-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div class="signature-title">Finance Officer</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">Approved by</div>
            <div class="signature-name">_______________________</div>
            <div class="signature-title">Barangay Captain</div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print {
        display: none !important;
    }
    
    .financial-header,
    .sidebar,
    .header,
    .footer {
        display: none !important;
    }
    
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    
    .chart-card {
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    body {
        background: white !important;
    }
}

.report-header {
    text-align: center;
    padding: 2rem 0;
    border-bottom: 3px solid #1f2937;
    margin-bottom: 2rem;
}

.report-logo {
    margin-bottom: 1rem;
}

.report-info {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 2rem;
    text-align: center;
}

.section-title {
    font-size: 1.125rem;
    font-weight: 600;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #f3f4f6;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.text-right {
    text-align: right !important;
}

.report-footer {
    margin-top: 4rem;
    page-break-inside: avoid;
}

.signature-section {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 4rem;
    margin-top: 3rem;
}

.signature-box {
    text-align: center;
}

.signature-line {
    border-bottom: 2px solid #1f2937;
    margin-bottom: 0.5rem;
    height: 60px;
}

.signature-label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
}

.signature-name {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 0.25rem;
}

.signature-title {
    font-size: 0.9rem;
    color: #6b7280;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #374151;
    font-size: 0.9rem;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.form-control {
    width: 100%;
    padding: 0.625rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.95rem;
}
</style>

<?php include '../../includes/footer.php'; ?>