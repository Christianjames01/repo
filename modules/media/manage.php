<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Media';
$success_message = '';
$error_message = '';

// Create uploads directory if it doesn't exist
$upload_dir = '../../uploads/media/photos/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_photo') {
            $caption = trim($_POST['caption']);
            
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $filename = $_FILES['photo']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    // Check file size (5MB max)
                    if ($_FILES['photo']['size'] <= 5 * 1024 * 1024) {
                        $new_filename = 'photo_' . time() . '_' . uniqid() . '.' . $ext;
                        $filepath = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
                            $db_path = 'uploads/media/photos/' . $new_filename;
                            
                            $stmt = $conn->prepare("INSERT INTO tbl_barangay_media (media_type, file_path, caption) VALUES ('photo', ?, ?)");
                            $stmt->bind_param("ss", $db_path, $caption);
                            
                            if ($stmt->execute()) {
                                $success_message = "Photo uploaded successfully!";
                            } else {
                                $error_message = "Error saving photo to database.";
                                @unlink($filepath); // Delete uploaded file if DB insert fails
                            }
                            $stmt->close();
                        } else {
                            $error_message = "Error uploading file.";
                        }
                    } else {
                        $error_message = "File size must not exceed 5MB.";
                    }
                } else {
                    $error_message = "Invalid file type. Only JPG, PNG, GIF, and WEBP allowed.";
                }
            } else {
                $error_message = "Please select a photo to upload.";
            }
            
        } elseif ($_POST['action'] === 'add_video') {
            $video_url = trim($_POST['video_url']);
            $caption = trim($_POST['caption']);
            
            // Convert YouTube watch URL to embed URL
            if (strpos($video_url, 'youtube.com/watch') !== false) {
                parse_str(parse_url($video_url, PHP_URL_QUERY), $params);
                if (isset($params['v'])) {
                    $video_url = 'https://www.youtube.com/embed/' . $params['v'];
                }
            } elseif (strpos($video_url, 'youtu.be/') !== false) {
                $video_id = substr(parse_url($video_url, PHP_URL_PATH), 1);
                $video_url = 'https://www.youtube.com/embed/' . $video_id;
            }
            
            $stmt = $conn->prepare("INSERT INTO tbl_barangay_media (media_type, video_url, caption) VALUES ('video', ?, ?)");
            $stmt->bind_param("ss", $video_url, $caption);
            
            if ($stmt->execute()) {
                $success_message = "Video added successfully!";
            } else {
                $error_message = "Error adding video.";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'toggle') {
            $id = intval($_POST['id']);
            $is_active = intval($_POST['is_active']);
            
            $stmt = $conn->prepare("UPDATE tbl_barangay_media SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_active, $id);
            
            if ($stmt->execute()) {
                $success_message = "Media status updated!";
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'delete') {
            $id = intval($_POST['id']);
            
            // Get file path to delete physical file
            $stmt = $conn->prepare("SELECT file_path FROM tbl_barangay_media WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $media = $result->fetch_assoc();
            $stmt->close();
            
            if ($media && $media['file_path']) {
                $file_to_delete = '../../' . $media['file_path'];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
            }
            
            $stmt = $conn->prepare("DELETE FROM tbl_barangay_media WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $success_message = "Media deleted successfully!";
            }
            $stmt->close();
        }
    }
}

// Fetch all media
$photos = [];
$videos = [];

$stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'photo' ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $photos[] = $row;
}
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'video' ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $videos[] = $row;
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

.btn-back {
    background: transparent;
    color: white;
    padding: 0.75rem 1.5rem;
    border: 1px solid #4a5568;
    border-radius: 4px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
}

.btn-back:hover {
    background: #4a5568;
    color: white;
}

.tabs {
    display: flex;
    gap: 1rem;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 2rem;
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    font-size: 1rem;
    color: #718096;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tab-btn.active {
    color: #2d3748;
    border-bottom-color: #4a5568;
    font-weight: 600;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.btn-primary {
    background: #2d3748;
    color: white;
    padding: 0.75rem 1.5rem;
    border: 1px solid #4a5568;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary:hover {
    background: #1a202c;
    border-color: #2d3748;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
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

.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.media-card {
    background: white;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid #e2e8f0;
}

.media-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.media-image {
    width: 100%;
    height: 220px;
    object-fit: cover;
    display: block;
    filter: grayscale(20%);
}

.media-video {
    width: 100%;
    height: 220px;
}

.media-body {
    padding: 1.25rem;
}

.media-caption {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.75rem;
    font-size: 1.05rem;
    line-height: 1.4;
}

.media-meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 1rem;
    flex-wrap: wrap;
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

.media-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    border: 1px solid;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    font-weight: 500;
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

.file-input-wrapper {
    position: relative;
    overflow: hidden;
    display: inline-block;
    width: 100%;
}

.file-input-wrapper input[type=file] {
    position: absolute;
    left: -9999px;
}

.file-input-label {
    display: block;
    padding: 2rem;
    background: #f7fafc;
    border: 1px dashed #cbd5e0;
    border-radius: 4px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.file-input-label:hover {
    background: #edf2f7;
    border-color: #4a5568;
}

.file-input-label i {
    font-size: 2.5rem;
    color: #a0aec0;
    margin-bottom: 0.5rem;
}

.help-text {
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.5rem;
}

.empty-state {
    grid-column: 1/-1;
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 4px;
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
}
</style>

<div class="manage-container">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-photo-video"></i>
                    Barangay Media
                </h1>
                <p class="page-subtitle">Upload and manage photos and videos for the barangay dashboard</p>
            </div>
            <a href="../dashboard/index.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
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

    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('photos')">
            <i class="fas fa-images"></i> Photos (<?php echo count($photos); ?>)
        </button>
        <button class="tab-btn" onclick="switchTab('videos')">
            <i class="fas fa-video"></i> Videos (<?php echo count($videos); ?>)
        </button>
    </div>

    <!-- Photos Tab -->
    <div id="photos-tab" class="tab-content active">
        <div style="margin-bottom: 1.5rem;">
            <button class="btn-primary" onclick="openModal('addPhotoModal')">
                <i class="fas fa-plus"></i> Upload Photo
            </button>
        </div>

        <div class="media-grid">
            <?php if (empty($photos)): ?>
                <div class="empty-state">
                    <i class="fas fa-images"></i>
                    <h3>No Photos Yet</h3>
                    <p>Upload photos to showcase barangay events and activities</p>
                </div>
            <?php else: ?>
                <?php foreach ($photos as $photo): ?>
                    <div class="media-card">
                        <img src="../../<?php echo htmlspecialchars($photo['file_path']); ?>" alt="<?php echo htmlspecialchars($photo['caption']); ?>" class="media-image">
                        <div class="media-body">
                            <div class="media-caption"><?php echo htmlspecialchars($photo['caption']); ?></div>
                            <div class="media-meta">
                                <span class="badge <?php echo $photo['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $photo['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                <span><i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($photo['created_at'])); ?></span>
                            </div>
                            <div class="media-actions">
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $photo['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn-sm btn-toggle">
                                        <i class="fas fa-toggle-<?php echo $photo['is_active'] ? 'off' : 'on'; ?>"></i>
                                        <?php echo $photo['is_active'] ? 'Hide' : 'Show'; ?>
                                    </button>
                                </form>
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to delete this photo?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                                    <button type="submit" class="btn-sm btn-delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Videos Tab -->
    <div id="videos-tab" class="tab-content">
        <div style="margin-bottom: 1.5rem;">
            <button class="btn-primary" onclick="openModal('addVideoModal')">
                <i class="fas fa-plus"></i> Add Video
            </button>
        </div>

        <div class="media-grid">
            <?php if (empty($videos)): ?>
                <div class="empty-state">
                    <i class="fas fa-video"></i>
                    <h3>No Videos Yet</h3>
                    <p>Add YouTube videos to showcase barangay updates</p>
                </div>
            <?php else: ?>
                <?php foreach ($videos as $video): ?>
                    <div class="media-card">
                        <iframe src="<?php echo htmlspecialchars($video['video_url']); ?>" class="media-video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        <div class="media-body">
                            <div class="media-caption"><?php echo htmlspecialchars($video['caption']); ?></div>
                            <div class="media-meta">
                                <span class="badge <?php echo $video['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $video['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                <span><i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($video['created_at'])); ?></span>
                            </div>
                            <div class="media-actions">
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $video['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $video['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn-sm btn-toggle">
                                        <i class="fas fa-toggle-<?php echo $video['is_active'] ? 'off' : 'on'; ?>"></i>
                                        <?php echo $video['is_active'] ? 'Hide' : 'Show'; ?>
                                    </button>
                                </form>
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to delete this video?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $video['id']; ?>">
                                    <button type="submit" class="btn-sm btn-delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Photo Modal -->
<div id="addPhotoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Upload Photo</h2>
            <button class="close-btn" onclick="closeModal('addPhotoModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_photo">
            <div class="form-group">
                <label>Photo *</label>
                <div class="file-input-wrapper">
                    <input type="file" name="photo" id="photo" accept="image/*" required onchange="updateFileName(this)">
                    <label for="photo" class="file-input-label">
                        <i class="fas fa-cloud-upload-alt"></i><br>
                        <span id="file-name">Click to select a photo</span>
                    </label>
                </div>
                <div class="help-text">Accepted formats: JPG, PNG, GIF, WEBP (Max 5MB)</div>
            </div>
            <div class="form-group">
                <label>Caption *</label>
                <input type="text" name="caption" class="form-control" placeholder="e.g., Medical Mission 2026" required>
            </div>
            <button type="submit" class="btn-primary" style="width: 100%;">
                <i class="fas fa-upload"></i> Upload Photo
            </button>
        </form>
    </div>
</div>

<!-- Add Video Modal -->
<div id="addVideoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Add Video</h2>
            <button class="close-btn" onclick="closeModal('addVideoModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_video">
            <div class="form-group">
                <label>YouTube Video URL *</label>
                <input type="url" name="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=..." required>
                <div class="help-text">Paste the full YouTube URL (e.g., https://www.youtube.com/watch?v=dQw4w9WgXcQ)</div>
            </div>
            <div class="form-group">
                <label>Caption *</label>
                <input type="text" name="caption" class="form-control" placeholder="e.g., Barangay Updates January 2026" required>
            </div>
            <button type="submit" class="btn-primary" style="width: 100%;">
                <i class="fas fa-save"></i> Add Video
            </button>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tab + '-tab').classList.add('active');
    event.target.classList.add('active');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function updateFileName(input) {
    const fileName = input.files[0]?.name || 'Click to select a photo';
    document.getElementById('file-name').textContent = fileName;
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