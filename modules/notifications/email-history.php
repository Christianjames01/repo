<?php
/**
 * Email History - modules/notifications/email-history.php
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

// Pagination setup
$per_page = 15;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

// Get total count
$count_sql  = "SELECT COUNT(*) as total FROM tbl_email_history";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = (int)($count_result->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = $total_records > 0 ? ceil($total_records / $per_page) : 1;

// Get email history records
$history_sql = "SELECT 
                    eh.*,
                    COALESCE(
                        CONCAT(r.first_name, ' ', r.last_name),
                        u.username
                    ) as sender_name
                FROM tbl_email_history eh
                LEFT JOIN tbl_users u ON eh.sender_id = u.user_id
                LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                ORDER BY eh.sent_at DESC
                LIMIT ? OFFSET ?";

$history_stmt = $conn->prepare($history_sql);
$history_stmt->bind_param('ii', $per_page, $offset);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

$email_history = [];
while ($row = $history_result->fetch_assoc()) {
    $email_history[] = $row;
}
$history_stmt->close();

// Overall stats
$stats = fetchOne($conn,
    "SELECT 
        COUNT(*) as total_emails,
        SUM(total_recipients) as total_recipients,
        SUM(successful_sends) as total_sent,
        SUM(failed_sends) as total_failed
     FROM tbl_email_history",
    [], ''
);

$page_title = 'Email History';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-history me-2 text-primary"></i>Email History
                    </h2>
                    <p class="text-muted mb-0">View all sent email notifications</p>
                </div>
                <div>
                    <a href="email-residents.php" class="btn btn-primary me-2">
                        <i class="fas fa-paper-plane me-2"></i>Send New Email
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Notifications
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 rounded-3 p-3 me-3">
                            <i class="fas fa-envelope fa-2x text-primary"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?= number_format($stats['total_emails'] ?? 0) ?></h3>
                            <p class="text-muted mb-0 small">Total Emails Sent</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info bg-opacity-10 rounded-3 p-3 me-3">
                            <i class="fas fa-users fa-2x text-info"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?= number_format($stats['total_recipients'] ?? 0) ?></h3>
                            <p class="text-muted mb-0 small">Total Recipients</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 rounded-3 p-3 me-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?= number_format($stats['total_sent'] ?? 0) ?></h3>
                            <p class="text-muted mb-0 small">Successfully Delivered</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-danger bg-opacity-10 rounded-3 p-3 me-3">
                            <i class="fas fa-times-circle fa-2x text-danger"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?= number_format($stats['total_failed'] ?? 0) ?></h3>
                            <p class="text-muted mb-0 small">Failed / No Email</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- History Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Email Records
                <span class="badge bg-secondary ms-2"><?= number_format($total_records) ?></span>
            </h5>
            <small class="text-muted">
                Showing <?= min(($offset + 1), $total_records) ?>–<?= min($offset + $per_page, $total_records) ?> of <?= number_format($total_records) ?>
            </small>
        </div>

        <div class="card-body p-0">
            <?php if (empty($email_history)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No email records found</h5>
                    <p class="text-muted mb-3">No emails have been sent yet.</p>
                    <a href="email-residents.php" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send First Email
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover email-history-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:50px;">#</th>
                                <th style="width:60px;"></th>
                                <th>Subject & Recipients</th>
                                <th style="width:120px;">Type</th>
                                <th style="width:100px;" class="text-center">Recipients</th>
                                <th style="width:100px;">Sent By</th>
                                <th style="width:140px;">Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($email_history as $index => $record): ?>
                                <?php
                                $success_rate = $record['total_recipients'] > 0
                                    ? round(($record['successful_sends'] / $record['total_recipients']) * 100)
                                    : 0;
                                $rate_class = $success_rate >= 80 ? 'success' : ($success_rate >= 50 ? 'warning' : 'danger');

                                $type_badges = [
                                    'general'           => ['bg-secondary', 'General', 'fa-info-circle'],
                                    'announcement'      => ['bg-primary',   'Announcement', 'fa-bullhorn'],
                                    'alert'             => ['bg-warning text-dark', 'Alert', 'fa-exclamation-triangle'],
                                    'incident_reported' => ['bg-danger',    'Incident', 'fa-fire'],
                                    'status_update'     => ['bg-info',      'Status Update', 'fa-sync-alt'],
                                ];
                                $badge_info  = $type_badges[$record['notification_type']] ?? ['bg-secondary', ucfirst($record['notification_type']), 'fa-envelope'];
                                
                                // Preview message
                                $preview_msg = "Sent to " . number_format($record['total_recipients']) . " recipient(s). " .
                                              number_format($record['successful_sends']) . " delivered successfully, " .
                                              number_format($record['failed_sends']) . " failed.";
                                              
                                $detail_url = "email-details.php?id=" . (int)$record['id'];
                                ?>
                                <tr class="email-row"
                                    data-preview-title="<?= htmlspecialchars($record['email_title']) ?>"
                                    data-preview-message="<?= htmlspecialchars($preview_msg) ?>"
                                    data-preview-recipients="<?= htmlspecialchars($record['recipient_details']) ?>"
                                    data-preview-icon="<?= $badge_info[2] ?>"
                                    data-preview-color="<?= strpos($badge_info[0], 'primary') !== false ? 'primary' : 
                                                            (strpos($badge_info[0], 'danger') !== false ? 'danger' :
                                                            (strpos($badge_info[0], 'warning') !== false ? 'warning' :
                                                            (strpos($badge_info[0], 'info') !== false ? 'info' : 'secondary'))) ?>"
                                    data-preview-type="<?= $badge_info[1] ?>"
                                    data-preview-time="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($record['sent_at']))) ?>"
                                    data-preview-sender="<?= htmlspecialchars($record['sender_name'] ?? 'Unknown') ?>"
                                    data-preview-total="<?= number_format($record['total_recipients']) ?>"
                                    data-preview-success="<?= number_format($record['successful_sends']) ?>"
                                    data-preview-failed="<?= number_format($record['failed_sends']) ?>"
                                    data-preview-rate="<?= $success_rate ?>%"
                                    data-url="<?= htmlspecialchars($detail_url) ?>"
                                    style="cursor:pointer;">
                                    
                                    <td class="text-muted small"><?= $offset + $index + 1 ?></td>
                                    
                                    <!-- Icon -->
                                    <td class="text-center">
                                        <div class="email-icon-wrapper">
                                            <div class="email-icon bg-<?= strpos($badge_info[0], 'primary') !== false ? 'primary' : 
                                                            (strpos($badge_info[0], 'danger') !== false ? 'danger' :
                                                            (strpos($badge_info[0], 'warning') !== false ? 'warning' :
                                                            (strpos($badge_info[0], 'info') !== false ? 'info' : 'secondary'))) ?>-subtle">
                                                <i class="fas <?= $badge_info[2] ?> text-<?= strpos($badge_info[0], 'primary') !== false ? 'primary' : 
                                                            (strpos($badge_info[0], 'danger') !== false ? 'danger' :
                                                            (strpos($badge_info[0], 'warning') !== false ? 'warning' :
                                                            (strpos($badge_info[0], 'info') !== false ? 'info' : 'secondary'))) ?>"></i>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <div class="fw-semibold text-dark mb-1"><?= htmlspecialchars($record['email_title']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars(mb_strimwidth($record['recipient_details'], 0, 80, '...')) ?></small>
                                    </td>
                                    
                                    <td>
                                        <span class="badge <?= $badge_info[0] ?>">
                                            <?= $badge_info[1] ?>
                                        </span>
                                    </td>
                                    
                                    <td class="text-center">
                                        <div>
                                            <strong><?= number_format($record['total_recipients']) ?></strong>
                                        </div>
                                        <div class="small">
                                            <span class="text-success"><?= $record['successful_sends'] ?></span> /
                                            <span class="text-danger"><?= $record['failed_sends'] ?></span>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <small><?= htmlspecialchars($record['sender_name'] ?? 'Unknown') ?></small>
                                    </td>
                                    
                                    <td>
                                        <div>
                                            <i class="far fa-calendar me-1 text-muted"></i>
                                            <small><?= date('M d, Y', strtotime($record['sent_at'])) ?></small>
                                        </div>
                                        <div>
                                            <i class="far fa-clock me-1 text-muted"></i>
                                            <small class="text-muted"><?= date('h:i A', strtotime($record['sent_at'])) ?></small>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="card-footer bg-white border-top">
                        <nav aria-label="Email history pagination">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $p ?>">
                                            <?= $p ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- =============================================
     HOVER PREVIEW CARD (floats near cursor)
============================================== -->
<div id="emailPreviewCard" class="email-preview-card" style="display:none;">
    <div class="email-preview-header">
        <div class="email-preview-icon-wrap">
            <div class="email-preview-icon" id="previewIconBox">
                <i class="fas fa-envelope" id="previewIcon"></i>
            </div>
        </div>
        <div class="email-preview-header-text">
            <div class="email-preview-type-label" id="previewTypeLabel"></div>
            <div class="email-preview-title" id="previewTitle"></div>
        </div>
    </div>
    <div class="email-preview-body">
        <div class="mb-2">
            <small class="text-muted d-block mb-1"><i class="fas fa-users me-1"></i>Recipients:</small>
            <p class="email-preview-recipients mb-0" id="previewRecipients"></p>
        </div>
        <div class="email-preview-stats">
            <div class="row g-2 text-center">
                <div class="col-4">
                    <div class="stat-box">
                        <div class="stat-value text-primary" id="previewTotal">0</div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-box">
                        <div class="stat-value text-success" id="previewSuccess">0</div>
                        <div class="stat-label">Sent</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-box">
                        <div class="stat-value text-danger" id="previewFailed">0</div>
                        <div class="stat-label">Failed</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="email-preview-footer">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="far fa-clock me-1"></i>
                    <span id="previewTime"></span>
                </div>
                <div>
                    <i class="fas fa-user me-1"></i>
                    <span id="previewSender"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.stat-icon {
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* ============================================
   EMAIL HISTORY TABLE
============================================ */
.email-history-table { font-size: 0.9rem; }
.email-history-table thead th {
    font-weight: 700; 
    text-transform: uppercase; 
    font-size: 0.75rem;
    letter-spacing: 0.5px; 
    color: #495057; 
    border-bottom: 2px solid #dee2e6;
    padding: 1rem; 
    background: #f8f9fa;
}
.email-history-table tbody td { 
    padding: 1rem 0.75rem; 
    vertical-align: middle; 
    border-bottom: 1px solid #f0f0f0; 
}
.email-row { 
    transition: all 0.2s; 
    background: white; 
    position: relative; 
}
.email-row:hover { 
    background: #f8f9fa; 
    box-shadow: inset 3px 0 0 #0d6efd; 
}

.email-icon-wrapper { 
    position: relative; 
    display: inline-block; 
}
.email-icon {
    width: 40px; 
    height: 40px; 
    border-radius: 10px;
    display: flex; 
    align-items: center; 
    justify-content: center;
    font-size: 1.1rem; 
    transition: transform 0.2s;
}
.email-row:hover .email-icon { 
    transform: scale(1.1); 
}

/* ============================================
   HOVER PREVIEW CARD
============================================ */
.email-preview-card {
    position: fixed;
    z-index: 9999;
    width: 360px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.10);
    border: 1px solid #e9ecef;
    overflow: hidden;
    pointer-events: none;
    animation: previewFadeIn 0.18s ease;
}
@keyframes previewFadeIn {
    from { opacity: 0; transform: translateY(6px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.email-preview-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f0f0f0;
}
.email-preview-icon-wrap { 
    flex-shrink: 0; 
}
.email-preview-icon {
    width: 42px; 
    height: 42px; 
    border-radius: 10px;
    display: flex; 
    align-items: center; 
    justify-content: center;
    font-size: 1.2rem;
}
.email-preview-header-text { 
    flex: 1; 
    min-width: 0; 
}
.email-preview-type-label {
    font-size: 0.7rem; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: 0.5px; 
    color: #6c757d; 
    margin-bottom: 2px;
}
.email-preview-title {
    font-size: 0.92rem; 
    font-weight: 700; 
    color: #212529;
    line-height: 1.3;
    white-space: nowrap; 
    overflow: hidden; 
    text-overflow: ellipsis;
}
.email-preview-body { 
    padding: 12px 16px 14px; 
}
.email-preview-recipients {
    font-size: 0.8rem; 
    color: #495057; 
    line-height: 1.5;
}
.email-preview-stats {
    margin: 12px 0;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
}
.stat-box {
    padding: 4px;
}
.stat-value {
    font-size: 1.2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 2px;
}
.stat-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.email-preview-footer {
    font-size: 0.75rem; 
    color: #6c757d;
    padding-top: 8px;
    border-top: 1px solid #e9ecef;
}

/* Color utilities */
.bg-primary-subtle   { background-color: rgba(13,110,253,0.10); }
.bg-warning-subtle   { background-color: rgba(255,193,7,0.10); }
.bg-success-subtle   { background-color: rgba(25,135,84,0.10); }
.bg-info-subtle      { background-color: rgba(13,202,240,0.10); }
.bg-secondary-subtle { background-color: rgba(108,117,125,0.10); }
.bg-danger-subtle    { background-color: rgba(220,53,69,0.10); }
</style>

<script>
// ============================================
// HOVER PREVIEW CARD + ROW CLICK NAVIGATION
// ============================================
(function () {
    const card           = document.getElementById('emailPreviewCard');
    const previewIcon    = document.getElementById('previewIcon');
    const previewIconBox = document.getElementById('previewIconBox');
    const previewTitle   = document.getElementById('previewTitle');
    const previewType    = document.getElementById('previewTypeLabel');
    const previewRecipients = document.getElementById('previewRecipients');
    const previewTime    = document.getElementById('previewTime');
    const previewSender  = document.getElementById('previewSender');
    const previewTotal   = document.getElementById('previewTotal');
    const previewSuccess = document.getElementById('previewSuccess');
    const previewFailed  = document.getElementById('previewFailed');

    const colorMap = {
        primary : { bg: 'rgba(13,110,253,0.12)',  text: '#0d6efd' },
        warning : { bg: 'rgba(255,193,7,0.12)',   text: '#d39e00' },
        success : { bg: 'rgba(25,135,84,0.12)',   text: '#198754' },
        info    : { bg: 'rgba(13,202,240,0.12)',  text: '#0aa2c0' },
        danger  : { bg: 'rgba(220,53,69,0.12)',   text: '#dc3545' },
        secondary:{ bg: 'rgba(108,117,125,0.12)', text: '#6c757d' }
    };

    let hideTimer = null;
    let activeRow = null;

    function showCard(row, e) {
        clearTimeout(hideTimer);
        activeRow = row;

        const color = row.dataset.previewColor || 'primary';

        previewTitle.textContent      = row.dataset.previewTitle;
        previewType.textContent       = row.dataset.previewType;
        previewRecipients.textContent = row.dataset.previewRecipients;
        previewTime.textContent       = row.dataset.previewTime;
        previewSender.textContent     = row.dataset.previewSender;
        previewTotal.textContent      = row.dataset.previewTotal;
        previewSuccess.textContent    = row.dataset.previewSuccess;
        previewFailed.textContent     = row.dataset.previewFailed;
        previewIcon.className         = 'fas ' + row.dataset.previewIcon;

        const c = colorMap[color] || colorMap.primary;
        previewIconBox.style.background = c.bg;
        previewIcon.style.color         = c.text;

        positionCard(e);
        card.style.display = 'block';
    }

    function hideCard() {
        card.style.display = 'none';
        activeRow = null;
    }

    function positionCard(e) {
        const margin = 16;
        const cw = card.offsetWidth  || 360;
        const ch = card.offsetHeight || 250;
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        let x = e.clientX + margin;
        let y = e.clientY + margin;

        if (x + cw > vw - margin) x = e.clientX - cw - margin;
        if (y + ch > vh - margin) y = e.clientY - ch - margin;

        card.style.left = x + 'px';
        card.style.top  = y + 'px';
    }

    // ── row events ───────────────────────────
    document.querySelectorAll('.email-row').forEach(row => {
        // Hover: show preview
        row.addEventListener('mouseenter', function (e) { showCard(this, e); });
        row.addEventListener('mousemove',  function (e) { positionCard(e); });
        row.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(() => {
                if (!card.matches(':hover')) hideCard();
            }, 150);
        });

        // Click on the row → navigate
        row.addEventListener('click', function (e) {
            const url = this.dataset.url;
            if (url) window.location.href = url;
        });
    });

})();
</script>

<?php include '../../includes/footer.php'; ?>