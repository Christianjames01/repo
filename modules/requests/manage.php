<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

$page_title = 'My Document Requests';

// Get resident_id linked to the current user
$sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

$resident_id = $user_data['resident_id'] ?? null;

if (!$resident_id) {
    $_SESSION['error_message'] = 'Resident profile not found. Your account may not be linked to a resident profile.';
    header('Location: ../dashboard/index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $request_id = intval($_POST['request_id']);
    $payment_status = intval($_POST['payment_status']);
    
    // Get request details
    $request_sql = "SELECT r.*, rt.request_type_name, rt.fee, res.first_name, res.last_name 
                    FROM tbl_requests r 
                    JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id 
                    JOIN tbl_residents res ON r.resident_id = res.resident_id 
                    WHERE r.request_id = ?";
    
    $request_stmt = $conn->prepare($request_sql);
    $request_stmt->bind_param("i", $request_id);
    $request_stmt->execute();
    $request_result = $request_stmt->get_result();
    $request_details = $request_result->fetch_assoc();
    $request_stmt->close();
    
    if ($request_details) {
        // Check if this is a new payment (changing from unpaid to paid)
        $is_new_payment = ($request_details['payment_status'] == 0 && $payment_status == 1);
        
        // Update payment status in tbl_requests
        $update_sql = "UPDATE tbl_requests SET payment_status = ?, payment_date = " . 
                      ($payment_status == 1 ? "NOW()" : "NULL") . " WHERE request_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $payment_status, $request_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            
            // If marking as paid for the first time AND there's a fee, create revenue record
            if ($is_new_payment && $request_details['fee'] > 0) {
                
                // Get Document Fees category (we know it exists with ID 9)
                $category_id = 9; // From your debug output
                
                // Generate revenue reference number
                $year = date('Y');
                $month = date('m');
                $ref_prefix = "REV-{$year}{$month}-";
                
                // Get last reference number
                $last_ref_sql = "SELECT reference_number FROM tbl_revenues 
                                WHERE reference_number LIKE ? 
                                ORDER BY revenue_id DESC LIMIT 1";
                $last_ref_stmt = $conn->prepare($last_ref_sql);
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
                
                // Prepare revenue data
                $source = trim($request_details['first_name'] . ' ' . $request_details['last_name']);
                $description = "Payment for " . $request_details['request_type_name'] . 
                               " (Request #" . str_pad($request_id, 5, '0', STR_PAD_LEFT) . ")";
                $amount = floatval($request_details['fee']);
                $transaction_date = date('Y-m-d');
                $receipt_number = "DOC-" . str_pad($request_id, 5, '0', STR_PAD_LEFT);
                $payment_method = isset($_POST['payment_method']) && !empty($_POST['payment_method']) ? 
                                  $_POST['payment_method'] : "Cash";
                
                // Insert revenue record with VERIFIED status
                $revenue_sql = "INSERT INTO tbl_revenues 
                               (reference_number, category_id, amount, source, description, 
                                transaction_date, receipt_number, payment_method, received_by, 
                                status, verified_by, verification_date, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Verified', ?, NOW(), NOW())";
                
                $revenue_stmt = $conn->prepare($revenue_sql);
                
                if (!$revenue_stmt) {
                    $_SESSION['error_message'] = 'Failed to prepare revenue statement: ' . $conn->error;
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();
                }
                
                $revenue_stmt->bind_param(
                    "sidsssssii", 
                    $reference_number,  // s - string
                    $category_id,       // i - integer (9)
                    $amount,            // d - double
                    $source,            // s - string (resident name)
                    $description,       // s - string
                    $transaction_date,  // s - string (Y-m-d)
                    $receipt_number,    // s - string (DOC-00001)
                    $payment_method,    // s - string (Cash, etc)
                    $user_id,           // i - integer (received_by)
                    $user_id            // i - integer (verified_by)
                );
                
                if ($revenue_stmt->execute()) {
                    $revenue_stmt->close();
                    
                    // Update fund balance
                    $balance_sql = "UPDATE tbl_fund_balance 
                                   SET current_balance = current_balance + ?, 
                                       last_updated = NOW(), 
                                       updated_by = ? 
                                   ORDER BY balance_id DESC LIMIT 1";
                    $balance_stmt = $conn->prepare($balance_sql);
                    $balance_stmt->bind_param("di", $amount, $user_id);
                    $balance_stmt->execute();
                    $balance_stmt->close();
                    
                    // Link revenue reference to request
                    $link_sql = "UPDATE tbl_requests 
                                SET revenue_reference = ? 
                                WHERE request_id = ?";
                    $link_stmt = $conn->prepare($link_sql);
                    $link_stmt->bind_param("si", $reference_number, $request_id);
                    $link_stmt->execute();
                    $link_stmt->close();
                    
                    $_SESSION['success_message'] = 
                        'Payment marked as paid and revenue record created successfully! ' .
                        'Revenue Reference: ' . $reference_number;
                    
                } else {
                    $_SESSION['error_message'] = 
                        'Payment status updated but failed to create revenue record. ' .
                        'Error: ' . $revenue_stmt->error;
                    $revenue_stmt->close();
                }
                
            } else {
                // No fee or already paid before
                $_SESSION['success_message'] = 'Payment status updated successfully!';
            }
            
        } else {
            $_SESSION['error_message'] = 'Failed to update payment status. Error: ' . $conn->error;
            $update_stmt->close();
        }
        
    } else {
        $_SESSION['error_message'] = 'Request not found.';
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get filters
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch requests with joins
$sql = "SELECT r.*, rt.request_type_name, rt.fee
        FROM tbl_requests r
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        WHERE r.resident_id = ?";

$params = [$resident_id];
$types = "i";

if ($status_filter) {
    $sql .= " AND r.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($search) {
    $sql .= " AND (rt.request_type_name LIKE ? OR r.purpose LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

// Get total records for pagination
$count_sql = str_replace("SELECT r.*, rt.request_type_name, rt.fee", "SELECT COUNT(*) as total", $sql);
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_records = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);
$count_stmt->close();

// Add ordering and limit
$sql .= " ORDER BY r.request_date DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

// Get stats
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
              SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
              SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
              FROM tbl_requests WHERE resident_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="mb-0"><i class="fas fa-file-alt me-2"></i>My Document Requests</h2>
            <p class="text-muted mb-0">View and track your document request history</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="new-request.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>New Request
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <?php
        $cards = [
            ['label' => 'Total Requests', 'value' => $stats['total'], 'icon' => 'fa-file-alt', 'color' => 'primary', 'status' => ''],
            ['label' => 'Pending', 'value' => $stats['pending'], 'icon' => 'fa-clock', 'color' => 'warning', 'status' => 'Pending'],
            ['label' => 'Approved', 'value' => $stats['approved'], 'icon' => 'fa-check-circle', 'color' => 'info', 'status' => 'Approved'],
            ['label' => 'Released', 'value' => $stats['released'], 'icon' => 'fa-check-double', 'color' => 'success', 'status' => 'Released'],
        ];
        foreach ($cards as $card):
        ?>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm stat-card h-100 <?php echo $status_filter === $card['status'] ? 'active' : ''; ?>" 
                 onclick="filterByStatus('<?php echo $card['status']; ?>')">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="mb-0 <?php echo 'text-' . $card['color']; ?>"><?php echo $card['value']; ?></h3>
                        <p class="text-muted small mb-0"><?php echo $card['label']; ?></p>
                    </div>
                    <div class="<?php echo 'text-' . $card['color']; ?>">
                        <i class="fas <?php echo $card['icon']; ?> fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter/Search -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <div class="search-box position-relative">
                        <i class="fas fa-search position-absolute top-50 translate-middle-y ms-2 text-muted"></i>
                        <input type="text" name="search" class="form-control ps-4" 
                               placeholder="Search by document type or purpose" 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter by Status</label>
                    <select name="status" class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Released" <?php echo $status_filter === 'Released' ? 'selected' : ''; ?>>Released</option>
                        <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                    <?php if ($status_filter || $search): ?>
                        <a href="?" class="btn btn-outline-secondary btn-sm mt-1"><i class="fas fa-times me-1"></i>Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if ($requests && $requests->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Request ID</th>
                            <th>Date</th>
                            <th>Document Type</th>
                            <th>Purpose</th>
                            <th>Fee</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($request = $requests->fetch_assoc()): 
                            $req_id = $request['request_id'] ?? 0;
                            $status = $request['status'] ?? '';
                        ?>
                        <tr>
                            <td>#<?php echo str_pad($req_id, 5, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo date('M d, Y', strtotime($request['request_date'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($request['request_type_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($request['purpose'] ?? ''); ?></td>
                            <td>
                                <?php 
                                if (($request['fee'] ?? 0) > 0) echo '₱' . number_format($request['fee'], 2);
                                else echo '<span class="badge bg-light text-dark">Free</span>';
                                ?>
                            </td>
                            <td>
                                <?php if ($request['payment_status']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Paid</span>
                                <?php else: ?>
                                    <?php if (($request['fee'] ?? 0) > 0): ?>
                                        <span class="badge bg-warning text-dark badge-pulse"><i class="fas fa-exclamation-circle me-1"></i>Unpaid</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_class = [
                                    'Pending'=>'warning','Approved'=>'info','Released'=>'success','Rejected'=>'danger'
                                ];
                                $status_icon = [
                                    'Pending'=>'clock','Approved'=>'check-circle','Released'=>'check-double','Rejected'=>'times-circle'
                                ];
                                $class = $status_class[$status] ?? 'secondary';
                                $icon = $status_icon[$status] ?? 'info-circle';
                                ?>
                                <span class="badge bg-<?php echo $class; ?>">
                                    <i class="fas fa-<?php echo $icon; ?> me-1"></i><?php echo $status; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="view-request.php?id=<?php echo $req_id; ?>" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($user_role !== 'Resident' && $status === 'Pending'): ?>
                                    <a href="process.php?id=<?php echo $req_id; ?>" class="btn btn-sm btn-outline-success" data-bs-toggle="tooltip" title="Process">
                                        <i class="fas fa-check"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($user_role !== 'Resident' && ($request['fee'] ?? 0) > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning" 
                                            onclick="updatePayment(<?php echo $req_id; ?>, <?php echo $request['payment_status']; ?>)"
                                            data-bs-toggle="tooltip" title="Update Payment">
                                        <i class="fas fa-dollar-sign"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center mb-0">
                    <?php for ($i=1;$i<=$total_pages;$i++): ?>
                        <li class="page-item <?php echo $i==$page?'active':''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>

            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No requests found</h5>
                    <a href="new-request.php" class="btn btn-primary mt-2"><i class="fas fa-plus me-1"></i>Submit New Request</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Update Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-dollar-sign me-2"></i>Update Payment Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="payment_request_id">
                    
                    <div class="mb-3">
                        <label class="form-label">
                            Payment Status <span class="text-danger">*</span>
                        </label>
                        <select name="payment_status" id="payment_status_select" 
                                class="form-select" required onchange="togglePaymentMethod()">
                            <option value="0">Unpaid</option>
                            <option value="1">Paid</option>
                        </select>
                    </div>

                    <div class="mb-3" id="payment_method_group" style="display: none;">
                        <label class="form-label">
                            Payment Method <span class="text-danger">*</span>
                        </label>
                        <select name="payment_method" id="payment_method_select" class="form-select">
                            <option value="Cash">Cash</option>
                            <option value="Check">Check</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="GCash">GCash</option>
                            <option value="PayMaya">PayMaya</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="alert alert-success mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> When marked as "Paid", a verified revenue record 
                        will be automatically created in the Financial Management system under 
                        "Document Fees" category and will be immediately available for filtering 
                        and reporting.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" name="update_payment" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Update Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Tooltips
document.addEventListener('DOMContentLoaded', function(){
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(el => new bootstrap.Tooltip(el));
});

// Filter by status
function filterByStatus(status){
    document.getElementById('statusFilter').value = status;
    document.getElementById('filterForm').submit();
}

// Update payment modal
function updatePayment(requestId, currentPaymentStatus) {
    document.getElementById('payment_request_id').value = requestId;
    document.getElementById('payment_status_select').value = currentPaymentStatus;
    togglePaymentMethod();
    
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// Show/hide payment method based on payment status
function togglePaymentMethod() {
    const paymentStatus = document.getElementById('payment_status_select').value;
    const paymentMethodGroup = document.getElementById('payment_method_group');
    const paymentMethodSelect = document.getElementById('payment_method_select');
    
    if (paymentStatus === '1') {
        paymentMethodGroup.style.display = 'block';
        paymentMethodSelect.required = true;
    } else {
        paymentMethodGroup.style.display = 'none';
        paymentMethodSelect.required = false;
    }
}
</script>

<?php include '../../includes/footer.php'; ?>