<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

requireLogin();
requireRole(['Admin', 'Staff']);

$page_title = 'Manage Activities';
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $activity_date = $_POST['activity_date'];
            $created_by = $_SESSION['user_id'];
            
            $stmt = $conn->prepare("INSERT INTO tbl_barangay_activities (title, description, activity_date, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $title, $description, $activity_date, $created_by);
            
            if ($stmt->execute()) {
                $success_message = "Activity added successfully!";
            } else {
                $error_message = "Error adding activity.";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'edit') {
            $id = intval($_POST['id']);
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $activity_date = $_POST['activity_date'];
            
            $stmt = $conn->prepare("UPDATE tbl_barangay_activities SET title = ?, description = ?, activity_date = ? WHERE id = ?");
            $stmt->bind_param("sssi", $title, $description, $activity_date, $id);
            
            if ($stmt->execute()) {
                $success_message = "Activity updated successfully!";
            } else {
                $error_message = "Error updating activity.";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'toggle') {
            $id = intval($_POST['id']);
            $is_active = intval($_POST['is_active']);
            
            $stmt = $conn->prepare("UPDATE tbl_barangay_activities SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_active, $id);
            
            if ($stmt->execute()) {
                $success_message = "Activity status updated!";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'delete') {
            $id = intval($_POST['id']);
            
            $stmt = $conn->prepare("DELETE FROM tbl_barangay_activities WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $success_message = "Activity deleted successfully!";
            }
            $stmt->close();
        }
    }
}

// Fetch all activities
$activities = [];
$stmt = $conn->prepare("SELECT * FROM tbl_barangay_activities ORDER BY activity_date DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
}
$stmt->close();

include '../../includes/header.php';
?>

<style>
.manage-container {
    max-width: 1200px;
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
    color: white;
}

.page-title i {
    font-size: 2.5rem;
}

.page-subtitle {
    margin: 0.5rem 0 0 0;
    opacity: 0.85;
    color: #e2e8f0;
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

.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    border: 1px solid;
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

.activities-list {
    background: white;
    border-radius: 4px;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
}

.activity-timeline {
    position: relative;
    padding-left: 2rem;
}

.activity-item {
    position: relative;
    padding: 1.5rem;
    background: #f7fafc;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    border-left: 3px solid #4a5568;
    transition: all 0.2s ease;
    border: 1px solid #e2e8f0;
}

.activity-item:hover {
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.activity-item:before {
    content: '';
    position: absolute;
    left: -2.5rem;
    top: 1.5rem;
    width: 10px;
    height: 10px;
    background: #4a5568;
    border-radius: 50%;
    border: 2px solid white;
    box-shadow: 0 0 0 2px #e2e8f0;
}

.activity-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.activity-title-section {
    flex: 1;
}

.activity-title {
    font-size: 1.25rem;
    font-weight: bold;
    color: #2d3748;
    margin: 0 0 0.5rem 0;
}

.activity-meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 0.875rem;
    color: #718096;
}

.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: 600;
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

.activity-description {
    color: #4a5568;
    line-height: 1.6;
    margin-bottom: 1rem;
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
}

.modal-content {
    background: white;
    border-radius: 4px;
    padding: 2rem;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    border: 1px solid #e2e8f0;
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

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    font-size: 1rem;
    transition: all 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: #4a5568;
    box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.1);
}

textarea.form-control {
    min-height: 120px;
    resize: vertical;
}

@media (max-width: 768px) {
    .activity-header {
        flex-direction: column;
    }
    
    .action-buttons {
        width: 100%;
    }
}
</style>

<div class="manage-container">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-calendar-check"></i>
                    Barangay Activities
                </h1>
                <p class="page-subtitle">Manage and showcase barangay events and activities</p>
            </div>
            <button class="btn-primary" onclick="openModal('addModal')">
                <i class="fas fa-plus"></i> Add New Activity
            </button>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <div class="activities-list">
        <h2 style="margin-bottom: 1.5rem;">All Activities</h2>
        
        <?php if (empty($activities)): ?>
            <p style="text-align: center; color: #718096; padding: 2rem;">No activities yet. Click "Add New Activity" to create one.</p>
        <?php else: ?>
            <div class="activity-timeline">
                <?php foreach ($activities as $activity): 
                    $days_ago = floor((time() - strtotime($activity['activity_date'])) / 86400);
                    $time_text = $days_ago == 0 ? 'Today' : ($days_ago == 1 ? 'Yesterday' : abs($days_ago) . ($days_ago > 0 ? ' days ago' : ' days from now'));
                ?>
                <div class="activity-item">
                    <div class="activity-header">
                        <div class="activity-title-section">
                            <h3 class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></h3>
                            <div class="activity-meta">
                                <span class="badge <?php echo $activity['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $activity['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                <span><i class="far fa-calendar"></i> <?php echo date('F j, Y', strtotime($activity['activity_date'])); ?></span>
                                <span><i class="far fa-clock"></i> <?php echo $time_text; ?></span>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button class="btn-sm btn-edit" onclick='editActivity(<?php echo json_encode($activity); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $activity['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $activity['is_active'] ? 0 : 1; ?>">
                                <button type="submit" class="btn-sm btn-toggle">
                                    <i class="fas fa-toggle-<?php echo $activity['is_active'] ? 'off' : 'on'; ?>"></i>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this activity?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $activity['id']; ?>">
                                <button type="submit" class="btn-sm btn-delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="activity-description">
                        <?php echo nl2br(htmlspecialchars($activity['description'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Add New Activity</h2>
            <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" class="form-control" placeholder="e.g., Community Clean-Up Drive" required>
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" class="form-control" placeholder="Describe the activity in detail..." required></textarea>
            </div>
            <div class="form-group">
                <label>Activity Date *</label>
                <input type="date" name="activity_date" class="form-control" required>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Save Activity
            </button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Edit Activity</h2>
            <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" id="edit_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" id="edit_description" class="form-control" required></textarea>
            </div>
            <div class="form-group">
                <label>Activity Date *</label>
                <input type="date" name="activity_date" id="edit_activity_date" class="form-control" required>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Update Activity
            </button>
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

function editActivity(activity) {
    document.getElementById('edit_id').value = activity.id;
    document.getElementById('edit_title').value = activity.title;
    document.getElementById('edit_description').value = activity.description;
    document.getElementById('edit_activity_date').value = activity.activity_date;
    openModal('editModal');
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>