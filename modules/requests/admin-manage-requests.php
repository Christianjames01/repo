<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: ../dashboard/index.php');
    exit();
}

$page_title = 'Document Requests Management';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $request_id = intval($_POST['request_id']);
    $status = sanitizeInput($_POST['status']);
    $remarks = isset($_POST['remarks']) ? sanitizeInput($_POST['remarks']) : '';

    $request_query = "SELECT r.*, rt.request_type_name, res.first_name, res.last_name, u.user_id 
                      FROM tbl_requests r 
                      JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id 
                      JOIN tbl_residents res ON r.resident_id = res.resident_id 
                      JOIN tbl_users u ON res.resident_id = u.resident_id
                      WHERE r.request_id = ?";
    $req_stmt = $conn->prepare($request_query);
    $req_stmt->bind_param("i", $request_id);
    $req_stmt->execute();
    $request_details = $req_stmt->get_result()->fetch_assoc();
    $req_stmt->close();

    if ($request_details) {
        $sql = "UPDATE tbl_requests SET status = ?, processed_by = ?, processed_date = NOW() WHERE request_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $status, $user_id, $request_id);

        if ($stmt->execute()) {
            $notif_title = "Request Status Updated";
            $notif_message = "Your request for {$request_details['request_type_name']} is now {$status}";
            if (!empty($remarks)) $notif_message .= ". Remarks: {$remarks}";
            $notif_type = "request_" . strtolower($status);
            $reference_type = "request";

            $notif_sql = "INSERT INTO tbl_notifications 
                         (user_id, type, reference_type, reference_id, title, message, is_read, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_stmt->bind_param("ississ",
                $request_details['user_id'], $notif_type, $reference_type,
                $request_id, $notif_title, $notif_message
            );
            $notif_stmt->execute();
            $notif_stmt->close();
            $_SESSION['success_message'] = 'Request status updated successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to update request status.';
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = 'Request not found.';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=manage');
    exit();
}

// Handle payment update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $request_id = intval($_POST['request_id']);
    $payment_status = intval($_POST['payment_status']);

    $request_query = "SELECT r.*, rt.request_type_name, rt.fee, res.first_name, res.last_name, u.user_id 
                      FROM tbl_requests r 
                      JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id 
                      JOIN tbl_residents res ON r.resident_id = res.resident_id 
                      JOIN tbl_users u ON res.resident_id = u.resident_id
                      WHERE r.request_id = ?";
    $req_stmt = $conn->prepare($request_query);
    $req_stmt->bind_param("i", $request_id);
    $req_stmt->execute();
    $request_details = $req_stmt->get_result()->fetch_assoc();
    $req_stmt->close();

    if ($request_details) {
        $sql = "UPDATE tbl_requests SET payment_status = ? WHERE request_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $payment_status, $request_id);

        if ($stmt->execute()) {
            if ($payment_status == 1 && $request_details['fee'] > 0) {
                $table_check = $conn->query("SHOW TABLES LIKE 'tbl_revenues'");
                $revenue_table_exists = ($table_check && $table_check->num_rows > 0);

                if ($revenue_table_exists) {
                    $category_query = "SELECT category_id FROM tbl_revenue_categories WHERE category_name = 'Document Fees' LIMIT 1";
                    $category_result = $conn->query($category_query);

                    if ($category_result && $category_result->num_rows > 0) {
                        $category_id = $category_result->fetch_assoc()['category_id'];
                        $reference_number = 'REV-' . date('Ymd') . '-' . str_pad($request_id, 6, '0', STR_PAD_LEFT);

                        $revenue_sql = "INSERT INTO tbl_revenues 
                                       (reference_number, category_id, source, amount, description, 
                                        transaction_date, payment_method, received_by, status) 
                                       VALUES (?, ?, ?, ?, ?, NOW(), 'Cash', ?, 'Pending')";
                        $source = "{$request_details['first_name']} {$request_details['last_name']}";
                        $description = "Payment for {$request_details['request_type_name']} (Request #{$request_id})";

                        $revenue_stmt = $conn->prepare($revenue_sql);
                        if ($revenue_stmt) {
                            $revenue_stmt->bind_param("sissdi",
                                $reference_number, $category_id, $source,
                                $request_details['fee'], $description, $user_id
                            );
                            if ($revenue_stmt->execute()) {
                                $notif_title = "Payment Confirmed";
                                $notif_message = "Your payment of ₱" . number_format($request_details['fee'], 2) . " for {$request_details['request_type_name']} has been confirmed. Reference: {$reference_number}";
                                $notif_sql = "INSERT INTO tbl_notifications 
                                             (user_id, type, reference_type, reference_id, title, message, is_read, created_at) 
                                             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
                                $notif_stmt = $conn->prepare($notif_sql);
                                $notif_stmt->bind_param("ississ",
                                    $request_details['user_id'], 'payment_confirmed', 'request',
                                    $request_id, $notif_title, $notif_message
                                );
                                $notif_stmt->execute();
                                $notif_stmt->close();
                                $_SESSION['success_message'] = "Payment status updated and revenue record created! Reference: {$reference_number}";
                            } else {
                                $_SESSION['success_message'] = 'Payment status updated but failed to create revenue record.';
                            }
                            $revenue_stmt->close();
                        }
                    } else {
                        $conn->query("INSERT INTO tbl_revenue_categories (category_name, description, is_active) VALUES ('Document Fees', 'Revenue from document requests', 1)");
                        $_SESSION['success_message'] = 'Payment status updated! Document Fees category created.';
                    }
                } else {
                    $_SESSION['success_message'] = 'Payment status updated successfully!';
                }
            } else {
                $_SESSION['success_message'] = 'Payment status updated successfully!';
            }
        } else {
            $_SESSION['error_message'] = 'Failed to update payment status.';
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = 'Request not found.';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=manage');
    exit();
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'manage';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$request_type_filter = isset($_GET['request_type']) ? intval($_GET['request_type']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Statistics
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
              SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
              SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
              SUM(CASE WHEN payment_status = 1 THEN 1 ELSE 0 END) as paid
              FROM tbl_requests";
$stats = $conn->query($stats_sql)->fetch_assoc();

// Request types
$request_types = [];
$result = $conn->query("SELECT * FROM tbl_request_types ORDER BY request_type_name");
if ($result) {
    while ($row = $result->fetch_assoc()) $request_types[] = $row;
}

// Fetch requests
$manage_sql = "SELECT r.*, 
            res.first_name, res.last_name, res.email,
            rt.request_type_name, rt.fee,
            u.username as processed_by_name
        FROM tbl_requests r
        INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        LEFT JOIN tbl_users u ON r.processed_by = u.user_id
        WHERE 1=1";

$manage_params = [];
$manage_types = "";

if ($status_filter && $status_filter !== 'Paid') {
    $manage_sql .= " AND r.status = ?";
    $manage_params[] = $status_filter;
    $manage_types .= "s";
}

if ($status_filter === 'Paid') {
    $manage_sql .= " AND r.payment_status = 1";
}

if ($search) {
    $manage_sql .= " AND (res.first_name LIKE ? OR res.last_name LIKE ? OR rt.request_type_name LIKE ?)";
    $search_param = "%{$search}%";
    $manage_params[] = $search_param;
    $manage_params[] = $search_param;
    $manage_params[] = $search_param;
    $manage_types .= "sss";
}

$manage_sql .= " ORDER BY CASE r.status 
    WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 
    WHEN 'Released' THEN 3 WHEN 'Rejected' THEN 4 END, r.request_date DESC";

if (!empty($manage_params)) {
    $manage_stmt = $conn->prepare($manage_sql);
    $manage_stmt->bind_param($manage_types, ...$manage_params);
    $manage_stmt->execute();
    $manage_requests = $manage_stmt->get_result();
} else {
    $manage_requests = $conn->query($manage_sql);
}

// Report stats
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_requests,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN payment_status = 1 THEN 1 ELSE 0 END) as paid_requests,
    SUM(CASE WHEN payment_status = 1 THEN rt.fee ELSE 0 END) as total_revenue
    FROM tbl_requests r
    LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
    WHERE DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$report_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Type distribution
$stmt = $conn->prepare("SELECT rt.request_type_name, COUNT(*) as count
    FROM tbl_requests r
    LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
    WHERE DATE(r.request_date) BETWEEN ? AND ?
    GROUP BY r.request_type_id, rt.request_type_name ORDER BY count DESC");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$type_distribution = $stmt->get_result();
$stmt->close();

// Detailed report
$detail_sql = "SELECT r.*, res.first_name, res.last_name, rt.request_type_name, rt.fee
    FROM tbl_requests r
    INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
    LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
    WHERE DATE(r.request_date) BETWEEN ? AND ?";
$params = [$date_from, $date_to];
$types = "ss";
if ($active_tab === 'reports' && $status_filter) {
    $detail_sql .= " AND r.status = ?"; $params[] = $status_filter; $types .= "s";
}
if ($active_tab === 'reports' && $request_type_filter) {
    $detail_sql .= " AND r.request_type_id = ?"; $params[] = $request_type_filter; $types .= "i";
}
$detail_sql .= " ORDER BY r.request_date DESC";
$stmt = $conn->prepare($detail_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$report_requests = $stmt->get_result();
$stmt->close();

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    padding: 1.25rem 1.5rem;
    border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
}

.card-header h5 {
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
}

.active-card {
    border: 2px solid #0d6efd !important;
    background: linear-gradient(to bottom, #ffffff, #f8f9ff);
}

.active-card-warning {
    border: 2px solid #ffc107 !important;
    background: linear-gradient(to bottom, #ffffff, #fffbf0);
}

.active-card-info {
    border: 2px solid #0dcaf0 !important;
    background: linear-gradient(to bottom, #ffffff, #f0fcff);
}

.active-card-success {
    border: 2px solid #198754 !important;
    background: linear-gradient(to bottom, #ffffff, #f0f9f4);
}

.active-card-danger {
    border: 2px solid #dc3545 !important;
    background: linear-gradient(to bottom, #ffffff, #fff5f5);
}

.active-card-primary {
    border: 2px solid #0d6efd !important;
    background: linear-gradient(to bottom, #ffffff, #f8f9ff);
}

/* Table Enhancements */
.table {
    margin-bottom: 0;
}

.table thead th {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 700;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
}

.table tbody tr {
    transition: all var(--transition-speed) ease;
    border-bottom: 1px solid #f1f3f5;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
    transform: scale(1.01);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.request-row {
    transition: all 0.2s;
    background: white;
    cursor: pointer;
}

.request-row:hover {
    background: #f8f9fa;
    box-shadow: inset 3px 0 0 #0d6efd;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Enhanced Buttons */
.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 600;
    transition: all var(--transition-speed) ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn:active {
    transform: translateY(0);
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* Alert Enhancements */
.alert {
    border: none;
    border-radius: var(--border-radius);
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid;
}

.alert-success {
    background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
    border-left-color: #198754;
}

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

.alert i {
    font-size: 1.1rem;
}

/* Navigation Tabs */
.nav-tabs {
    border-bottom: 2px solid #e9ecef;
}

.nav-tabs .nav-link {
    color: #6c757d;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 600;
    padding: 1rem 1.5rem;
    transition: all var(--transition-speed) ease;
}

.nav-tabs .nav-link:hover {
    border-color: #e9ecef;
    color: #0d6efd;
    background: rgba(13, 110, 253, 0.05);
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom-color: #0d6efd;
    background: transparent;
}

/* Hover Preview Card */
.req-preview-card {
    position: fixed;
    z-index: 9999;
    width: 340px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.10);
    border: 1px solid #e9ecef;
    overflow: hidden;
    pointer-events: none;
    animation: previewFadeIn 0.18s ease;
}

@keyframes previewFadeIn {
    from { opacity: 0; transform: translateY(6px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.req-preview-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f0f0f0;
}

.req-preview-icon {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}

.req-preview-header-text {
    flex: 1;
    min-width: 0;
}

.req-preview-type {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 2px;
}

.req-preview-title {
    font-size: 0.92rem;
    font-weight: 700;
    color: #212529;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.req-preview-body {
    padding: 12px 16px 14px;
}

.req-preview-row {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin-bottom: 7px;
    font-size: 0.82rem;
}

.req-preview-label {
    color: #adb5bd;
    font-weight: 600;
    min-width: 72px;
    flex-shrink: 0;
}

.req-preview-value {
    color: #495057;
}

.req-preview-footer {
    font-size: 0.75rem;
    color: #adb5bd;
    display: flex;
    align-items: center;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f0f0f0;
    gap: 8px;
}

.bg-warning-subtle { background-color: rgba(255,193,7,0.12); }
.bg-info-subtle { background-color: rgba(13,202,240,0.12); }
.bg-success-subtle { background-color: rgba(25,135,84,0.12); }
.bg-danger-subtle { background-color: rgba(220,53,69,0.12); }
.bg-secondary-subtle { background-color: rgba(108,117,125,0.12); }

/* Form Enhancements */
.form-label {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
}

.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
}

/* Modal Enhancements */
.modal-content {
    border: none;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-lg);
}

.modal-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e9ecef;
    border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
    padding: 1.5rem;
}

.modal-title {
    font-weight: 700;
    font-size: 1.25rem;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
    padding: 1.25rem 2rem;
    border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

@media print {
    .no-print, .nav-tabs, .btn, form { display: none !important; }
}

@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stat-card {
        margin-bottom: 1rem;
    }
    
    .table thead th {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
    
    .table tbody td {
        font-size: 0.875rem;
        padding: 0.75rem;
    }
    
    .req-preview-card {
        display: none !important;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-tasks me-2 text-primary"></i>
                        Document Requests Management
                    </h2>
                    <p class="text-muted mb-0">Manage and analyze document requests</p>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <a href="?tab=manage" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo ($active_tab==='manage' && !$status_filter) ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Total</p>
                                <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                <i class="fas fa-tasks fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="?tab=manage&status=Pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo ($status_filter==='Pending') ? 'active-card-warning' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Pending</p>
                                <h3 class="mb-0"><?php echo $stats['pending']; ?></h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                                <i class="fas fa-clock fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="?tab=manage&status=Approved" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo ($status_filter==='Approved') ? 'active-card-info' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Approved</p>
                                <h3 class="mb-0"><?php echo $stats['approved']; ?></h3>
                            </div>
                            <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                                <i class="fas fa-check-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="?tab=manage&status=Released" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo ($status_filter==='Released') ? 'active-card-success' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Released</p>
                                <h3 class="mb-0"><?php echo $stats['released']; ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                                <i class="fas fa-check-double fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="?tab=manage&status=Rejected" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo ($status_filter==='Rejected') ? 'active-card-danger' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Rejected</p>
                                <h3 class="mb-0"><?php echo $stats['rejected']; ?></h3>
                            </div>
                            <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3">
                                <i class="fas fa-times-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="?tab=manage&status=Paid" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo ($status_filter==='Paid') ? 'active-card-primary' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Paid</p>
                                <h3 class="mb-0"><?php echo $stats['paid']; ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                <i class="fas fa-dollar-sign fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4 no-print" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'manage' ? 'active' : ''; ?>" href="?tab=manage">
                <i class="fas fa-tasks me-2"></i>Manage Requests
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'reports' ? 'active' : ''; ?>" href="?tab=reports">
                <i class="fas fa-chart-bar me-2"></i>Reports & Analytics
            </a>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ── Manage Tab ── -->
        <div class="tab-pane <?php echo $active_tab === 'manage' ? 'show active' : ''; ?>">

            <!-- Search only (no status filter) -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="tab" value="manage">
                        <?php if ($status_filter): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <?php endif; ?>
                        <div class="col-md-10">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control"
                                   placeholder="Search by name or document type..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i>Search
                                </button>
                            </div>
                        </div>
                    </form>
                    <?php if ($status_filter): ?>
                    <div class="mt-2">
                        <span class="text-muted small">Filtering by: </span>
                        <?php
                        $badge_colors = ['Pending'=>'warning','Approved'=>'info','Released'=>'success','Rejected'=>'danger','Paid'=>'primary'];
                        $bc = $badge_colors[$status_filter] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $bc ?>"><?= htmlspecialchars($status_filter) ?></span>
                        <a href="?tab=manage" class="ms-2 small text-muted">
                            <i class="fas fa-times me-1"></i>Clear filter
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Requests Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-list me-2"></i>
                        <?php 
                        if ($status_filter) {
                            echo htmlspecialchars($status_filter) . ' Requests';
                        } else {
                            echo 'All Requests';
                        }
                        ?>
                        <span class="badge bg-primary ms-2"><?php echo $manage_requests ? $manage_requests->num_rows : 0; ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Hover over a row to preview details. Click a row to open the full request page.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Resident</th>
                                    <th>Document Type</th>
                                    <th>Purpose</th>
                                    <th>Fee</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($manage_requests && $manage_requests->num_rows > 0): ?>
                                    <?php while ($request = $manage_requests->fetch_assoc()):

                                        // Icon + color per status
                                        $icon = 'fa-file-alt'; $icon_color = 'secondary';
                                        if ($request['status'] === 'Pending')  { $icon = 'fa-clock';        $icon_color = 'warning'; }
                                        if ($request['status'] === 'Approved') { $icon = 'fa-check-circle'; $icon_color = 'info'; }
                                        if ($request['status'] === 'Released') { $icon = 'fa-check-double'; $icon_color = 'success'; }
                                        if ($request['status'] === 'Rejected') { $icon = 'fa-times-circle'; $icon_color = 'danger'; }

                                        $full_name   = htmlspecialchars(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
                                        $doc_type    = htmlspecialchars($request['request_type_name'] ?? 'N/A');
                                        $purpose_full= $request['purpose'] ?? '';
                                        $fee_text    = $request['fee'] > 0 ? '₱' . number_format($request['fee'], 2) : 'Free';
                                        $pay_text    = $request['payment_status'] ? 'Paid' : ($request['fee'] > 0 ? 'Unpaid' : 'N/A');

                                        // Preview data
                                        $preview_title   = $doc_type;
                                        $preview_message = htmlspecialchars(mb_strimwidth($purpose_full, 0, 120, '...'));
                                        $preview_time    = htmlspecialchars(date('M j, Y g:i A', strtotime($request['request_date'])));
                                        $preview_type    = htmlspecialchars($request['status']);
                                        $preview_name    = $full_name;
                                        $preview_fee     = htmlspecialchars($fee_text);
                                        $preview_pay     = htmlspecialchars($pay_text);
                                        $preview_email   = htmlspecialchars($request['email'] ?? 'N/A');
                                    ?>
                                    <tr class="request-row"
                                        data-preview-title="<?= $preview_title ?>"
                                        data-preview-message="<?= $preview_message ?>"
                                        data-preview-icon="<?= $icon ?>"
                                        data-preview-color="<?= $icon_color ?>"
                                        data-preview-type="<?= $preview_type ?>"
                                        data-preview-time="<?= $preview_time ?>"
                                        data-preview-name="<?= $preview_name ?>"
                                        data-preview-fee="<?= $preview_fee ?>"
                                        data-preview-pay="<?= $preview_pay ?>"
                                        data-preview-email="<?= $preview_email ?>"
                                        onclick="window.location.href='view-request.php?id=<?= $request['request_id'] ?>'"  >

                                        <td><strong class="text-primary">#<?php echo str_pad($request['request_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                        <td>
                                            <i class="fas fa-calendar-alt text-muted me-1"></i>
                                            <small><?php echo date('M d, Y', strtotime($request['request_date'])); ?></small>
                                        </td>
                                        <td>
                                            <i class="fas fa-user text-muted me-1"></i>
                                            <strong><?= $full_name ?></strong><br>
                                            <small class="text-muted"><i class="fas fa-envelope me-1"></i><?= $preview_email ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= $doc_type ?></span>
                                        </td>
                                        <td>
                                            <?php if (strlen($purpose_full) > 40): ?>
                                                <span title="<?= htmlspecialchars($purpose_full) ?>">
                                                    <?= htmlspecialchars(substr($purpose_full, 0, 40)) ?>...
                                                </span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($purpose_full) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($request['fee'] > 0): ?>
                                                <strong class="text-success">₱<?php echo number_format($request['fee'], 2); ?></strong>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark">Free</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($request['payment_status']): ?>
                                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Paid</span>
                                            <?php elseif ($request['fee'] > 0): ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-exclamation me-1"></i>Unpaid</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $sc = ['Pending'=>'warning','Approved'=>'info','Released'=>'success','Rejected'=>'danger'];
                                            $si = ['Pending'=>'clock','Approved'=>'check-circle','Released'=>'check-double','Rejected'=>'times-circle'];
                                            $cls = $sc[$request['status']] ?? 'secondary';
                                            $ico = $si[$request['status']] ?? 'info-circle';
                                            ?>
                                            <span class="badge bg-<?= $cls ?>">
                                                <i class="fas fa-<?= $ico ?> me-1"></i><?= $request['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="fas fa-inbox"></i>
                                                <p>No requests found</p>
                                                <?php if ($status_filter): ?>
                                                <a href="?tab=manage" class="btn btn-outline-primary">
                                                    <i class="fas fa-times me-2"></i>Clear Filter
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Reports Tab ── -->
        <div class="tab-pane <?php echo $active_tab === 'reports' ? 'show active' : ''; ?>">
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="tab" value="reports">
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="Pending"  <?php echo $status_filter === 'Pending'  ? 'selected' : ''; ?>>Pending</option>
                                <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="Released" <?php echo $status_filter === 'Released' ? 'selected' : ''; ?>>Released</option>
                                <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Request Type</label>
                            <select name="request_type" class="form-select">
                                <option value="0">All Types</option>
                                <?php foreach ($request_types as $type): ?>
                                    <option value="<?php echo $type['request_type_id']; ?>"
                                            <?php echo $request_type_filter == $type['request_type_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['request_type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Report Stats -->
            <div class="row mb-4">
                <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body text-center"><h3 class="mb-1"><?php echo $report_stats['total_requests']; ?></h3><p class="text-muted small mb-0">Total</p></div></div></div>
                <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body text-center"><h3 class="mb-1 text-warning"><?php echo $report_stats['pending']; ?></h3><p class="text-muted small mb-0">Pending</p></div></div></div>
                <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body text-center"><h3 class="mb-1 text-info"><?php echo $report_stats['approved']; ?></h3><p class="text-muted small mb-0">Approved</p></div></div></div>
                <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body text-center"><h3 class="mb-1 text-success"><?php echo $report_stats['released']; ?></h3><p class="text-muted small mb-0">Released</p></div></div></div>
                <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body text-center"><h3 class="mb-1 text-primary"><?php echo $report_stats['paid_requests']; ?></h3><p class="text-muted small mb-0">Paid</p></div></div></div>
                <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body text-center"><h5 class="mb-1 text-success">₱<?php echo number_format($report_stats['total_revenue'] ?? 0, 2); ?></h5><p class="text-muted small mb-0">Revenue</p></div></div></div>
            </div>

            <div class="row mb-4">
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Request Type Distribution</h5></div>
                        <div class="card-body">
                            <canvas id="requestTypeChart" style="max-height: 300px;"></canvas>
                            <div class="mt-3">
                                <table class="table table-sm"><tbody>
                                    <?php if ($type_distribution && $type_distribution->num_rows > 0): $type_distribution->data_seek(0); while ($row = $type_distribution->fetch_assoc()): ?>
                                        <tr><td><?= htmlspecialchars($row['request_type_name'] ?? 'Unknown') ?></td><td class="text-end fw-bold"><?= $row['count'] ?></td></tr>
                                    <?php endwhile; else: echo '<tr><td colspan="2" class="text-center text-muted">No data</td></tr>'; endif; ?>
                                </tbody></table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Status Overview</h5></div>
                        <div class="card-body">
                            <canvas id="statusChart" style="max-height: 300px;"></canvas>
                            <div class="mt-3">
                                <table class="table table-sm"><tbody>
                                    <tr><td><span class="badge bg-warning">Pending</span></td><td class="text-end fw-bold"><?= $report_stats['pending'] ?></td></tr>
                                    <tr><td><span class="badge bg-info">Approved</span></td><td class="text-end fw-bold"><?= $report_stats['approved'] ?></td></tr>
                                    <tr><td><span class="badge bg-success">Released</span></td><td class="text-end fw-bold"><?= $report_stats['released'] ?></td></tr>
                                    <tr><td><span class="badge bg-danger">Rejected</span></td><td class="text-end fw-bold"><?= $report_stats['rejected'] ?></td></tr>
                                </tbody></table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export -->
            <div class="card border-0 shadow-sm mb-4 no-print">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h6 class="mb-3">Period Summary</h6>
                            <p class="small mb-2"><strong>Date Range:</strong> <?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?></p>
                            <?php $d1=new DateTime($date_from); $d2=new DateTime($date_to); $iv=$d1->diff($d2); $days=$iv->days+1; ?>
                            <p class="small mb-2"><strong>Total Days:</strong> <?= $days ?> days</p>
                            <p class="small mb-2"><strong>Average per Day:</strong> <?= $days > 0 ? number_format($report_stats['total_requests']/$days,2) : 0 ?> requests</p>
                            <p class="small mb-0"><strong>Revenue Collected:</strong> <span class="text-success fw-bold">₱<?= number_format($report_stats['total_revenue']??0,2) ?></span></p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="mb-3">Export Options</h6>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Report</button>
                                <button class="btn btn-success" onclick="exportToExcel()"><i class="fas fa-file-excel me-2"></i>Export to Excel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3"><h5 class="mb-0">Detailed Request List</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Request ID</th><th>Date</th><th>Resident Name</th>
                                    <th>Request Type</th><th>Purpose</th><th>Status</th><th>Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($report_requests->num_rows > 0): while ($request = $report_requests->fetch_assoc()):
                                    $sc = ['Pending'=>'warning','Approved'=>'info','Released'=>'success','Rejected'=>'danger'];
                                    $cls = $sc[$request['status']] ?? 'secondary';
                                ?>
                                    <tr>
                                        <td><strong class="text-primary">#<?= str_pad($request['request_id'],5,'0',STR_PAD_LEFT) ?></strong></td>
                                        <td><small><?= date('M d, Y', strtotime($request['request_date'])) ?></small></td>
                                        <td><?= htmlspecialchars($request['first_name'].' '.$request['last_name']) ?></td>
                                        <td><?= htmlspecialchars($request['request_type_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(mb_strimwidth($request['purpose']??'',0,50,'...')) ?></td>
                                        <td><span class="badge bg-<?= $cls ?>"><?= $request['status'] ?></span></td>
                                        <td><?= $request['payment_status'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning text-dark">Unpaid</span>' ?></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="7" class="text-center py-5"><i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i><p class="text-muted">No requests found</p></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Hover Preview Card ── -->
<div id="reqPreviewCard" class="req-preview-card" style="display:none;">
    <div class="req-preview-header">
        <div class="req-preview-icon" id="reqPreviewIconBox">
            <i class="fas fa-file-alt" id="reqPreviewIcon"></i>
        </div>
        <div class="req-preview-header-text">
            <div class="req-preview-type" id="reqPreviewType"></div>
            <div class="req-preview-title" id="reqPreviewTitle"></div>
        </div>
    </div>
    <div class="req-preview-body">
        <div class="req-preview-row">
            <span class="req-preview-label"><i class="fas fa-user me-1"></i>Resident</span>
            <span class="req-preview-value" id="reqPreviewName"></span>
        </div>
        <div class="req-preview-row">
            <span class="req-preview-label"><i class="fas fa-envelope me-1"></i>Email</span>
            <span class="req-preview-value" id="reqPreviewEmail"></span>
        </div>
        <div class="req-preview-row">
            <span class="req-preview-label"><i class="fas fa-align-left me-1"></i>Purpose</span>
            <span class="req-preview-value" id="reqPreviewMessage"></span>
        </div>
        <div class="req-preview-row">
            <span class="req-preview-label"><i class="fas fa-peso-sign me-1"></i>Fee</span>
            <span class="req-preview-value" id="reqPreviewFee"></span>
        </div>
        <div class="req-preview-row">
            <span class="req-preview-label"><i class="fas fa-credit-card me-1"></i>Payment</span>
            <span class="req-preview-value" id="reqPreviewPay"></span>
        </div>
        <div class="req-preview-footer">
            <i class="far fa-calendar-alt"></i>
            <span id="reqPreviewTime"></span>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Update Request Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="status_request_id">
                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" id="status_select" class="form-select" required>
                            <option value="Pending">Pending</option>
                            <option value="Approved">Approved</option>
                            <option value="Released">Released</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Add any remarks..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-dollar-sign me-2"></i>Update Payment Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="payment_request_id">
                    <div class="mb-3">
                        <label class="form-label">Payment Status <span class="text-danger">*</span></label>
                        <select name="payment_status" id="payment_status_select" class="form-select" required>
                            <option value="0">Unpaid</option>
                            <option value="1">Paid</option>
                        </select>
                    </div>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        When marked as "Paid", a revenue record will be automatically created.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_payment" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Update Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// ── Hover Preview ─────────────────────────────────────────────
(function () {
    const card    = document.getElementById('reqPreviewCard');
    const iconBox = document.getElementById('reqPreviewIconBox');
    const icon    = document.getElementById('reqPreviewIcon');

    const colorMap = {
        warning : { bg: 'rgba(255,193,7,0.12)',   text: '#d39e00' },
        info    : { bg: 'rgba(13,202,240,0.12)',  text: '#0aa2c0' },
        success : { bg: 'rgba(25,135,84,0.12)',   text: '#198754' },
        danger  : { bg: 'rgba(220,53,69,0.12)',   text: '#dc3545' },
        secondary:{ bg: 'rgba(108,117,125,0.12)', text: '#6c757d' }
    };

    let hideTimer = null;

    function positionCard(e) {
        const margin = 16, cw = 340, ch = card.offsetHeight || 240;
        const vw = window.innerWidth, vh = window.innerHeight;
        let x = e.clientX + margin, y = e.clientY + margin;
        if (x + cw > vw - margin) x = e.clientX - cw - margin;
        if (y + ch > vh - margin) y = e.clientY - ch - margin;
        card.style.left = x + 'px';
        card.style.top  = y + 'px';
    }

    function showCard(row, e) {
        clearTimeout(hideTimer);
        const color = row.dataset.previewColor || 'secondary';
        const c = colorMap[color] || colorMap.secondary;

        document.getElementById('reqPreviewTitle').textContent   = row.dataset.previewTitle;
        document.getElementById('reqPreviewType').textContent    = row.dataset.previewType;
        document.getElementById('reqPreviewMessage').textContent = row.dataset.previewMessage;
        document.getElementById('reqPreviewTime').textContent    = row.dataset.previewTime;
        document.getElementById('reqPreviewName').textContent    = row.dataset.previewName;
        document.getElementById('reqPreviewEmail').textContent   = row.dataset.previewEmail;
        document.getElementById('reqPreviewFee').textContent     = row.dataset.previewFee;
        document.getElementById('reqPreviewPay').textContent     = row.dataset.previewPay;

        icon.className        = 'fas ' + row.dataset.previewIcon;
        iconBox.style.background = c.bg;
        icon.style.color         = c.text;

        positionCard(e);
        card.style.display = 'block';
    }

    function hideCard() { card.style.display = 'none'; }

    document.querySelectorAll('.request-row').forEach(row => {
        row.addEventListener('mouseenter', function(e) { showCard(this, e); });
        row.addEventListener('mousemove',  function(e) { positionCard(e); });
        row.addEventListener('mouseleave', function() {
            hideTimer = setTimeout(() => { if (!card.matches(':hover')) hideCard(); }, 150);
        });
    });
})();

// ── Charts ─────────────────────────────────────────────────────
<?php if ($active_tab === 'reports'): ?>
document.addEventListener('DOMContentLoaded', function () {
    const typeCtx = document.getElementById('requestTypeChart');
    if (typeCtx) {
        new Chart(typeCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: [<?php if ($type_distribution && $type_distribution->num_rows > 0) { $type_distribution->data_seek(0); while ($row = $type_distribution->fetch_assoc()) echo "'" . addslashes($row['request_type_name'] ?? 'Unknown') . "',"; } ?>],
                datasets: [{ data: [<?php if ($type_distribution && $type_distribution->num_rows > 0) { $type_distribution->data_seek(0); while ($row = $type_distribution->fetch_assoc()) echo $row['count'] . ','; } ?>], backgroundColor: ['#0d6efd','#6610f2','#6f42c1','#d63384','#dc3545','#fd7e14','#ffc107','#198754','#20c997','#0dcaf0'] }]
            },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
        });
    }
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx.getContext('2d'), {
            type: 'pie',
            data: {
                labels: ['Pending','Approved','Released','Rejected'],
                datasets: [{ data: [<?= $report_stats['pending'] ?>,<?= $report_stats['approved'] ?>,<?= $report_stats['released'] ?>,<?= $report_stats['rejected'] ?>], backgroundColor: ['#ffc107','#0dcaf0','#198754','#dc3545'] }]
            },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
        });
    }
});
<?php endif; ?>

function exportToExcel() {
    alert('Excel export can be implemented with PHPSpreadsheet.');
}

setTimeout(function() {
    document.querySelectorAll('.alert-dismissible').forEach(a => new bootstrap.Alert(a).close());
}, 5000);
</script>

<?php
if (isset($manage_stmt)) $manage_stmt->close();
include '../../includes/footer.php';
?>