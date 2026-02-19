<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

requireLogin();
requireRole(['Admin', 'Staff']);

$page_title = 'Manage Announcements';
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $title = trim($_POST['title']);
            $content = trim($_POST['content']);
            $created_by = $_SESSION['user_id'];
            
            $stmt = $conn->prepare("INSERT INTO tbl_announcements (title, content, created_by) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $title, $content, $created_by);
            
            if ($stmt->execute()) {
                $success_message = "Announcement posted successfully!";
            } else {
                $error_message = "Error posting announcement.";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'edit') {
            $id = intval($_POST['id']);
            $title = trim($_POST['title']);
            $content = trim($_POST['content']);
            
            $stmt = $conn->prepare("UPDATE tbl_announcements SET title = ?, content = ? WHERE id = ?");
            $stmt->bind_param("ssi", $title, $content, $id);
            
            if ($stmt->execute()) {
                $success_message = "Announcement updated successfully!";
            } else {
                $error_message = "Error updating announcement.";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'toggle') {
            $id = intval($_POST['id']);
            $is_active = intval($_POST['is_active']);
            
            $stmt = $conn->prepare("UPDATE tbl_announcements SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_active, $id);
            
            if ($stmt->execute()) {
                $success_message = $is_active ? "Announcement activated!" : "Announcement deactivated!";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'delete') {
            $id = intval($_POST['id']);
            
            $stmt = $conn->prepare("DELETE FROM tbl_announcements WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $success_message = "Announcement deleted successfully!";
            }
            $stmt->close();
        }
    }
}

// Fetch all announcements
$announcements = [];
$stmt = $conn->prepare("SELECT a.*, u.username as created_by_name FROM tbl_announcements a LEFT JOIN tbl_users u ON a.created_by = u.id ORDER BY a.created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}
$stmt->close();

include '../../includes/header.php';
?>

<style>
.manage-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

.page-header {
    background: #2d3748;
    border-radius: 4px;
    padding: 2rem;
    color: white;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border: 1px solid #4a5568;
}

.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.page-title {
    font-size: 2rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-title i {
    font-size: 2.5rem;
}

.page-subtitle {
    margin: 0.5rem 0 0 0;
    opacity: 0.85;
}

.btn-primary {
    background: white;
    color: #2d3748;
    padding: 0.75rem 1.5rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-primary:hover {
    background: #f7fafc;
    border-color: #a0aec0;
}

.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 4px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border-left: 3px solid #4a5568;
    border: 1px solid #e2e8f0;
}

.stat-label {
    color: #718096;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #2d3748;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    animation: slideIn 0.2s ease;
    border: 1px solid;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert i {
    font-size: 1.5rem;
}

.alert-success {
    background: #f0f0f0;
    color: #2d3748;
    border-color: #cbd5e0;
}

.alert-error {
    background: #f7fafc;
    color: #2d3748;
    border-color: #a0aec0;
}

.announcements-grid {
    display: grid;
    gap: 1.5rem;
}

.announcement-card {
    background: white;
    border-radius: 4px;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: all 0.2s ease;
    border: 1px solid #e2e8f0;
}

.announcement-card:hover {
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.announcement-card.inactive {
    opacity: 0.6;
    border-color: #cbd5e0;
    background: #f7fafc;
}

.announcement-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.announcement-title-section {
    flex: 1;
}

.announcement-title {
    font-size: 1.5rem;
    font-weight: bold;
    color: #2d3748;
    margin: 0 0 0.5rem 0;
}

.announcement-meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    font-size: 0.875rem;
    color: #718096;
}

.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    border: 1px solid;
}

.badge-active {
    background: #f7fafc;
    color: #2d3748;
    border-color: #cbd5e0;
}

.badge-inactive {
    background: #e2e8f0;
    color: #4a5568;
    border-color: #a0aec0;
}

.announcement-content {
    color: #4a5568;
    line-height: 1.8;
    margin-bottom: 1.5rem;
    white-space: pre-wrap;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    border: 1px solid;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-edit {
    background: white;
    color: #2d3748;
    border-color: #cbd5e0;
}

.btn-edit:hover {
    background: #f7fafc;
    border-color: #a0aec0;
}

.btn-toggle {
    background: #4a5568;
    color: white;
    border-color: #4a5568;
}

.btn-toggle:hover {
    background: #2d3748;
    border-color: #2d3748;
}

.btn-toggle.inactive {
    background: #718096;
    border-color: #718096;
}

.btn-toggle.inactive:hover {
    background: #4a5568;
    border-color: #4a5568;
}

.btn-delete {
    background: #718096;
    color: white;
    border-color: #718096;
}

.btn-delete:hover {
    background: #4a5568;
    border-color: #4a5568;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background: white;
    border-radius: 4px;
    padding: 2rem;
    max-width: 700px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    animation: slideUp 0.2s ease;
    border: 1px solid #e2e8f0;
}

@keyframes slideUp {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.modal-title {
    font-size: 1.5rem;
    margin: 0;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.close-btn {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    width: 36px;
    height: 36px;
    border-radius: 4px;
    font-size: 1.3rem;
    cursor: pointer;
    color: #718096;
    transition: all 0.2s ease;
}

.close-btn:hover {
    background: #e2e8f0;
    color: #2d3748;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #2d3748;
}

.form-group label .required {
    color: #718096;
}

.form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    font-size: 1rem;
    transition: all 0.2s ease;
    font-family: inherit;
}

.form-control:focus {
    outline: none;
    border-color: #4a5568;
    box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.1);
}

textarea.form-control {
    min-height: 150px;
    resize: vertical;
}

.char-counter {
    text-align: right;
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.25rem;
}

.form-footer {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.btn-submit {
    background: #2d3748;
    color: white;
    padding: 0.75rem 2rem;
    border: 1px solid #4a5568;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-submit:hover {
    background: #1a202c;
    border-color: #2d3748;
}

.btn-cancel {
    background: #e2e8f0;
    color: #2d3748;
    padding: 0.75rem 2rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-cancel:hover {
    background: #cbd5e0;
    border-color: #a0aec0;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
}

.empty-state i {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 1rem;
}

.empty-state h3 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: #718096;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .page-header-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .announcement-header {
        flex-direction: column;
    }
    
    .action-buttons {
        width: 100%;
    }
    
    .btn-sm {
        flex: 1;
    }
}
</style>

<div class="manage-container">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-bullhorn"></i>
                    Announcements
                </h1>
                <p class="page-subtitle">Create and manage barangay announcements for all residents</p>
            </div>
            <button class="btn-primary" onclick="openModal('addModal')">
                <i class="fas fa-plus-circle"></i> Post New Announcement
            </button>
        </div>
    </div>

    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-label">Total Announcements</div>
            <div class="stat-value"><?php echo count($announcements); ?></div>
        </div>
        <div class="stat-card" style="border-color: #48bb78;">
            <div class="stat-label">Active</div>
            <div class="stat-value" style="color: #48bb78;">
                <?php echo count(array_filter($announcements, fn($a) => $a['is_active'] == 1)); ?>
            </div>
        </div>
        <div class="stat-card" style="border-color: #f56565;">
            <div class="stat-label">Inactive</div>
            <div class="stat-value" style="color: #f56565;">
                <?php echo count(array_filter($announcements, fn($a) => $a['is_active'] == 0)); ?>
            </div>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
    <?php endif; ?>

    <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <i class="fas fa-bullhorn"></i>
            <h3>No Announcements Yet</h3>
            <p>Get started by posting your first announcement to keep residents informed!</p>
            <button class="btn-primary" onclick="openModal('addModal')">
                <i class="fas fa-plus-circle"></i> Post Your First Announcement
            </button>
        </div>
    <?php else: ?>
        <div class="announcements-grid">
            <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-card <?php echo $announcement['is_active'] ? '' : 'inactive'; ?>">
                    <div class="announcement-header">
                        <div class="announcement-title-section">
                            <h3 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                            <div class="announcement-meta">
                                <span class="badge <?php echo $announcement['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $announcement['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                <span>
                                    <i class="far fa-user"></i> 
                                    <?php echo htmlspecialchars($announcement['created_by_name'] ?? 'Admin'); ?>
                                </span>
                                <span>
                                    <i class="far fa-calendar"></i> 
                                    <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                                </span>
                                <span>
                                    <i class="far fa-clock"></i> 
                                    <?php echo date('g:i A', strtotime($announcement['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button class="btn-sm btn-edit" onclick='editAnnouncement(<?php echo json_encode($announcement); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $announcement['is_active'] ? 0 : 1; ?>">
                                <button type="submit" class="btn-sm btn-toggle <?php echo $announcement['is_active'] ? '' : 'inactive'; ?>">
                                    <i class="fas fa-toggle-<?php echo $announcement['is_active'] ? 'on' : 'off'; ?>"></i>
                                    <?php echo $announcement['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this announcement? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                <button type="submit" class="btn-sm btn-delete">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="announcement-content">
                        <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-plus-circle"></i>
                Post New Announcement
            </h2>
            <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="e.g., Community Clean-Up Drive" required maxlength="255" oninput="updateCharCount('title', this.value, 255)">
                <div class="char-counter"><span id="title-count">0</span>/255</div>
            </div>
            <div class="form-group">
                <label>Content <span class="required">*</span></label>
                <textarea name="content" class="form-control" placeholder="Write your announcement here..." required maxlength="2000" oninput="updateCharCount('content', this.value, 2000)"></textarea>
                <div class="char-counter"><span id="content-count">0</span>/2000</div>
            </div>
            <div class="form-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Post Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-edit"></i>
                Edit Announcement
            </h2>
            <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <input type="text" name="title" id="edit_title" class="form-control" required maxlength="255" oninput="updateCharCount('edit-title', this.value, 255)">
                <div class="char-counter"><span id="edit-title-count">0</span>/255</div>
            </div>
            <div class="form-group">
                <label>Content <span class="required">*</span></label>
                <textarea name="content" id="edit_content" class="form-control" required maxlength="2000" oninput="updateCharCount('edit-content', this.value, 2000)"></textarea>
                <div class="char-counter"><span id="edit-content-count">0</span>/2000</div>
            </div>
            <div class="form-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function editAnnouncement(announcement) {
    document.getElementById('edit_id').value = announcement.id;
    document.getElementById('edit_title').value = announcement.title;
    document.getElementById('edit_content').value = announcement.content;
    updateCharCount('edit-title', announcement.title, 255);
    updateCharCount('edit-content', announcement.content, 2000);
    openModal('editModal');
}

function updateCharCount(field, value, max) {
    const count = value.length;
    const counter = document.getElementById(field + '-count');
    if (counter) {
        counter.textContent = count;
        if (count > max * 0.9) {
            counter.style.color = '#e53e3e';
        } else {
            counter.style.color = '#718096';
        }
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('show');
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>