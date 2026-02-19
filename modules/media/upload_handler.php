<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

requireAnyRole(['Admin', 'Staff', 'Super Admin']);

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
        
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'No file uploaded or upload error occurred.';
            echo json_encode($response);
            exit;
        }
        
        $file = $_FILES['photo'];
        $caption = trim($_POST['caption'] ?? '');
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = mime_content_type($file['tmp_name']);
        
        if (!in_array($file_type, $allowed_types)) {
            $response['message'] = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
            echo json_encode($response);
            exit;
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            $response['message'] = 'File size must not exceed 5MB.';
            echo json_encode($response);
            exit;
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'photo_' . time() . '_' . uniqid() . '.' . $extension;
        $upload_path = MEDIA_PHOTO_DIR . $filename;
        $db_path = 'uploads/media/photos/' . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Save to database
            $created_by = $_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO tbl_barangay_media (media_type, file_path, caption, created_by) VALUES ('photo', ?, ?, ?)");
            $stmt->bind_param("ssi", $db_path, $caption, $created_by);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Photo uploaded successfully!';
            } else {
                $response['message'] = 'Database error: ' . $stmt->error;
                @unlink($upload_path); // Delete file if database insert fails
            }
            $stmt->close();
        } else {
            $response['message'] = 'Failed to move uploaded file.';
        }
        
    } elseif (isset($_POST['action']) && $_POST['action'] === 'add_video') {
        
        $video_url = trim($_POST['video_url'] ?? '');
        $caption = trim($_POST['caption'] ?? '');
        
        if (empty($video_url)) {
            $response['message'] = 'Video URL is required.';
            echo json_encode($response);
            exit;
        }
        
        // Convert YouTube URL to embed format
        if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
            $video_url = 'https://www.youtube.com/embed/' . $matches[1];
        } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
            $video_url = 'https://www.youtube.com/embed/' . $matches[1];
        }
        
        // Save to database
        $created_by = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO tbl_barangay_media (media_type, video_url, caption, created_by) VALUES ('video', ?, ?, ?)");
        $stmt->bind_param("ssi", $video_url, $caption, $created_by);
        
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Video added successfully!';
        } else {
            $response['message'] = 'Database error: ' . $stmt->error;
        }
        $stmt->close();
    }
}

echo json_encode($response);
?>