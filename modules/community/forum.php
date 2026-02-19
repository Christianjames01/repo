<?php
// modules/community/forum.php - Community Board for Residents
session_start();
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = getCurrentUserId();
$page_title = "Community Board";

// Handle new topic creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topic'])) {
    $category_id = intval($_POST['category_id']);
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    
    if (!empty($title) && !empty($content) && $category_id > 0) {
        $stmt = $conn->prepare("INSERT INTO tbl_forum_topics (category_id, user_id, title, content) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $category_id, $user_id, $title, $content);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Topic created successfully!";
            header("Location: forum.php");
            exit();
        }
        $stmt->close();
    }
}

// Handle new reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reply'])) {
    $topic_id = intval($_POST['topic_id']);
    $reply_content = trim($_POST['reply_content']);
    
    if (!empty($reply_content) && $topic_id > 0) {
        // Check if topic exists and is not locked
        $check_stmt = $conn->prepare("SELECT is_locked FROM tbl_forum_topics WHERE topic_id = ?");
        $check_stmt->bind_param("i", $topic_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $topic = $result->fetch_assoc();
            if ($topic['is_locked'] == 0) {
                // Insert the reply
                $stmt = $conn->prepare("INSERT INTO tbl_forum_replies (topic_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("iis", $topic_id, $user_id, $reply_content);
                
                if ($stmt->execute()) {
                    // Update topic's updated_at timestamp
                    $update_stmt = $conn->prepare("UPDATE tbl_forum_topics SET updated_at = NOW() WHERE topic_id = ?");
                    $update_stmt->bind_param("i", $topic_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $_SESSION['success_message'] = "Reply posted successfully!";
                } else {
                    $_SESSION['error_message'] = "Error posting reply. Please try again.";
                }
                $stmt->close();
            } else {
                $_SESSION['error_message'] = "This topic is locked and cannot receive new replies.";
            }
        } else {
            $_SESSION['error_message'] = "Topic not found.";
        }
        $check_stmt->close();
        
        // Redirect back to forum with anchor to topic
        header("Location: forum.php#topic-" . $topic_id);
        exit();
    } else {
        $_SESSION['error_message'] = "Invalid reply data.";
        header("Location: forum.php");
        exit();
    }
}

// Get all categories with topic count
$categories_query = "
    SELECT 
        c.*,
        COUNT(DISTINCT t.topic_id) as topic_count,
        COUNT(DISTINCT r.reply_id) as reply_count
    FROM tbl_forum_categories c
    LEFT JOIN tbl_forum_topics t ON c.category_id = t.category_id
    LEFT JOIN tbl_forum_replies r ON t.topic_id = r.topic_id
    WHERE c.is_active = 1
    GROUP BY c.category_id
    ORDER BY c.display_order ASC
";
$categories_result = $conn->query($categories_query);

// Get recent topics with updated profile photos
$recent_topics_query = "
    SELECT 
        t.*,
        u.username,
        res.first_name,
        res.last_name,
        res.profile_photo,
        c.category_name,
        (SELECT COUNT(*) FROM tbl_forum_replies WHERE topic_id = t.topic_id) as reply_count
    FROM tbl_forum_topics t
    LEFT JOIN tbl_users u ON t.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
    ORDER BY t.is_pinned DESC, t.created_at DESC
    LIMIT 10
";
$recent_topics = $conn->query($recent_topics_query);

include '../../includes/header.php';
?>

<style>
    :root {
        --primary: #2563eb;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-500: #6b7280;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-900: #111827;
    }
    
    .forum-header {
        background: white;
        border-bottom: 1px solid var(--gray-200);
        padding: 2rem;
        margin-bottom: 2rem;
    }
    
    .category-card {
        background: white;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        transition: all 0.2s;
        cursor: pointer;
    }
    
    .category-card:hover {
        border-color: var(--primary);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    .category-icon {
        width: 48px;
        height: 48px;
        background: var(--gray-100);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--gray-600);
        font-size: 1.25rem;
    }
    
    .stat-badge {
        background: var(--gray-100);
        color: var(--gray-700);
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.875rem;
        margin-right: 0.5rem;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }
    
    .topic-card {
        background: white;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 0.5rem;
        transition: all 0.2s;
        cursor: pointer;
    }
    
    .topic-card:hover {
        border-color: var(--primary);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    .user-avatar-small {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--gray-200);
    }
    
    .modal-content {
        border: none;
        border-radius: 8px;
    }
    
    .modal-header {
        background: white;
        border-bottom: 1px solid var(--gray-200);
        padding: 1.25rem 1.5rem;
    }
    
    .modal-body {
        padding: 0;
        max-height: 70vh;
        overflow-y: auto;
    }
    
    .topic-item {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--gray-200);
        cursor: pointer;
        transition: background 0.2s;
    }
    
    .topic-item:hover {
        background: var(--gray-50);
    }
    
    .topic-item:last-child {
        border-bottom: none;
    }
    
    .empty-state {
        padding: 3rem;
        text-align: center;
        color: var(--gray-500);
    }
    
    .loading-spinner {
        padding: 3rem;
        text-align: center;
    }
    
    .spinner-border {
        width: 2rem;
        height: 2rem;
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="forum-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="fas fa-comments me-2"></i>Community Board</h1>
                <p class="text-muted mb-0">Connect, discuss, and share with your community</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTopicModal">
                <i class="fas fa-plus me-2"></i>New Topic
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Categories Section -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-th-list me-2"></i>Discussion Categories</h5>
                </div>
                <div class="card-body">
                    <?php while ($category = $categories_result->fetch_assoc()): ?>
                        <div class="category-card" onclick="viewCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars(addslashes($category['category_name'])); ?>')">
                            <div class="d-flex align-items-start">
                                <div class="category-icon me-3">
                                    <i class="<?php echo htmlspecialchars($category['icon']); ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1" style="font-size: 1rem; font-weight: 600; color: var(--gray-900);">
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </h5>
                                    <p class="text-muted mb-2" style="font-size: 0.875rem;">
                                        <?php echo htmlspecialchars($category['description']); ?>
                                    </p>
                                    
                                    <div class="d-flex align-items-center flex-wrap">
                                        <span class="stat-badge">
                                            <i class="fas fa-comment"></i><?php echo $category['topic_count']; ?> Topics
                                        </span>
                                        <span class="stat-badge">
                                            <i class="fas fa-reply"></i><?php echo $category['reply_count']; ?> Replies
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <!-- Recent Topics Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Recent Topics</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php while ($topic = $recent_topics->fetch_assoc()): ?>
                            <div class="list-group-item topic-card" onclick="viewTopic(<?php echo $topic['topic_id']; ?>)">
                                <div class="d-flex align-items-start">
                                    <?php if (!empty($topic['profile_photo'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/uploads/profiles/<?php echo htmlspecialchars($topic['profile_photo']); ?>" 
                                             class="user-avatar-small me-2" alt="User">
                                    <?php else: ?>
                                        <div class="user-avatar-small me-2 bg-secondary text-white d-flex align-items-center justify-content-center" style="font-size: 0.875rem;">
                                            <?php echo strtoupper(substr($topic['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 text-truncate" style="font-size: 0.9375rem;">
                                            <?php echo htmlspecialchars($topic['title']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            by <?php echo htmlspecialchars($topic['username']); ?> • 
                                            <?php echo $topic['reply_count']; ?> replies
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Topic Modal -->
<div class="modal fade" id="createTopicModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Topic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select a category...</option>
                            <?php 
                            $categories_result->data_seek(0);
                            while ($cat = $categories_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cat['category_id']; ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Topic Title</label>
                        <input type="text" name="title" class="form-control" placeholder="Enter a clear title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Content</label>
                        <textarea name="content" class="form-control" rows="6" placeholder="Share your thoughts..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_topic" class="btn btn-primary">Post Topic</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Category Topics Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">Category Topics</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="categoryModalBody">
                <div class="loading-spinner">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Topic Modal -->
<div class="modal fade" id="viewTopicModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Topic Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="topicModalBody">
                <div class="loading-spinner">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?php echo BASE_URL; ?>';

function viewCategory(categoryId, categoryName) {
    const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    document.getElementById('categoryModalTitle').textContent = categoryName;
    modal.show();
    loadCategoryTopics(categoryId);
}

async function loadCategoryTopics(categoryId) {
    const modalBody = document.getElementById('categoryModalBody');
    modalBody.innerHTML = '<div class="loading-spinner"><div class="spinner-border text-primary"></div></div>';
    
    try {
        const response = await fetch(`${BASE_URL}/modules/community/get-category-topics.php?id=${categoryId}`);
        const data = await response.json();
        
        if (data.success && data.topics.length > 0) {
            modalBody.innerHTML = data.topics.map(topic => `
                <div class="topic-item" onclick="viewTopicFromCategory(${topic.topic_id})">
                    <div class="d-flex align-items-start gap-3">
                        <div>
                            ${topic.profile_photo 
                                ? `<img src="${BASE_URL}/uploads/profiles/${topic.profile_photo}" class="user-avatar-small" alt="User">` 
                                : `<div class="user-avatar-small bg-secondary text-white d-flex align-items-center justify-content-center">${topic.username.charAt(0).toUpperCase()}</div>`
                            }
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">${escapeHtml(topic.title)}</h6>
                            <p class="text-muted mb-2 small">${escapeHtml(topic.content.substring(0, 150))}${topic.content.length > 150 ? '...' : ''}</p>
                            <small class="text-muted">
                                by ${escapeHtml(topic.username)} • ${topic.reply_count} replies • ${formatDate(topic.created_at)}
                            </small>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-comments fa-3x mb-3"></i>
                    <p>No topics in this category yet</p>
                </div>
            `;
        }
    } catch (error) {
        modalBody.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle fa-3x mb-3 text-danger"></i>
                <p>Error loading topics</p>
            </div>
        `;
    }
}

function viewTopicFromCategory(topicId) {
    bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide();
    viewTopic(topicId);
}

function viewTopic(topicId) {
    const modal = new bootstrap.Modal(document.getElementById('viewTopicModal'));
    modal.show();
    loadTopicDetails(topicId);
}

async function loadTopicDetails(topicId) {
    const modalBody = document.getElementById('topicModalBody');
    modalBody.innerHTML = '<div class="loading-spinner"><div class="spinner-border text-primary"></div></div>';
    
    try {
        const response = await fetch(`${BASE_URL}/modules/community/get-topic-data.php?id=${topicId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', text);
            throw new Error('Invalid response from server');
        }
        
        if (data.success) {
            renderTopicView(data.topic, data.replies);
        } else {
            modalBody.innerHTML = `<div class="alert alert-danger m-4"><i class="fas fa-exclamation-circle me-2"></i>${data.message || 'Error loading topic'}</div>`;
        }
    } catch (error) {
        console.error('Error loading topic:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger m-4">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Error loading topic</strong>
                <p class="mb-0 small mt-1">${error.message}</p>
            </div>`;
    }
}

function renderTopicView(topic, replies) {
    const repliesHtml = replies.length > 0 
        ? replies.map(reply => `
            <div class="border-bottom p-3">
                <div class="d-flex gap-2 mb-2">
                    ${reply.profile_photo 
                        ? `<img src="${BASE_URL}/uploads/profiles/${reply.profile_photo}" class="user-avatar-small" alt="User">` 
                        : `<div class="user-avatar-small bg-secondary text-white d-flex align-items-center justify-content-center">${reply.username.charAt(0).toUpperCase()}</div>`
                    }
                    <div>
                        <div class="fw-bold small">${escapeHtml(reply.author_name || reply.username)}</div>
                        <div class="text-muted" style="font-size: 0.75rem;">${formatDate(reply.created_at)}</div>
                    </div>
                </div>
                <div class="ms-5">${escapeHtml(reply.content)}</div>
            </div>
        `).join('')
        : '<div class="empty-state"><p>No replies yet</p></div>';
    
    const replyForm = topic.is_locked != 1 
        ? `<div class="p-3 border-top">
            <form method="POST" action="${BASE_URL}/modules/community/forum.php">
                <input type="hidden" name="topic_id" value="${topic.topic_id}">
                <textarea name="reply_content" class="form-control mb-2" rows="3" placeholder="Add a reply..." required></textarea>
                <button type="submit" name="add_reply" class="btn btn-primary btn-sm">
                    <i class="fas fa-paper-plane me-1"></i>Post Reply
                </button>
            </form>
        </div>`
        : '<div class="alert alert-warning m-3"><i class="fas fa-lock me-2"></i>This topic is locked</div>';
    
    document.getElementById('topicModalBody').innerHTML = `
        <div class="p-4 border-bottom bg-light">
            <h4>${escapeHtml(topic.title)}</h4>
            <div class="d-flex align-items-center gap-3 mt-3">
                ${topic.profile_photo 
                    ? `<img src="${BASE_URL}/uploads/profiles/${topic.profile_photo}" class="user-avatar-small" alt="User">` 
                    : `<div class="user-avatar-small bg-secondary text-white d-flex align-items-center justify-content-center">${topic.username.charAt(0).toUpperCase()}</div>`
                }
                <div>
                    <div class="fw-bold">${escapeHtml(topic.author_name || topic.username)}</div>
                    <div class="text-muted small">${formatDate(topic.created_at)}</div>
                </div>
            </div>
        </div>
        <div class="p-4 border-bottom">${escapeHtml(topic.content)}</div>
        <div class="bg-light">
            <div class="p-3 border-bottom fw-bold">Replies (${replies.length})</div>
            ${repliesHtml}
        </div>
        ${replyForm}
    `;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/\n/g, '<br>');
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<?php include '../../includes/footer.php'; ?>