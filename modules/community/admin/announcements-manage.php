<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Announcements';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(title LIKE ? OR content LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get announcements
$query = "
    SELECT *
    FROM tbl_announcements
    $where_clause
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$announcements = $stmt->get_result();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_announcements,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_announcements,
        COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_announcements,
        COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as month_announcements
    FROM tbl_announcements
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

include '../../../includes/header.php';
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #f8f9fa;
        color: #1a1a1a;
    }
    
    .page-header {
        background: white;
        padding: 2rem;
        margin-bottom: 2rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .page-header h1 {
        font-size: 1.75rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .breadcrumb {
        font-size: 0.875rem;
        color: #6b7280;
    }
    
    .breadcrumb a {
        color: #3b82f6;
        text-decoration: none;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .stat-card {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }
    
    .stat-label {
        font-size: 0.875rem;
        color: #6b7280;
        margin-bottom: 0.5rem;
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: 600;
        color: #1a1a1a;
    }
    
    .filters-section {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        margin-bottom: 2rem;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 1rem;
        margin-top: 1rem;
    }
    
    .form-group label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151;
    }
    
    .form-control {
        width: 100%;
        padding: 0.625rem 0.875rem;
        font-size: 0.875rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        transition: all 0.15s;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        font-size: 0.875rem;
        font-weight: 500;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary {
        background: #3b82f6;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
    }
    
    .btn-secondary {
        background: #6b7280;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #4b5563;
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
    }
    
    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.8125rem;
    }
    
    .action-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .announcements-list {
        background: white;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }
    
    .announcement-item {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .announcement-item:last-child {
        border-bottom: none;
    }
    
    .announcement-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .author-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: #6b7280;
    }
    
    .announcement-meta {
        flex: 1;
    }
    
    .author-name {
        font-weight: 500;
        color: #1a1a1a;
        font-size: 0.875rem;
    }
    
    .post-date {
        font-size: 0.75rem;
        color: #6b7280;
    }
    
    .announcement-title {
        font-size: 1.125rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .announcement-content {
        color: #4b5563;
        line-height: 1.6;
        margin-bottom: 1rem;
    }
    
    .announcement-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .announcement-actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #6b7280;
    }
    
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    
    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        animation: fadeIn 0.3s;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    .modal-content {
        background-color: white;
        margin: 5% auto;
        padding: 0;
        border-radius: 8px;
        width: 90%;
        max-width: 600px;
        max-height: 80vh;
        overflow: hidden;
        animation: slideDown 0.3s;
    }
    
    @keyframes slideDown {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h2 {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1a1a1a;
    }
    
    .close {
        color: #6b7280;
        font-size: 1.5rem;
        font-weight: 400;
        cursor: pointer;
        border: none;
        background: none;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: all 0.15s;
    }
    
    .close:hover {
        background: #f3f4f6;
        color: #1a1a1a;
    }
    
    .modal-body {
        padding: 1.5rem;
        max-height: 60vh;
        overflow-y: auto;
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }
    
    .detail-row {
        margin-bottom: 1.5rem;
    }
    
    .detail-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #6b7280;
        margin-bottom: 0.5rem;
        letter-spacing: 0.025em;
    }
    
    .detail-value {
        color: #1a1a1a;
        line-height: 1.6;
    }
    
    .form-group-modal {
        margin-bottom: 1.25rem;
    }
    
    .form-group-modal label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151;
    }
    
    .form-group-modal input,
    .form-group-modal textarea {
        width: 100%;
        padding: 0.625rem 0.875rem;
        font-size: 0.875rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        transition: all 0.15s;
    }
    
    .form-group-modal textarea {
        min-height: 150px;
        resize: vertical;
        font-family: inherit;
    }
    
    .form-group-modal input:focus,
    .form-group-modal textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .btn-outline {
        background: white;
        border: 1px solid #d1d5db;
        color: #374151;
    }
    
    .btn-outline:hover {
        background: #f9fafb;
        border-color: #9ca3af;
    }
</style>

<div class="page-header">
    <h1>Manage Announcements</h1>
    <div class="breadcrumb">
        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php">Dashboard</a> / 
        <span>Announcements Management</span>
    </div>
</div>

<div class="container-fluid">
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Announcements</div>
            <div class="stat-value"><?php echo number_format($stats['total_announcements']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Posted Today</div>
            <div class="stat-value"><?php echo number_format($stats['today_announcements']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">This Week</div>
            <div class="stat-value"><?php echo number_format($stats['week_announcements']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">This Month</div>
            <div class="stat-value"><?php echo number_format($stats['month_announcements']); ?></div>
        </div>
    </div>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div></div>
        <a href="create-announcement.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Create New Announcement
        </a>
    </div>
    
    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search announcements..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Announcements List -->
    <div class="announcements-list">
        <?php if ($announcements->num_rows > 0): ?>
            <?php while ($announcement = $announcements->fetch_assoc()): 
                // Debug: print all keys to see what's available
                // Uncomment next line if you need to debug
                // error_log("Announcement keys: " . print_r(array_keys($announcement), true));
                
                // Get ID - should be 'id' based on your table structure
                $announcement_id = isset($announcement['id']) ? $announcement['id'] : 0;
                $title = htmlspecialchars($announcement['title']);
                $content = htmlspecialchars($announcement['content']);
                $created_at = date('M d, Y h:i A', strtotime($announcement['created_at']));
                
                // Debug: Check if ID exists
                if ($announcement_id === 0) {
                    error_log("Warning: Announcement ID is 0 or missing");
                }
            ?>
                <div class="announcement-item">
                    <div class="announcement-header">
                        <div class="author-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="announcement-meta">
                            <div class="author-name">Admin</div>
                            <div class="post-date"><?php echo $created_at; ?></div>
                        </div>
                    </div>
                    
                    <h3 class="announcement-title"><?php echo $title; ?></h3>
                    <div class="announcement-content">
                        <?php 
                        if (strlen($content) > 200) {
                            echo nl2br(substr($content, 0, 200)) . '...';
                        } else {
                            echo nl2br($content);
                        }
                        ?>
                    </div>
                    
                    <div class="announcement-footer">
                        <div></div>
                        <div class="announcement-actions">
                            <?php if ($announcement_id > 0): ?>
                                <button onclick="viewAnnouncement(<?php echo $announcement_id; ?>)" class="btn btn-secondary btn-sm">View</button>
                                <button onclick="editAnnouncement(<?php echo $announcement_id; ?>)" class="btn btn-primary btn-sm">Edit</button>
                                <button onclick="confirmDelete(<?php echo $announcement_id; ?>)" class="btn btn-danger btn-sm">Delete</button>
                            <?php else: ?>
                                <span style="color: #999; font-size: 0.75rem;">ID not found</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-bullhorn"></i>
                <p>No announcements found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-eye"></i> View Announcement</h2>
            <button class="close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div class="detail-row">
                <div class="detail-label">Title</div>
                <div class="detail-value" id="view-title"></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Content</div>
                <div class="detail-value" id="view-content"></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Posted On</div>
                <div class="detail-value" id="view-date"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('viewModal')" class="btn btn-outline">Close</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Announcement</h2>
            <button class="close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editForm">
                <input type="hidden" id="edit-id" name="id">
                <div class="form-group-modal">
                    <label>Title</label>
                    <input type="text" id="edit-title" name="title" required>
                </div>
                <div class="form-group-modal">
                    <label>Content</label>
                    <textarea id="edit-content" name="content" required></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('editModal')" class="btn btn-outline">Cancel</button>
            <button onclick="saveAnnouncement()" class="btn btn-primary">Save Changes</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2><i class="fas fa-trash"></i> Delete Announcement</h2>
            <button class="close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color: #4b5563; line-height: 1.6;">Are you sure you want to delete this announcement? This action cannot be undone.</p>
            <input type="hidden" id="delete-id">
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('deleteModal')" class="btn btn-outline">Cancel</button>
            <button onclick="deleteAnnouncement()" class="btn btn-danger">Delete</button>
        </div>
    </div>
</div>

<script>
// Modal Functions
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// View Announcement
function viewAnnouncement(id) {
    console.log('Fetching announcement ID:', id);
    
    fetch(`actions/get-announcement.php?id=${id}`)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.success) {
                // Check if announcement object exists
                if (!data.announcement) {
                    alert('Error: Announcement data is missing');
                    return;
                }
                
                // Safely access properties
                const title = data.announcement.title || 'No title';
                const content = data.announcement.content || 'No content';
                const createdAt = data.announcement.created_at || new Date().toISOString();
                
                document.getElementById('view-title').textContent = title;
                document.getElementById('view-content').innerHTML = content.replace(/\n/g, '<br>');
                document.getElementById('view-date').textContent = new Date(createdAt).toLocaleString();
                document.getElementById('viewModal').style.display = 'block';
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            alert('Failed to load announcement. Error: ' + error.message);
        });
}

// Edit Announcement
function editAnnouncement(id) {
    fetch(`actions/get-announcement.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-title').value = data.announcement.title;
                document.getElementById('edit-content').value = data.announcement.content;
                document.getElementById('editModal').style.display = 'block';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load announcement');
        });
}

// Save Announcement
function saveAnnouncement() {
    const id = document.getElementById('edit-id').value;
    const title = document.getElementById('edit-title').value;
    const content = document.getElementById('edit-content').value;
    
    if (!title || !content) {
        alert('Please fill in all fields');
        return;
    }
    
    fetch('actions/update-announcement.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, title, content })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('editModal');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update announcement');
    });
}

// Confirm Delete
function confirmDelete(id) {
    document.getElementById('delete-id').value = id;
    document.getElementById('deleteModal').style.display = 'block';
}

// Delete Announcement
function deleteAnnouncement() {
    const id = document.getElementById('delete-id').value;
    
    fetch('actions/delete-announcement.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('deleteModal');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to delete announcement');
    });
}
</script>

<?php include '../../../includes/footer.php'; ?>