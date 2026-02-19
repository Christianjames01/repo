<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';
requireLogin();
requireRole(['Admin', 'Super Admin']);

$page_title = 'Manage Barangay Officials';
$success_message = '';
$error_message = '';

// Handle file upload and form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Handle photo upload
        $photo_filename = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/officials/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $file_type = $_FILES['photo']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                $extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photo_filename = uniqid() . '_' . time() . '.' . $extension;
                $upload_path = $upload_dir . $photo_filename;
                
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                    $error_message = "Failed to upload photo.";
                    $photo_filename = null;
                }
            } else {
                $error_message = "Invalid file type. Only JPG, JPEG, and PNG allowed.";
            }
        }
        
        // ADD OFFICIAL
        if ($_POST['action'] === 'add' && !$error_message) {
            $first_name = trim($_POST['first_name']);
            $middle_name = trim($_POST['middle_name']);
            $last_name = trim($_POST['last_name']);
            $position = trim($_POST['position']);
            $term_start = $_POST['term_start'];
            $term_end = $_POST['term_end'];
            
            $stmt = $conn->prepare("INSERT INTO tbl_barangay_officials (first_name, middle_name, last_name, position, term_start, term_end, photo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $first_name, $middle_name, $last_name, $position, $term_start, $term_end, $photo_filename);
            
            if ($stmt->execute()) {
                $success_message = "Official added successfully!";
            } else {
                $error_message = "Error adding official: " . $stmt->error;
            }
            $stmt->close();
        }
        
        // EDIT OFFICIAL
        elseif ($_POST['action'] === 'edit' && !$error_message) {
            $official_id = intval($_POST['official_id']);
            $first_name = trim($_POST['first_name']);
            $middle_name = trim($_POST['middle_name']);
            $last_name = trim($_POST['last_name']);
            $position = trim($_POST['position']);
            $term_start = $_POST['term_start'];
            $term_end = $_POST['term_end'];
            
            // If new photo uploaded, delete old photo
            if ($photo_filename) {
                $old_photo_query = $conn->query("SELECT photo FROM tbl_barangay_officials WHERE official_id = $official_id");
                if ($old_row = $old_photo_query->fetch_assoc()) {
                    if ($old_row['photo'] && file_exists('../../uploads/officials/' . $old_row['photo'])) {
                        unlink('../../uploads/officials/' . $old_row['photo']);
                    }
                }
                
                $stmt = $conn->prepare("UPDATE tbl_barangay_officials SET first_name = ?, middle_name = ?, last_name = ?, position = ?, term_start = ?, term_end = ?, photo = ? WHERE official_id = ?");
                $stmt->bind_param("sssssssi", $first_name, $middle_name, $last_name, $position, $term_start, $term_end, $photo_filename, $official_id);
            } else {
                $stmt = $conn->prepare("UPDATE tbl_barangay_officials SET first_name = ?, middle_name = ?, last_name = ?, position = ?, term_start = ?, term_end = ? WHERE official_id = ?");
                $stmt->bind_param("ssssssi", $first_name, $middle_name, $last_name, $position, $term_start, $term_end, $official_id);
            }
            
            if ($stmt->execute()) {
                $success_message = "Official updated successfully!";
            } else {
                $error_message = "Error updating official: " . $stmt->error;
            }
            $stmt->close();
        }
        
        // TOGGLE ACTIVE STATUS
        elseif ($_POST['action'] === 'toggle') {
            $official_id = intval($_POST['official_id']);
            $is_active = intval($_POST['is_active']);
            
            $stmt = $conn->prepare("UPDATE tbl_barangay_officials SET is_active = ? WHERE official_id = ?");
            $stmt->bind_param("ii", $is_active, $official_id);
            
            if ($stmt->execute()) {
                $success_message = $is_active ? "Official activated!" : "Official deactivated!";
            } else {
                $error_message = "Error toggling status: " . $stmt->error;
            }
            $stmt->close();
        }
        
        // DELETE OFFICIAL
        elseif ($_POST['action'] === 'delete') {
            $official_id = intval($_POST['official_id']);
            
            // Delete photo file if exists
            $photo_query = $conn->query("SELECT photo FROM tbl_barangay_officials WHERE official_id = $official_id");
            if ($photo_row = $photo_query->fetch_assoc()) {
                if ($photo_row['photo'] && file_exists('../../uploads/officials/' . $photo_row['photo'])) {
                    unlink('../../uploads/officials/' . $photo_row['photo']);
                }
            }
            
            $stmt = $conn->prepare("DELETE FROM tbl_barangay_officials WHERE official_id = ?");
            $stmt->bind_param("i", $official_id);
            
            if ($stmt->execute()) {
                $success_message = "Official removed successfully!";
            } else {
                $error_message = "Error removing official: " . $stmt->error;
            }
            $stmt->close();
        }
        
        // Redirect to clear POST data
        if ($success_message || $error_message) {
            $_SESSION['temp_success'] = $success_message;
            $_SESSION['temp_error'] = $error_message;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Get messages from session
if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

// Fetch all officials
$officials = [];
$sql = "SELECT * FROM tbl_barangay_officials
        ORDER BY 
            CASE position
                WHEN 'Barangay Captain' THEN 1
                WHEN 'Barangay Kagawad' THEN 2
                WHEN 'SK Chairperson' THEN 3
                WHEN 'SK Kagawad' THEN 4
                WHEN 'Barangay Secretary' THEN 5
                WHEN 'Barangay Treasurer' THEN 6
                ELSE 7
            END,
            last_name ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officials[] = $row;
    }
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/manage-officials.css">';
include '../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Manage Barangay Officials</h1>
        <p>Add, edit, or remove barangay officials</p>
    </div>
    <div class="header-actions">
        <button class="btn-add" onclick="openModal('addModal')">
            <i class="fas fa-plus"></i> Add Official
        </button>
        <a href="index.php" class="btn-view">
            <i class="fas fa-eye"></i> View Page
        </a>
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

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Photo</th>
                <th>Name</th>
                <th>Position</th>
                <th>Term</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($officials)): ?>
            <tr>
                <td colspan="6" class="no-data">No officials found. Add your first official above.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($officials as $official): ?>
                <tr class="<?php echo $official['is_active'] ? '' : 'inactive'; ?>">
                    <td>
                        <div class="avatar">
                            <?php if ($official['photo'] && file_exists('../../uploads/officials/' . $official['photo'])): ?>
                                <img src="../../uploads/officials/<?php echo htmlspecialchars($official['photo']); ?>" alt="Photo">
                            <?php else: ?>
                                <span><?php echo strtoupper(substr($official['first_name'], 0, 1) . substr($official['last_name'], 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($official['first_name'] . ' ' . ($official['middle_name'] ? $official['middle_name'] . ' ' : '') . $official['last_name']); ?></strong>
                    </td>
                    <td><?php echo htmlspecialchars($official['position']); ?></td>
                    <td>
                        <?php echo date('M Y', strtotime($official['term_start'])); ?> - 
                        <?php echo date('M Y', strtotime($official['term_end'])); ?>
                    </td>
                    <td>
                        <span class="status <?php echo $official['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $official['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions">
                            <button class="btn-icon" onclick='editOfficial(<?php echo json_encode($official); ?>)' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            
                            <button type="button" class="btn-icon" 
                                    onclick="showToggleModal(<?php echo $official['official_id']; ?>, <?php echo $official['is_active']; ?>, '<?php echo htmlspecialchars($official['first_name'] . ' ' . $official['last_name']); ?>')"
                                    title="<?php echo $official['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                <i class="fas fa-<?php echo $official['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                            </button>
                            
                            <button type="button" class="btn-icon delete" 
                                    onclick="showDeleteModal(<?php echo $official['official_id']; ?>, '<?php echo htmlspecialchars($official['first_name'] . ' ' . $official['last_name']); ?>')"
                                    title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Official</h2>
            <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name">
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Position *</label>
                <select name="position" required>
                    <option value="">-- Select Position --</option>
                    <option value="Barangay Captain">Barangay Captain</option>
                    <option value="Barangay Kagawad">Barangay Kagawad</option>
                    <option value="SK Chairperson">SK Chairperson</option>
                    <option value="SK Kagawad">SK Kagawad</option>
                    <option value="Barangay Secretary">Barangay Secretary</option>
                    <option value="Barangay Treasurer">Barangay Treasurer</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Term Start *</label>
                    <input type="date" name="term_start" required>
                </div>
                
                <div class="form-group">
                    <label>Term End *</label>
                    <input type="date" name="term_end" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Photo (Optional)</label>
                <input type="file" name="photo" accept="image/jpeg,image/jpg,image/png">
                <small>JPG, JPEG, or PNG format</small>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-submit">Add Official</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Official</h2>
            <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="official_id" id="edit_official_id">
            
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" id="edit_first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" id="edit_middle_name">
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" id="edit_last_name" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Position *</label>
                <select name="position" id="edit_position" required>
                    <option value="">-- Select Position --</option>
                    <option value="Barangay Captain">Barangay Captain</option>
                    <option value="Barangay Kagawad">Barangay Kagawad</option>
                    <option value="SK Chairperson">SK Chairperson</option>
                    <option value="SK Kagawad">SK Kagawad</option>
                    <option value="Barangay Secretary">Barangay Secretary</option>
                    <option value="Barangay Treasurer">Barangay Treasurer</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Term Start *</label>
                    <input type="date" name="term_start" id="edit_term_start" required>
                </div>
                
                <div class="form-group">
                    <label>Term End *</label>
                    <input type="date" name="term_end" id="edit_term_end" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Change Photo (Optional)</label>
                <input type="file" name="photo" accept="image/jpeg,image/jpg,image/png">
                <small>Leave blank to keep current photo</small>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-submit">Update Official</button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Status Confirmation Modal -->
<div id="toggleModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Confirm Status Change</h2>
            <button class="close-btn" onclick="closeModal('toggleModal')">&times;</button>
        </div>
        <div style="padding: 20px;">
            <p id="toggleMessage" style="margin-bottom: 20px; font-size: 16px;"></p>
            <form method="POST" id="toggleForm">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="official_id" id="toggle_official_id">
                <input type="hidden" name="is_active" id="toggle_is_active">
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('toggleModal')">Cancel</button>
                    <button type="submit" class="btn-submit" id="toggleSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background-color: #fee; border-bottom: 2px solid #dc3545;">
            <h2 style="color: #dc3545;">
                <i class="fas fa-exclamation-triangle"></i> Confirm Deletion
            </h2>
            <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div style="padding: 20px;">
            <p style="margin-bottom: 15px; font-size: 16px;">Are you sure you want to remove this official?</p>
            <div style="background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
                <strong id="deleteOfficialName" style="color: #856404;"></strong>
            </div>
            <p style="color: #dc3545; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> This action cannot be undone. The official's photo will also be deleted.
            </p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="official_id" id="delete_official_id">
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn-submit" style="background-color: #dc3545;">
                        <i class="fas fa-trash"></i> Yes, Delete Official
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function editOfficial(official) {
    document.getElementById('edit_official_id').value = official.official_id;
    document.getElementById('edit_first_name').value = official.first_name;
    document.getElementById('edit_middle_name').value = official.middle_name || '';
    document.getElementById('edit_last_name').value = official.last_name;
    document.getElementById('edit_position').value = official.position;
    document.getElementById('edit_term_start').value = official.term_start;
    document.getElementById('edit_term_end').value = official.term_end;
    openModal('editModal');
}

function showToggleModal(officialId, isActive, officialName) {
    document.getElementById('toggle_official_id').value = officialId;
    document.getElementById('toggle_is_active').value = isActive ? 0 : 1;
    
    const action = isActive ? 'deactivate' : 'activate';
    const message = `Are you sure you want to ${action} <strong>${officialName}</strong>?`;
    document.getElementById('toggleMessage').innerHTML = message;
    document.getElementById('toggleSubmitBtn').textContent = isActive ? 'Deactivate' : 'Activate';
    
    openModal('toggleModal');
}

function showDeleteModal(officialId, officialName) {
    document.getElementById('delete_official_id').value = officialId;
    document.getElementById('deleteOfficialName').textContent = officialName;
    openModal('deleteModal');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>