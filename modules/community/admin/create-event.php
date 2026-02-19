<?php
// modules/community/admin/create-event.php
session_start();
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
require_once '../../../includes/functions.php';

if (!isLoggedIn()) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = getCurrentUserId();
$page_title = "Create Event";

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current user's resident_id
$user_stmt = $conn->prepare("
    SELECT r.resident_id
    FROM tbl_users u
    LEFT JOIN tbl_resident r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$resident_id = $user_data['resident_id'] ?? 0;

// If no resident record exists, create one automatically
if (!$resident_id) {
    $create_resident = $conn->prepare("
        INSERT INTO tbl_resident (first_name, last_name)
        VALUES ('User', ?)
    ");
    $username = 'Guest';
    $create_resident->bind_param("s", $username);
    $create_resident->execute();
    $resident_id = $conn->insert_id;
    $create_resident->close();
    
    // Link resident to user
    $link_stmt = $conn->prepare("UPDATE tbl_users SET resident_id = ? WHERE user_id = ?");
    $link_stmt->bind_param("ii", $resident_id, $user_id);
    $link_stmt->execute();
    $link_stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF validation failed');
    }
    
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $event_type = trim($_POST['event_type']);
    $event_date = trim($_POST['event_date']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    $location = trim($_POST['location']);
    $max_attendees = intval($_POST['max_attendees']);
    
    $errors = [];
    
    // Validation
    if (empty($title)) {
        $errors[] = "Event title is required";
    } elseif (strlen($title) > 255) {
        $errors[] = "Event title is too long (max 255 characters)";
    }
    
    if (empty($description)) {
        $errors[] = "Event description is required";
    } elseif (strlen($description) > 2000) {
        $errors[] = "Event description is too long (max 2000 characters)";
    }
    
    if (empty($event_type)) {
        $errors[] = "Event type is required";
    } elseif (!in_array($event_type, ['meeting', 'social', 'cleanup', 'sports', 'other'])) {
        $errors[] = "Invalid event type";
    }
    
    if (empty($event_date)) {
        $errors[] = "Event date is required";
    } else {
        $date_obj = DateTime::createFromFormat('Y-m-d', $event_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $event_date) {
            $errors[] = "Invalid date format";
        } elseif ($date_obj < new DateTime('today')) {
            $errors[] = "Event date cannot be in the past";
        }
    }
    
    if (empty($start_time)) {
        $errors[] = "Start time is required";
    }
    
    if (empty($end_time)) {
        $errors[] = "End time is required";
    }
    
    if (!empty($start_time) && !empty($end_time)) {
        $start = DateTime::createFromFormat('H:i', $start_time);
        $end = DateTime::createFromFormat('H:i', $end_time);
        if ($start && $end && $end <= $start) {
            $errors[] = "End time must be after start time";
        }
    }
    
    if (empty($location)) {
        $errors[] = "Location is required";
    } elseif (strlen($location) > 255) {
        $errors[] = "Location is too long (max 255 characters)";
    }
    
    if ($max_attendees < 0 || $max_attendees > 10000) {
        $errors[] = "Max attendees must be between 0 and 10000";
    }
    
    // Handle image upload
    $event_image = null;
    if (isset($_FILES['event_image']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file_type = $_FILES['event_image']['type'];
        $file_size = $_FILES['event_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Invalid image format. Only JPG, PNG, and WEBP are allowed.";
        } elseif ($file_size > $max_size) {
            $errors[] = "Image is too large. Maximum size is 5MB.";
        } else {
            // Create upload directory if it doesn't exist
            $upload_dir = '../../../uploads/events/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['event_image']['name'], PATHINFO_EXTENSION);
            $event_image = uniqid('event_', true) . '.' . $file_extension;
            $upload_path = $upload_dir . $event_image;
            
            if (!move_uploaded_file($_FILES['event_image']['tmp_name'], $upload_path)) {
                $errors[] = "Failed to upload image. Please try again.";
                $event_image = null;
            }
        }
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            $status = 'upcoming';
            
            $stmt = $conn->prepare("
                INSERT INTO tbl_events (
                    title, description, event_type, event_date, start_time, end_time, 
                    location, max_attendees, organizer_id, status, event_image, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->bind_param(
                "sssssssiiis",
                $title, $description, $event_type, $event_date, $start_time, 
                $end_time, $location, $max_attendees, $resident_id, $status, $event_image
            );
            
            $stmt->execute();
            $event_id = $conn->insert_id;
            $stmt->close();
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Event created successfully!";
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            // Redirect to events-manage.php
            header("Location: events-manage.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            
            // Delete uploaded image if database insert failed
            if ($event_image && file_exists($upload_dir . $event_image)) {
                unlink($upload_dir . $event_image);
            }
            
            $errors[] = "Failed to create event. Please try again.";
            error_log("Event creation error: " . $e->getMessage());
        }
    }
}

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
        background: #fafafa;
        color: #1a1a1a;
    }
    
    .page-header {
        background: white;
        padding: 2rem;
        border-radius: 4px;
        margin-bottom: 2rem;
        border: 1px solid #e0e0e0;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .page-header p {
        color: #757575;
        margin: 0;
        font-size: 0.875rem;
    }
    
    .form-container {
        background: white;
        border-radius: 4px;
        padding: 2rem;
        border: 1px solid #e0e0e0;
        max-width: 800px;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-group label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .required {
        color: #d32f2f;
        margin-left: 0.25rem;
    }
    
    .form-control {
        width: 100%;
        padding: 0.625rem 0.875rem;
        font-size: 0.875rem;
        border: 1px solid #e0e0e0;
        border-radius: 3px;
        background: white;
        transition: all 0.15s;
        font-family: inherit;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #1a1a1a;
        box-shadow: 0 0 0 2px rgba(26, 26, 26, 0.1);
    }
    
    .form-control:disabled {
        background: #f5f5f5;
        color: #9e9e9e;
        cursor: not-allowed;
    }
    
    select.form-control {
        cursor: pointer;
    }
    
    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    .form-help {
        font-size: 0.75rem;
        color: #757575;
        margin-top: 0.375rem;
    }
    
    /* Image Upload Styles */
    .image-upload-wrapper {
        border: 2px dashed #e0e0e0;
        border-radius: 4px;
        padding: 2rem;
        text-align: center;
        background: #fafafa;
        transition: all 0.15s;
        cursor: pointer;
    }
    
    .image-upload-wrapper:hover {
        border-color: #bdbdbd;
        background: #f5f5f5;
    }
    
    .image-upload-wrapper.drag-over {
        border-color: #1a1a1a;
        background: #f0f0f0;
    }
    
    .image-upload-icon {
        font-size: 3rem;
        color: #bdbdbd;
        margin-bottom: 1rem;
    }
    
    .image-upload-text {
        font-size: 0.875rem;
        color: #616161;
        margin-bottom: 0.5rem;
    }
    
    .image-upload-subtext {
        font-size: 0.75rem;
        color: #9e9e9e;
    }
    
    #event_image {
        display: none;
    }
    
    .image-preview {
        margin-top: 1rem;
        display: none;
        position: relative;
    }
    
    .image-preview img {
        max-width: 100%;
        max-height: 300px;
        border-radius: 4px;
        border: 1px solid #e0e0e0;
    }
    
    .image-preview-remove {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        background: #d32f2f;
        color: white;
        border: none;
        border-radius: 50%;
        width: 32px;
        height: 32px;
        cursor: pointer;
        font-size: 1.125rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s;
    }
    
    .image-preview-remove:hover {
        background: #b71c1c;
    }
    
    .btn {
        padding: 0.625rem 1.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        border-radius: 3px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary {
        background: #1a1a1a;
        color: white;
    }
    
    .btn-primary:hover {
        background: #333;
    }
    
    .btn-secondary {
        background: white;
        color: #616161;
        border: 1px solid #e0e0e0;
    }
    
    .btn-secondary:hover {
        background: #f5f5f5;
        border-color: #bdbdbd;
    }
    
    .btn-group {
        display: flex;
        gap: 0.75rem;
        margin-top: 2rem;
        padding-top: 2rem;
        border-top: 1px solid #e0e0e0;
    }
    
    .alert {
        padding: 0.875rem 1rem;
        border-radius: 3px;
        margin-bottom: 1.5rem;
        border: 1px solid;
        font-size: 0.875rem;
    }
    
    .alert-danger {
        background: #fef5f5;
        color: #5f2120;
        border-color: #f5c6cb;
    }
    
    .alert-danger ul {
        margin: 0.5rem 0 0 1.25rem;
        padding: 0;
    }
    
    .alert-danger li {
        margin-bottom: 0.25rem;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .btn-group {
            flex-direction: column;
        }
        
        .btn-group .btn {
            width: 100%;
        }
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fas fa-calendar-plus" style="margin-right: 0.5rem;"></i>Create Event</h1>
        <p>Organize a new community event</p>
    </div>

    <div class="form-container">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong><i class="fas fa-exclamation-circle" style="margin-right: 0.5rem;"></i>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            
            <!-- Event Image Upload -->
            <div class="form-group">
                <label>Event Image</label>
                <div class="image-upload-wrapper" id="imageUploadWrapper" onclick="document.getElementById('event_image').click()">
                    <div class="image-upload-icon">
                        <i class="fas fa-image"></i>
                    </div>
                    <div class="image-upload-text">Click to upload or drag and drop</div>
                    <div class="image-upload-subtext">JPG, PNG or WEBP (max 5MB)</div>
                </div>
                <input type="file" id="event_image" name="event_image" accept="image/jpeg,image/png,image/jpg,image/webp">
                <div class="image-preview" id="imagePreview">
                    <button type="button" class="image-preview-remove" onclick="removeImage(event)">&times;</button>
                    <img id="previewImg" src="" alt="Preview">
                </div>
            </div>
            
            <!-- Event Title -->
            <div class="form-group">
                <label>Event Title<span class="required">*</span></label>
                <input type="text" name="title" class="form-control" 
                       value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                       placeholder="e.g., Community Cleanup Day"
                       maxlength="255" required>
            </div>

            <!-- Event Description -->
            <div class="form-group">
                <label>Description<span class="required">*</span></label>
                <textarea name="description" class="form-control" 
                          placeholder="Describe your event..."
                          maxlength="2000" required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                <div class="form-help">Maximum 2000 characters</div>
            </div>

            <!-- Event Type -->
            <div class="form-group">
                <label>Event Type<span class="required">*</span></label>
                <select name="event_type" class="form-control" required>
                    <option value="">Select event type</option>
                    <option value="meeting" <?php echo (($_POST['event_type'] ?? '') === 'meeting') ? 'selected' : ''; ?>>Meeting</option>
                    <option value="social" <?php echo (($_POST['event_type'] ?? '') === 'social') ? 'selected' : ''; ?>>Social Gathering</option>
                    <option value="cleanup" <?php echo (($_POST['event_type'] ?? '') === 'cleanup') ? 'selected' : ''; ?>>Cleanup Drive</option>
                    <option value="sports" <?php echo (($_POST['event_type'] ?? '') === 'sports') ? 'selected' : ''; ?>>Sports Activity</option>
                    <option value="other" <?php echo (($_POST['event_type'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>

            <!-- Date and Time -->
            <div class="form-row">
                <div class="form-group">
                    <label>Event Date<span class="required">*</span></label>
                    <input type="date" name="event_date" class="form-control" 
                           value="<?php echo htmlspecialchars($_POST['event_date'] ?? ''); ?>" 
                           min="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Start Time<span class="required">*</span></label>
                    <input type="time" name="start_time" class="form-control" 
                           value="<?php echo htmlspecialchars($_POST['start_time'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>End Time<span class="required">*</span></label>
                    <input type="time" name="end_time" class="form-control" 
                           value="<?php echo htmlspecialchars($_POST['end_time'] ?? ''); ?>" required>
                </div>
            </div>

            <!-- Location -->
            <div class="form-group">
                <label>Location<span class="required">*</span></label>
                <input type="text" name="location" class="form-control" 
                       value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" 
                       placeholder="e.g., Community Center, Barangay Hall"
                       maxlength="255" required>
            </div>

            <!-- Max Attendees -->
            <div class="form-group">
                <label>Maximum Attendees</label>
                <input type="number" name="max_attendees" class="form-control" 
                       value="<?php echo htmlspecialchars($_POST['max_attendees'] ?? '0'); ?>" 
                       min="0" max="10000" placeholder="0 for unlimited">
                <div class="form-help">Leave as 0 for unlimited attendees</div>
            </div>

            <!-- Buttons -->
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check" style="margin-right: 0.5rem;"></i>Create Event
                </button>
                <a href="events-manage.php" class="btn btn-secondary">
                    <i class="fas fa-times" style="margin-right: 0.5rem;"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
const imageInput = document.getElementById('event_image');
const imagePreview = document.getElementById('imagePreview');
const previewImg = document.getElementById('previewImg');
const uploadWrapper = document.getElementById('imageUploadWrapper');

// Handle file selection
imageInput.addEventListener('change', function(e) {
    handleFiles(e.target.files);
});

// Drag and drop
uploadWrapper.addEventListener('dragover', function(e) {
    e.preventDefault();
    uploadWrapper.classList.add('drag-over');
});

uploadWrapper.addEventListener('dragleave', function(e) {
    e.preventDefault();
    uploadWrapper.classList.remove('drag-over');
});

uploadWrapper.addEventListener('drop', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadWrapper.classList.remove('drag-over');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        imageInput.files = files;
        handleFiles(files);
    }
});

function handleFiles(files) {
    if (files.length === 0) return;
    
    const file = files[0];
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    if (!validTypes.includes(file.type)) {
        alert('Please select a valid image file (JPG, PNG, or WEBP)');
        imageInput.value = '';
        return;
    }
    
    // Validate file size (5MB)
    if (file.size > 5 * 1024 * 1024) {
        alert('Image size must be less than 5MB');
        imageInput.value = '';
        return;
    }
    
    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        previewImg.src = e.target.result;
        imagePreview.style.display = 'block';
        uploadWrapper.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

function removeImage(e) {
    e.preventDefault();
    e.stopPropagation();
    imageInput.value = '';
    imagePreview.style.display = 'none';
    uploadWrapper.style.display = 'block';
    previewImg.src = '';
}
</script>

<?php include '../../../includes/footer.php'; ?>