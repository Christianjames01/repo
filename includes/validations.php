<?php
// Business Permit Helper Functions

/**
 * Generate unique permit number
 */
function generatePermitNumber($conn) {
    $year = date('Y');
    
    $stmt = $conn->query("
        SELECT MAX(CAST(SUBSTRING(permit_number, -5) AS UNSIGNED)) as max_num
        FROM tbl_business_permits
        WHERE permit_number LIKE 'BP-$year-%'
    ");
    
    $result = $stmt->fetch_assoc();
    $next_number = ($result['max_num'] ?? 0) + 1;
    
    return 'BP-' . $year . '-' . str_pad($next_number, 5, '0', STR_PAD_LEFT);
}

/**
 * Calculate permit fees based on business type and other factors
 */
function calculatePermitFees($base_fee, $capital_investment = 0, $is_renewal = false) {
    $permit_fee = $base_fee;
    
    // Apply renewal discount (25%)
    if ($is_renewal) {
        $permit_fee *= 0.75;
    }
    
    // Additional fee for large capital investment
    if ($capital_investment > 1000000) {
        $permit_fee *= 1.5;
    } elseif ($capital_investment > 500000) {
        $permit_fee *= 1.25;
    }
    
    $sanitary_fee = 500.00;
    $garbage_fee = 300.00;
    
    return [
        'permit_fee' => $permit_fee,
        'sanitary_fee' => $sanitary_fee,
        'garbage_fee' => $garbage_fee,
        'total_fee' => $permit_fee + $sanitary_fee + $garbage_fee
    ];
}

/**
 * Validate business permit application
 */
function validateBusinessApplication($data) {
    $errors = [];
    
    $required_fields = [
        'business_name' => 'Business Name',
        'business_type_id' => 'Business Type',
        'business_address' => 'Business Address',
        'owner_name' => 'Owner Name',
        'nature_of_business' => 'Nature of Business'
    ];
    
    foreach ($required_fields as $field => $label) {
        if (empty($data[$field])) {
            $errors[] = "$label is required";
        }
    }
    
    // Validate email if provided
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Validate numbers
    if (isset($data['capital_investment']) && $data['capital_investment'] < 0) {
        $errors[] = "Capital investment cannot be negative";
    }
    
    if (isset($data['number_of_employees']) && $data['number_of_employees'] < 0) {
        $errors[] = "Number of employees cannot be negative";
    }
    
    return $errors;
}

/**
 * Upload and validate business document
 */
function uploadBusinessDocument($file, $permit_id, $document_type) {
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload error occurred'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File size exceeds 5MB limit'];
    }
    
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type. Only PDF, JPG, and PNG allowed'];
    }
    
    $upload_dir = UPLOAD_DIR . 'business/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $permit_id . '_' . $document_type . '_' . time() . '.' . $extension;
    $target_path = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'error' => 'Failed to save file'];
}

/**
 * Check if permit is expiring soon (within specified days)
 */
function isPermitExpiring($expiry_date, $days = 60) {
    $expiry = strtotime($expiry_date);
    $now = time();
    $diff = ($expiry - $now) / (60 * 60 * 24);
    
    return $diff > 0 && $diff <= $days;
}

/**
 * Check if permit is expired
 */
function isPermitExpired($expiry_date) {
    return strtotime($expiry_date) < time();
}

/**
 * Get business permit statistics
 */
function getBusinessPermitStats($conn) {
    $stats = [];
    
    $queries = [
        'total' => "SELECT COUNT(*) as count FROM tbl_business_permits",
        'active' => "SELECT COUNT(*) as count FROM tbl_business_permits WHERE status = 'Approved'",
        'pending' => "SELECT COUNT(*) as count FROM tbl_business_permits WHERE status = 'Pending'",
        'expired' => "SELECT COUNT(*) as count FROM tbl_business_permits WHERE status = 'Approved' AND expiry_date < CURDATE()",
        'revenue' => "SELECT SUM(total_fee) as total FROM tbl_business_permits WHERE payment_status = 'Paid'"
    ];
    
    foreach ($queries as $key => $query) {
        $result = $conn->query($query);
        $stats[$key] = $result->fetch_assoc()[$key === 'revenue' ? 'total' : 'count'] ?? 0;
    }
    
    return $stats;
}

/**
 * Send notification for permit application
 */
function sendPermitNotification($conn, $permit_id, $type, $recipient_id) {
    $messages = [
        'submitted' => 'Your business permit application has been submitted and is under review.',
        'approved' => 'Your business permit application has been approved! Please proceed to payment.',
        'rejected' => 'Your business permit application has been rejected. Please check the rejection reason.',
        'expiring' => 'Your business permit is expiring soon. Please renew to avoid interruption.',
        'expired' => 'Your business permit has expired. Please renew immediately.'
    ];
    
    $message = $messages[$type] ?? 'Business permit status update';
    
    // Insert into notifications table (if you have one)
    // Or send email/SMS
    
    return true;
}

/**
 * Log business permit activity
 */
function logPermitActivity($conn, $permit_id, $action, $old_status = null, $new_status = null, $remarks = null, $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO tbl_business_permit_history (permit_id, action, old_status, new_status, remarks, action_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("issssi", $permit_id, $action, $old_status, $new_status, $remarks, $user_id);
    return $stmt->execute();
}
?>