<?php
/**
 * QR Code Helper Functions
 * Barangay Management System
 * 
 * Uses quickchart.io as primary API (proven to work on your system)
 * Updated to generate QR codes that link to ID card verification page
 */

/**
 * Generate QR Code using reliable API
 */
function generateQRCodeSimple($data, $filename, $size = 400) {
    // Primary API: quickchart.io (PROVEN TO WORK on your system)
    $url = "https://quickchart.io/qr?text=" . urlencode($data) . "&size=" . $size;
    
    // Use cURL for better reliability
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        // Check if we got a valid response
        if ($httpCode === 200 && $imageData !== false && strlen($imageData) > 2000) {
            // Verify it's actually an image
            $isPNG = (substr($imageData, 0, 8) === "\x89PNG\r\n\x1a\n");
            
            if ($isPNG || strpos($contentType, 'image') !== false) {
                $result = file_put_contents($filename, $imageData);
                
                if ($result !== false) {
                    error_log("QR code generated successfully: " . basename($filename) . " (" . strlen($imageData) . " bytes)");
                    return true;
                } else {
                    error_log("Failed to save QR code to: " . $filename);
                    return false;
                }
            } else {
                error_log("Response was not a valid image (Content-Type: $contentType)");
            }
        } else {
            error_log("API request failed. HTTP Code: $httpCode, Size: " . strlen($imageData));
        }
        
        // Fallback to alternative API (api.qrserver.com) - though it's smaller
        $url2 = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data);
        
        $ch2 = curl_init($url2);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
        
        $imageData2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        if ($httpCode2 === 200 && $imageData2 !== false && strlen($imageData2) > 1000) {
            $result = file_put_contents($filename, $imageData2);
            
            if ($result !== false) {
                error_log("QR code generated using fallback API: " . basename($filename));
                return true;
            }
        }
    } else {
        // Fallback to file_get_contents if cURL not available
        $imageData = @file_get_contents($url);
        
        if ($imageData !== false && strlen($imageData) > 2000) {
            $result = file_put_contents($filename, $imageData);
            
            if ($result !== false) {
                return true;
            }
        }
    }
    
    error_log("All QR code generation methods failed for: " . $filename);
    return false;
}

/**
 * Generate QR Code for Resident
 * NOW GENERATES A URL THAT SHOWS THE ID CARD WHEN SCANNED
 * 
 * @param int $resident_id Resident ID
 * @param array $resident_data Resident information
 * @param string $output_dir Output directory path
 * @return string|false Filename on success, false on failure
 */
function generateResidentQRCode($resident_id, $resident_data, $output_dir) {
    // Ensure output directory exists
    if (!file_exists($output_dir)) {
        if (!mkdir($output_dir, 0777, true)) {
            error_log("Failed to create directory: " . $output_dir);
            return false;
        }
    }
    
    // Make sure directory is writable
    if (!is_writable($output_dir)) {
        error_log("Directory not writable: " . $output_dir);
        @chmod($output_dir, 0777);
        
        if (!is_writable($output_dir)) {
            return false;
        }
    }
    
    // **IMPORTANT CHANGE**: Generate verification URL instead of data
    // This URL will display the ID card when scanned
    $verification_url = (defined('BASE_URL') ? BASE_URL : (defined('APP_URL') ? APP_URL : '')) . 'modules/qrcodes/verify.php?id=' . $resident_id;
    
    // Log the URL being generated
    error_log("Generating QR code with verification URL: " . $verification_url);
    
    // Generate unique filename
    $filename = 'resident_' . $resident_id . '_' . time() . '.png';
    $filepath = $output_dir . $filename;
    
    // Generate QR code with the URL (not JSON data)
    // When scanned, it will open the verification page showing the ID card
    if (generateQRCodeSimple($verification_url, $filepath, 400)) {
        // Verify the file was created and has reasonable size
        if (file_exists($filepath) && filesize($filepath) > 2000) {
            error_log("QR code generated and verified: " . $filename . " (" . filesize($filepath) . " bytes)");
            return $filename;
        } else {
            error_log("QR code file created but appears invalid: " . $filename);
            // Delete the invalid file
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            return false;
        }
    }
    
    error_log("Failed to generate QR code for resident ID: " . $resident_id);
    return false;
}

/**
 * LEGACY FUNCTION - For backwards compatibility
 * Generate QR Code with JSON data (old method)
 * 
 * @param int $resident_id Resident ID
 * @param array $resident_data Resident information
 * @param string $output_dir Output directory path
 * @return string|false Filename on success, false on failure
 */
function generateResidentQRCodeWithData($resident_id, $resident_data, $output_dir) {
    // Ensure output directory exists
    if (!file_exists($output_dir)) {
        if (!mkdir($output_dir, 0777, true)) {
            error_log("Failed to create directory: " . $output_dir);
            return false;
        }
    }
    
    // Make sure directory is writable
    if (!is_writable($output_dir)) {
        error_log("Directory not writable: " . $output_dir);
        @chmod($output_dir, 0777);
        
        if (!is_writable($output_dir)) {
            return false;
        }
    }
    
    // Build QR code data - JSON format for structured data (OLD METHOD)
    $qr_data = json_encode([
        'type' => 'RESIDENT_ID',
        'resident_id' => $resident_id,
        'name' => $resident_data['full_name'] ?? 'Unknown',
        'address' => $resident_data['address'] ?? '',
        'contact' => $resident_data['contact'] ?? '',
        'barangay' => defined('BARANGAY_NAME') ? BARANGAY_NAME : 'Barangay Centro',
        'municipality' => defined('MUNICIPALITY') ? MUNICIPALITY : 'General Santos City',
        'province' => defined('PROVINCE') ? PROVINCE : 'South Cotabato',
        'generated_date' => date('Y-m-d H:i:s'),
        'verification_url' => (defined('APP_URL') ? APP_URL : '') . '/modules/qrcodes/verify.php?id=' . $resident_id
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    // Generate unique filename
    $filename = 'resident_' . $resident_id . '_' . time() . '.png';
    $filepath = $output_dir . $filename;
    
    // Generate QR code
    if (generateQRCodeSimple($qr_data, $filepath, 400)) {
        // Verify the file was created and has reasonable size
        if (file_exists($filepath) && filesize($filepath) > 2000) {
            error_log("QR code generated and verified: " . $filename . " (" . filesize($filepath) . " bytes)");
            return $filename;
        } else {
            error_log("QR code file created but appears invalid: " . $filename);
            // Delete the invalid file
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            return false;
        }
    }
    
    error_log("Failed to generate QR code for resident ID: " . $resident_id);
    return false;
}

/**
 * Verify QR Code data
 * @param string $qr_data JSON encoded QR code data
 * @return array|false Decoded data on success, false on failure
 */
function verifyQRCode($qr_data) {
    $data = json_decode($qr_data, true);
    
    if ($data === null || !isset($data['type']) || $data['type'] !== 'RESIDENT_ID') {
        return false;
    }
    
    return $data;
}

/**
 * Get QR Code URL
 * @param string $filename QR code filename
 * @return string URL to QR code image
 */
function getQRCodeURL($filename) {
    if (empty($filename)) {
        return '';
    }
    
    return (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . 'qrcodes/' . $filename;
}

/**
 * Delete QR Code file
 * @param string $filename QR code filename
 * @param string $directory QR code directory
 * @return bool True on success, false on failure
 */
function deleteQRCode($filename, $directory) {
    if (empty($filename)) {
        return false;
    }
    
    $filepath = $directory . $filename;
    
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    
    return false;
}

/**
 * Batch delete old QR codes for a resident (keep only the latest)
 * @param int $resident_id Resident ID
 * @param string $current_filename Current QR code filename to keep
 * @param string $directory QR code directory
 * @return int Number of files deleted
 */
function deleteOldQRCodes($resident_id, $current_filename, $directory) {
    $pattern = $directory . 'resident_' . $resident_id . '_*.png';
    $files = glob($pattern);
    $deleted = 0;
    
    foreach ($files as $file) {
        $basename = basename($file);
        if ($basename !== $current_filename) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}
?>