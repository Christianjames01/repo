<?php
// Start output buffering and error suppression
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once '../../config/config.php';
require_once '../../config/session.php';

// Clear output buffer
ob_end_clean();

// Start fresh output buffer
ob_start();

// Check login
if (!isLoggedIn()) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id <= 0) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}

try {
    // Get resident_id from current user
    $resident_sql = "SELECT r.resident_id FROM tbl_residents r 
                     INNER JOIN tbl_users u ON r.email = u.email 
                     WHERE u.user_id = ?";
    $resident_stmt = $conn->prepare($resident_sql);
    $resident_stmt->bind_param("i", $user_id);
    $resident_stmt->execute();
    $resident_result = $resident_stmt->get_result();
    $resident_data = $resident_result->fetch_assoc();
    $resident_stmt->close();

    if (!$resident_data) {
        throw new Exception('Resident profile not found');
    }

    $resident_id = $resident_data['resident_id'];

    // Fetch request details - ensure it belongs to this resident
    $sql = "SELECT r.request_id, r.request_date, r.purpose, r.status, r.remarks, 
            r.payment_status, r.processed_date,
            res.first_name, res.middle_name, res.last_name, res.email, res.address,
            rt.request_type_name, rt.fee,
            u.username as processed_by_name
            FROM tbl_requests r
            INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
            LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
            LEFT JOIN tbl_users u ON r.processed_by = u.user_id
            WHERE r.request_id = ? AND r.resident_id = ?";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Query preparation failed');
    }
    
    $stmt->bind_param("ii", $request_id, $resident_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Request not found or access denied']);
        exit;
    }

    $request = $result->fetch_assoc();
    $stmt->close();

    // Status configuration
    $status_classes = [
        'Pending' => 'warning',
        'Approved' => 'info',
        'Released' => 'success',
        'Rejected' => 'danger'
    ];
    $status_icons = [
        'Pending' => 'clock',
        'Approved' => 'check-circle',
        'Released' => 'check-double',
        'Rejected' => 'times-circle'
    ];
    
    $status_class = isset($status_classes[$request['status']]) ? $status_classes[$request['status']] : 'secondary';
    $status_icon = isset($status_icons[$request['status']]) ? $status_icons[$request['status']] : 'info-circle';

    // Build HTML
    $html = '<div class="row">
        <div class="col-md-6 mb-3">
            <div class="card border-0 bg-light h-100">
                <div class="card-body">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-file-alt me-2"></i>Request Information
                    </h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" width="45%">Request ID:</td>
                            <td><strong class="text-primary">#' . str_pad($request['request_id'], 5, '0', STR_PAD_LEFT) . '</strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Submitted:</td>
                            <td>' . date('F d, Y', strtotime($request['request_date'])) . '<br>
                                <small class="text-muted">' . date('h:i A', strtotime($request['request_date'])) . '</small>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Document Type:</td>
                            <td><strong>' . htmlspecialchars($request['request_type_name'] ?? 'N/A') . '</strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Current Status:</td>
                            <td>
                                <span class="badge bg-' . $status_class . ' fs-6">
                                    <i class="fas fa-' . $status_icon . ' me-1"></i>' . htmlspecialchars($request['status']) . '
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <div class="card border-0 bg-light h-100">
                <div class="card-body">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-dollar-sign me-2"></i>Payment Information
                    </h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" width="45%">Document Fee:</td>
                            <td>';
    
    if ($request['fee'] > 0) {
        $html .= '<strong class="text-success fs-4">₱' . number_format($request['fee'], 2) . '</strong>';
    } else {
        $html .= '<span class="badge bg-success">FREE</span>';
    }
    
    $html .= '</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Payment Status:</td>
                            <td>';
    
    if ($request['payment_status']) {
        $html .= '<span class="badge bg-success fs-6"><i class="fas fa-check-circle me-1"></i>Paid</span>';
    } else {
        if ($request['fee'] > 0) {
            $html .= '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-exclamation-circle me-1"></i>Unpaid</span>
                      <br><small class="text-muted mt-1 d-block">Please pay at the barangay office</small>';
        } else {
            $html .= '<span class="badge bg-secondary">N/A</span>';
        }
    }
    
    $html .= '</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-3">
            <div class="card border-0 bg-light">
                <div class="card-body">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-info-circle me-2"></i>Request Purpose
                    </h6>
                    <p class="mb-0" style="white-space: pre-wrap;">' . htmlspecialchars($request['purpose']) . '</p>
                </div>
            </div>
        </div>
    </div>';

    // Processing information
    if (!empty($request['remarks']) || !empty($request['processed_by_name']) || !empty($request['processed_date'])) {
        $html .= '<div class="row">
            <div class="col-12 mb-3">
                <div class="card border-0 bg-light">
                    <div class="card-body">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-clipboard-check me-2"></i>Processing Updates
                        </h6>';
        
        if (!empty($request['processed_by_name'])) {
            $html .= '<div class="mb-2">
                            <small class="text-muted">Processed By:</small>
                            <p class="mb-0"><strong>' . htmlspecialchars($request['processed_by_name']) . '</strong></p>
                        </div>';
        }

        if (!empty($request['processed_date'])) {
            $html .= '<div class="mb-2">
                            <small class="text-muted">Processed Date:</small>
                            <p class="mb-0">' . date('F d, Y h:i A', strtotime($request['processed_date'])) . '</p>
                        </div>';
        }

        if (!empty($request['remarks'])) {
            $html .= '<div class="mb-0">
                            <small class="text-muted">Staff Remarks:</small>
                            <div class="alert alert-info mb-0 mt-2">
                                <i class="fas fa-comment-dots me-2"></i>' . nl2br(htmlspecialchars($request['remarks'])) . '
                            </div>
                        </div>';
        }
        
        $html .= '</div>
                </div>
            </div>
        </div>';
    }

    // Status timeline
    $html .= '<div class="row">
        <div class="col-12">
            <div class="card border-0 bg-light">
                <div class="card-body">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-history me-2"></i>Request Timeline
                    </h6>
                    <div class="timeline">
                        <div class="timeline-item ' . ($request['status'] !== '' ? 'completed' : '') . '">
                            <div class="timeline-marker bg-success"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1">Request Submitted</h6>
                                <p class="text-muted small mb-0">' . date('F d, Y h:i A', strtotime($request['request_date'])) . '</p>
                            </div>
                        </div>';

    if ($request['status'] === 'Approved' || $request['status'] === 'Released') {
        $html .= '<div class="timeline-item completed">
                            <div class="timeline-marker bg-info"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1">Request Approved</h6>
                                <p class="text-muted small mb-0">Your request has been approved and is being processed</p>
                            </div>
                        </div>';
    }

    if ($request['status'] === 'Released') {
        $html .= '<div class="timeline-item completed">
                            <div class="timeline-marker bg-success"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1">Document Released</h6>
                                <p class="text-muted small mb-0">Your document is ready for pickup at the barangay office</p>
                            </div>
                        </div>';
    }

    if ($request['status'] === 'Rejected') {
        $html .= '<div class="timeline-item completed">
                            <div class="timeline-marker bg-danger"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1">Request Rejected</h6>
                                <p class="text-muted small mb-0">Please check the remarks above for more information</p>
                            </div>
                        </div>';
    }

    if ($request['status'] === 'Pending') {
        $html .= '<div class="timeline-item">
                            <div class="timeline-marker bg-warning"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1">Under Review</h6>
                                <p class="text-muted small mb-0">Your request is being reviewed by our staff</p>
                            </div>
                        </div>';
    }

    $html .= '</div>
                </div>
            </div>
        </div>
    </div>

    <style>
    .timeline {
        position: relative;
        padding: 1rem 0;
    }
    .timeline-item {
        display: flex;
        padding-bottom: 2rem;
        position: relative;
    }
    .timeline-item:not(:last-child)::before {
        content: "";
        position: absolute;
        left: 11px;
        top: 30px;
        bottom: 0;
        width: 2px;
        background: #dee2e6;
    }
    .timeline-item.completed:not(:last-child)::before {
        background: #198754;
    }
    .timeline-marker {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        flex-shrink: 0;
        border: 3px solid white;
        box-shadow: 0 0 0 2px #dee2e6;
        margin-right: 1rem;
    }
    .timeline-item.completed .timeline-marker {
        box-shadow: 0 0 0 2px #198754;
    }
    .timeline-content {
        flex: 1;
        padding-top: 0;
    }
    </style>';

    // Clear any output and send JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
    
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>