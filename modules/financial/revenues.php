<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();

$page_title = 'Revenue Management';
$user_role = getCurrentUserRole();

if (!in_array($user_role, ['Super Admin', 'Treasurer'])) {
    header('Location: ../../modules/dashboard/index.php');
    exit();
}

$current_user_id = getCurrentUserId();

// ============================================================================
// HANDLE POST ACTIONS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {

        // VERIFY REVENUE
        if ($_POST['action'] === 'verify' && isset($_POST['revenue_id'])) {
            $revenue_id = intval($_POST['revenue_id']);

            $stmt = $conn->prepare("UPDATE tbl_revenues SET status = 'Verified', verified_by = ?, verification_date = NOW() WHERE revenue_id = ?");
            $stmt->bind_param("ii", $current_user_id, $revenue_id);
            if ($stmt->execute()) {
                $rev_stmt = $conn->prepare("SELECT amount FROM tbl_revenues WHERE revenue_id = ?");
                $rev_stmt->bind_param("i", $revenue_id);
                $rev_stmt->execute();
                $amount = $rev_stmt->get_result()->fetch_assoc()['amount'];
                $rev_stmt->close();

                $update_balance = $conn->prepare("UPDATE tbl_fund_balance SET current_balance = current_balance + ?, updated_by = ?, last_updated = NOW() ORDER BY balance_id DESC LIMIT 1");
                $update_balance->bind_param("di", $amount, $current_user_id);
                $update_balance->execute();
                $update_balance->close();

                $_SESSION['success_message'] = "Revenue verified successfully!";
            }
            $stmt->close();
            header('Location: revenues.php');
            exit();
        }

        // REJECT/CANCEL REVENUE
        elseif ($_POST['action'] === 'cancel' && isset($_POST['revenue_id'])) {
            $revenue_id = intval($_POST['revenue_id']);

            $stmt = $conn->prepare("UPDATE tbl_revenues SET status = 'Cancelled' WHERE revenue_id = ?");
            $stmt->bind_param("i", $revenue_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Revenue rejected successfully!";
            }
            $stmt->close();
            header('Location: revenues.php');
            exit();
        }

        // DELETE REVENUE
        elseif ($_POST['action'] === 'delete' && isset($_POST['revenue_id'])) {
            $revenue_id = intval($_POST['revenue_id']);
            $stmt = $conn->prepare("DELETE FROM tbl_revenues WHERE revenue_id = ? AND status = 'Pending'");
            $stmt->bind_param("i", $revenue_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Revenue deleted successfully!";
            }
            $stmt->close();
            header('Location: revenues.php');
            exit();
        }
    }
}

// ============================================================================
// FILTERS
// ============================================================================
$status_filter   = isset($_GET['status'])   ? $_GET['status']          : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$date_from       = isset($_GET['date_from'])? $_GET['date_from']        : '';
$date_to         = isset($_GET['date_to'])  ? $_GET['date_to']          : '';
$search          = isset($_GET['search'])   ? trim($_GET['search'])     : '';
// Filter only document-payment revenues
$doc_only        = isset($_GET['doc_only']) && $_GET['doc_only'] === '1';

// Pagination
$page            = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 15;
$offset          = ($page - 1) * $records_per_page;

// Build WHERE
$where_clauses = [];
$params = [];
$types  = '';

if ($status_filter) {
    $where_clauses[] = "r.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

if ($category_filter > 0) {
    $where_clauses[] = "r.category_id = ?";
    $params[] = $category_filter;
    $types   .= 'i';
}

if ($date_from) {
    $where_clauses[] = "r.transaction_date >= ?";
    $params[] = $date_from;
    $types   .= 's';
}

if ($date_to) {
    $where_clauses[] = "r.transaction_date <= ?";
    $params[] = $date_to;
    $types   .= 's';
}

if ($search) {
    $where_clauses[] = "(r.reference_number LIKE ? OR r.source LIKE ? OR r.description LIKE ?)";
    $sp = "%$search%";
    $params[] = $sp; $params[] = $sp; $params[] = $sp;
    $types   .= 'sss';
}

// Filter: only document-payment records
if ($doc_only) {
    $where_clauses[] = "r.description LIKE '%Payment for%Request #%'";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Total count
$count_sql  = "SELECT COUNT(*) as total FROM tbl_revenues r $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = ceil($total_records / $records_per_page);

// Fetch revenues
$sql = "SELECT r.*, rc.category_name,
        u1.username as received_by_name,
        u2.username as verified_by_name
        FROM tbl_revenues r
        LEFT JOIN tbl_revenue_categories rc ON r.category_id = rc.category_id
        LEFT JOIN tbl_users u1 ON r.received_by  = u1.user_id
        LEFT JOIN tbl_users u2 ON r.verified_by  = u2.user_id
        $where_sql
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?";

$fetch_params = $params;
$fetch_types  = $types . 'ii';
$fetch_params[] = $records_per_page;
$fetch_params[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($fetch_types, ...$fetch_params);
$stmt->execute();
$revenues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Categories for filter
$categories = fetchAll($conn, "SELECT * FROM tbl_revenue_categories WHERE is_active = 1 ORDER BY category_name");

// Summary stats
$total_pending  = fetchOne($conn, "SELECT COALESCE(SUM(amount),0) as total FROM tbl_revenues WHERE status='Pending'")['total'];
$total_verified = fetchOne($conn, "SELECT COALESCE(SUM(amount),0) as total FROM tbl_revenues WHERE status='Verified'")['total'];
// Total from document payments specifically
$total_doc_payments = fetchOne($conn, "SELECT COALESCE(SUM(amount),0) as total FROM tbl_revenues WHERE status='Verified' AND description LIKE '%Payment for%Request #%'")['total'];

$extra_css = '<link rel="stylesheet" href="../../assets/css/financial.css">';
include '../../includes/header.php';
?>

<div class="financial-header">
    <div>
        <h1 class="page-title"><i class="fas fa-arrow-down"></i> Revenue Management</h1>
        <p class="page-subtitle">Track and manage all barangay revenue</p>
    </div>
    <div class="header-actions">
        <a href="revenue-add.php" class="action-btn revenue">
            <i class="fas fa-plus-circle"></i> Add Revenue
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
    <div class="stat-card revenue">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details">
            <div class="stat-label">Verified Revenue</div>
            <div class="stat-value">₱<?php echo number_format($total_verified, 2); ?></div>
        </div>
    </div>
    <div class="stat-card pending">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-details">
            <div class="stat-label">Pending Verification</div>
            <div class="stat-value">₱<?php echo number_format($total_pending, 2); ?></div>
        </div>
    </div>
    <!-- Document Payments card — click to filter -->
    <div class="stat-card" style="background: linear-gradient(135deg,#eff6ff,#dbeafe); cursor:pointer;"
         onclick="window.location.href='revenues.php?doc_only=1'">
        <div class="stat-icon" style="background:rgba(59,130,246,0.15); color:#2563eb;">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Document Payments</div>
            <div class="stat-value" style="color:#1d4ed8;">₱<?php echo number_format($total_doc_payments, 2); ?></div>
            <div style="font-size:0.75rem;color:#3b82f6;margin-top:0.25rem;">
                <i class="fas fa-filter" style="margin-right:3px;"></i>Click to filter
            </div>
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
                    <option value="Pending"   <?php echo $status_filter === 'Pending'   ? 'selected' : ''; ?>>Pending</option>
                    <option value="Verified"  <?php echo $status_filter === 'Verified'  ? 'selected' : ''; ?>>Verified</option>
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
                <input type="text" name="search" class="form-control" placeholder="Reference, source..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="doc_only" class="form-control">
                    <option value="0" <?php echo !$doc_only ? 'selected' : ''; ?>>All Revenue</option>
                    <option value="1" <?php echo $doc_only  ? 'selected' : ''; ?>>Document Payments Only</option>
                </select>
            </div>
            <div class="form-group" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                <button type="submit" class="action-btn budget" style="flex: 1;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="revenues.php" class="action-btn report" style="flex: 1;">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </div>
    </form>
    <?php if ($doc_only): ?>
    <div style="margin-top: 0.75rem; padding: 0.6rem 1rem; background: #eff6ff; border-radius: 8px; border-left: 4px solid #3b82f6; font-size: 0.875rem; color: #1d4ed8;">
        <i class="fas fa-filter me-1"></i>
        Showing <strong>Document Payment</strong> revenues only —
        <a href="revenues.php" style="color: #1d4ed8; text-decoration: underline;">Clear filter</a>
    </div>
    <?php endif; ?>
</div>

<!-- Revenue Table -->
<div class="chart-card">
    <div class="chart-header">
        <h3>
            <i class="fas fa-list"></i>
            <?php if ($doc_only): ?>
                Document Payment Records
            <?php else: ?>
                Revenue Records
            <?php endif; ?>
            (<?php echo number_format($total_records); ?> total)
        </h3>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference #</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Source / Document</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Received By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($revenues)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 2.5rem; color: #9ca3af;">
                            <i class="fas fa-inbox" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
                            No revenue records found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($revenues as $rev):
                        // Detect if this came from a document payment
                        $is_doc_payment = (
                            strpos($rev['description'] ?? '', 'Payment for') !== false &&
                            strpos($rev['description'] ?? '', 'Request #') !== false
                        );

                        // ── FIX: regex uses 0* so it matches both "Request #5" and "Request #00005" ──
                        // intval() normalises the captured ID so the link always uses the plain number.
                        $doc_type   = '';
                        $request_id = '';
                        if ($is_doc_payment) {
                            if (preg_match('/Payment for (.+?) \(Request #0*(\d+)\)/', $rev['description'], $m)) {
                                $doc_type   = $m[1];
                                $request_id = intval($m[2]); // plain integer, no zero-padding
                            }
                        }
                    ?>
                        <tr style="<?php echo $is_doc_payment ? 'background: #f0f9ff;' : ''; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($rev['reference_number']); ?></strong>
                                <?php if ($is_doc_payment): ?>
                                    <br><span class="doc-payment-badge"><i class="fas fa-file-alt"></i> Doc Payment</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($rev['transaction_date'])); ?></td>
                            <td><?php echo htmlspecialchars($rev['category_name'] ?? '—'); ?></td>
                            <td>
                                <?php
                                // Source field format: "Name – DocType"
                                // Split on the em-dash to show resident name cleanly
                                $source_parts = explode(' – ', $rev['source'], 2);
                                echo htmlspecialchars($source_parts[0]); // Resident name
                                ?>
                                <?php if ($is_doc_payment && $doc_type): ?>
                                    <br>
                                    <span style="font-size:0.8rem; color:#2563eb;">
                                        <i class="fas fa-file-alt" style="margin-right:3px;"></i>
                                        <?php echo htmlspecialchars($doc_type); ?>
                                        <?php if ($request_id): ?>
                                            — <a href="../requests/view-request.php?id=<?php echo $request_id; ?>"
                                                 style="color:#2563eb; text-decoration:none;"
                                                 title="View request">
                                                Request #<?php echo str_pad($request_id, 5, '0', STR_PAD_LEFT); ?>
                                                <i class="fas fa-external-link-alt" style="font-size:0.7rem;"></i>
                                              </a>
                                        <?php endif; ?>
                                    </span>
                                <?php elseif (isset($source_parts[1])): ?>
                                    <br><span style="font-size:0.8rem;color:#6b7280;"><?php echo htmlspecialchars($source_parts[1]); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="revenue-color"><strong>₱<?php echo number_format($rev['amount'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($rev['payment_method']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($rev['received_by_name'] ?? '—'); ?>
                                <?php if ($rev['verified_by_name'] && $rev['status'] === 'Verified'): ?>
                                    <br><small style="color:#6b7280; font-size:0.75rem;">
                                        <i class="fas fa-check-circle" style="color:#059669;"></i>
                                        Verified by <?php echo htmlspecialchars($rev['verified_by_name']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo getStatusBadge($rev['status']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-icon" onclick='viewRevenue(<?php echo json_encode($rev); ?>)' title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($rev['status'] === 'Pending'): ?>
                                        <button class="btn-icon success" onclick='openApproveModal(<?php echo json_encode($rev); ?>)' title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn-icon danger" onclick='openRejectModal(<?php echo json_encode($rev); ?>)' title="Reject">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($is_doc_payment && $request_id): ?>
                                        <a class="btn-icon" href="../requests/view-request.php?id=<?php echo $request_id; ?>"
                                           title="View Document Request" style="text-decoration:none;">
                                            <i class="fas fa-file-alt"></i>
                                        </a>
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
            $base_url = 'revenues.php?' . http_build_query($query_params) . '&page=';
            ?>
            <?php if ($page > 1): ?>
                <a href="<?php echo $base_url . ($page - 1); ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="<?php echo $base_url . $i; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="<?php echo $base_url . ($page + 1); ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- View Revenue Modal -->
<div id="viewRevenueModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title">Revenue Details</h2>
            <button class="close-btn" onclick="closeModal('viewRevenueModal')">&times;</button>
        </div>
        <div id="revenueDetails" class="details-grid"></div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-check-circle" style="color: #059669;"></i> Approve Revenue</h2>
            <button class="close-btn" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <form method="POST" id="approveForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="revenue_id" id="approve_revenue_id">
                <div class="confirmation-message">
                    <p><strong>Are you sure you want to approve this revenue?</strong></p>
                    <div id="approveRevenueInfo" class="revenue-info"></div>
                </div>
                <div class="form-group" style="margin-top: 1.5rem;">
                    <label>Remarks (Optional)</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Add any notes or comments..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn-success"><i class="fas fa-check"></i> Approve Revenue</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-times-circle" style="color: #dc2626;"></i> Reject Revenue</h2>
            <button class="close-btn" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <form method="POST" id="rejectForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="revenue_id" id="reject_revenue_id">
                <div class="confirmation-message">
                    <p><strong>Are you sure you want to reject this revenue?</strong></p>
                    <div id="rejectRevenueInfo" class="revenue-info"></div>
                </div>
                <div class="form-group" style="margin-top: 1.5rem;">
                    <label>Reason for Rejection <span style="color: #dc2626;">*</span></label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Please provide a reason for rejection..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn-danger"><i class="fas fa-times"></i> Reject Revenue</button>
            </div>
        </form>
    </div>
</div>

<style>
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}
.form-group label { display: block; font-weight: 500; margin-bottom: 0.5rem; color: #374151; font-size: 0.9rem; }
.form-control { width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; }
.form-control:focus { outline: none; border-color: #3b82f6; }
.action-buttons { display: flex; gap: 0.5rem; align-items: center; }
.btn-icon { padding: 0.5rem; border: none; background: #f3f4f6; color: #374151; border-radius: 6px; cursor: pointer; transition: all 0.2s; }
.btn-icon:hover { background: #e5e7eb; }
.btn-icon.success { background: #d1fae5; color: #065f46; }
.btn-icon.success:hover { background: #a7f3d0; }
.btn-icon.danger { background: #fee2e2; color: #991b1b; }
.btn-icon.danger:hover { background: #fecaca; }

/* Document payment badge */
.doc-payment-badge {
    display: inline-block;
    margin-top: 4px;
    font-size: 0.72rem;
    font-weight: 700;
    background: #dbeafe;
    color: #1d4ed8;
    padding: 2px 8px;
    border-radius: 20px;
    letter-spacing: 0.3px;
}

.pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #f3f4f6; }
.page-link { padding: 0.5rem 0.875rem; border: 1px solid #d1d5db; background: white; color: #374151; text-decoration: none; border-radius: 6px; transition: all 0.2s; }
.page-link:hover { background: #f9fafb; border-color: #9ca3af; }
.page-link.active { background: #3b82f6; color: white; border-color: #3b82f6; }
.details-grid { display: grid; gap: 1rem; padding: 1.5rem; }
.detail-item { display: flex; justify-content: space-between; padding: 0.75rem; background: #f9fafb; border-radius: 6px; }
.detail-label { font-weight: 600; color: #6b7280; }
.detail-value { color: #1f2937; font-weight: 500; text-align: right; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
.modal.show { display: flex; }
.modal-content { background: white; border-radius: 12px; max-width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 2px solid #f3f4f6; }
.modal-title { margin: 0; font-size: 1.25rem; color: #1f2937; display: flex; align-items: center; gap: 0.5rem; }
.modal-body { padding: 1.5rem; }
.modal-footer { padding: 1.5rem; border-top: 2px solid #f3f4f6; display: flex; justify-content: flex-end; gap: 0.75rem; }
.close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
.close-btn:hover { background: #f3f4f6; color: #1f2937; }
.confirmation-message { background: #f9fafb; padding: 1rem; border-radius: 8px; border-left: 4px solid #3b82f6; }
.confirmation-message p { margin: 0 0 1rem 0; color: #1f2937; }
.revenue-info { background: white; padding: 1rem; border-radius: 6px; font-size: 0.95rem; }
.revenue-info-item { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6; }
.revenue-info-item:last-child { border-bottom: none; }
.revenue-info-label { font-weight: 600; color: #6b7280; }
.revenue-info-value { color: #1f2937; font-weight: 500; }
.btn-secondary { padding: 0.625rem 1.25rem; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
.btn-secondary:hover { background: #e5e7eb; }
.btn-success { padding: 0.625rem 1.25rem; background: #059669; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 0.5rem; }
.btn-success:hover { background: #047857; }
.btn-danger { padding: 0.625rem 1.25rem; background: #dc2626; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 0.5rem; }
.btn-danger:hover { background: #b91c1c; }
</style>

<script>
function viewRevenue(revenue) {
    const modal   = document.getElementById('viewRevenueModal');
    const details = document.getElementById('revenueDetails');

    const isDocPayment = revenue.description &&
        revenue.description.includes('Payment for') &&
        revenue.description.includes('Request #');

    // ── FIX: 0* handles zero-padded IDs like "Request #00005" ──
    let docLink = '';
    if (isDocPayment) {
        const m = revenue.description.match(/Payment for .+? \(Request #0*(\d+)\)/);
        if (m) {
            const reqId = parseInt(m[1]);
            const reqIdPadded = String(reqId).padStart(5, '0');
            docLink = `<div class="detail-item">
                <span class="detail-label">Document Request:</span>
                <span class="detail-value">
                    <a href="../requests/view-request.php?id=${reqId}"
                       style="color:#2563eb; font-weight:600;"
                       target="_blank">
                        Request #${reqIdPadded} <i class="fas fa-external-link-alt" style="font-size:0.7rem;"></i>
                    </a>
                </span>
            </div>`;
        }
    }

    details.innerHTML = `
        ${isDocPayment ? '<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:0.75rem 1rem;margin:0 1.5rem;border-radius:0 8px 8px 0;color:#1d4ed8;font-size:0.875rem;font-weight:600;"><i class="fas fa-file-alt" style="margin-right:6px;"></i>Document Payment Revenue</div>' : ''}
        <div class="detail-item">
            <span class="detail-label">Reference Number:</span>
            <span class="detail-value">${revenue.reference_number}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Category:</span>
            <span class="detail-value">${revenue.category_name || '—'}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Amount:</span>
            <span class="detail-value revenue-color"><strong>₱${parseFloat(revenue.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Source:</span>
            <span class="detail-value">${revenue.source}</span>
        </div>
        ${docLink}
        <div class="detail-item">
            <span class="detail-label">Description:</span>
            <span class="detail-value" style="text-align:right;max-width:60%;">${revenue.description || '—'}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Transaction Date:</span>
            <span class="detail-value">${new Date(revenue.transaction_date).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'})}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Payment Method:</span>
            <span class="detail-value">${revenue.payment_method}</span>
        </div>
        ${revenue.receipt_number ? `<div class="detail-item"><span class="detail-label">Receipt Number:</span><span class="detail-value">${revenue.receipt_number}</span></div>` : ''}
        <div class="detail-item">
            <span class="detail-label">Received By:</span>
            <span class="detail-value">${revenue.received_by_name || '—'}</span>
        </div>
        ${revenue.verified_by_name ? `
        <div class="detail-item">
            <span class="detail-label">Verified By:</span>
            <span class="detail-value">${revenue.verified_by_name}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Verification Date:</span>
            <span class="detail-value">${new Date(revenue.verification_date).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'})}</span>
        </div>` : ''}
        <div class="detail-item">
            <span class="detail-label">Status:</span>
            <span class="detail-value">${getStatusBadgeHTML(revenue.status)}</span>
        </div>
    `;

    modal.classList.add('show');
}

function openApproveModal(revenue) {
    const modal = document.getElementById('approveModal');
    document.getElementById('approve_revenue_id').value = revenue.revenue_id;
    document.getElementById('approveRevenueInfo').innerHTML = `
        <div class="revenue-info-item"><span class="revenue-info-label">Reference #:</span><span class="revenue-info-value">${revenue.reference_number}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Source:</span><span class="revenue-info-value">${revenue.source}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Amount:</span><span class="revenue-info-value" style="color:#059669;font-weight:700;">₱${parseFloat(revenue.amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Category:</span><span class="revenue-info-value">${revenue.category_name || '—'}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Date:</span><span class="revenue-info-value">${new Date(revenue.transaction_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>
    `;
    modal.classList.add('show');
}

function openRejectModal(revenue) {
    const modal = document.getElementById('rejectModal');
    document.getElementById('reject_revenue_id').value = revenue.revenue_id;
    document.getElementById('rejectRevenueInfo').innerHTML = `
        <div class="revenue-info-item"><span class="revenue-info-label">Reference #:</span><span class="revenue-info-value">${revenue.reference_number}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Source:</span><span class="revenue-info-value">${revenue.source}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Amount:</span><span class="revenue-info-value" style="color:#dc2626;font-weight:700;">₱${parseFloat(revenue.amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Category:</span><span class="revenue-info-value">${revenue.category_name || '—'}</span></div>
        <div class="revenue-info-item"><span class="revenue-info-label">Date:</span><span class="revenue-info-value">${new Date(revenue.transaction_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>
    `;
    modal.classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    if (modalId === 'approveModal') document.getElementById('approveForm').reset();
    else if (modalId === 'rejectModal') document.getElementById('rejectForm').reset();
}

function getStatusBadgeHTML(status) {
    const badges = {
        'Pending'  : '<span style="background:#fef3c7;color:#92400e;padding:0.25rem 0.75rem;border-radius:6px;font-weight:600;font-size:0.85rem;">Pending</span>',
        'Verified' : '<span style="background:#d1fae5;color:#065f46;padding:0.25rem 0.75rem;border-radius:6px;font-weight:600;font-size:0.85rem;">Verified</span>',
        'Cancelled': '<span style="background:#fee2e2;color:#991b1b;padding:0.25rem 0.75rem;border-radius:6px;font-weight:600;font-size:0.85rem;">Cancelled</span>'
    };
    return badges[status] || status;
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) event.target.classList.remove('show');
};
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
});
</script>

<?php include '../../includes/footer.php'; ?>