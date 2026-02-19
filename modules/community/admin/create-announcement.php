<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Create Announcement';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $created_by = $_SESSION['user_id']; // Get logged-in user's ID
    
    if (empty($title) || empty($content)) {
        $error = "Please fill in all required fields.";
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_announcements (title, content, created_by, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("ssi", $title, $content, $created_by);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Announcement created successfully!";
            header("Location: announcements-manage.php");
            exit();
        } else {
            $error = "Failed to create announcement. Please try again.";
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
    
    .container-fluid {
        max-width: 800px;
        margin: 0 auto;
        padding: 0 1rem 2rem;
    }
    
    .card {
        background: white;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        padding: 2rem;
    }
    
    .alert {
        padding: 1rem 1.25rem;
        border-radius: 6px;
        margin-bottom: 1.5rem;
        font-size: 0.875rem;
    }
    
    .alert-danger {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }
    
    .alert-success {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-group label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151;
    }
    
    .form-group label .required {
        color: #ef4444;
        margin-left: 0.25rem;
    }
    
    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        transition: all 0.15s;
        font-family: inherit;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    textarea.form-control {
        min-height: 200px;
        resize: vertical;
    }
    
    .form-help {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.375rem;
    }
    
    .char-counter {
        text-align: right;
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.375rem;
    }
    
    .form-actions {
        display: flex;
        gap: 1rem;
        margin-top: 2rem;
        padding-top: 2rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .btn {
        padding: 0.75rem 1.5rem;
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
    
    .btn-primary:disabled {
        background: #9ca3af;
        cursor: not-allowed;
    }
    
    .btn-secondary {
        background: white;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    
    .btn-secondary:hover {
        background: #f9fafb;
        border-color: #9ca3af;
    }
    
    .preview-section {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .preview-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.025em;
    }
    
    .preview-content {
        background: white;
        padding: 1.5rem;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
    }
    
    .preview-content h3 {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.75rem;
        color: #1a1a1a;
    }
    
    .preview-content p {
        color: #4b5563;
        line-height: 1.6;
    }
    
    .preview-empty {
        color: #9ca3af;
        font-style: italic;
        font-size: 0.875rem;
    }
</style>

<div class="page-header">
    <h1><i class="fas fa-plus-circle"></i> Create Announcement</h1>
    <div class="breadcrumb">
        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php">Dashboard</a> / 
        <a href="announcements-manage.php">Announcements</a> / 
        <span>Create</span>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <div class="card">
        <form method="POST" action="" id="announcementForm">
            <div class="form-group">
                <label>
                    Title
                    <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    name="title" 
                    class="form-control" 
                    id="title"
                    placeholder="Enter announcement title"
                    maxlength="200"
                    required
                    value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                >
                <div class="char-counter">
                    <span id="titleCount">0</span> / 200 characters
                </div>
            </div>
            
            <div class="form-group">
                <label>
                    Content
                    <span class="required">*</span>
                </label>
                <textarea 
                    name="content" 
                    class="form-control"
                    id="content"
                    placeholder="Enter announcement content"
                    required
                ><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
                <div class="form-help">
                    <i class="fas fa-info-circle"></i> You can use line breaks to format your announcement.
                </div>
                <div class="char-counter">
                    <span id="contentCount">0</span> characters
                </div>
            </div>
            
            <div class="preview-section">
                <div class="preview-title">
                    <i class="fas fa-eye"></i> Preview
                </div>
                <div class="preview-content">
                    <h3 id="previewTitle" class="preview-empty">Title will appear here...</h3>
                    <p id="previewContent" class="preview-empty">Content will appear here...</p>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Publish Announcement
                </button>
                <a href="announcements-manage.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Character counters
const titleInput = document.getElementById('title');
const contentInput = document.getElementById('content');
const titleCount = document.getElementById('titleCount');
const contentCount = document.getElementById('contentCount');
const previewTitle = document.getElementById('previewTitle');
const previewContent = document.getElementById('previewContent');

function updateCounters() {
    titleCount.textContent = titleInput.value.length;
    contentCount.textContent = contentInput.value.length;
}

function updatePreview() {
    const title = titleInput.value.trim();
    const content = contentInput.value.trim();
    
    if (title) {
        previewTitle.textContent = title;
        previewTitle.classList.remove('preview-empty');
    } else {
        previewTitle.textContent = 'Title will appear here...';
        previewTitle.classList.add('preview-empty');
    }
    
    if (content) {
        previewContent.innerHTML = content.replace(/\n/g, '<br>');
        previewContent.classList.remove('preview-empty');
    } else {
        previewContent.textContent = 'Content will appear here...';
        previewContent.classList.add('preview-empty');
    }
}

titleInput.addEventListener('input', function() {
    updateCounters();
    updatePreview();
});

contentInput.addEventListener('input', function() {
    updateCounters();
    updatePreview();
});

// Initialize on page load
updateCounters();
updatePreview();

// Form validation
document.getElementById('announcementForm').addEventListener('submit', function(e) {
    const title = titleInput.value.trim();
    const content = contentInput.value.trim();
    
    if (!title || !content) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    // Disable submit button to prevent double submission
    document.getElementById('submitBtn').disabled = true;
});
</script>

<?php include '../../../includes/footer.php'; ?>