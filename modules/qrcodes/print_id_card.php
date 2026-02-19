<?php
/**
 * Enhanced Professional ID Card Printer - COMPLETE VERSION WITH IMPROVED ERROR HANDLING
 * Barangay Management System
 * 
 * Features:
 * - Works with standard Letter paper (8.5" x 11")
 * - Prints both front and back on same page
 * - Includes cut guides
 * - Professional layout preservation
 * - Better error handling and user guidance
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/qr_helper.php';

// Require admin or staff access
requireAnyRole(['Admin', 'Super Admin', 'Staff']);

$resident_id = intval($_GET['resident_id'] ?? 0);

// ============================================================================
// IMPROVED ERROR HANDLING - Show helpful message instead of just dying
// ============================================================================
if ($resident_id <= 0) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ID Card Printer - Error</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body {
                font-family: Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                padding: 40px;
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                max-width: 600px;
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                color: #f59e0b;
                margin-bottom: 20px;
            }
            h1 {
                color: #1f2937;
                margin-bottom: 15px;
                font-size: 28px;
            }
            p {
                color: #6b7280;
                margin-bottom: 15px;
                line-height: 1.6;
            }
            .help-box {
                background: #fef3c7;
                border: 2px solid #f59e0b;
                padding: 20px;
                border-radius: 8px;
                margin: 25px 0;
                text-align: left;
            }
            .help-box h3 {
                color: #92400e;
                margin-bottom: 10px;
                font-size: 16px;
            }
            .help-box ol {
                color: #78350f;
                margin-left: 20px;
            }
            .help-box li {
                margin: 8px 0;
            }
            .example-url {
                background: #f3f4f6;
                padding: 10px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 14px;
                margin: 10px 0;
                word-break: break-all;
                color: #1f2937;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: #2c5282;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                margin-top: 20px;
                transition: all 0.2s;
            }
            .btn:hover {
                background: #1e3a5f;
                transform: translateY(-1px);
            }
            .btn i {
                margin-right: 8px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h1>Invalid Resident ID</h1>
            <p>You accessed this page without specifying which resident's ID card to print.</p>
            
            <div class="help-box">
                <h3><i class="fas fa-info-circle"></i> How to Use This Page:</h3>
                <ol>
                    <li>Go to the <strong>QR Code Management</strong> page (modules/qrcodes/index.php)</li>
                    <li>Find the resident whose ID card you want to print</li>
                    <li>Click the <strong><i class="fas fa-print"></i> Print</strong> button next to their name</li>
                </ol>
                
                <p style="margin-top: 15px; font-size: 14px;">
                    <strong>Or</strong> access this page directly with a resident ID:
                </p>
                <div class="example-url">
                    print_id_card.php?resident_id=1
                </div>
                <p style="font-size: 13px; color: #78350f; margin-top: 5px;">
                    Replace "1" with the actual resident ID number
                </p>
            </div>
            
            <a href="index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Go to QR Code Management
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Get resident data with comprehensive date handling
$resident = fetchOne($conn,
    "SELECT 
        r.*,
        CONCAT(r.first_name, ' ', IFNULL(CONCAT(r.middle_name, ' '), ''), r.last_name) as full_name,
        CONCAT(r.first_name, ' ', IFNULL(CONCAT(SUBSTRING(r.middle_name, 1, 1), '. '), ''), r.last_name) as display_name,
        CASE 
            WHEN r.date_of_birth IS NOT NULL AND r.date_of_birth != '0000-00-00' 
            THEN TIMESTAMPDIFF(YEAR, r.date_of_birth, CURDATE())
            WHEN r.birth_date IS NOT NULL AND r.birth_date != '0000-00-00'
            THEN TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE())
            ELSE 0
        END as age
    FROM tbl_residents r
    WHERE r.resident_id = ?",
    [$resident_id], 'i'
);

// ============================================================================
// IMPROVED ERROR HANDLING - Better "Resident Not Found" message
// ============================================================================
if (!$resident) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ID Card Printer - Resident Not Found</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body {
                font-family: Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                padding: 40px;
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                max-width: 500px;
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                color: #ef4444;
                margin-bottom: 20px;
            }
            h1 {
                color: #1f2937;
                margin-bottom: 15px;
            }
            p {
                color: #6b7280;
                margin-bottom: 20px;
                line-height: 1.6;
            }
            .resident-id {
                background: #fee2e2;
                color: #991b1b;
                padding: 10px 20px;
                border-radius: 8px;
                font-weight: 700;
                font-size: 18px;
                display: inline-block;
                margin: 10px 0;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: #2c5282;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                margin: 5px;
                transition: all 0.2s;
            }
            .btn:hover {
                background: #1e3a5f;
            }
            .btn i {
                margin-right: 8px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">
                <i class="fas fa-user-times"></i>
            </div>
            <h1>Resident Not Found</h1>
            <p>No resident was found with this ID:</p>
            <div class="resident-id">ID: <?php echo htmlspecialchars($resident_id); ?></div>
            <p>This resident may have been deleted or the ID is incorrect.</p>
            
            <a href="index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Format birthday with comprehensive fallback logic
$resident['formatted_birthdate'] = '';

// Try date_of_birth first (most common field name)
if (!empty($resident['date_of_birth']) && $resident['date_of_birth'] != '0000-00-00') {
    try {
        $date = new DateTime($resident['date_of_birth']);
        $resident['formatted_birthdate'] = $date->format('m/d/Y');
    } catch (Exception $e) {
        // Date parsing failed
    }
}

// Fallback to birth_date if date_of_birth is empty
if (empty($resident['formatted_birthdate']) && !empty($resident['birth_date']) && $resident['birth_date'] != '0000-00-00') {
    try {
        $date = new DateTime($resident['birth_date']);
        $resident['formatted_birthdate'] = $date->format('m/d/Y');
    } catch (Exception $e) {
        // Date parsing failed
    }
}

// If still empty, set a default message
if (empty($resident['formatted_birthdate'])) {
    $resident['formatted_birthdate'] = 'Not provided';
}

// Generate QR code if it doesn't exist
if (empty($resident['qr_code'])) {
    $qr_dir = UPLOAD_DIR . 'qrcodes/';
    
    if (!file_exists($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }
    
    $resident_data = [
        'resident_id' => $resident['resident_id'],
        'full_name' => $resident['full_name'],
        'address' => $resident['address'],
        'contact' => $resident['contact_number']
    ];
    
    $qr_filename = generateResidentQRCode($resident_id, $resident_data, $qr_dir);
    
    if ($qr_filename) {
        executeQuery($conn,
            "UPDATE tbl_residents SET qr_code = ? WHERE resident_id = ?",
            [$qr_filename, $resident_id], 'si'
        );
        $resident['qr_code'] = $qr_filename;
    }
}

// Define constants
$barangay_name = BARANGAY_NAME;
$municipality = MUNICIPALITY;
$province = PROVINCE;
$contact = BARANGAY_CONTACT;
$logo_path = BARANGAY_LOGO;

$logo_file_path = $_SERVER['DOCUMENT_ROOT'] . $logo_path;
$logo_exists = file_exists($logo_file_path);
$logo_display_path = $logo_path;

// Check for signature image
$signature_path = UPLOAD_DIR . 'signatures/resident_' . $resident_id . '_signature.png';
$signature_url = UPLOAD_URL . 'signatures/resident_' . $resident_id . '_signature.png';
$signature_exists = file_exists($signature_path);

$admin_signature_path = UPLOAD_DIR . 'signatures/admin_signature.png';
$admin_signature_url = UPLOAD_URL . 'signatures/admin_signature.png';
$admin_signature_exists = file_exists($admin_signature_path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay ID Card - <?php echo htmlspecialchars($resident['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== PRINT PAGE SETUP ===== */
        @page {
            size: letter portrait;
            margin: 0.5in;
        }
        
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        
        /* ===== SCREEN STYLES ===== */
        body {
            margin: 0;
            padding: 20px;
            font-family: 'Arial', sans-serif;
            background: #f8f9fa;
        }
        
        .print-container {
            max-width: 8.5in;
            margin: 0 auto;
            background: white;
            padding: 0.5in;
        }
        
        .print-page {
            width: 7.5in;
            margin: 0 auto;
            position: relative;
        }
        
        .cards-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5in;
            margin-bottom: 0.5in;
        }
        
        .card-section {
            position: relative;
        }
        
        .card-label {
            text-align: center;
            font-weight: 700;
            color: #2c5282;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        .id-card-container {
            width: 3.375in;
            height: 2.125in;
            position: relative;
            margin: 0 auto;
            border: 2px dashed #cbd5e0;
            padding: 2px;
        }
        
        /* ===== CUT GUIDES ===== */
        .cut-guide {
            position: absolute;
            background: #cbd5e0;
        }
        
        .cut-guide.horizontal {
            height: 1px;
            width: 0.25in;
        }
        
        .cut-guide.vertical {
            width: 1px;
            height: 0.25in;
        }
        
        .cut-guide.top-left.horizontal { top: -1px; left: -0.25in; }
        .cut-guide.top-left.vertical { top: -0.25in; left: -1px; }
        .cut-guide.top-right.horizontal { top: -1px; right: -0.25in; }
        .cut-guide.top-right.vertical { top: -0.25in; right: -1px; }
        .cut-guide.bottom-left.horizontal { bottom: -1px; left: -0.25in; }
        .cut-guide.bottom-left.vertical { bottom: -0.25in; left: -1px; }
        .cut-guide.bottom-right.horizontal { bottom: -1px; right: -0.25in; }
        .cut-guide.bottom-right.vertical { bottom: -0.25in; right: -1px; }
        
        /* ===== FRONT OF ID ===== */
        .id-front {
            width: 100%;
            height: 100%;
            background: white;
            position: relative;
            overflow: hidden;
            border: 2px solid #2c5282;
            border-radius: 0.15in;
        }
        
        .id-header {
            background: white;
            padding: 6px 8px;
            text-align: center;
            border-bottom: 2px solid #2c5282;
            position: relative;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .barangay-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .header-text {
            flex: 1;
            text-align: center;
        }
        
        .id-header h3 {
            margin: 0;
            font-size: 12px;
            color: #2c5282;
            font-weight: 700;
            text-transform: uppercase;
            line-height: 1.1;
        }
        
        .id-header .id-type {
            font-size: 6.5px;
            font-weight: 600;
            color: #4a5568;
            margin-top: 1px;
            line-height: 1;
        }
        
        .id-body {
            display: flex;
            padding: 12px 10px;
            align-items: flex-start;
            gap: 10px;
        }
        
        .id-photo {
            width: 0.9in;
            height: 1.1in;
            background: white;
            border: 2px solid #2c5282;
            border-radius: 4px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .id-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .id-photo-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #718096;
            font-size: 40px;
            font-weight: 700;
        }
        
        .id-details {
            flex: 1;
            color: #2d3748;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .id-number {
            background: #2c5282;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 6.5px;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 6px;
            align-self: flex-start;
        }
        
        .id-name {
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 8px 0;
            text-transform: uppercase;
            color: #1a202c;
            line-height: 1.2;
        }
        
        .id-info-group {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 3px 6px;
            font-size: 7px;
            line-height: 1.3;
        }
        
        .id-info-label {
            color: #4a5568;
            font-weight: 600;
        }
        
        .id-info-value {
            color: #1a202c;
            font-weight: 600;
        }
        
        .id-footer-front {
            position: absolute;
            bottom: 5px;
            left: 10px;
            right: 10px;
            text-align: center;
            font-size: 5.5px;
            color: #718096;
            border-top: 1px solid #e2e8f0;
            padding-top: 3px;
        }
        
        /* ===== BACK OF ID ===== */
        .id-back {
            width: 100%;
            height: 100%;
            background: white;
            position: relative;
            padding: 8px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            border: 2px solid #2c5282;
            border-radius: 0.15in;
        }
        
        .id-back-title {
            background: #2c5282;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 6px;
            flex-shrink: 0;
        }
        
        .back-content {
            flex: 1;
            display: flex;
            gap: 8px;
            align-items: stretch;
            min-height: 0;
        }
        
        /* QR Code Section */
        .qr-section {
            width: 1.1in;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            background: white;
            padding: 6px;
            border: 1.5px solid #2c5282;
            border-radius: 4px;
            flex-shrink: 0;
        }
        
        .qr-code {
            width: 0.9in;
            height: 0.9in;
            background: white;
            display: block;
            margin: 0 auto 4px;
            border: 1px solid #cbd5e0;
            border-radius: 2px;
        }
        
        .qr-code img {
            width: 100%;
            height: 100%;
            display: block;
        }
        
        .qr-label {
            font-size: 5px;
            color: #2c5282;
            font-weight: 700;
            text-align: center;
            line-height: 1.1;
            text-transform: uppercase;
        }
        
        /* Information Section */
        .info-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 0;
        }
        
        .info-content {
            flex: 0 0 auto;
        }
        
        .info-row {
            margin-bottom: 3px;
        }
        
        .info-row-label {
            font-size: 5px;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 1px;
            font-weight: 600;
            line-height: 1;
        }
        
        .info-row-value {
            font-size: 7px;
            color: #1a202c;
            font-weight: 700;
            line-height: 1.2;
        }
        
        /* Signature Box */
        .signature-box {
            margin-top: auto;
            padding-top: 4px;
            border-top: 1.5px solid #2c5282;
            flex-shrink: 0;
        }
        
        .signature-image-container {
            height: 40px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            margin-bottom: 0px;
            padding-bottom: 2px;
        }
        
        .signature-image-container img {
            max-height: 38px;
            max-width: 100%;
            object-fit: contain;
        }
        
        .signature-line {
            border-bottom: 1.5px solid #2d3748;
            margin-bottom: 2px;
        }
        
        .signature-label {
            font-size: 5px;
            color: #2c5282;
            text-transform: uppercase;
            font-weight: 700;
            text-align: center;
            line-height: 1;
        }
        
        /* Footer */
        .id-footer-back {
            margin-top: 5px;
            padding-top: 4px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        
        .emergency-contact {
            font-size: 5px;
            color: #4a5568;
            line-height: 1.2;
            flex: 1;
        }
        
        .emergency-contact strong {
            display: block;
            color: #2c5282;
            font-weight: 700;
            font-size: 5.5px;
            margin-bottom: 1px;
        }
        
        .id-validity {
            background: #edf2f7;
            color: #2c5282;
            padding: 3px 5px;
            border-radius: 2px;
            font-size: 5px;
            font-weight: 700;
            border: 1px solid #2c5282;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        /* ===== INSTRUCTIONS ===== */
        .instructions {
            background: #fff5f5;
            border: 2px solid #fc8181;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .instructions h3 {
            color: #c53030;
            margin: 0 0 15px 0;
            font-size: 18px;
        }
        
        .instructions ol {
            margin: 10px 0 10px 20px;
            padding: 0;
            color: #2d3748;
        }
        
        .instructions li {
            margin: 8px 0;
            font-size: 14px;
        }
        
        .instructions strong {
            color: #c53030;
        }
        
        .instructions .note {
            background: #fed7d7;
            padding: 10px;
            border-radius: 4px;
            margin-top: 15px;
            font-weight: 600;
        }
        
        /* ===== PRINT CONTROLS ===== */
        .no-print {
            padding: 20px;
            text-align: center;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .no-print h2 {
            color: #2c5282;
            margin-bottom: 15px;
            font-size: 24px;
        }
        
        .no-print button {
            padding: 12px 24px;
            font-size: 16px;
            cursor: pointer;
            background: #2c5282;
            color: white;
            border: none;
            border-radius: 6px;
            margin: 0 5px;
            transition: all 0.2s;
            font-weight: 600;
        }
        
        .no-print button:hover {
            background: #2d3748;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .no-print button.secondary {
            background: #718096;
        }
        
        .no-print button.secondary:hover {
            background: #4a5568;
        }
        
        /* ===== PRINT MEDIA QUERIES ===== */
        @media print {
            @page {
                size: letter portrait;
                margin: 0.5in;
            }
            
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            
            .no-print,
            .instructions {
                display: none !important;
            }
            
            .print-container {
                max-width: none;
                padding: 0;
            }
            
            .print-page {
                width: 7.5in;
            }
            
            .card-label {
                display: none;
            }
            
            .id-card-container {
                border: none;
                padding: 0;
            }
            
            .cut-guide {
                display: block !important;
            }
            
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        
        /* ===== RESPONSIVE ===== */
        @media screen and (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .print-container {
                padding: 10px;
            }
            
            .print-page {
                transform: scale(0.9);
                transform-origin: top center;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Instructions -->
        <div class="instructions no-print">
            <h3><i class="fas fa-info-circle"></i> Printing Instructions</h3>
            <ol>
                <li>Click the <strong>"Print ID Cards"</strong> button below</li>
                <li>In the print dialog:
                    <ul style="margin-top: 5px;">
                        <li>Select <strong>Paper size: Letter</strong></li>
                        <li>Enable <strong>"Background graphics"</strong></li>
                        <li>Set <strong>Margins: Default</strong></li>
                        <li>Set <strong>Scale: 100%</strong></li>
                    </ul>
                </li>
                <li>Print on <strong>cardstock paper</strong> for best results</li>
                <li>Cut along the dashed lines using the corner guides</li>
                <li>Laminate each card for durability</li>
            </ol>
            <div class="note">
                ⚠️ Make sure "Background graphics" is enabled or colors won't print!
            </div>
        </div>
        
        <!-- Print Controls -->
        <div class="no-print">
            <h2><i class="fas fa-id-card"></i> Barangay ID Card Print Sheet</h2>
            <p style="margin-bottom: 15px;">
                Resident: <strong><?php echo htmlspecialchars($resident['full_name']); ?></strong><br>
                <small style="color: #718096;">This will print both front and back on one Letter-size page</small>
            </p>
            <div>
                <button onclick="window.print()">
                    <i class="fas fa-print"></i> Print ID Cards
                </button>
                <button class="secondary" onclick="window.close()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button class="secondary" onclick="location.href='index.php'">
                    <i class="fas fa-arrow-left"></i> Back to List
                </button>
            </div>
        </div>
        
        <!-- Print Page -->
        <div class="print-page">
            <div class="cards-layout">
                <!-- Front Card -->
                <div class="card-section">
                    <div class="card-label no-print">
                        <i class="fas fa-arrow-right"></i> FRONT SIDE
                    </div>
                    <div class="id-card-container">
                        <!-- Cut Guides -->
                        <div class="cut-guide horizontal top-left"></div>
                        <div class="cut-guide vertical top-left"></div>
                        <div class="cut-guide horizontal top-right"></div>
                        <div class="cut-guide vertical top-right"></div>
                        <div class="cut-guide horizontal bottom-left"></div>
                        <div class="cut-guide vertical bottom-left"></div>
                        <div class="cut-guide horizontal bottom-right"></div>
                        <div class="cut-guide vertical bottom-right"></div>
                        
                        <div class="id-front">
                            <div class="id-header">
                                <div class="header-content">
                                    <?php if ($logo_exists): ?>
                                        <img src="<?php echo htmlspecialchars($logo_display_path); ?>" alt="Barangay Logo" class="barangay-logo">
                                    <?php else: ?>
                                        <svg class="barangay-logo" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M50 5 L90 20 L90 50 Q90 80 50 95 Q10 80 10 50 L10 20 Z" fill="#2c5282" stroke="#1a365d" stroke-width="2"/>
                                            <rect x="35" y="40" width="30" height="35" fill="white" rx="2"/>
                                            <path d="M30 40 L50 25 L70 40 Z" fill="white"/>
                                            <rect x="43" y="55" width="14" height="20" fill="#2c5282" rx="1"/>
                                            <rect x="38" y="45" width="8" height="7" fill="#2c5282" rx="0.5"/>
                                            <rect x="54" y="45" width="8" height="7" fill="#2c5282" rx="0.5"/>
                                            <line x1="50" y1="20" x2="50" y2="30" stroke="white" stroke-width="1"/>
                                            <polygon points="50,20 58,23 50,26" fill="#dc143c"/>
                                        </svg>
                                    <?php endif; ?>
                                    
                                    <div class="header-text">
                                        <h3><?php echo htmlspecialchars($barangay_name); ?></h3>
                                        <p class="id-type">RESIDENT IDENTIFICATION CARD</p>
                                    </div>
                                    
                                    <div style="width: 40px;"></div>
                                </div>
                            </div>
                            
                            <div class="id-body">
                                <div class="id-photo">
                                    <?php if (!empty($resident['profile_photo']) && file_exists(UPLOAD_DIR . 'profiles/' . $resident['profile_photo'])): ?>
                                        <img src="<?php echo UPLOAD_URL; ?>profiles/<?php echo htmlspecialchars($resident['profile_photo']); ?>" 
                                             alt="Photo">
                                    <?php else: ?>
                                        <div class="id-photo-placeholder">
                                            <?php echo strtoupper(substr($resident['first_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="id-details">
                                    <div class="id-number">
                                        ID NO: <?php echo str_pad($resident['resident_id'], 6, '0', STR_PAD_LEFT); ?>
                                    </div>
                                    <h2 class="id-name"><?php echo htmlspecialchars(strtoupper($resident['full_name'])); ?></h2>
                                    
                                    <div class="id-info-group">
                                        <div class="id-info-label">Birthday:</div>
                                        <div class="id-info-value"><?php echo htmlspecialchars($resident['formatted_birthdate']); ?></div>
                                        
                                        <div class="id-info-label">Age/Sex:</div>
                                        <div class="id-info-value"><?php echo htmlspecialchars($resident['age']); ?> / <?php echo htmlspecialchars(strtoupper(substr($resident['gender'], 0, 1))); ?></div>
                                        
                                        <?php if (!empty($resident['contact_number'])): ?>
                                        <div class="id-info-label">Contact:</div>
                                        <div class="id-info-value"><?php echo htmlspecialchars($resident['contact_number']); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($resident['blood_type'])): ?>
                                        <div class="id-info-label">Blood Type:</div>
                                        <div class="id-info-value"><?php echo htmlspecialchars($resident['blood_type']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="id-footer-front">
                                Property of <?php echo htmlspecialchars($barangay_name); ?> • If found, please return to Barangay Hall
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Back Card -->
                <div class="card-section">
                    <div class="card-label no-print">
                        <i class="fas fa-arrow-left"></i> BACK SIDE
                    </div>
                    <div class="id-card-container">
                        <!-- Cut Guides -->
                        <div class="cut-guide horizontal top-left"></div>
                        <div class="cut-guide vertical top-left"></div>
                        <div class="cut-guide horizontal top-right"></div>
                        <div class="cut-guide vertical top-right"></div>
                        <div class="cut-guide horizontal bottom-left"></div>
                        <div class="cut-guide vertical bottom-left"></div>
                        <div class="cut-guide horizontal bottom-right"></div>
                        <div class="cut-guide vertical bottom-right"></div>
                        
                        <div class="id-back">
                            <div class="id-back-title">
                                <i class="fas fa-shield-check"></i> VERIFICATION & IDENTIFICATION
                            </div>
                            
                            <div class="back-content">
                                <!-- QR Code Section -->
                                <div class="qr-section">
                                    <?php if (!empty($resident['qr_code'])): ?>
                                        <div class="qr-code">
                                            <img src="<?php echo UPLOAD_URL; ?>qrcodes/<?php echo htmlspecialchars($resident['qr_code']); ?>" 
                                                 alt="QR Code">
                                        </div>
                                        <p class="qr-label">
                                            <i class="fas fa-qrcode"></i> Scan to Verify
                                        </p>
                                    <?php else: ?>
                                        <div class="qr-code" style="display: flex; align-items: center; justify-content: center;">
                                            <p style="font-size: 6px; color: #718096; margin: 0; text-align: center;">QR Code<br>Not Available</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Information Section -->
                                <div class="info-section">
                                    <div class="info-content">
                                        <div class="info-row">
                                            <div class="info-row-label">Name</div>
                                            <div class="info-row-value"><?php echo htmlspecialchars(strtoupper($resident['full_name'])); ?></div>
                                        </div>
                                        
                                        <div class="info-row">
                                            <div class="info-row-label">Date of Birth</div>
                                            <div class="info-row-value"><?php echo htmlspecialchars($resident['formatted_birthdate']); ?></div>
                                        </div>
                                        
                                        <div class="info-row">
                                            <div class="info-row-label">Sex</div>
                                            <div class="info-row-value"><?php echo htmlspecialchars(strtoupper($resident['gender'])); ?></div>
                                        </div>
                                        
                                        <div class="info-row">
                                            <div class="info-row-label">Civil Status</div>
                                            <div class="info-row-value"><?php echo htmlspecialchars(strtoupper($resident['civil_status'])); ?></div>
                                        </div>
                                        
                                        <?php if (!empty($resident['blood_type'])): ?>
                                        <div class="info-row">
                                            <div class="info-row-label">Blood Type</div>
                                            <div class="info-row-value"><?php echo htmlspecialchars($resident['blood_type']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="info-row">
                                            <div class="info-row-label">Address</div>
                                            <div class="info-row-value" style="font-size: 6px;"><?php echo htmlspecialchars($resident['address']); ?></div>
                                        </div>
                                        
                                        <?php if (!empty($resident['contact_number'])): ?>
                                        <div class="info-row">
                                            <div class="info-row-label">Contact Number</div>
                                            <div class="info-row-value"><?php echo htmlspecialchars($resident['contact_number']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Signature Box -->
                                    <div class="signature-box">
                                        <?php if ($signature_exists || $admin_signature_exists): ?>
                                            <div class="signature-image-container">
                                                <?php if ($signature_exists): ?>
                                                    <img src="<?php echo htmlspecialchars($signature_url); ?>" alt="Signature">
                                                <?php elseif ($admin_signature_exists): ?>
                                                    <img src="<?php echo htmlspecialchars($admin_signature_url); ?>" alt="Authorized Signature">
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="signature-image-container"></div>
                                        <?php endif; ?>
                                        <div class="signature-line"></div>
                                        <div class="signature-label">Signature of Holder</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Footer -->
                            <div class="id-footer-back">
                                <div class="emergency-contact">
                                    <strong><i class="fas fa-phone-alt"></i> EMERGENCY CONTACT</strong>
                                    <?php echo htmlspecialchars($contact); ?>
                                </div>
                                
                                <div class="id-validity">
                                    <i class="fas fa-calendar-check"></i> VALID UNTIL: <?php echo strtoupper(date('M Y', strtotime('+1 year'))); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="no-print" style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px dashed #cbd5e0;">
                <p style="color: #718096; font-size: 13px;">
                    <i class="fas fa-scissors"></i> Cut along the dashed lines using the corner guides as reference
                </p>
                <p style="color: #718096; font-size: 13px; margin-top: 10px;">
                    <i class="fas fa-image"></i> 
                    <?php if ($logo_exists): ?>
                        <span style="color: #48bb78;">✓ Logo Active</span>
                    <?php else: ?>
                        <span style="color: #f6ad55;">⚠ Default Logo</span>
                    <?php endif; ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <i class="fas fa-signature"></i>
                    <?php if ($signature_exists): ?>
                        <span style="color: #48bb78;">✓ Signature Active</span>
                    <?php elseif ($admin_signature_exists): ?>
                        <span style="color: #4299e1;">✓ Admin Signature</span>
                    <?php else: ?>
                        <span style="color: #f6ad55;">⚠ No Signature</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>