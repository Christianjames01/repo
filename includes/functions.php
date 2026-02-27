<?php

function sanitizeInput($data) {
    if ($data === null || $data === '') {
        return $data;
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Alias for consistency
function sanitize($data) {
    return sanitizeInput($data);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone($phone) {
    // Remove spaces and dashes
    $phone = preg_replace('/[\s\-]/', '', $phone);
    // Check if it matches Philippine phone format
    return preg_match('/^(09|\+639)\d{9}$/', $phone);
}

if (!function_exists('tableExists')) {
    function tableExists($conn, $table_name) {
        $result = $conn->query("SHOW TABLES LIKE '{$table_name}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('columnExists')) {
    function columnExists($conn, $table_name, $column_name) {
        if (!tableExists($conn, $table_name)) {
            return false;
        }
        $result = $conn->query("SHOW COLUMNS FROM {$table_name} LIKE '{$column_name}'");
        return $result && $result->num_rows > 0;
    }
}
function execute($conn, $sql, $params = [], $types = '') {
    // Auto-fix SQL if it's an UPDATE or SELECT query with potential missing columns
    if (preg_match('/^(UPDATE|SELECT)/i', trim($sql))) {
        $sql = validateAndFixSQL($conn, $sql);
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Prepare failed: " . $conn->error . " | SQL: " . $sql);
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; margin: 10px; border: 1px solid #f5c6cb; border-radius: 5px;'>";
            echo "<strong>SQL Prepare Error:</strong><br>";
            echo "<strong>Error:</strong> " . htmlspecialchars($conn->error) . "<br>";
            echo "<strong>SQL:</strong> <pre>" . htmlspecialchars($sql) . "</pre>";
            echo "</div>";
        }
        return false;
    }
    
    if (!empty($params) && !empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $result = $stmt->execute();
    
    if (!$result) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Execute failed: " . $stmt->error . " | SQL: " . $sql);
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; margin: 10px; border: 1px solid #f5c6cb; border-radius: 5px;'>";
            echo "<strong>SQL Execute Error:</strong><br>";
            echo "<strong>Error:</strong> " . htmlspecialchars($stmt->error) . "<br>";
            echo "<strong>SQL:</strong> <pre>" . htmlspecialchars($sql) . "</pre>";
            echo "<strong>Params:</strong> <pre>" . print_r($params, true) . "</pre>";
            echo "<strong>Types:</strong> " . htmlspecialchars($types) . "<br>";
            echo "</div>";
        }
        $stmt->close();
        return false;
    }
    
    return $stmt;
}

/**
 * Execute insert and return the inserted ID
 * RECOMMENDED for all INSERT operations that need the ID
 */
function executeInsert($conn, $sql, $params = [], $types = '') {
    $stmt = execute($conn, $sql, $params, $types);
    if ($stmt && is_object($stmt)) {
        $insert_id = $conn->insert_id;
        $stmt->close();
        return $insert_id > 0 ? $insert_id : false;
    }
    return false;
}

/**
 * Backward compatible - auto-closes statement
 * Use for UPDATE/DELETE queries or when you don't need insert_id
 */
function executeQuery($conn, $sql, $params = [], $types = '') {
    $stmt = execute($conn, $sql, $params, $types);
    if ($stmt && is_object($stmt)) {
        $stmt->close();
        return true;
    }
    return false;
}

function fetchAll($conn, $sql, $params = [], $types = '') {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Prepare failed: " . $conn->error . " | SQL: " . $sql);
        }
        return [];
    }
    
    if (!empty($params) && !empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Execute failed: " . $stmt->error . " | SQL: " . $sql);
        }
        $stmt->close();
        return [];
    }
    
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $data;
}

function validateAndFixSQL($conn, $sql) {
    // Extract table aliases and their actual table names from the query
    preg_match_all('/FROM\s+(\w+)\s+(?:AS\s+)?(\w+)|JOIN\s+(\w+)\s+(?:AS\s+)?(\w+)/i', $sql, $matches);
    
    $tables = [];
    for ($i = 0; $i < count($matches[0]); $i++) {
        $tableName = !empty($matches[1][$i]) ? $matches[1][$i] : $matches[3][$i];
        $alias = !empty($matches[2][$i]) ? $matches[2][$i] : $matches[4][$i];
        if ($tableName && $alias) {
            $tables[$alias] = $tableName;
        }
    }
    
    // Get columns for each table
    $tableColumns = [];
    foreach ($tables as $alias => $tableName) {
        if (tableExists($conn, $tableName)) {
            $result = $conn->query("SHOW COLUMNS FROM {$tableName}");
            if ($result) {
                $tableColumns[$alias] = [];
                while ($row = $result->fetch_assoc()) {
                    $tableColumns[$alias][] = $row['Field'];
                }
            }
        }
    }
    
    // Common column mappings for missing columns
    $columnMappings = [
        'age' => ['age', 'birth_date', 'birthdate', 'date_of_birth', 'dob'],
        'first_name' => ['first_name', 'firstname', 'fname', 'name', 'full_name', 'username'],
        'last_name' => ['last_name', 'lastname', 'lname', 'surname'],
        'middle_name' => ['middle_name', 'middlename', 'mname'],
        'gender' => ['gender', 'sex'],
        'contact' => ['contact', 'contact_number', 'phone', 'phone_number', 'mobile'],
        'email' => ['email', 'email_address'],
        'address' => ['address', 'street_address', 'full_address', 'location']
    ];
    
    // Check and fix columns for each table
    foreach ($tableColumns as $alias => $columns) {
        // Find all column references for this alias in the query
        preg_match_all('/' . preg_quote($alias) . '\.(\w+)/i', $sql, $columnMatches);
        
        foreach ($columnMatches[1] as $referencedColumn) {
            // Skip if column exists
            if (in_array($referencedColumn, $columns)) {
                continue;
            }
            
            // Try to find a replacement
            $replacement = null;
            
            // Check if we have a mapping for this column
            if (isset($columnMappings[$referencedColumn])) {
                foreach ($columnMappings[$referencedColumn] as $possibleColumn) {
                    if (in_array($possibleColumn, $columns)) {
                        $replacement = $possibleColumn;
                        break;
                    }
                }
            }
            
            // Special handling for age - calculate from birth_date if available
            if ($referencedColumn === 'age' && !$replacement) {
                foreach (['birth_date', 'birthdate', 'date_of_birth', 'dob'] as $dateCol) {
                    if (in_array($dateCol, $columns)) {
                        // Replace age with calculation
                        $sql = str_replace(
                            $alias . '.age',
                            'TIMESTAMPDIFF(YEAR, ' . $alias . '.' . $dateCol . ', CURDATE())',
                            $sql
                        );
                        $replacement = 'calculated';
                        break;
                    }
                }
            }
            
            // Apply simple replacement if found
            if ($replacement && $replacement !== 'calculated') {
                $sql = str_replace(
                    $alias . '.' . $referencedColumn,
                    $alias . '.' . $replacement,
                    $sql
                );
            }
            
            // If still no replacement found, remove the column from SELECT
            if (!$replacement) {
                // Remove from SELECT clause (handles both ",column" and "column," formats)
                $sql = preg_replace(
                    '/,\s*' . preg_quote($alias . '.' . $referencedColumn) . '\s*(?:AS\s+\w+)?/',
                    '',
                    $sql
                );
                $sql = preg_replace(
                    '/' . preg_quote($alias . '.' . $referencedColumn) . '\s*(?:AS\s+\w+)?\s*,/',
                    '',
                    $sql
                );
            }
        }
    }
    
    // Handle user table specific patterns
    if (isset($tableColumns['u'])) {
        $userColumns = $tableColumns['u'];
        
        // Handle last_name removal if first_name was merged into a single name field
        if (strpos($sql, 'u.last_name') !== false && !in_array('last_name', $userColumns)) {
            // Check if first_name exists or was replaced
            $hasFirstName = in_array('first_name', $userColumns) || 
                           strpos($sql, 'u.name') !== false || 
                           strpos($sql, 'u.full_name') !== false;
            
            if ($hasFirstName) {
                $sql = preg_replace('/,?\s*u\.last_name\s*(?:AS\s+\w+)?/', '', $sql);
            }
        }
    }
    
    return $sql;
}

function fetchOne($conn, $sql, $params = [], $types = '') {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Prepare failed: " . $conn->error . " | SQL: " . $sql);
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; margin: 10px; border: 1px solid #f5c6cb; border-radius: 5px;'>";
            echo "<strong>SQL Prepare Error:</strong><br>";
            echo "<strong>Error:</strong> " . htmlspecialchars($conn->error) . "<br>";
            echo "<strong>SQL:</strong> <pre>" . htmlspecialchars($sql) . "</pre>";
            echo "</div>";
        }
        return null;
    }
    
    if (!empty($params) && !empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Execute failed: " . $stmt->error . " | SQL: " . $sql);
        }
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    return $data;
}

function getLastInsertId($conn) {
    return $conn->insert_id;
}

function displayMessage() {
    $output = '';
    $types = ['success', 'error', 'warning', 'info'];
    
    foreach ($types as $type) {
        $key = $type . '_message';
        if (isset($_SESSION[$key])) {
            $alertClass = $type === 'error' ? 'danger' : $type;
            $icons = [
                'success' => 'check-circle',
                'error' => 'exclamation-circle',
                'warning' => 'exclamation-triangle',
                'info' => 'info-circle'
            ];
            
            $output .= '<div class="alert alert-' . $alertClass . ' alert-dismissible fade show" role="alert">';
            $output .= '<i class="fas fa-' . $icons[$type] . ' me-2"></i>';
            $output .= htmlspecialchars($_SESSION[$key]);
            $output .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            $output .= '</div>';
            
            unset($_SESSION[$key]);
        }
    }
    
    return $output;
}

function setMessage($message, $type = 'info') {
    $_SESSION[$type . '_message'] = $message;
}

function formatDate($date, $format = null) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    
    $format = $format ?? (defined('DATE_FORMAT') ? DATE_FORMAT : 'F j, Y');
    return date($format, strtotime($date));
}

function formatTime($time, $format = null) {
    if (empty($time) || $time === '00:00:00') {
        return '-';
    }
    
    $format = $format ?? (defined('TIME_FORMAT') ? TIME_FORMAT : 'g:i A');
    return date($format, strtotime($time));
}

function formatDateTime($datetime, $format = null) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    
    $format = $format ?? (defined('DATETIME_FORMAT') ? DATETIME_FORMAT : 'F j, Y g:i A');
    return date($format, strtotime($datetime));
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return formatDate($datetime);
    }
}

function uploadFile($file, $destination, $allowed_types = [], $max_size = null) {
    $max_size = $max_size ?? (defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 5242880);
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return ['success' => false, 'message' => 'No file uploaded'];
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        $max_mb = $max_size / 1048576;
        return ['success' => false, 'message' => "File too large. Maximum size is {$max_mb}MB"];
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!empty($allowed_types) && !in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $destination . $filename;
    
    // Create directory if it doesn't exist
    if (!file_exists($destination)) {
        mkdir($destination, 0755, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'message' => 'File uploaded successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }
}

function deleteFile($filepath) {
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Log activity with proper validation
 * Validates record_id to prevent duplicate entry errors
 */
function logActivity($conn, $user_id, $action, $table_name = null, $record_id = null) {
    // Validate that tbl_activity_logs exists
    if (!tableExists($conn, 'tbl_activity_logs')) {
        error_log("Table tbl_activity_logs does not exist");
        return false;
    }
    
    // Check which columns exist in the activity_logs table
    $result = $conn->query("SHOW COLUMNS FROM tbl_activity_logs");
    if (!$result) {
        error_log("Failed to check tbl_activity_logs columns: " . $conn->error);
        return false;
    }
    
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Build SQL based on available columns
    $fields = ['user_id', 'action'];
    $values = ['?', '?'];
    $types = 'is';
    $params = [$user_id, $action];
    
    // Add optional fields if they exist in the table AND have valid values
    if (in_array('table_name', $columns) && !empty($table_name)) {
        $fields[] = 'table_name';
        $values[] = '?';
        $types .= 's';
        $params[] = $table_name;
    }
    
    // CRITICAL FIX: Only add record_id if it's a valid positive integer
    // This prevents duplicate entry errors from record_id = 0
    if (in_array('record_id', $columns) && !empty($record_id) && is_numeric($record_id) && $record_id > 0) {
        $fields[] = 'record_id';
        $values[] = '?';
        $types .= 'i';
        $params[] = (int)$record_id;
    }
    
    if (in_array('ip_address', $columns)) {
        $fields[] = 'ip_address';
        $values[] = '?';
        $types .= 's';
        $params[] = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    if (in_array('user_agent', $columns)) {
        $fields[] = 'user_agent';
        $values[] = '?';
        $types .= 's';
        $params[] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    // Add timestamp field if it exists
    if (in_array('created_at', $columns)) {
        $fields[] = 'created_at';
        $values[] = 'NOW()';
    } elseif (in_array('timestamp', $columns)) {
        $fields[] = 'timestamp';
        $values[] = 'NOW()';
    } elseif (in_array('date_created', $columns)) {
        $fields[] = 'date_created';
        $values[] = 'NOW()';
    }
    
    $sql = "INSERT INTO tbl_activity_logs (" . implode(', ', $fields) . ") 
            VALUES (" . implode(', ', $values) . ")";
    
    return executeQuery($conn, $sql, $params, $types);
}

function generatePagination($current_page, $total_pages, $base_url) {
    if ($total_pages <= 1) {
        return '';
    }
    
    $output = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // Previous button
    $disabled = $current_page <= 1 ? 'disabled' : '';
    $prev_page = max(1, $current_page - 1);
    $output .= '<li class="page-item ' . $disabled . '">';
    $output .= '<a class="page-link" href="' . $base_url . '&page=' . $prev_page . '">Previous</a>';
    $output .= '</li>';
    
    // Page numbers
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $current_page ? 'active' : '';
        $output .= '<li class="page-item ' . $active . '">';
        $output .= '<a class="page-link" href="' . $base_url . '&page=' . $i . '">' . $i . '</a>';
        $output .= '</li>';
    }
    
    // Next button
    $disabled = $current_page >= $total_pages ? 'disabled' : '';
    $next_page = min($total_pages, $current_page + 1);
    $output .= '<li class="page-item ' . $disabled . '">';
    $output .= '<a class="page-link" href="' . $base_url . '&page=' . $next_page . '">Next</a>';
    $output .= '</li>';
    
    $output .= '</ul></nav>';
    
    return $output;
}

/**
 * Build query string from current GET parameters, excluding specified keys
 */
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    
    if (empty($params)) {
        return '';
    }
    
    return '&' . http_build_query($params);
}

function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function sendJsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function dd($data) {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        die();
    }
}

function getUnreadNotificationCount($conn, $user_id) {
    $result = fetchOne($conn, 
        "SELECT COUNT(*) as count FROM tbl_notifications WHERE user_id = ? AND is_read = 0",
        [$user_id], 'i'
    );
    return $result ? (int)$result['count'] : 0;
}

function markNotificationAsRead($conn, $notification_id, $user_id) {
    return executeQuery($conn, 
        "UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?",
        [$notification_id, $user_id], 'ii'
    );
}

function markAllNotificationsAsRead($conn, $user_id) {
    return executeQuery($conn,
        "UPDATE tbl_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
        [$user_id], 'i'
    );
}

function createNotification($conn, $user_id, $title, $message, $type, $reference_id = null, $reference_type = null) {
    // Convert empty values to NULL
    $reference_id = (!empty($reference_id) && $reference_id > 0) ? (int)$reference_id : null;
    $reference_type = !empty($reference_type) ? $reference_type : null;
    
    $sql = "INSERT INTO tbl_notifications 
            (user_id, title, message, type, reference_id, reference_type, is_read, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    
    // Build parameters based on what we have
    if ($reference_id !== null && $reference_type !== null) {
        return executeInsert($conn, $sql, [$user_id, $title, $message, $type, $reference_id, $reference_type], 'isssis');
    } elseif ($reference_id !== null) {
        // Only reference_id, set reference_type to NULL
        return executeInsert($conn, $sql, [$user_id, $title, $message, $type, $reference_id, null], 'isssis');
    } elseif ($reference_type !== null) {
        // Only reference_type, set reference_id to NULL
        return executeInsert($conn, $sql, [$user_id, $title, $message, $type, null, $reference_type], 'isssis');
    } else {
        // Both NULL
        return executeInsert($conn, $sql, [$user_id, $title, $message, $type, null, null], 'isssis');
    }
}
function getUserFullName($conn, $user_id) {
    $user = fetchOne($conn,
        "SELECT u.username, u.resident_id, 
                r.first_name, r.middle_name, r.last_name 
         FROM tbl_users u
         LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
         WHERE u.user_id = ?",
        [$user_id], 'i'
    );
    
    if (!$user) {
        return "User #$user_id";
    }
    
    // Build full name if available
    if (!empty($user['first_name']) && !empty($user['last_name'])) {
        $name_parts = [$user['first_name']];
        if (!empty($user['middle_name'])) {
            $name_parts[] = substr($user['middle_name'], 0, 1) . '.';
        }
        $name_parts[] = $user['last_name'];
        return implode(' ', $name_parts);
    }
    
    return $user['username'];
}

/**
 * Notify when a new incident is reported
 * Super Admin gets notified
 */
/**
 * Notify when a new incident is reported
 * Super Admin AND other staff roles get notified
 */
function notifyIncidentReported($conn, $incident_id, $incident_title) {
    // Get all admin and staff users who should be notified
    $admins = fetchAll($conn,
        "SELECT user_id FROM tbl_users 
         WHERE role IN ('Super Administrator', 'Administrator', 'Admin', 'Staff', 'Secretary', 'Tanod') 
         AND status = 'active'",
        [], ''
    );

    $success = true;
    foreach ($admins as $admin) {
        $result = createNotification(
            $conn,
            $admin['user_id'],
            'New Incident Reported',
            "A new incident has been reported: {$incident_title}. Please review and assign to a staff member.",
            'incident_reported',
            $incident_id,
            'incident'
        );
        $success = $success && $result;
    }

    return $success;
}
function notifyIncidentAssignment($conn, $incident_id, $incident_title, $assigned_to_id, $assigned_by_name, $reporter_id = null) {
    $success = true;
    
    // Get the assigned staff member's name using helper function
    $staff_name = getUserFullName($conn, $assigned_to_id);
    
    // Notify the assigned staff member
    $result1 = createNotification(
        $conn,
        $assigned_to_id,
        'New Incident Assignment',
        "You have been assigned to incident: {$incident_title}",
        'incident_assignment',
        $incident_id,
        'incident'
    );
    $success = $success && $result1;
    
    // Notify the reporter (if exists and different from assigned staff)
    if ($reporter_id && $reporter_id != $assigned_to_id) {
        $result2 = createNotification(
            $conn,
            $reporter_id,
            'Incident Assigned',
            "Your incident report (ID: #{$incident_id}) has been assigned to {$staff_name}.",
            'incident_assignment',
            $incident_id,
            'incident'
        );
        $success = $success && $result2;
    }
    
    return $success;
}

function notifyIncidentStatusUpdate($conn, $incident_id, $incident_title, $new_status, $reporter_id, $assigned_to_id = null) {
    $success = true;
    
    // Notify the reporter
    $result1 = createNotification(
        $conn,
        $reporter_id,
        'Incident Status Updated',
        "Your incident report (ID: #{$incident_id}) status has been updated to: {$new_status}",
        'status_update',
        $incident_id,
        'incident'
    );
    $success = $success && $result1;
    
    // Notify assigned staff if exists and different from reporter
    if ($assigned_to_id && $assigned_to_id != $reporter_id) {
        $result2 = createNotification(
            $conn,
            $assigned_to_id,
            'Incident Status Updated',
            "Incident #{$incident_id} - {$incident_title} status changed to: {$new_status}",
            'status_update',
            $incident_id,
            'incident'
        );
        $success = $success && $result2;
    }
    
    return $success;
}

function notifyComplaintSubmitted($conn, $complaint_id, $complaint_subject) {
    // Get all Super Administrators
    $super_admins = fetchAll($conn, 
        "SELECT user_id FROM tbl_users WHERE role = 'Super Administrator' AND status = 'active'", 
        [], ''
    );
    
    $success = true;
    foreach ($super_admins as $admin) {
        $result = createNotification(
            $conn,
            $admin['user_id'],
            'New Complaint Submitted',
            "A new complaint has been submitted: {$complaint_subject}. Please review and assign.",
            'complaint_submitted',
            $complaint_id,
            'complaint'
        );
        $success = $success && $result;
    }
    
    return $success;
}

function notifyComplaintAssignment($conn, $complaint_id, $complaint_subject, $assigned_to_id, $complainant_id = null) {
    $success = true;
    
    // Get the assigned staff member's name using helper function
    $staff_name = getUserFullName($conn, $assigned_to_id);
    
    // Notify the assigned staff member
    $result1 = createNotification(
        $conn,
        $assigned_to_id,
        'New Complaint Assignment',
        "You have been assigned to complaint: {$complaint_subject}",
        'complaint_assignment',
        $complaint_id,
        'complaint'
    );
    $success = $success && $result1;
    
    // Notify the complainant (if exists and different from assigned staff)
    if ($complainant_id && $complainant_id != $assigned_to_id) {
        $result2 = createNotification(
            $conn,
            $complainant_id,
            'Complaint Assigned',
            "Your complaint (ID: #{$complaint_id}) has been assigned to {$staff_name}.",
            'complaint_assignment',
            $complaint_id,
            'complaint'
        );
        $success = $success && $result2;
    }
    
    return $success;
}

function notifyComplaintStatusUpdate($conn, $complaint_id, $complaint_subject, $new_status, $complainant_id, $assigned_to_id = null) {
    $success = true;
    
    // Notify the complainant
    $result1 = createNotification(
        $conn,
        $complainant_id,
        'Complaint Status Updated',
        "Your complaint (ID: #{$complaint_id}) status has been updated to: {$new_status}",
        'complaint_update',
        $complaint_id,
        'complaint'
    );
    $success = $success && $result1;
    
    // Notify assigned staff if exists and different from complainant
    if ($assigned_to_id && $assigned_to_id != $complainant_id) {
        $result2 = createNotification(
            $conn,
            $assigned_to_id,
            'Complaint Status Updated',
            "Complaint #{$complaint_id} - {$complaint_subject} status changed to: {$new_status}",
            'complaint_update',
            $complaint_id,
            'complaint'
        );
        $success = $success && $result2;
    }
    
    return $success;
}

function getStatusBadge($status) {
    // Handle null or empty status
    if (empty($status) || $status === '') {
        return '<span class="badge bg-secondary"><i class="fas fa-question-circle me-1"></i>Unknown</span>';
    }
    
    // Normalize the status: trim whitespace and make case-insensitive comparison
    $status = trim($status);
    $statusLower = strtolower($status);
    
    // Define badges with lowercase keys for matching
    $badges = [
        // Main incident statuses (prioritized)
        'pending' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>',
        'open' => '<span class="badge bg-info"><i class="fas fa-folder-open me-1"></i>Open</span>',
        'under investigation' => '<span class="badge bg-info"><i class="fas fa-search me-1"></i>Under Investigation</span>',
        'in progress' => '<span class="badge bg-primary"><i class="fas fa-spinner me-1"></i>In Progress</span>',
        'resolved' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Resolved</span>',
        'archived' => '<span class="badge bg-dark"><i class="fas fa-archive me-1"></i>Archived</span>',
        'closed' => '<span class="badge bg-secondary"><i class="fas fa-lock me-1"></i>Closed</span>',
        'reported' => '<span class="badge bg-warning text-dark"><i class="fas fa-flag me-1"></i>Reported</span>',
        
        // Attendance statuses
        'present' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Present</span>',
        'late' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Late</span>',
        'absent' => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Absent</span>',
        'on leave' => '<span class="badge bg-info"><i class="fas fa-calendar-times me-1"></i>On Leave</span>',
        'half day' => '<span class="badge bg-secondary"><i class="fas fa-adjust me-1"></i>Half Day</span>',
        'excused' => '<span class="badge bg-primary"><i class="fas fa-user-check me-1"></i>Excused</span>',
        
        // Document request statuses
        'processing' => '<span class="badge bg-info"><i class="fas fa-cog me-1"></i>Processing</span>',
        'ready for pickup' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Ready for Pickup</span>',
        'completed' => '<span class="badge bg-success"><i class="fas fa-check-double me-1"></i>Completed</span>',
        'rejected' => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Rejected</span>',
        'cancelled' => '<span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        
        // Other statuses
        'under review' => '<span class="badge bg-info"><i class="fas fa-eye me-1"></i>Under Review</span>',
        'approved' => '<span class="badge bg-success"><i class="fas fa-thumbs-up me-1"></i>Approved</span>',
        'verified' => '<span class="badge bg-primary"><i class="fas fa-check-square me-1"></i>Verified</span>',
        'active' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
        'inactive' => '<span class="badge bg-secondary"><i class="fas fa-sign-out-alt me-1"></i>Checked Out</span>',
        'denied' => '<span class="badge bg-danger"><i class="fas fa-thumbs-down me-1"></i>Denied</span>'
    ];
    
    // Try to find a match (case-insensitive)
    if (isset($badges[$statusLower])) {
        return $badges[$statusLower];
    }
    
    // If no match found, return a default badge with the original status text
    return '<span class="badge bg-secondary"><i class="fas fa-info-circle me-1"></i>' . htmlspecialchars($status) . '</span>';
}

function getSeverityBadge($severity) {
    // Handle null or empty severity
    if (empty($severity) || $severity === '') {
        return '<span class="badge bg-secondary">N/A</span>';
    }
    
    // Normalize the severity: trim whitespace and make case-insensitive comparison
    $severity = trim($severity);
    $severityLower = strtolower($severity);
    
    $badges = [
        // Incident severities
        'low' => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Low</span>',
        'medium' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
        'high' => '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
        'critical' => '<span class="badge bg-dark"><i class="fas fa-skull-crossbones me-1"></i>Critical</span>',
        
        // Damage assessment severities
        'minor' => '<span class="badge bg-success"><i class="fas fa-info-circle me-1"></i>Minor</span>',
        'moderate' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>Moderate</span>',
        'major' => '<span class="badge bg-warning"><i class="fas fa-exclamation-triangle me-1"></i>Major</span>',
        'severe' => '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Severe</span>',
        'total loss' => '<span class="badge bg-dark"><i class="fas fa-times-circle me-1"></i>Total Loss</span>'
    ];
    
    // Try to find a match (case-insensitive)
    if (isset($badges[$severityLower])) {
        return $badges[$severityLower];
    }
    
    // If no match found, return a default badge with the original severity text
    return '<span class="badge bg-secondary"><i class="fas fa-info-circle me-1"></i>' . htmlspecialchars($severity) . '</span>';
}

?>