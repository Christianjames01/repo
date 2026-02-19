<?php
/**
 * Notifications Dashboard - modules/notifications/index.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Notifications';
$user_id = $_SESSION['user_id'];
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    $notification_filter = "(
        type LIKE '%incident%' OR 
        type LIKE '%request%' OR 
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        type LIKE '%blotter%' OR         
        reference_type IN ('incident', 'request', 'document', 'complaint', 
                          'appointment', 'medical_assistance', 'blotter') 
    )";


} else {
    $notification_filter = "(
        type LIKE '%incident%' OR 
        type LIKE '%blotter%' OR 
        type LIKE '%request%' OR 
        type LIKE '%document%' OR
        type LIKE '%complaint%' OR
        type LIKE '%appointment%' OR
        type LIKE '%medical_assistance%' OR
        reference_type IN ('incident', 'blotter', 'request', 'document', 'complaint', 'appointment', 'medical_assistance')
    )";
}


// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $notification_id = intval($_POST['notification_id']);
        executeQuery($conn, 
            "DELETE FROM tbl_notifications 
             WHERE notification_id = ? AND user_id = ? AND $notification_filter", 
            [$notification_id, $user_id], 'ii'
        );
        $_SESSION['success_message'] = 'Notification deleted successfully';
        header('Location: index.php');
        exit();
        
    } elseif ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        $notification_id = intval($_POST['notification_id']);
        $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notification_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success_message'] = 'Notification marked as read';
        header('Location: index.php');
        exit();
        
    } elseif ($_POST['action'] === 'bulk_delete') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                executeQuery($conn, 
                    "DELETE FROM tbl_notifications 
                     WHERE notification_id = ? AND user_id = ? AND $notification_filter", 
                    [$nid, $user_id], 'ii'
                );
            }
            $_SESSION['success_message'] = 'Selected notifications deleted successfully';
        }
        header('Location: index.php');
        exit();
        
    } elseif ($_POST['action'] === 'bulk_read') {
        if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
            foreach ($_POST['notification_ids'] as $nid) {
                $nid = intval($nid);
                $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $nid, $user_id);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['success_message'] = 'Selected notifications marked as read';
        }
        header('Location: index.php');
        exit();
    }
}

// Statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as `read`,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
    FROM tbl_notifications 
    WHERE user_id = ? 
    AND $notification_filter
";
$stats = fetchOne($conn, $stats_query, [$user_id], 'i');

// Type breakdown
$type_query = "
    SELECT type, COUNT(*) as count
    FROM tbl_notifications 
    WHERE user_id = ? AND is_read = 0 AND $notification_filter
    GROUP BY type ORDER BY count DESC
";
$type_stats = fetchAll($conn, $type_query, [$user_id], 'i');

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$filter = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? '';

$where = "user_id = ? AND $notification_filter";
$params = [$user_id];
$types = 'i';

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $where .= " AND is_read = 1";
} elseif ($filter === 'today') {
    $where .= " AND DATE(created_at) = CURDATE()";
} elseif ($filter === 'week') {
    $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
}

if ($type_filter) {
    if (stripos($type_filter, 'blotter') !== false) {
        $where .= " AND (type LIKE '%blotter%' OR reference_type = 'blotter')";
    } elseif (stripos($type_filter, 'incident') !== false) {
        $where .= " AND (type LIKE '%incident%' OR reference_type = 'incident')";
    } elseif (stripos($type_filter, 'complaint') !== false) {
        $where .= " AND (type LIKE '%complaint%' OR reference_type = 'complaint')";
    } elseif (stripos($type_filter, 'appointment') !== false) {
        $where .= " AND (type LIKE '%appointment%' OR reference_type = 'appointment')";
    } elseif (stripos($type_filter, 'medical_assistance') !== false) {
        $where .= " AND (type LIKE '%medical_assistance%' OR reference_type = 'medical_assistance')";
    } elseif (stripos($type_filter, 'request') !== false || stripos($type_filter, 'document') !== false) {
        $where .= " AND (type LIKE '%request%' OR type LIKE '%document%' OR reference_type IN ('request', 'document'))";
    } else {
        $where .= " AND type = ?";
        $params[] = $type_filter;
        $types .= 's';
    }
}

$count = fetchOne($conn, "SELECT COUNT(*) as total FROM tbl_notifications WHERE $where", $params, $types);
$total = $count['total'];
$total_pages = ceil($total / $per_page);

$notifications = fetchAll($conn, 
    "SELECT * FROM tbl_notifications 
     WHERE $where 
     ORDER BY is_read ASC, created_at DESC 
     LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset]), 
    $types . 'ii'
);

// Type counts for filter buttons
$all_type_query = "
    SELECT type, reference_type, COUNT(*) as count
    FROM tbl_notifications 
    WHERE user_id = ? AND $notification_filter
    GROUP BY type, reference_type
";
$all_type_stats = fetchAll($conn, $all_type_query, [$user_id], 'i');

$incident_total = 0;
$blotter_total  = 0;
$complaint_total = 0;
$request_total  = 0;
$appointment_total = 0;
$medical_assistance_total = 0;

foreach ($all_type_stats as $ts) {
    if (stripos($ts['type'], 'incident') !== false || $ts['reference_type'] === 'incident') {
        $incident_total += $ts['count'];
    } elseif (stripos($ts['type'], 'blotter') !== false || $ts['reference_type'] === 'blotter') {
        $blotter_total += $ts['count'];
    } elseif (stripos($ts['type'], 'complaint') !== false || $ts['reference_type'] === 'complaint') {
        $complaint_total += $ts['count'];
    } elseif (stripos($ts['type'], 'appointment') !== false || $ts['reference_type'] === 'appointment') {
        $appointment_total += $ts['count'];
    } elseif (stripos($ts['type'], 'medical_assistance') !== false || $ts['reference_type'] === 'medical_assistance') {
        $medical_assistance_total += $ts['count'];
    } elseif (stripos($ts['type'], 'request') !== false || stripos($ts['type'], 'document') !== false ||
              $ts['reference_type'] === 'request' || $ts['reference_type'] === 'document') {
        $request_total += $ts['count'];
    }
}

$is_incident_active = (stripos($type_filter, 'incident') !== false);
$is_blotter_active  = (stripos($type_filter, 'blotter')  !== false);
$is_complaint_active= (stripos($type_filter, 'complaint')!== false);
$is_request_active  = (stripos($type_filter, 'request')  !== false || stripos($type_filter, 'document') !== false);
$is_appointment_active = (stripos($type_filter, 'appointment') !== false);
$is_medical_assistance_active = (stripos($type_filter, 'medical_assistance') !== false);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-bell me-2 text-primary"></i>My Notifications
                    </h2>
                    <p class="text-muted mb-0">
                        <i class="far fa-calendar me-1"></i><?= date('l, F j, Y') ?>
                    </p>
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php if ($user_role === 'Resident'): ?>
                            Showing notifications for Incidents, Complaints, and Document Requests
                        <?php else: ?>
                            Showing notifications for Incidents, Blotter, Complaints, and Document Requests
                        <?php endif; ?>
                    </small>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($user_role === 'Super Admin' || $user_role === 'Super Administrator'): ?>
                        <a href="email-residents.php" class="btn btn-info">
                            <i class="fas fa-envelope me-2"></i>Email Residents
                        </a>
                        <a href="email-history.php" class="btn btn-info">
                            <i class="fas fa-history me-2"></i>Email History
                        </a>
                    <?php endif; ?>
                    <?php if ($stats['unread'] > 0): ?>
                        <a href="mark_all_read.php" class="btn btn-success">
                            <i class="fas fa-check-double me-2"></i>Mark All Read
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <a href="?filter=all<?= $type_filter ? '&type='.$type_filter : '' ?>" class="stat-card-link">
                <div class="stat-card stat-card-primary">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-bell"></i></div>
                        <div class="stat-details">
                            <h3 class="stat-number"><?= $stats['total'] ?></h3>
                            <p class="stat-label">Total Notifications</p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <a href="?filter=unread" class="stat-card-link">
                <div class="stat-card stat-card-warning">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                        <div class="stat-details">
                            <h3 class="stat-number"><?= $stats['unread'] ?></h3>
                            <p class="stat-label">Unread</p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <a href="?filter=read" class="stat-card-link">
                <div class="stat-card stat-card-success">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-envelope-open"></i></div>
                        <div class="stat-details">
                            <h3 class="stat-number"><?= $stats['read'] ?></h3>
                            <p class="stat-label">Read</p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <a href="?filter=today" class="stat-card-link">
                <div class="stat-card stat-card-info">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stat-details">
                            <h3 class="stat-number"><?= $stats['today'] ?></h3>
                            <p class="stat-label">Today</p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <a href="?filter=week" class="stat-card-link">
                <div class="stat-card stat-card-purple">
                    <div class="stat-content">
                        <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
                        <div class="stat-details">
                            <h3 class="stat-number"><?= $stats['this_week'] ?></h3>
                            <p class="stat-label">This Week</p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

  <!-- Filter Buttons -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="text-muted fw-semibold me-2" style="font-size: 0.9rem;">FILTER BY TYPE:</span>

                    <a href="?filter=<?= $filter ?>" 
                       class="btn btn-sm <?= !$type_filter ? 'btn-secondary' : 'btn-outline-secondary' ?> rounded-pill">
                        <i class="fas fa-th me-1"></i>All Types
                        <span class="badge <?= !$type_filter ? 'bg-white text-secondary' : 'bg-secondary text-white' ?> ms-1"><?= $stats['total'] ?></span>
                    </a>

                    <?php if ($incident_total > 0): ?>
                    <a href="?filter=<?= $filter ?>&type=incident_reported" 
                       class="btn btn-sm <?= $is_incident_active ? 'btn-primary' : 'btn-outline-primary' ?> rounded-pill">
                        <i class="fas fa-exclamation-triangle me-1"></i>Incident
                        <span class="badge <?= $is_incident_active ? 'bg-white text-primary' : 'bg-primary text-white' ?> ms-1"><?= $incident_total ?></span>
                    </a>
                    <?php endif; ?>

                  <?php if ($blotter_total > 0): ?>
                    <a href="?filter=<?= $filter ?>&type=blotter_filed" 
                       class="btn btn-sm <?= $is_blotter_active ? 'btn-danger' : 'btn-outline-danger' ?> rounded-pill">
                        <i class="fas fa-gavel me-1"></i>Blotter
                        <span class="badge <?= $is_blotter_active ? 'bg-white text-danger' : 'bg-danger text-white' ?> ms-1"><?= $blotter_total ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($complaint_total > 0): ?>
                    <a href="?filter=<?= $filter ?>&type=complaint_filed" 
                       class="btn btn-sm <?= $is_complaint_active ? 'btn-warning' : 'btn-outline-warning' ?> rounded-pill">
                        <i class="fas fa-comments me-1"></i>Complaints
                        <span class="badge <?= $is_complaint_active ? 'bg-white text-warning' : 'bg-warning text-white' ?> ms-1"><?= $complaint_total ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($request_total > 0): ?>
                    <a href="?filter=<?= $filter ?>&type=document_request" 
                       class="btn btn-sm <?= $is_request_active ? 'btn-info' : 'btn-outline-info' ?> rounded-pill">
                        <i class="fas fa-file-alt me-1"></i>Request Documents
                        <span class="badge <?= $is_request_active ? 'bg-white text-info' : 'bg-info text-white' ?> ms-1"><?= $request_total ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($appointment_total > 0): ?>
                    <a href="?filter=<?= $filter ?>&type=appointment_booked" 
                       class="btn btn-sm <?= $is_appointment_active ? 'btn-success' : 'btn-outline-success' ?> rounded-pill">
                        <i class="fas fa-calendar-check me-1"></i>Appointments
                        <span class="badge <?= $is_appointment_active ? 'bg-white text-success' : 'bg-success text-white' ?> ms-1"><?= $appointment_total ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($medical_assistance_total > 0): ?>
                    <a href="?filter=<?= $filter ?>&type=medical_assistance_request" 
                       class="btn btn-sm <?= $is_medical_assistance_active ? 'btn-purple' : 'btn-outline-purple' ?> rounded-pill">
                        <i class="fas fa-hand-holding-medical me-1"></i>Medical Assistance
                        <span class="badge <?= $is_medical_assistance_active ? 'bg-white text-purple' : 'bg-purple text-white' ?> ms-1"><?= $medical_assistance_total ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Main Content Card -->
    <div class="row">
        <div class="col-12">
            <div class="card main-card shadow-sm border-0">
                <div class="card-header bg-white border-bottom-0">
                    <div class="row align-items-center g-3">
                        <div class="col-md-4">
                            <h5 class="mb-0 fw-semibold">
                                <i class="fas fa-list-ul me-2 text-primary"></i>
                                <?php 
                                $filter_titles = [
                                    'all'   => 'All Notifications',
                                    'unread'=> 'Unread Notifications',
                                    'read'  => 'Read Notifications',
                                    'today' => "Today's Notifications",
                                    'week'  => "This Week's Notifications"
                                ];
                                echo $filter_titles[$filter] ?? 'All Notifications';
                                ?>
                                <span class="badge bg-primary ms-2"><?= $total ?></span>
                            </h5>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                                <!-- Status Filter Dropdown -->
                                <div class="dropdown">
                                    <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-filter me-1"></i>
                                        Status: <?php 
                                        $filter_labels = ['all'=>'All','unread'=>'Unread','read'=>'Read','today'=>'Today','week'=>'This Week'];
                                        echo $filter_labels[$filter] ?? 'All';
                                        ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li><h6 class="dropdown-header">Filter by Status</h6></li>
                                        <li><a class="dropdown-item <?= $filter==='all'?'active':'' ?>" 
                                               href="?filter=all<?= $type_filter?'&type='.$type_filter:'' ?>">
                                            <i class="fas fa-list me-2"></i>All Notifications</a></li>
                                        <li><a class="dropdown-item <?= $filter==='unread'?'active':'' ?>" 
                                               href="?filter=unread<?= $type_filter?'&type='.$type_filter:'' ?>">
                                            <i class="fas fa-envelope me-2"></i>Unread Only</a></li>
                                        <li><a class="dropdown-item <?= $filter==='read'?'active':'' ?>" 
                                               href="?filter=read<?= $type_filter?'&type='.$type_filter:'' ?>">
                                            <i class="fas fa-envelope-open me-2"></i>Read Only</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">Filter by Date</h6></li>
                                        <li><a class="dropdown-item <?= $filter==='today'?'active':'' ?>" 
                                               href="?filter=today<?= $type_filter?'&type='.$type_filter:'' ?>">
                                            <i class="fas fa-calendar-day me-2"></i>Today</a></li>
                                        <li><a class="dropdown-item <?= $filter==='week'?'active':'' ?>" 
                                               href="?filter=week<?= $type_filter?'&type='.$type_filter:'' ?>">
                                            <i class="fas fa-calendar-week me-2"></i>This Week</a></li>
                                    </ul>
                                </div>

                                <!-- Type Filter Dropdown -->
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-tag me-1"></i>
                                        Type: <?php
                                        if ($type_filter) {
                                            if (stripos($type_filter,'incident')!==false) echo 'Incident';
                                            elseif (stripos($type_filter,'blotter')!==false) echo 'Blotter';
                                            elseif (stripos($type_filter,'complaint')!==false) echo 'Complaints';
                                            elseif (stripos($type_filter,'appointment')!==false) echo 'Appointments';
                                            elseif (stripos($type_filter,'medical_assistance')!==false) echo 'Medical Assistance';
                                            elseif (stripos($type_filter,'request')!==false||stripos($type_filter,'document')!==false) echo 'Request Documents';
                                            else echo ucwords(str_replace('_',' ',$type_filter));
                                        } else { echo 'All'; }
                                        ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li><h6 class="dropdown-header">Filter by Type</h6></li>
                                        <li><a class="dropdown-item <?= !$type_filter?'active':'' ?>" href="?filter=<?= $filter ?>">
                                            <i class="fas fa-th me-2"></i>All Types</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php if ($incident_total > 0): ?>
                                        <li><a class="dropdown-item <?= $is_incident_active?'active':'' ?>" 
                                               href="?filter=<?= $filter ?>&type=incident_reported">
                                            <i class="fas fa-exclamation-triangle me-2 text-primary"></i>Incident
                                            <span class="badge bg-primary ms-1"><?= $incident_total ?></span></a></li>
                                        <?php endif; ?>
                                     <?php if ($blotter_total > 0): ?>
                                        <li><a class="dropdown-item <?= $is_blotter_active?'active':'' ?>" 
                                               href="?filter=<?= $filter ?>&type=blotter_filed">
                                            <i class="fas fa-gavel me-2 text-danger"></i>Blotter
                                            <span class="badge bg-danger ms-1"><?= $blotter_total ?></span></a></li>
                                        <?php endif; ?>
                                        <?php if ($complaint_total > 0): ?>
                                        <li><a class="dropdown-item <?= $is_complaint_active?'active':'' ?>" 
                                               href="?filter=<?= $filter ?>&type=complaint_filed">
                                            <i class="fas fa-comments me-2 text-warning"></i>Complaints
                                            <span class="badge bg-warning ms-1"><?= $complaint_total ?></span></a></li>
                                        <?php endif; ?>
                                        <?php if ($request_total > 0): ?>
                                        <li><a class="dropdown-item <?= $is_request_active?'active':'' ?>" 
                                               href="?filter=<?= $filter ?>&type=document_request">
                                            <i class="fas fa-file-alt me-2 text-info"></i>Request Documents
                                            <span class="badge bg-info ms-1"><?= $request_total ?></span></a></li>
                                        <?php endif; ?>
                                        <?php if ($appointment_total > 0): ?>
                                        <li><a class="dropdown-item <?= $is_appointment_active?'active':'' ?>" 
                                               href="?filter=<?= $filter ?>&type=appointment_booked">
                                            <i class="fas fa-calendar-check me-2 text-success"></i>Appointments
                                            <span class="badge bg-success ms-1"><?= $appointment_total ?></span></a></li>
                                        <?php endif; ?>
                                        <?php if ($medical_assistance_total > 0): ?>
                                        <li><a class="dropdown-item <?= $is_medical_assistance_active?'active':'' ?>" 
                                               href="?filter=<?= $filter ?>&type=medical_assistance_request">
                                            <i class="fas fa-hand-holding-medical me-2 text-purple"></i>Medical Assistance
                                            <span class="badge bg-purple ms-1"><?= $medical_assistance_total ?></span></a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <!-- Bulk Actions -->
                                <?php if (!empty($notifications)): ?>
                                <div class="dropdown">
                                    <button class="btn btn-outline-dark btn-sm dropdown-toggle" type="button" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-tasks me-1"></i>Bulk Actions
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li><h6 class="dropdown-header">Bulk Operations</h6></li>
                                        <li><a class="dropdown-item" href="#" onclick="selectAll(); return false;">
                                            <i class="fas fa-check-square me-2"></i>Select All</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="deselectAll(); return false;">
                                            <i class="fas fa-square me-2"></i>Deselect All</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-success" href="#" onclick="bulkMarkRead(); return false;">
                                            <i class="fas fa-check-circle me-2"></i>Mark Selected as Read</a></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="bulkDelete(); return false;">
                                            <i class="fas fa-trash me-2"></i>Delete Selected</a></li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                
                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state text-center py-5">
                            <div class="empty-icon mb-4">
                                <i class="fas fa-bell-slash fa-5x text-muted opacity-50"></i>
                            </div>
                            <h4 class="text-muted mb-2">No Notifications Found</h4>
                            <p class="text-muted mb-4">
                                <?php 
                                if ($filter === 'unread') echo "You're all caught up! No unread notifications.";
                                elseif ($filter === 'today') echo "No notifications received today.";
                                else echo $user_role === 'Resident'
                                    ? "You don't have any notifications for incidents, complaints, or requests yet."
                                    : "You don't have any notifications for incidents, blotter, complaints, or requests yet.";
                                ?>
                            </p>
                            <?php if ($filter !== 'all'): ?>
                                <a href="?filter=all" class="btn btn-primary">
                                    <i class="fas fa-list me-2"></i>View All Notifications
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <form id="bulkForm" method="POST">
                            <input type="hidden" name="action" id="bulkAction">
                            <div class="table-responsive">
                                <table class="table table-hover notifications-table mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40px;" class="text-center">
                                                <input type="checkbox" class="form-check-input" id="selectAllCheckbox" 
                                                       onclick="toggleSelectAll(this)">
                                            </th>
                                            <th style="width:60px;"></th>
                                            <th>Notification Details</th>
                                            <th style="width:160px;">Received</th>

                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($notifications as $notif):
                                            $time_text = timeAgo($notif['created_at']);
                                            
                                            $icon = 'fa-bell';
                                            $icon_color = 'primary';
                                            $badge_color = 'primary';
                                            
                                            if (stripos($notif['type'], 'incident') !== false || $notif['reference_type'] === 'incident') {
                                                $icon = 'fa-exclamation-triangle'; $icon_color = 'warning'; $badge_color = 'warning';
                                                if ($notif['type'] == 'incident_assignment') { $icon = 'fa-user-check'; $icon_color = 'info'; $badge_color = 'info'; }
                                                elseif ($notif['type'] == 'status_update') { $icon = 'fa-sync-alt'; $icon_color = 'success'; $badge_color = 'success'; }
                                            } elseif (stripos($notif['type'], 'blotter') !== false || $notif['reference_type'] === 'blotter') {
                                                $icon = 'fa-gavel'; $icon_color = 'danger'; $badge_color = 'danger';
                                            } elseif (stripos($notif['type'], 'complaint') !== false || $notif['reference_type'] === 'complaint') {
                                                $icon = 'fa-comments'; $icon_color = 'warning'; $badge_color = 'warning';
                                                if (stripos($notif['type'], 'status') !== false) { $icon = 'fa-sync-alt'; $icon_color = 'success'; $badge_color = 'success'; }
                                            } elseif (stripos($notif['type'], 'request') !== false || stripos($notif['type'], 'document') !== false ||
                                                    $notif['reference_type'] === 'request' || $notif['reference_type'] === 'document') {
                                                $icon = 'fa-file-alt'; $icon_color = 'info'; $badge_color = 'info';
                                            } elseif (stripos($notif['type'], 'appointment') !== false || $notif['reference_type'] === 'appointment') {
                                                $icon = 'fa-calendar-check'; $icon_color = 'success'; $badge_color = 'success';
                                                if (stripos($notif['type'], 'cancelled') !== false) { $icon = 'fa-calendar-times'; $icon_color = 'danger'; $badge_color = 'danger'; }
                                            } elseif (stripos($notif['type'], 'medical_assistance') !== false || $notif['reference_type'] === 'medical_assistance') {
                                                $icon = 'fa-hand-holding-medical'; $icon_color = 'primary'; $badge_color = 'primary';
                                            }

                                         $view_url = null;
                                            if (!empty($notif['reference_id'])) {
                                                if ($notif['reference_type'] == 'incident') {
                                                    $view_url = $user_role === 'Resident'
                                                        ? '../incidents/incident-details.php?id=' . intval($notif['reference_id'])
                                                        : '../incidents/incident-details.php?id=' . intval($notif['reference_id']);

                                                } elseif ($notif['reference_type'] == 'blotter') {
                                                    $view_url = '../blotter/view-blotter.php?id=' . intval($notif['reference_id']);

                                                } elseif ($notif['reference_type'] == 'complaint') {
                                                    $view_url = '../complaints/complaint-details.php?id=' . intval($notif['reference_id']);

                                                } elseif ($notif['reference_type'] == 'request' || $notif['reference_type'] == 'document') {
                                                    $view_url = '../requests/view-request.php?id=' . intval($notif['reference_id']);

                                                } elseif ($notif['reference_type'] == 'appointment') {
                                                    $view_url = '../health/appointments.php';

                                                } elseif ($notif['reference_type'] == 'medical_assistance') {
                                                    $view_url = '../health/medical-assistance.php';
                                                }
                                            }

                                            // Truncate message for preview (first 120 chars)
                                            $preview_msg = htmlspecialchars(mb_strimwidth($notif['message'], 0, 120, '...'));
                                            $full_title   = htmlspecialchars($notif['title']);
                                            $type_label   = htmlspecialchars(ucwords(str_replace('_', ' ', $notif['type'])));
                                        ?>
                                            <tr class="notification-row <?= !$notif['is_read'] ? 'unread-row' : '' ?>"
                                                data-preview-title="<?= $full_title ?>"
                                                data-preview-message="<?= $preview_msg ?>"
                                                data-preview-icon="<?= $icon ?>"
                                                data-preview-color="<?= $icon_color ?>"
                                                data-preview-type="<?= $type_label ?>"
                                                data-preview-time="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($notif['created_at']))) ?>"
                                                data-url="<?= $view_url ? htmlspecialchars($view_url) : '' ?>"
                                                data-notif-id="<?= $notif['notification_id'] ?>"
                                                data-is-read="<?= $notif['is_read'] ? '1' : '0' ?>"
                                                style="<?= $view_url ? 'cursor:pointer;' : '' ?>">

                                                <!-- Checkbox -->
                                                <td class="text-center">
                                                    <input type="checkbox" class="form-check-input notification-checkbox" 
                                                           name="notification_ids[]" value="<?= $notif['notification_id'] ?>">
                                                </td>

                                                <!-- Icon -->
                                                <td class="text-center">
                                                    <div class="notification-icon-wrapper">
                                                        <div class="notification-icon bg-<?= $icon_color ?>-subtle">
                                                            <i class="fas <?= $icon ?> text-<?= $icon_color ?>"></i>
                                                        </div>
                                                        <?php if (!$notif['is_read']): ?>
                                                            <span class="unread-indicator"></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <!-- Content -->
                                                <td>
                                                    <div class="notification-content">
                                                        <h6 class="notification-title mb-1">
                                                            <?= $full_title ?>
                                                            <?php if (!$notif['is_read']): ?>
                                                                <span class="badge bg-primary ms-2 badge-new">NEW</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <p class="notification-message mb-2">
                                                            <?= $preview_msg ?>
                                                        </p>
                                                        <div class="notification-meta">
                                                            <span class="badge bg-<?= $badge_color ?>-subtle text-<?= $badge_color ?> me-2">
                                                                <i class="fas fa-tag me-1"></i><?= $type_label ?>
                                                            </span>
                                                            <?php if (!empty($notif['reference_type'])): ?>
                                                                <span class="badge bg-secondary-subtle text-secondary">
                                                                    <i class="fas fa-link me-1"></i>
                                                                    <?= ucwords($notif['reference_type']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Time -->
                                                <td>
                                                    <div class="notification-time">
                                                        <i class="far fa-clock text-muted me-1"></i>
                                                        <span class="text-muted"><?= $time_text ?></span><br>
                                                        <small class="text-muted">
                                                            <?= date('M j, g:i A', strtotime($notif['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                </td>

                                                <!-- Actions td removed — row itself is clickable -->
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white border-top">
                            <div class="row align-items-center">
                                <div class="col-md-6 mb-2 mb-md-0">
                                    <p class="mb-0 text-muted small">
                                        Showing <?= $offset+1 ?> to <?= min($offset+$per_page,$total) ?> of <?= $total ?> notifications
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <nav>
                                        <ul class="pagination pagination-sm justify-content-md-end justify-content-center mb-0">
                                            <li class="page-item <?= $page<=1?'disabled':'' ?>">
                                                <a class="page-link" href="?page=1&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>">
                                                    <i class="fas fa-angle-double-left"></i></a></li>
                                            <li class="page-item <?= $page<=1?'disabled':'' ?>">
                                                <a class="page-link" href="?page=<?= $page-1 ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>">
                                                    <i class="fas fa-chevron-left"></i></a></li>
                                            <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
                                            <li class="page-item <?= $i===$page?'active':'' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>"><?= $i ?></a></li>
                                            <?php endfor; ?>
                                            <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
                                                <a class="page-link" href="?page=<?= $page+1 ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>">
                                                    <i class="fas fa-chevron-right"></i></a></li>
                                            <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
                                                <a class="page-link" href="?page=<?= $total_pages ?>&filter=<?= $filter ?><?= $type_filter?'&type='.$type_filter:'' ?>">
                                                    <i class="fas fa-angle-double-right"></i></a></li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =============================================
     HOVER PREVIEW CARD (floats near cursor)
============================================== -->
<div id="notifPreviewCard" class="notif-preview-card" style="display:none;">
    <div class="notif-preview-header">
        <div class="notif-preview-icon-wrap">
            <div class="notif-preview-icon" id="previewIconBox">
                <i class="fas fa-bell" id="previewIcon"></i>
            </div>
        </div>
        <div class="notif-preview-header-text">
            <div class="notif-preview-type-label" id="previewTypeLabel"></div>
            <div class="notif-preview-title" id="previewTitle"></div>
        </div>
    </div>
    <div class="notif-preview-body">
        <p class="notif-preview-message" id="previewMessage"></p>
        <div class="notif-preview-footer">
            <i class="far fa-clock me-1"></i>
            <span id="previewTime"></span>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle"
                             style="width: 72px; height: 72px;">
                            <i class="fas fa-trash fa-2x text-danger"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2">Delete Notification</h5>
                    <p class="text-muted mb-0">Are you sure you want to delete this notification? This action cannot be undone.</p>
                </div>
                <div class="px-4 pb-4 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill fw-semibold" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger flex-fill fw-semibold" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-2"></i>Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Validation Modal -->
<div class="modal fade" id="bulkValidationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle"
                             style="width: 72px; height: 72px;">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2" id="bulkValidationTitle">Action Required</h5>
                    <p class="text-muted mb-0" id="bulkValidationMessage">Please select notifications first.</p>
                </div>
                <div class="px-4 pb-4">
                    <button type="button" class="btn btn-primary w-100 fw-semibold" data-bs-dismiss="modal">
                        <i class="fas fa-check me-2"></i>Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Confirm Modal -->
<div class="modal fade" id="bulkConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle"
                             id="bulkConfirmIconWrap"
                             style="width: 72px; height: 72px; background: rgba(13,110,253,0.1);">
                            <i class="fas fa-check-circle fa-2x text-primary" id="bulkConfirmIcon"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2" id="bulkConfirmTitle">Confirm Action</h5>
                    <p class="text-muted mb-0" id="bulkConfirmMessage">Proceed with this bulk action?</p>
                </div>
                <div class="px-4 pb-4 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill fw-semibold" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary flex-fill fw-semibold" id="bulkConfirmBtn">
                        <i class="fas fa-check me-2"></i>Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ============================================
   STATISTICS CARDS
============================================ */
.stat-card-link {
    text-decoration: none;
    display: block;
}

.stat-card {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    border: 1px solid rgba(0,0,0,0.05);
    height: 100%;
    position: relative;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--card-gradient-start), var(--card-gradient-end));
}
.stat-card-link:hover .stat-card { 
    transform: translateY(-8px); 
    box-shadow: 0 8px 24px rgba(0,0,0,0.15); 
}
.stat-card-primary { --card-gradient-start: #0d6efd; --card-gradient-end: #0a58ca; }
.stat-card-warning { --card-gradient-start: #ffc107; --card-gradient-end: #f59f00; }
.stat-card-success { --card-gradient-start: #198754; --card-gradient-end: #146c43; }
.stat-card-info    { --card-gradient-start: #0dcaf0; --card-gradient-end: #0aa2c0; }
.stat-card-purple  { --card-gradient-start: #6f42c1; --card-gradient-end: #5a32a3; }
.stat-content { padding: 1.5rem; display: flex; align-items: center; gap: 1rem; }
.stat-icon {
    width: 64px; height: 64px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.75rem; flex-shrink: 0;
}
.stat-card-primary .stat-icon { background: rgba(13,110,253,0.12); color: #0d6efd; }
.stat-card-warning .stat-icon { background: rgba(255,193,7,0.12); color: #ffc107; }
.stat-card-success .stat-icon { background: rgba(25,135,84,0.12); color: #198754; }
.stat-card-info    .stat-icon { background: rgba(13,202,240,0.12); color: #0dcaf0; }
.stat-card-purple  .stat-icon { background: rgba(111,66,193,0.12); color: #6f42c1; }
.stat-details { flex: 1; }
.stat-number {
    font-size: 2.25rem; font-weight: 800; margin-bottom: 0.25rem; line-height: 1;
    background: linear-gradient(135deg,#333,#666);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.stat-label { font-size: 0.875rem; color: #6c757d; margin: 0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.btn-purple {
    background: #6f42c1;
    color: white;
}

.btn-purple:hover {
    background: #5a32a3;
    color: white;
}

.btn-outline-purple {
    border-color: #6f42c1;
    color: #6f42c1;
}

.btn-outline-purple:hover {
    background: #6f42c1;
    color: white;
}

.bg-purple {
    background: #6f42c1;
}

.text-purple {
    color: #6f42c1;
}
/* ============================================
   MAIN TABLE
============================================ */
.main-card { border-radius: 16px; overflow: hidden; }
.card-header { padding: 1.5rem; }
.notifications-table { font-size: 0.9rem; }
.notifications-table thead th {
    font-weight: 700; text-transform: uppercase; font-size: 0.75rem;
    letter-spacing: 0.5px; color: #495057; border-bottom: 2px solid #dee2e6;
    padding: 1rem; background: #f8f9fa;
}
.notifications-table tbody td { padding: 1.25rem 1rem; vertical-align: middle; border-bottom: 1px solid #f0f0f0; }
.notification-row { transition: all 0.2s; background: white; position: relative; }
.notification-row:hover { background: #f8f9fa; box-shadow: inset 3px 0 0 #0d6efd; }
.notification-row.unread-row {
    background: linear-gradient(to right, rgba(13,110,253,0.04), rgba(13,110,253,0.01));
    border-left: 4px solid #0d6efd;
}
.notification-icon-wrapper { position: relative; display: inline-block; }
.notification-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; transition: transform 0.2s;
}
.notification-row:hover .notification-icon { transform: scale(1.1); }
.unread-indicator {
    position: absolute; top: -4px; right: -4px;
    width: 12px; height: 12px;
    background: #0d6efd; border-radius: 50%; border: 2px solid white;
    animation: pulseDot 2s infinite;
}
@keyframes pulseDot { 0%,100%{opacity:1;} 50%{opacity:0.5;} }
.notification-title { font-size: 1rem; font-weight: 600; color: #212529; margin-bottom: 0.5rem; }
.notification-message { font-size: 0.875rem; color: #6c757d; line-height: 1.5; margin-bottom: 0.5rem; }
.notification-meta { font-size: 0.75rem; }
.badge-new { font-size: 0.65rem; padding: 0.25rem 0.5rem; font-weight: 700; }

/* ============================================
   HOVER PREVIEW CARD
============================================ */
.notif-preview-card {
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
.notif-preview-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f0f0f0;
}
.notif-preview-icon-wrap { flex-shrink: 0; }
.notif-preview-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
}
.notif-preview-header-text { flex: 1; min-width: 0; }
.notif-preview-type-label {
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.5px; color: #6c757d; margin-bottom: 2px;
}
.notif-preview-title {
    font-size: 0.92rem; font-weight: 700; color: #212529;
    line-height: 1.3;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.notif-preview-body { padding: 12px 16px 14px; }
.notif-preview-message {
    font-size: 0.82rem; color: #495057; line-height: 1.6; margin-bottom: 10px;
}
.notif-preview-footer {
    font-size: 0.75rem; color: #adb5bd;
    display: flex; align-items: center;
}

/* ============================================
   COLOR UTILITIES
============================================ */
.bg-primary-subtle   { background-color: rgba(13,110,253,0.10); }
.bg-warning-subtle   { background-color: rgba(255,193,7,0.10); }
.bg-success-subtle   { background-color: rgba(25,135,84,0.10); }
.bg-info-subtle      { background-color: rgba(13,202,240,0.10); }
.bg-secondary-subtle { background-color: rgba(108,117,125,0.10); }
.bg-danger-subtle    { background-color: rgba(220,53,69,0.10); }

/* ============================================
   DROPDOWN
============================================ */
.dropdown-menu { border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border: 1px solid rgba(0,0,0,0.08); padding: 0.5rem; }
.dropdown-header { font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; padding: 0.5rem 1rem; }
.dropdown-item { font-size: 0.875rem; padding: 0.65rem 1rem; border-radius: 8px; transition: all 0.2s; }
.dropdown-item:hover { background: #f8f9fa; transform: translateX(4px); }
.dropdown-item.active { background: linear-gradient(135deg,#0d6efd,#0a58ca); color: white; }

/* ============================================
   EMPTY STATE & ANIMATIONS
============================================ */
.empty-state { padding: 4rem 2rem; }
.empty-icon { animation: float 3s ease-in-out infinite; }
@keyframes float { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-10px);} }

/* ============================================
   RESPONSIVE
============================================ */
@media (max-width: 768px) {
    .stat-number { font-size: 1.75rem; }
    .stat-icon { width: 52px; height: 52px; }
    .notifications-table { font-size: 0.85rem; }
    .notifications-table thead th,
    .notifications-table tbody td { padding: 0.75rem 0.5rem; }
    .notification-actions { flex-direction: column; }
    .notif-preview-card { display: none !important; } /* disable on mobile */
}
</style>

<script>
(function () {
    const card        = document.getElementById('notifPreviewCard');
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
    document.querySelectorAll('.notification-row').forEach(row => {

        row.addEventListener('mouseenter', function (e) { showCard(this, e); });
        row.addEventListener('mousemove',  function (e) { positionCard(e); });
        row.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(() => {
                if (!card.matches(':hover')) hideCard();
            }, 150);
        });

        row.addEventListener('click', function (e) {
            if (e.target.closest('input[type="checkbox"]')) return;
            const url     = this.dataset.url;
            const notifId = this.dataset.notifId;
            const isRead  = this.dataset.isRead;

            if (!notifId) return;

            // Instant visual feedback
            if (isRead === '0') {
                this.classList.remove('unread-row');
                const indicator = this.querySelector('.unread-indicator');
                if (indicator) indicator.remove();
                const badge = this.querySelector('.badge-new');
                if (badge) badge.remove();
            }

            window.location.href = `mark_read_redirect.php?id=${notifId}&redirect=${encodeURIComponent(url || 'index.php')}`;
        });

    }); // ← closes forEach

})(); // ← closes IIFE

// ============================================
// BULK ACTIONS
// ============================================
function toggleSelectAll(checkbox) {
    document.querySelectorAll('.notification-checkbox').forEach(cb => cb.checked = checkbox.checked);
}
function selectAll() {
    document.querySelectorAll('.notification-checkbox').forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
}
function deselectAll() {
    document.querySelectorAll('.notification-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
}

function showBulkValidation(message, title = 'Action Required') {
    document.getElementById('bulkValidationTitle').textContent   = title;
    document.getElementById('bulkValidationMessage').textContent = message;
    new bootstrap.Modal(document.getElementById('bulkValidationModal')).show();
}

function showBulkConfirm(message, title, iconClass, btnClass, onConfirm) {
    document.getElementById('bulkConfirmTitle').textContent   = title;
    document.getElementById('bulkConfirmMessage').textContent = message;
    const iconEl = document.getElementById('bulkConfirmIcon');
    iconEl.className = 'fas ' + iconClass + ' fa-2x text-' + btnClass;
    const wrap = document.getElementById('bulkConfirmIconWrap');
    wrap.style.background = btnClass === 'danger' ? 'rgba(220,53,69,0.1)' : 'rgba(13,110,253,0.1)';
    const btn = document.getElementById('bulkConfirmBtn');
    btn.className = 'btn btn-' + btnClass + ' flex-fill fw-semibold';
    const freshBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(freshBtn, btn);
    const modal = new bootstrap.Modal(document.getElementById('bulkConfirmModal'));
    freshBtn.addEventListener('click', () => { modal.hide(); onConfirm(); });
    modal.show();
}

function bulkMarkRead() {
    const checked = document.querySelectorAll('.notification-checkbox:checked');
    if (checked.length === 0) {
        showBulkValidation('Please select at least one notification to mark as read.', 'Nothing Selected');
        return;
    }
    showBulkConfirm(
        `Mark ${checked.length} notification(s) as read?`,
        'Mark as Read', 'fa-check-circle', 'primary',
        () => {
            document.getElementById('bulkAction').value = 'bulk_read';
            document.getElementById('bulkForm').submit();
        }
    );
}

function bulkDelete() {
    const checked = document.querySelectorAll('.notification-checkbox:checked');
    if (checked.length === 0) {
        showBulkValidation('Please select at least one notification to delete.', 'Nothing Selected');
        return;
    }
    showBulkConfirm(
        `Delete ${checked.length} notification(s)? This action cannot be undone.`,
        'Delete Notifications', 'fa-trash', 'danger',
        () => {
            document.getElementById('bulkAction').value = 'bulk_delete';
            document.getElementById('bulkForm').submit();
        }
    );
}
</script>

<?php include '../../includes/footer.php'; ?>