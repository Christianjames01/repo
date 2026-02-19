<?php
/**
 * Security Functions
 * BarangayLink System
 * Path: barangaylink/includes/security.php
 * 
 * NOTE: Some functions may already exist in functions.php
 * We check with function_exists() to avoid redeclaration errors
 */

// Validate email format
if (!function_exists('isValidEmail')) {
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

// Validate phone number (Philippine format)
if (!function_exists('isValidPhone')) {
    function isValidPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return preg_match('/^(09|\+639)\d{9}$/', $phone);
    }
}

// Hash password
if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

// Verify password
if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

// Generate random string
if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10) {
        return bin2hex(random_bytes($length / 2));
    }
}

// Generate reference number
if (!function_exists('generateReferenceNumber')) {
    function generateReferenceNumber($prefix = 'REF') {
        return strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    }
}

// CSRF Token Generation and Validation
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        // Use hash_equals to prevent timing attacks
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('getCSRFField')) {
    function getCSRFField() {
        $token = generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

// Check if user has specific permission
if (!function_exists('hasPermission')) {
    function hasPermission($permission) {
        if (!isset($_SESSION['role'])) {
            return false;
        }
        
        // Admin has all permissions
        if ($_SESSION['role'] === 'Admin') {
            return true;
        }
        
        // Define role-based permissions
        $permissions = [
            'Resident' => [
                'view_own_incidents',
                'report_incident',
                'view_announcements',
                'submit_documents',
                'view_own_profile'
            ],
            'Tanod' => [
                'view_own_incidents',
                'report_incident',
                'view_announcements',
                'submit_documents',
                'view_own_profile',
                'respond_to_incidents',
                'view_all_incidents',
                'patrol_duty'
            ]
        ];
        
        $user_role = $_SESSION['role'];
        
        if (isset($permissions[$user_role])) {
            return in_array($permission, $permissions[$user_role]);
        }
        
        return false;
    }
}

// Validate file upload
if (!function_exists('validateFileUpload')) {
    function validateFileUpload($file, $allowedTypes, $maxSize = null) {
        // Use defined constant or default to 10MB
        if ($maxSize === null) {
            $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 10485760;
        }
        
        $errors = [];
        
        if (!isset($file['error'])) {
            $errors[] = 'No file uploaded.';
            return $errors;
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = 'File size exceeds maximum allowed size.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = 'File was only partially uploaded.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = 'No file was uploaded.';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errors[] = 'Missing temporary folder.';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errors[] = 'Failed to write file to disk.';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errors[] = 'File upload stopped by extension.';
                    break;
                default:
                    $errors[] = 'File upload error occurred.';
                    break;
            }
            return $errors;
        }
        
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size of ' . round($maxSize / 1048576, 2) . 'MB.';
        }
        
        if ($file['size'] === 0) {
            $errors[] = 'File is empty.';
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $errors[] = 'Invalid file type.';
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx']
        ];
        
        if (isset($allowedExtensions[$mimeType]) && !in_array($extension, $allowedExtensions[$mimeType])) {
            $errors[] = 'File extension does not match file type.';
        }
        
        return $errors;
    }
}

// Upload file securely (different name to avoid conflict with functions.php)
if (!function_exists('uploadFileSecure')) {
    function uploadFileSecure($file, $destination, $allowedTypes, $maxSize = null) {
        if ($maxSize === null) {
            $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 10485760;
        }
        
        $errors = validateFileUpload($file, $allowedTypes, $maxSize);
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $filepath = rtrim($destination, '/') . '/' . $filename;
        
        if (!file_exists($destination)) {
            if (!mkdir($destination, 0755, true)) {
                return ['success' => false, 'errors' => ['Failed to create upload directory.']];
            }
        }
        
        if (!is_writable($destination)) {
            return ['success' => false, 'errors' => ['Upload directory is not writable.']];
        }
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            chmod($filepath, 0644);
            
            return [
                'success' => true, 
                'filename' => $filename, 
                'filepath' => $filepath,
                'relative_path' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $filepath)
            ];
        } else {
            return ['success' => false, 'errors' => ['Failed to move uploaded file.']];
        }
    }
}

// Upload multiple files
if (!function_exists('uploadMultipleFiles')) {
    function uploadMultipleFiles($files, $destination, $allowedTypes, $maxSize = null) {
        $uploaded = [];
        $errors = [];
        
        if (isset($files['name']) && is_array($files['name'])) {
            $fileCount = count($files['name']);
            
            for ($i = 0; $i < $fileCount; $i++) {
                $file = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];
                
                $result = function_exists('uploadFile') 
                    ? uploadFile($file, $destination, $allowedTypes, $maxSize)
                    : uploadFileSecure($file, $destination, $allowedTypes, $maxSize);
                
                if ($result['success']) {
                    $uploaded[] = $result;
                } else {
                    $errors[] = ['file' => $files['name'][$i], 'errors' => $result['errors']];
                }
            }
        } else {
            $result = function_exists('uploadFile') 
                ? uploadFile($files, $destination, $allowedTypes, $maxSize)
                : uploadFileSecure($files, $destination, $allowedTypes, $maxSize);
            
            if ($result['success']) {
                $uploaded[] = $result;
            } else {
                $errors[] = ['file' => $files['name'], 'errors' => $result['errors']];
            }
        }
        
        return [
            'success' => !empty($uploaded),
            'uploaded' => $uploaded,
            'errors' => $errors,
            'total' => count($uploaded),
            'failed' => count($errors)
        ];
    }
}

// Delete file
if (!function_exists('deleteFile')) {
    function deleteFile($filepath) {
        if (file_exists($filepath) && is_file($filepath)) {
            return unlink($filepath);
        }
        return false;
    }
}

// Prevent SQL Injection
if (!function_exists('escapeString')) {
    function escapeString($conn, $string) {
        return $conn->real_escape_string($string);
    }
}

// Log security event
if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent($conn, $user_id, $action, $details = '') {
        if (function_exists('logActivity')) {
            logActivity($conn, $user_id, $action . ': ' . $details, 'security_log');
        }
    }
}

// Check login attempts
if (!function_exists('checkLoginAttempts')) {
    function checkLoginAttempts($conn, $username) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $time_limit = date('Y-m-d H:i:s', strtotime('-15 minutes'));
        
        $sql = "SELECT COUNT(*) as attempts FROM tbl_audit_trail 
                WHERE action LIKE 'Failed login attempt%' 
                AND ip_address = ? 
                AND action_timestamp > ?";
        
        if (function_exists('fetchOne')) {
            $result = fetchOne($conn, $sql, [$ip_address, $time_limit], 'ss');
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $ip_address, $time_limit);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        
        return $result ? $result['attempts'] : 0;
    }
}

// Rate limiting
if (!function_exists('checkRateLimit')) {
    function checkRateLimit($action, $limit = 10, $period = 60) {
        $key = 'rate_limit_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 1, 'start_time' => time()];
            return true;
        }
        
        $data = $_SESSION[$key];
        $elapsed = time() - $data['start_time'];
        
        if ($elapsed > $period) {
            $_SESSION[$key] = ['count' => 1, 'start_time' => time()];
            return true;
        }
        
        if ($data['count'] >= $limit) {
            return false;
        }
        
        $_SESSION[$key]['count']++;
        return true;
    }
}

// Get allowed image types
if (!function_exists('getAllowedImageTypes')) {
    function getAllowedImageTypes() {
        return defined('ALLOWED_IMAGE_TYPES') 
            ? ALLOWED_IMAGE_TYPES 
            : ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }
}

// Get allowed document types
if (!function_exists('getAllowedDocumentTypes')) {
    function getAllowedDocumentTypes() {
        return defined('ALLOWED_DOCUMENT_TYPES') ? ALLOWED_DOCUMENT_TYPES : [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
    }
}

// Get max upload size
if (!function_exists('getMaxUploadSize')) {
    function getMaxUploadSize() {
        if (defined('UPLOAD_MAX_SIZE')) {
            return UPLOAD_MAX_SIZE;
        }
        
        $max_upload = ini_get('upload_max_filesize');
        $max_post = ini_get('post_max_size');
        $memory_limit = ini_get('memory_limit');
        
        $max_upload_bytes = convertToBytes($max_upload);
        $max_post_bytes = convertToBytes($max_post);
        $memory_limit_bytes = convertToBytes($memory_limit);
        
        return min($max_upload_bytes, $max_post_bytes, $memory_limit_bytes, 10485760);
    }
}

// Convert to bytes
if (!function_exists('convertToBytes')) {
    function convertToBytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int)$value;
        
        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}

// Format file size
if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}
?>