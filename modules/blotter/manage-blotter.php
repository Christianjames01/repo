<?php
/**
 * Admin Blotter Management Page
 * Path: modules/blotter/manage-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: my-blotter.php');
    exit();
}

$page_title = 'Manage Blotter Records';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_status') {
            $blotter_id = intval($_POST['blotter_id']);
            $status = $_POST['status'];
            
            $stmt = $conn->prepare("UPDATE tbl_blotter SET status = ?, updated_at = NOW() WHERE blotter_id = ?");
            $stmt->bind_param("si", $status, $blotter_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Status updated successfully!";
            } else {
                $_SESSION['error_message'] = "Error updating status.";
            }
            $stmt->close();
            
            header("Location: manage-blotter.php");
            exit();
        }
    }
}

// Get filter from URL (only status filter for clickable cards)
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$sql = "SELECT b.*, 
        CONCAT(c.first_name, ' ', c.last_name) as complainant_name,
        CONCAT(r.first_name, ' ', r.last_name) as respondent_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents c ON b.complainant_id = c.resident_id
        LEFT JOIN tbl_residents r ON b.respondent_id = r.resident_id
        WHERE 1=1";

$params = [];
$types = '';

if ($filter_status) {
    $sql .= " AND b.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$sql .= " ORDER BY b.incident_date DESC, b.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$blotter_records = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'under_investigation' => 0,
    'resolved' => 0,
    'closed' => 0
];

$stats_sql = "SELECT status, COUNT(*) as count FROM tbl_blotter GROUP BY status";
$stats_result = $conn->query($stats_sql);

if ($stats_result && $stats_result->num_rows > 0) {
    while ($row = $stats_result->fetch_assoc()) {
        $status = trim($row['status']);
        $count = (int)$row['count'];
        
        $stats['total'] += $count;
        
        if ($status === 'Pending') {
            $stats['pending'] = $count;
        } elseif ($status === 'Under Investigation') {
            $stats['under_investigation'] = $count;
        } elseif ($status === 'Resolved') {
            $stats['resolved'] = $count;
        } elseif ($status === 'Closed') {
            $stats['closed'] = $count;
        }
    }
}

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

.stat-card.active-card {
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

.blotter-row {
    transition: all 0.2s;
    background: white;
    cursor: pointer;
}

.blotter-row:hover {
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

.btn-group .btn {
    box-shadow: none;
    border-radius: 0;
}

.btn-group .btn:first-child {
    border-top-left-radius: 6px;
    border-bottom-left-radius: 6px;
}

.btn-group .btn:last-child {
    border-top-right-radius: 6px;
    border-bottom-right-radius: 6px;
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

/* Hover Preview Card */
.blotter-preview-card {
    position: fixed;
    z-index: 9999;
    width: 320px;
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

.blotter-preview-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f0f0f0;
}

.blotter-preview-icon-wrap { flex-shrink: 0; }

.blotter-preview-icon {
    width: 42px; 
    height: 42px; 
    border-radius: 10px;
    display: flex; 
    align-items: center; 
    justify-content: center;
    font-size: 1.2rem;
}

.blotter-preview-header-text { flex: 1; min-width: 0; }

.blotter-preview-type-label {
    font-size: 0.7rem; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: 0.5px; 
    color: #6c757d; 
    margin-bottom: 2px;
}

.blotter-preview-title {
    font-size: 0.92rem; 
    font-weight: 700; 
    color: #212529;
    line-height: 1.3;
    white-space: nowrap; 
    overflow: hidden; 
    text-overflow: ellipsis;
}

.blotter-preview-body { padding: 12px 16px 14px; }

.blotter-preview-message {
    font-size: 0.82rem; 
    color: #495057; 
    line-height: 1.6; 
    margin-bottom: 10px;
}

.blotter-preview-footer {
    font-size: 0.75rem; 
    color: #adb5bd;
    display: flex; 
    align-items: center;
    gap: 8px;
}

/* Responsive Adjustments */
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
    
    .blotter-preview-card { 
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
                        <i class="fas fa-clipboard-list me-2 text-primary"></i>
                        Blotter Records Management
                    </h2>
                    <p class="text-muted mb-0">Manage all barangay blotter records</p>
                </div>
                <div>
                    <a href="blotter-reports.php" class="btn btn-info me-2">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a href="add-blotter.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add New Blotter
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards - Clickable -->
    <div class="row mb-4">
        <div class="col-md">
            <a href="manage-blotter.php" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo empty($filter_status) ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Total Blotter</p>
                                <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                <i class="fas fa-clipboard-list fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Pending' ? 'active-card' : ''; ?>">
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
        <div class="col-md">
            <a href="?status=Under Investigation" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Under Investigation' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Investigating</p>
                                <h3 class="mb-0"><?php echo $stats['under_investigation']; ?></h3>
                            </div>
                            <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                                <i class="fas fa-search fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Resolved" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Resolved' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Resolved</p>
                                <h3 class="mb-0"><?php echo $stats['resolved']; ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                                <i class="fas fa-check-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md">
            <a href="?status=Closed" class="text-decoration-none">
                <div class="card border-0 shadow-sm stat-card <?php echo $filter_status === 'Closed' ? 'active-card' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small">Closed</p>
                                <h3 class="mb-0"><?php echo $stats['closed']; ?></h3>
                            </div>
                            <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle p-3">
                                <i class="fas fa-archive fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Blotter Records Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                <?php 
                if ($filter_status) {
                    echo htmlspecialchars($filter_status) . ' Blotter Records';
                } else {
                    echo 'All Blotter Records';
                }
                ?>
                <span class="badge bg-primary ms-2"><?php echo count($blotter_records); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($blotter_records)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>No blotter records found</p>
                <?php if (!empty($filter_status)): ?>
                <a href="manage-blotter.php" class="btn btn-outline-primary">
                    <i class="fas fa-times me-2"></i>Clear Filter
                </a>
                <?php else: ?>
                <p class="text-muted mt-2">Start by adding a new blotter record</p>
                <a href="add-blotter.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus me-2"></i>Add New Blotter
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle blotter-table">
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Incident Date</th>
                            <th>Type</th>
                            <th>Complainant</th>
                            <th>Respondent</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blotter_records as $record): ?>
                        <?php
                            // Prepare preview data
                            $case_num = htmlspecialchars($record['case_number'] ?? '#' . str_pad($record['blotter_id'], 5, '0', STR_PAD_LEFT));
                            $preview_title = htmlspecialchars($case_num . ' - ' . $record['incident_type']);
                            $preview_message = htmlspecialchars(mb_strimwidth($record['description'] ?? '', 0, 150, '...'));
                            $preview_type = htmlspecialchars($record['incident_type'] ?? 'Blotter');
                            $preview_time = date('M j, Y', strtotime($record['incident_date']));
                            $view_url = 'view-blotter.php?id=' . intval($record['blotter_id']);
                            
                            // Icon color based on status
                            $icon_color = 'warning';
                            if ($record['status'] === 'Closed') $icon_color = 'secondary';
                            elseif ($record['status'] === 'Resolved') $icon_color = 'success';
                            elseif ($record['status'] === 'Under Investigation') $icon_color = 'info';
                            elseif ($record['status'] === 'Pending') $icon_color = 'warning';
                        ?>
                        <tr class="blotter-row"
                            data-preview-title="<?= $preview_title ?>"
                            data-preview-message="<?= $preview_message ?>"
                            data-preview-icon="fa-clipboard-list"
                            data-preview-color="<?= $icon_color ?>"
                            data-preview-type="<?= $preview_type ?>"
                            data-preview-time="<?= $preview_time ?>"
                            data-url="<?= htmlspecialchars($view_url) ?>"
                            style="cursor: pointer;">
                            <td><strong><?= $case_num ?></strong></td>
                            <td>
                                <i class="fas fa-calendar-alt text-muted me-1"></i>
                                <?= date('M d, Y', strtotime($record['incident_date'])) ?>
                            </td>
                            <td><span class="badge bg-info"><?= htmlspecialchars($record['incident_type']) ?></span></td>
                            <td>
                                <i class="fas fa-user text-muted me-1"></i>
                                <?= htmlspecialchars($record['complainant_name'] ?? 'N/A') ?>
                            </td>
                            <td>
                                <i class="fas fa-user text-muted me-1"></i>
                                <?= htmlspecialchars($record['respondent_name'] ?? 'N/A') ?>
                            </td>
                            <td><?= htmlspecialchars(substr($record['description'], 0, 50)) ?>...</td>
                            <td><?= getStatusBadge($record['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Hover Preview Card -->
<div id="blotterPreviewCard" class="blotter-preview-card" style="display:none;">
    <div class="blotter-preview-header">
        <div class="blotter-preview-icon-wrap">
            <div class="blotter-preview-icon" id="previewIconBox">
                <i class="fas fa-clipboard-list" id="previewIcon"></i>
            </div>
        </div>
        <div class="blotter-preview-header-text">
            <div class="blotter-preview-type-label" id="previewTypeLabel"></div>
            <div class="blotter-preview-title" id="previewTitle"></div>
        </div>
    </div>
    <div class="blotter-preview-body">
        <p class="blotter-preview-message" id="previewMessage"></p>
        <div class="blotter-preview-footer">
            <i class="far fa-calendar-alt"></i>
            <span id="previewTime"></span>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    var alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });

    // ============================================
    // HOVER PREVIEW CARD + ROW CLICK NAVIGATION
    // ============================================
    const card        = document.getElementById('blotterPreviewCard');
    const previewIcon = document.getElementById('previewIcon');
    const previewIconBox = document.getElementById('previewIconBox');
    const previewTitle   = document.getElementById('previewTitle');
    const previewMessage = document.getElementById('previewMessage');
    const previewType    = document.getElementById('previewTypeLabel');
    const previewTime    = document.getElementById('previewTime');

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

        previewTitle.textContent   = row.dataset.previewTitle;
        previewMessage.textContent = row.dataset.previewMessage;
        previewType.textContent    = row.dataset.previewType;
        previewTime.textContent    = row.dataset.previewTime;
        previewIcon.className      = 'fas ' + row.dataset.previewIcon;

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
        const cw = card.offsetWidth  || 320;
        const ch = card.offsetHeight || 200;
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
    document.querySelectorAll('.blotter-row').forEach(row => {
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
});
</script>

<?php include '../../includes/footer.php'; ?>