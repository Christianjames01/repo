<?php
/**
 * Email Details - modules/notifications/email-details.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

// Get current user role directly from database
$user_id = $_SESSION['user_id'];
$user_query = "SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1";
$stmt = $conn->prepare($user_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$current_user = $user_result->fetch_assoc();
$stmt->close();

$user_role = $current_user['role'] ?? '';

// Only Super Administrator can access
if ($user_role !== 'Super Administrator') {
    $_SESSION['error_message'] = 'Access denied. Super Administrator only.';
    header('Location: ../../dashboard.php');
    exit();
}

$history_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($history_id <= 0) {
    $_SESSION['error_message'] = 'Invalid email history ID.';
    header('Location: email-history.php');
    exit();
}

// Fetch the single email history record
$email_query = "SELECT 
                    eh.*,
                    COALESCE(
                        CONCAT(r.first_name, ' ', r.last_name),
                        u.username
                    ) as sender_name
                FROM tbl_email_history eh
                LEFT JOIN tbl_users u ON eh.sender_id = u.user_id
                LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                WHERE eh.id = ?
                LIMIT 1";

$stmt = $conn->prepare($email_query);
$stmt->bind_param('i', $history_id);
$stmt->execute();
$result = $stmt->get_result();
$email = $result->fetch_assoc();
$stmt->close();

if (!$email) {
    $_SESSION['error_message'] = 'Email history not found.';
    header('Location: email-history.php');
    exit();
}

// Get recipients
$recipients_query = "SELECT * FROM tbl_email_recipients 
                    WHERE email_history_id = ? 
                    ORDER BY resident_name";

$stmt = $conn->prepare($recipients_query);
$stmt->bind_param('i', $history_id);
$stmt->execute();
$result = $stmt->get_result();
$recipients = [];
while ($row = $result->fetch_assoc()) {
    $recipients[] = $row;
}
$stmt->close();

// Calculate statistics
$total_recipients = count($recipients);
$sent_successfully = 0;
$failed_to_send = 0;
$no_email = 0;

foreach ($recipients as $recipient) {
    if ($recipient['email_sent']) {
        $sent_successfully++;
    } elseif (!$recipient['has_email']) {
        $no_email++;
    } else {
        $failed_to_send++;
    }
}

$page_title = 'Email Details';

// Safe date formatter — guards against NULL, zero-dates, and negative timestamps.
// Using a closure avoids redeclaration conflicts with any formatDate() in functions.php.
$safeDate = function(string $format, ?string $value, string $fallback = '-'): string {
    if (empty($value)) return $fallback;
    if (in_array(trim($value), ['0000-00-00', '0000-00-00 00:00:00'], true)) return $fallback;
    $ts = strtotime($value);
    if ($ts === false || $ts < 0) return $fallback;
    return date($format, $ts);
};

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-envelope-open me-2 text-primary"></i>Email Details
                    </h2>
                    <p class="text-muted mb-0">Detailed information about this email</p>
                </div>
                <a href="email-history.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to History
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-detail stat-card-primary">
                <div class="stat-card-body">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-number"><?php echo $total_recipients; ?></h3>
                        <p class="stat-label">Total Recipients</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-detail stat-card-success">
                <div class="stat-card-body">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-number"><?php echo $sent_successfully; ?></h3>
                        <p class="stat-label">Sent Successfully</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-detail stat-card-warning">
                <div class="stat-card-body">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-number"><?php echo $no_email; ?></h3>
                        <p class="stat-label">No Email</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-detail stat-card-danger">
                <div class="stat-card-body">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-number"><?php echo $failed_to_send; ?></h3>
                        <p class="stat-label">Failed</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Information -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm modern-card mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fas fa-info-circle me-2 text-primary"></i>Email Information
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="info-row">
                        <div class="info-label">Subject:</div>
                        <div class="info-value"><?= htmlspecialchars($email['email_title']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Sent By:</div>
                        <div class="info-value"><?= htmlspecialchars($email['sender_name']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date & Time:</div>
                        <div class="info-value">
                            <?= $safeDate('F d, Y h:i A', $email['sent_at'], 'Not available') ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Recipient Type:</div>
                        <div class="info-value">
                            <span class="badge bg-secondary"><?= htmlspecialchars($email['recipient_details']) ?></span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Notification Type:</div>
                        <div class="info-value">
                            <?php
                            $type_badges = [
                                'general'           => 'bg-secondary',
                                'announcement'      => 'bg-primary',
                                'alert'             => 'bg-warning',
                                'incident_reported' => 'bg-danger',
                                'status_update'     => 'bg-info'
                            ];
                            $badge_class = $type_badges[$email['notification_type']] ?? 'bg-secondary';
                            ?>
                            <span class="badge <?= $badge_class ?>">
                                <?= ucfirst(str_replace('_', ' ', $email['notification_type'])) ?>
                            </span>
                        </div>
                    </div>
                    <?php if (!empty($email['action_url'])): ?>
                        <div class="info-row">
                            <div class="info-label">Action URL:</div>
                            <div class="info-value">
                                <a href="<?= htmlspecialchars($email['action_url']) ?>" target="_blank" class="text-primary">
                                    <?= htmlspecialchars($email['action_url']) ?>
                                    <i class="fas fa-external-link-alt ms-1"></i>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="info-row border-0 mb-0">
                        <div class="info-label">Message:</div>
                        <div class="info-value">
                            <div class="message-box">
                                <?= nl2br(htmlspecialchars($email['email_message'])) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Rate -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm modern-card">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-chart-pie me-2 text-success"></i>Success Rate
                    </h6>
                </div>
                <div class="card-body text-center p-4">
                    <div class="success-rate-circle">
                        <h2 class="display-3 mb-0 fw-bold text-primary">
                            <?php echo $total_recipients > 0 ? round(($sent_successfully / $total_recipients) * 100, 1) : 0; ?>%
                        </h2>
                        <p class="text-muted mb-0 mt-2">Successfully Delivered</p>
                    </div>
                    
                    <div class="mt-4">
                        <div class="progress-item mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-success">Sent</small>
                                <small class="fw-semibold text-success"><?= $sent_successfully ?></small>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-success" 
                                     style="width: <?php echo $total_recipients > 0 ? ($sent_successfully / $total_recipients * 100) : 0; ?>%">
                                </div>
                            </div>
                        </div>
                        
                        <div class="progress-item mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-warning">No Email</small>
                                <small class="fw-semibold text-warning"><?= $no_email ?></small>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-warning" 
                                     style="width: <?php echo $total_recipients > 0 ? ($no_email / $total_recipients * 100) : 0; ?>%">
                                </div>
                            </div>
                        </div>
                        
                        <div class="progress-item">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-danger">Failed</small>
                                <small class="fw-semibold text-danger"><?= $failed_to_send ?></small>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-danger" 
                                     style="width: <?php echo $total_recipients > 0 ? ($failed_to_send / $total_recipients * 100) : 0; ?>%">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recipients List -->
    <div class="card border-0 shadow-sm modern-card">
        <div class="card-header bg-white border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <h5 class="mb-0 fw-semibold">
                    <i class="fas fa-users me-2 text-primary"></i>Recipients 
                    <span class="badge bg-primary ms-2"><?= $total_recipients ?></span>
                </h5>
                
                <!-- Filter Buttons -->
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active" onclick="filterRecipients('all')">
                        All (<?= $total_recipients ?>)
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="filterRecipients('sent')">
                        Sent (<?= $sent_successfully ?>)
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="filterRecipients('no-email')">
                        No Email (<?= $no_email ?>)
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="filterRecipients('failed')">
                        Failed (<?= $failed_to_send ?>)
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover recipients-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Resident Name</th>
                            <th>Email Address</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Sent At</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody id="recipientsTable">
                        <?php foreach ($recipients as $recipient): ?>
                            <tr class="recipient-row" 
                                data-status="<?= $recipient['email_sent'] ? 'sent' : (!$recipient['has_email'] ? 'no-email' : 'failed') ?>">
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($recipient['resident_name']) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($recipient['resident_email'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($recipient['resident_email']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($recipient['email_sent']): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Sent
                                        </span>
                                    <?php elseif (!$recipient['has_email']): ?>
                                        <span class="badge bg-warning">
                                            <i class="fas fa-exclamation-triangle me-1"></i>No Email
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-times-circle me-1"></i>Failed
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    // Use $safeDate() — shows '-' for NULL/zero-date/invalid values
                                    $date_part = $safeDate('M d, Y', $recipient['sent_at']);
                                    $time_part = $safeDate('h:i:s A', $recipient['sent_at']);
                                    ?>
                                    <?php if ($date_part !== '-'): ?>
                                        <small>
                                            <?= $date_part ?><br>
                                            <span class="text-muted"><?= $time_part ?></span>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($recipient['error_message']): ?>
                                        <small class="text-danger">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <?= htmlspecialchars($recipient['error_message']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function filterRecipients(status) {
    const rows = document.querySelectorAll('.recipient-row');
    const buttons = document.querySelectorAll('.btn-group button');
    
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    rows.forEach(row => {
        if (status === 'all' || row.dataset.status === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<style>
/* Modern Statistics Cards */
.stat-card-detail {
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    border: 1px solid #e9ecef;
    position: relative;
}

.stat-card-detail::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.stat-card-primary::before { background: linear-gradient(90deg, #0d6efd, #0a58ca); }
.stat-card-success::before { background: linear-gradient(90deg, #198754, #146c43); }
.stat-card-warning::before { background: linear-gradient(90deg, #ffc107, #f59f00); }
.stat-card-danger::before { background: linear-gradient(90deg, #dc3545, #b02a37); }

.stat-card-detail:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}

.stat-card-body {
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    background: white;
}

.stat-icon-wrapper {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.stat-card-primary .stat-icon-wrapper {
    background: rgba(13, 110, 253, 0.1);
    color: #0d6efd;
}

.stat-card-success .stat-icon-wrapper {
    background: rgba(25, 135, 84, 0.1);
    color: #198754;
}

.stat-card-warning .stat-icon-wrapper {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.stat-card-danger .stat-icon-wrapper {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.25rem;
    color: #212529;
}

.stat-label {
    font-size: 0.875rem;
    color: #6c757d;
    margin: 0;
    font-weight: 500;
}

/* Modern Cards */
.modern-card {
    border-radius: 12px;
}

.modern-card .card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #f0f0f0;
}

/* Info Rows */
.info-row {
    display: flex;
    padding: 0.875rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    flex: 0 0 180px;
    font-weight: 600;
    color: #495057;
    font-size: 0.9375rem;
}

.info-value {
    flex: 1;
    color: #212529;
    font-size: 0.9375rem;
}

.message-box {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    line-height: 1.6;
    font-size: 0.9375rem;
}

/* Success Rate */
.success-rate-circle {
    padding: 1.5rem 0;
}

.progress {
    border-radius: 4px;
    background-color: #e9ecef;
}

.progress-item {
    padding: 0;
}

/* Recipients Table */
.recipients-table {
    font-size: 0.9rem;
}

.recipients-table thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding: 1rem;
    background: #f8f9fa;
}

.recipients-table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.recipient-row {
    transition: all 0.2s;
}

.recipient-row:hover {
    background: #f8f9fa;
}

/* Filter Buttons */
.btn-group .btn {
    border-radius: 6px;
    font-size: 0.875rem;
    padding: 0.5rem 1rem;
    font-weight: 500;
}

.btn-group .btn.active {
    font-weight: 600;
}

/* Responsive */
@media (max-width: 768px) {
    .info-row {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .info-label {
        flex: 1;
        font-size: 0.875rem;
    }
    
    .info-value {
        font-size: 0.875rem;
    }
    
    .stat-number {
        font-size: 1.5rem;
    }
    
    .stat-icon-wrapper {
        width: 48px;
        height: 48px;
        font-size: 1.25rem;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>