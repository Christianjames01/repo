<?php
/**
 * Notifications Page - modules/notifications/index.php
 * View all notifications for current user
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Notifications';
$user_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        $notification_id = intval($_POST['notification_id']);
        executeQuery($conn, 
            "DELETE FROM tbl_notifications WHERE notification_id = ? AND user_id = ?", 
            [$notification_id, $user_id], 'ii'
        );
        $_SESSION['success_message'] = 'Notification deleted successfully';
        header('Location: index.php');
        exit();
    } elseif ($_POST['action'] === 'mark_read') {
        $notification_id = intval($_POST['notification_id']);
        markNotificationAsRead($conn, $notification_id, $user_id);
        $_SESSION['success_message'] = 'Notification marked as read';
        header('Location: index.php');
        exit();
    }
}

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filter
$filter = $_GET['filter'] ?? 'all';
$where = "user_id = ?";
$params = [$user_id];
$types = 'i';

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $where .= " AND is_read = 1";
}

// Get total count
$count = fetchOne($conn, "SELECT COUNT(*) as total FROM tbl_notifications WHERE $where", $params, $types);
$total = $count['total'];
$total_pages = ceil($total / $per_page);

// Get notifications
$notifications = fetchAll($conn, 
    "SELECT * FROM tbl_notifications 
     WHERE $where 
     ORDER BY created_at DESC 
     LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset]), 
    $types . 'ii'
);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h4 class="mb-0">
                        <i class="fas fa-bell me-2 text-primary"></i>Notifications
                    </h4>
                    <?php if ($total > 0): ?>
                        <a href="mark_all_read.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-check-double"></i> Mark All as Read
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="card-body">
                    <?php echo displayMessage(); ?>
                    
                    <!-- Filter Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" 
                               href="?filter=all">
                                <i class="fas fa-list"></i> All (<?php echo $total; ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'unread' ? 'active' : ''; ?>" 
                               href="?filter=unread">
                                <i class="fas fa-envelope"></i> Unread
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'read' ? 'active' : ''; ?>" 
                               href="?filter=read">
                                <i class="fas fa-envelope-open"></i> Read
                            </a>
                        </li>
                    </ul>
                    
                    <!-- Notifications List -->
                    <?php if (empty($notifications)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-bell-slash fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No notifications</h5>
                            <p class="text-muted">You're all caught up!</p>
                        </div>
                    <?php else: ?>
                        <div class="notifications-list">
                            <?php foreach ($notifications as $notif): 
                                // Calculate time ago
                                $time_diff = time() - strtotime($notif['created_at']);
                                if ($time_diff < 60) {
                                    $time_text = 'Just now';
                                } elseif ($time_diff < 3600) {
                                    $time_text = floor($time_diff / 60) . ' minutes ago';
                                } elseif ($time_diff < 86400) {
                                    $time_text = floor($time_diff / 3600) . ' hours ago';
                                } else {
                                    $time_text = floor($time_diff / 86400) . ' days ago';
                                }
                                
                                // Icon and color based on type
                                $icon = 'fa-bell';
                                $icon_color = 'text-secondary';
                                switch($notif['type']) {
                                    case 'incident_reported':
                                        $icon = 'fa-exclamation-triangle';
                                        $icon_color = 'text-warning';
                                        break;
                                    case 'incident_assignment':
                                        $icon = 'fa-user-check';
                                        $icon_color = 'text-info';
                                        break;
                                    case 'status_update':
                                        $icon = 'fa-sync-alt';
                                        $icon_color = 'text-success';
                                        break;
                                    case 'complaint_assignment':
                                        $icon = 'fa-clipboard-list';
                                        $icon_color = 'text-primary';
                                        break;
                                }
                            ?>
                                <div class="notification-card <?php echo !$notif['is_read'] ? 'unread' : ''; ?> mb-3">
                                    <div class="d-flex gap-3">
                                        <!-- Icon -->
                                        <div class="notification-icon">
                                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" 
                                                 style="width: 50px; height: 50px;">
                                                <i class="fas <?php echo $icon; ?> fa-lg <?php echo $icon_color; ?>"></i>
                                            </div>
                                        </div>
                                        
                                        <!-- Content -->
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="mb-0 fw-bold">
                                                    <?php echo htmlspecialchars($notif['title']); ?>
                                                    <?php if (!$notif['is_read']): ?>
                                                        <span class="badge bg-primary ms-2">New</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="far fa-clock"></i> <?php echo $time_text; ?>
                                                </small>
                                            </div>
                                            
                                            <p class="text-muted mb-2">
                                                <?php echo htmlspecialchars($notif['message']); ?>
                                            </p>
                                            
                                            <!-- Actions -->
                                            <div class="d-flex gap-2">
                                                <?php if (!empty($notif['reference_type']) && !empty($notif['reference_id'])): ?>
                                                    <?php 
                                                    // Build the correct URL based on reference type
                                                    $view_url = '#';
                                                    
                                                    if ($notif['reference_type'] === 'incident') {
                                                        // Redirect to incident-details.php for all roles
                                                        $view_url = '../incidents/incident-details.php?id=' . intval($notif['reference_id']);
                                                    } elseif ($notif['reference_type'] === 'complaint') {
                                                        $view_url = '../complaints/view-complaint.php?id=' . intval($notif['reference_id']);
                                                    }
                                                    ?>
                                                    <a href="<?php echo htmlspecialchars($view_url); ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <?php if (!$notif['is_read']): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="mark_read">
                                                        <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Mark as read">
                                                            <i class="fas fa-check"></i> Mark Read
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Delete this notification?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4" aria-label="Notification pagination">
                                <ul class="pagination justify-content-center">
                                    <!-- Previous -->
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                    
                                    <!-- Page Numbers -->
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <!-- Next -->
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.notification-card {
    padding: 1.25rem;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: white;
    transition: all 0.2s;
}

.notification-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.notification-card.unread {
    background: #f0f7ff;
    border-left: 4px solid #007bff;
}

.notifications-list {
    max-width: 900px;
    margin: 0 auto;
}

.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: #007bff;
    font-weight: 600;
}

.nav-tabs .nav-link:hover {
    color: #0056b3;
}
</style>

<?php include '../../includes/footer.php'; ?>