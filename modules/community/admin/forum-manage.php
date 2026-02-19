<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

// Check if user is logged in and has admin/staff role
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Community Forum';

// Handle topic actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $topic_id = intval($_POST['topic_id'] ?? 0);
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if ($topic_id > 0) {
        if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM tbl_forum_topics WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Topic deleted successfully!'];
            }
            $stmt->close();
        } elseif ($action === 'pin') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_pinned = 1 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Topic pinned successfully!'];
            }
            $stmt->close();
        } elseif ($action === 'unpin') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_pinned = 0 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Topic unpinned successfully!'];
            }
            $stmt->close();
        } elseif ($action === 'lock') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_locked = 1 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Topic locked successfully!'];
            }
            $stmt->close();
        } elseif ($action === 'unlock') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_locked = 0 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Topic unlocked successfully!'];
            }
            $stmt->close();
        }
    }
    
    // Check if it's an AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode($response);
        exit();
    } else {
        // Regular form submission - redirect with message
        if ($response['success']) {
            $_SESSION['success_message'] = $response['message'];
        }
        header("Location: " . BASE_URL . "/modules/community/admin/forum-manage.php");
        exit();
    }
}

// Get filter parameters
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];
$types = '';

if ($category_filter > 0) {
    $where_conditions[] = "t.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $where_conditions[] = "(t.title LIKE ? OR t.content LIKE ? OR u.username LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// FIXED: Changed tbl_resident to tbl_residents
$query = "
    SELECT t.*, 
           u.username,
           CONCAT(res.first_name, ' ', res.last_name) as author_name,
           res.profile_photo,
           c.category_name,
           (SELECT COUNT(*) FROM tbl_forum_replies WHERE topic_id = t.topic_id) as reply_count
    FROM tbl_forum_topics t
    LEFT JOIN tbl_users u ON t.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
    $where_clause
    ORDER BY t.is_pinned DESC, t.created_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$topics = $stmt->get_result();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT t.topic_id) as total_topics,
        COUNT(DISTINCT r.reply_id) as total_replies,
        COUNT(DISTINCT CASE WHEN t.is_pinned = 1 THEN t.topic_id END) as pinned_topics,
        COUNT(DISTINCT CASE WHEN t.is_locked = 1 THEN t.topic_id END) as locked_topics
    FROM tbl_forum_topics t
    LEFT JOIN tbl_forum_replies r ON t.topic_id = r.topic_id
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get categories for filter
$categories_result = $conn->query("SELECT * FROM tbl_forum_categories WHERE is_active = 1 ORDER BY display_order");

include '../../../includes/header.php';
?>

<!-- Keep all your existing styles -->
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; color: #1a1a1a; }
    .page-header { background: white; padding: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    .page-header h1 { font-size: 1.75rem; font-weight: 600; margin-bottom: 0.5rem; color: #1a1a1a; }
    .breadcrumb { font-size: 0.875rem; color: #6b7280; }
    .breadcrumb a { color: #3b82f6; text-decoration: none; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-card { background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; }
    .stat-label { font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem; }
    .stat-value { font-size: 2rem; font-weight: 600; color: #1a1a1a; }
    .filters-section { background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 2rem; }
    .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; }
    .form-group label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: #374151; }
    .form-control { width: 100%; padding: 0.625rem 0.875rem; font-size: 0.875rem; border: 1px solid #d1d5db; border-radius: 6px; background: white; transition: all 0.15s; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .btn { padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; display: inline-block; }
    .btn-primary { background: #3b82f6; color: white; }
    .btn-primary:hover { background: #2563eb; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-secondary:hover { background: #4b5563; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-danger:hover { background: #dc2626; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-warning:hover { background: #d97706; }
    .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
    .topics-list { background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden; }
    .topic-item { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; }
    .topic-item:last-child { border-bottom: none; }
    .topic-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
    .author-avatar { width: 40px; height: 40px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #6b7280; overflow: hidden; }
    .author-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .topic-meta { flex: 1; }
    .author-name { font-weight: 500; color: #1a1a1a; font-size: 0.875rem; }
    .topic-date { font-size: 0.75rem; color: #6b7280; }
    .topic-title { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem; color: #1a1a1a; }
    .topic-content { color: #4b5563; line-height: 1.6; margin-bottom: 1rem; }
    .topic-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
    .topic-stats { display: flex; gap: 1.5rem; font-size: 0.875rem; color: #6b7280; }
    .topic-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .badge { display: inline-block; padding: 0.25rem 0.75rem; font-size: 0.75rem; font-weight: 500; border-radius: 12px; margin-right: 0.5rem; }
    .badge-pinned { background: #fbbf24; color: #78350f; }
    .badge-locked { background: #fee2e2; color: #991b1b; }
    .empty-state { text-align: center; padding: 3rem; color: #6b7280; }
    .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
    .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal-overlay.active { display: flex; }
    .modal-content { background: white; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); animation: modalSlideIn 0.2s ease-out; }
    @keyframes modalSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    .modal-header { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
    .modal-header h3 { margin: 0; font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem; }
    .modal-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
    .modal-icon.pin { background: #fef3c7; color: #d97706; }
    .modal-icon.lock { background: #fef3c7; color: #d97706; }
    .modal-icon.delete { background: #fee2e2; color: #dc2626; }
    .modal-close { background: none; border: none; font-size: 1.5rem; color: #6b7280; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.15s; }
    .modal-close:hover { background: #f3f4f6; color: #1a1a1a; }
    .modal-body { padding: 1.5rem; }
    .modal-body p { color: #4b5563; line-height: 1.6; margin-bottom: 1rem; }
    .topic-preview { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 1rem; margin-top: 1rem; }
    .topic-preview-title { font-weight: 600; color: #1a1a1a; margin-bottom: 0.25rem; font-size: 0.875rem; }
    .topic-preview-label { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
    .modal-footer { padding: 1.5rem; border-top: 1px solid #e5e7eb; display: flex; gap: 0.75rem; justify-content: flex-end; }
    .btn-outline { background: white; border: 1px solid #d1d5db; color: #374151; }
    .btn-outline:hover { background: #f9fafb; border-color: #9ca3af; }
    .modal-content.large { max-width: 800px; }
    .view-modal-body { padding: 0; max-height: 70vh; overflow-y: auto; }
    .topic-full-header { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; background: #f9fafb; }
    .topic-full-title { font-size: 1.5rem; font-weight: 600; color: #1a1a1a; margin-bottom: 1rem; }
    .topic-full-meta { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .topic-full-author { display: flex; align-items: center; gap: 0.75rem; }
    .topic-full-content { padding: 1.5rem; line-height: 1.8; color: #374151; white-space: pre-wrap; }
    .replies-section { border-top: 1px solid #e5e7eb; background: #fafafa; }
    .replies-header { padding: 1rem 1.5rem; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #1a1a1a; }
    .reply-item { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; background: white; }
    .reply-item:last-child { border-bottom: none; }
    .reply-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
    .reply-author { font-weight: 500; color: #1a1a1a; font-size: 0.875rem; }
    .reply-date { font-size: 0.75rem; color: #6b7280; }
    .reply-content { color: #4b5563; line-height: 1.6; padding-left: 3rem; }
    .no-replies { padding: 2rem; text-align: center; color: #6b7280; }
    .loading-spinner { display: flex; align-items: center; justify-content: center; padding: 3rem; }
    .spinner { border: 3px solid #f3f4f6; border-top: 3px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<div class="page-header">
    <h1>Manage Community Forum</h1>
    <div class="breadcrumb">
        <a href="<?php echo BASE_URL; ?>/modules/dashboard/index.php">Dashboard</a> / 
        <span>Forum Management</span>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Topics</div>
            <div class="stat-value"><?php echo number_format($stats['total_topics']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Replies</div>
            <div class="stat-value"><?php echo number_format($stats['total_replies']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pinned Topics</div>
            <div class="stat-value"><?php echo number_format($stats['pinned_topics']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Locked Topics</div>
            <div class="stat-value"><?php echo number_format($stats['locked_topics']); ?></div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search topics..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" class="form-control">
                        <option value="0">All Categories</option>
                        <?php while ($cat = $categories_result->fetch_assoc()): ?>
                            <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Apply Filters</button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Topics List -->
    <div class="topics-list">
        <?php if ($topics->num_rows > 0): ?>
            <?php while ($topic = $topics->fetch_assoc()): ?>
                <div class="topic-item">
                    <div class="topic-header">
                        <div class="author-avatar">
                            <?php if (!empty($topic['profile_photo'])): ?>
                                <img src="<?php echo BASE_URL; ?>/uploads/profiles/<?php echo htmlspecialchars($topic['profile_photo']); ?>" alt="" onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo strtoupper(substr($topic['username'], 0, 1)); ?>';">
                            <?php else: ?>
                                <?php echo strtoupper(substr($topic['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="topic-meta">
                            <div class="author-name"><?php echo htmlspecialchars($topic['author_name'] ?: $topic['username']); ?></div>
                            <div class="topic-date"><?php echo date('M d, Y h:i A', strtotime($topic['created_at'])); ?></div>
                        </div>
                        <div>
                            <?php if ($topic['is_pinned']): ?>
                                <span class="badge badge-pinned"><i class="fas fa-thumbtack"></i> Pinned</span>
                            <?php endif; ?>
                            <?php if ($topic['is_locked']): ?>
                                <span class="badge badge-locked"><i class="fas fa-lock"></i> Locked</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h3 class="topic-title"><?php echo htmlspecialchars($topic['title']); ?></h3>
                    <div class="topic-content">
                        <?php echo nl2br(htmlspecialchars(substr($topic['content'], 0, 200))); ?>
                        <?php if (strlen($topic['content']) > 200): ?>...<?php endif; ?>
                    </div>
                    
                    <div class="topic-footer">
                        <div class="topic-stats">
                            <span><i class="fas fa-comment"></i> <?php echo $topic['reply_count']; ?> replies</span>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($topic['category_name']); ?></span>
                        </div>
                        <div class="topic-actions">
                            <button onclick="showViewModal(<?php echo $topic['topic_id']; ?>)" 
                                    class="btn btn-secondary btn-sm">
                                <i class="fas fa-eye"></i> View
                            </button>
                            
                            <?php if (!$topic['is_pinned']): ?>
                                <button onclick="showActionModal('pin', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" 
                                        class="btn btn-warning btn-sm">
                                    <i class="fas fa-thumbtack"></i> Pin
                                </button>
                            <?php else: ?>
                                <button onclick="showActionModal('unpin', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" 
                                        class="btn btn-secondary btn-sm">
                                    <i class="fas fa-thumbtack"></i> Unpin
                                </button>
                            <?php endif; ?>
                            
                            <?php if (!$topic['is_locked']): ?>
                                <button onclick="showActionModal('lock', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" 
                                        class="btn btn-warning btn-sm">
                                    <i class="fas fa-lock"></i> Lock
                                </button>
                            <?php else: ?>
                                <button onclick="showActionModal('unlock', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" 
                                        class="btn btn-secondary btn-sm">
                                    <i class="fas fa-unlock"></i> Unlock
                                </button>
                            <?php endif; ?>
                            
                            <button onclick="showActionModal('delete', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" 
                                    class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <p>No forum topics found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Confirmation Modal -->
<div class="modal-overlay" id="actionModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <span class="modal-icon" id="modalIcon"></span>
                <span id="modalTitle"></span>
            </h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="modalMessage"></p>
            <div class="topic-preview">
                <div class="topic-preview-label">Topic</div>
                <div class="topic-preview-title" id="modalTopicTitle"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <button class="btn" id="modalConfirmBtn" onclick="confirmAction()"></button>
        </div>
    </div>
</div>

<!-- View Topic Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3>
                <span class="modal-icon" style="background: #dbeafe; color: #1d4ed8;">
                    <i class="fas fa-eye"></i>
                </span>
                View Topic
            </h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="view-modal-body" id="viewModalBody">
            <div class="loading-spinner">
                <div class="spinner"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<form id="actionForm" method="POST" action="<?php echo BASE_URL; ?>/modules/community/admin/forum-manage.php" style="display: none;">
    <input type="hidden" name="topic_id" id="formTopicId">
    <input type="hidden" name="action" id="formAction">
</form>

<script>
let currentAction = '';
let currentTopicId = 0;

const modalConfig = {
    pin: {
        title: 'Pin Topic',
        message: 'Pinning this topic will keep it at the top of the forum list. This helps highlight important discussions.',
        icon: 'fas fa-thumbtack',
        iconClass: 'pin',
        btnText: 'Pin Topic',
        btnClass: 'btn-warning'
    },
    unpin: {
        title: 'Unpin Topic',
        message: 'This topic will return to normal ordering based on recent activity.',
        icon: 'fas fa-thumbtack',
        iconClass: 'pin',
        btnText: 'Unpin Topic',
        btnClass: 'btn-secondary'
    },
    lock: {
        title: 'Lock Topic',
        message: 'Locking this topic will prevent users from adding new replies. Existing replies will remain visible.',
        icon: 'fas fa-lock',
        iconClass: 'lock',
        btnText: 'Lock Topic',
        btnClass: 'btn-warning'
    },
    unlock: {
        title: 'Unlock Topic',
        message: 'Users will be able to reply to this topic again.',
        icon: 'fas fa-unlock',
        iconClass: 'lock',
        btnText: 'Unlock Topic',
        btnClass: 'btn-secondary'
    },
    delete: {
        title: 'Delete Topic',
        message: 'This will permanently delete the topic and all its replies. This action cannot be undone.',
        icon: 'fas fa-trash',
        iconClass: 'delete',
        btnText: 'Delete Topic',
        btnClass: 'btn-danger'
    }
};

function showActionModal(action, topicId, topicTitle) {
    currentAction = action;
    currentTopicId = topicId;
    
    const config = modalConfig[action];
    
    // Set modal content
    document.getElementById('modalTitle').textContent = config.title;
    document.getElementById('modalMessage').textContent = config.message;
    document.getElementById('modalTopicTitle').textContent = topicTitle;
    
    // Set icon
    const iconEl = document.getElementById('modalIcon');
    iconEl.innerHTML = `<i class="${config.icon}"></i>`;
    iconEl.className = 'modal-icon ' + config.iconClass;
    
    // Set confirm button
    const confirmBtn = document.getElementById('modalConfirmBtn');
    confirmBtn.textContent = config.btnText;
    confirmBtn.className = 'btn ' + config.btnClass;
    
    // Show modal
    document.getElementById('actionModal').classList.add('active');
}

function closeModal() {
    document.getElementById('actionModal').classList.remove('active');
}

function confirmAction() {
    document.getElementById('formTopicId').value = currentTopicId;
    document.getElementById('formAction').value = currentAction;
    document.getElementById('actionForm').submit();
}

// View Modal Functions
function showViewModal(topicId) {
    document.getElementById('viewModal').classList.add('active');
    loadTopicDetails(topicId);
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
    document.getElementById('viewModalBody').innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';
}

async function loadTopicDetails(topicId) {
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/modules/community/admin/get-topic-details.php?id=' + topicId);
        const data = await response.json();
        
        if (data.success) {
            renderTopicDetails(data.topic, data.replies);
        } else {
            document.getElementById('viewModalBody').innerHTML = `
                <div class="no-replies">
                    <i class="fas fa-exclamation-circle" style="font-size: 2rem; color: #ef4444;"></i>
                    <p>Error loading topic details</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('viewModalBody').innerHTML = `
            <div class="no-replies">
                <i class="fas fa-exclamation-circle" style="font-size: 2rem; color: #ef4444;"></i>
                <p>Error loading topic details</p>
            </div>
        `;
    }
}

function renderTopicDetails(topic, replies) {
    let repliesHtml = '';
    
    if (replies && replies.length > 0) {
        repliesHtml = replies.map(reply => `
            <div class="reply-item">
                <div class="reply-header">
                    <div class="author-avatar">
                        ${reply.profile_photo 
                            ? `<img src="<?php echo BASE_URL; ?>/uploads/profiles/${reply.profile_photo}" alt="">` 
                            : reply.username.charAt(0).toUpperCase()
                        }
                    </div>
                    <div>
                        <div class="reply-author">${reply.author_name || reply.username}</div>
                        <div class="reply-date">${formatDate(reply.created_at)}</div>
                    </div>
                </div>
                <div class="reply-content">${escapeHtml(reply.content)}</div>
            </div>
        `).join('');
    } else {
        repliesHtml = '<div class="no-replies"><i class="fas fa-comments"></i><p>No replies yet</p></div>';
    }
    
    const badges = [];
    if (topic.is_pinned == 1) badges.push('<span class="badge badge-pinned"><i class="fas fa-thumbtack"></i> Pinned</span>');
    if (topic.is_locked == 1) badges.push('<span class="badge badge-locked"><i class="fas fa-lock"></i> Locked</span>');
    
    document.getElementById('viewModalBody').innerHTML = `
        <div class="topic-full-header">
            <div class="topic-full-title">${escapeHtml(topic.title)}</div>
            <div class="topic-full-meta">
                <div class="topic-full-author">
                    <div class="author-avatar">
                        ${topic.profile_photo 
                            ? `<img src="<?php echo BASE_URL; ?>/uploads/profiles/${topic.profile_photo}" alt="">` 
                            : topic.username.charAt(0).toUpperCase()
                        }
                    </div>
                    <div>
                        <div class="author-name">${topic.author_name || topic.username}</div>
                        <div class="topic-date">${formatDate(topic.created_at)}</div>
                    </div>
                </div>
                <div>${badges.join(' ')}</div>
                <span class="stat-badge">
                    <i class="fas fa-tag"></i> ${escapeHtml(topic.category_name)}
                </span>
            </div>
        </div>
        <div class="topic-full-content">${escapeHtml(topic.content)}</div>
        <div class="replies-section">
            <div class="replies-header">
                <i class="fas fa-comments"></i> Replies (${replies.length})
            </div>
            ${repliesHtml}
        </div>
    `;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return date.toLocaleDateString('en-US', options);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/\n/g, '<br>');
}

// Close modal on overlay click
document.getElementById('actionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeViewModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeViewModal();
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>