<?php
/**
 * QR Code Verification Page - Mobile-Optimized ID Card Display
 * Location: /modules/qrcodes/verify.php
 * 
 * This page is accessed when someone scans the QR code on an ID card
 * It displays the complete ID card (front and back) optimized for mobile viewing
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Get resident ID from URL
$resident_id = intval($_GET['id'] ?? 0);

if ($resident_id <= 0) {
    die('<div style="text-align: center; padding: 40px; font-family: Arial, sans-serif;">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f59e0b;"></i>
            <h2>Invalid QR Code</h2>
            <p>The scanned QR code is invalid or corrupted.</p>
        </div>');
}

// Get resident data
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

if (!$resident) {
    die('<div style="text-align: center; padding: 40px; font-family: Arial, sans-serif;">
            <i class="fas fa-user-times" style="font-size: 48px; color: #ef4444;"></i>
            <h2>Resident Not Found</h2>
            <p>The resident record could not be found in the database.</p>
        </div>');
}

// Format birthday
$resident['formatted_birthdate'] = '';

if (!empty($resident['date_of_birth']) && $resident['date_of_birth'] != '0000-00-00') {
    try {
        $date = new DateTime($resident['date_of_birth']);
        $resident['formatted_birthdate'] = $date->format('m/d/Y');
    } catch (Exception $e) {}
}

if (empty($resident['formatted_birthdate']) && !empty($resident['birth_date']) && $resident['birth_date'] != '0000-00-00') {
    try {
        $date = new DateTime($resident['birth_date']);
        $resident['formatted_birthdate'] = $date->format('m/d/Y');
    } catch (Exception $e) {}
}

if (empty($resident['formatted_birthdate'])) {
    $resident['formatted_birthdate'] = 'Not provided';
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

// Check for signature
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2c5282">
    <title>ID Verification - <?php echo htmlspecialchars($resident['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
        }
        
        .verification-header {
            background: white;
            padding: 20px 15px;
            border-radius: 16px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
            margin-bottom: 15px;
            text-align: center;
            width: 100%;
            max-width: 500px;
            animation: slideDown 0.4s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .verification-header i {
            font-size: 56px;
            color: #10b981;
            margin-bottom: 12px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .verification-header h1 {
            color: #1f2937;
            font-size: 22px;
            margin-bottom: 8px;
            font-weight: 700;
        }
        
        .verification-header p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 24px;
            font-weight: 700;
            font-size: 14px;
            margin-top: 15px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .id-card-container {
            width: 100%;
            max-width: 500px;
            background: white;
            padding: 15px;
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.25);
            margin-bottom: 15px;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card-label {
            text-align: center;
            color: #2c5282;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .id-card {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
            border: 3px solid #2c5282;
        }
        
        /* Front of ID */
        .id-front {
            width: 100%;
            position: relative;
        }
        
        .id-header {
            background: white;
            padding: 12px;
            text-align: center;
            border-bottom: 4px solid #2c5282;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        
        .barangay-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .header-text {
            flex: 1;
            text-align: center;
        }
        
        .id-header h3 {
            margin: 0;
            font-size: 16px;
            color: #2c5282;
            font-weight: 700;
            text-transform: uppercase;
            line-height: 1.3;
        }
        
        .id-header p {
            margin: 3px 0 0 0;
            font-size: 10px;
            color: #4a5568;
            line-height: 1.3;
        }
        
        .id-header .id-type {
            font-size: 11px;
            font-weight: 600;
            color: #2c5282;
            margin-top: 3px;
        }
        
        .id-body {
            display: flex;
            padding: 15px;
            align-items: flex-start;
            gap: 12px;
        }
        
        .id-photo {
            width: 100px;
            height: 120px;
            background: #f7fafc;
            border: 3px solid #e2e8f0;
            border-radius: 8px;
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
            font-size: 48px;
            font-weight: 700;
        }
        
        .id-details {
            flex: 1;
            color: #2d3748;
        }
        
        .id-number {
            background: linear-gradient(135deg, #2c5282 0%, #1e3a5f 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 8px;
            box-shadow: 0 2px 6px rgba(44, 82, 130, 0.3);
        }
        
        .id-name {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 8px 0;
            text-transform: uppercase;
            color: #1a202c;
            line-height: 1.3;
        }
        
        .id-info {
            font-size: 11px;
            margin: 4px 0;
            line-height: 1.4;
            color: #4a5568;
        }
        
        .id-info strong {
            display: inline-block;
            min-width: 65px;
            color: #2d3748;
            font-weight: 700;
        }
        
        .id-footer-front {
            padding: 10px 15px;
            text-align: center;
            font-size: 9px;
            color: #718096;
            border-top: 2px solid #e2e8f0;
            background: #f7fafc;
        }
        
        /* Back of ID */
        .id-back {
            width: 100%;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .id-back-title {
            background: linear-gradient(135deg, #2c5282 0%, #1e3a5f 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            box-shadow: 0 2px 6px rgba(44, 82, 130, 0.3);
        }
        
        .back-content {
            display: flex;
            gap: 12px;
            flex-direction: column;
        }
        
        .qr-section {
            background: #f7fafc;
            padding: 15px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            text-align: center;
        }
        
        .qr-code {
            width: 100%;
            max-width: 200px;
            aspect-ratio: 1;
            background: white;
            display: block;
            margin: 0 auto 8px;
            border: 2px solid #cbd5e0;
            border-radius: 8px;
            padding: 10px;
        }
        
        .qr-code img {
            width: 100%;
            height: 100%;
            display: block;
        }
        
        .qr-label {
            font-size: 11px;
            color: #2c5282;
            font-weight: 700;
            text-align: center;
        }
        
        .info-section {
            background: #f7fafc;
            padding: 15px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }
        
        .info-row {
            margin-bottom: 10px;
        }
        
        .info-row:last-child {
            margin-bottom: 0;
        }
        
        .info-row-label {
            font-size: 10px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            font-weight: 700;
        }
        
        .info-row-value {
            font-size: 13px;
            color: #2d3748;
            font-weight: 600;
            line-height: 1.4;
        }
        
        .signature-box {
            margin-top: 15px;
            padding-top: 12px;
            border-top: 2px solid #2c5282;
        }
        
        .signature-image-container {
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
        }
        
        .signature-image-container img {
            max-height: 55px;
            max-width: 100%;
            object-fit: contain;
        }
        
        .signature-line {
            border-bottom: 2px solid #2d3748;
            margin-bottom: 5px;
        }
        
        .signature-label {
            font-size: 10px;
            color: #2c5282;
            text-transform: uppercase;
            font-weight: 700;
            text-align: center;
        }
        
        .id-footer-back {
            margin-top: 12px;
            padding: 12px;
            background: #f7fafc;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
        }
        
        .emergency-contact {
            font-size: 11px;
            color: #4a5568;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        
        .emergency-contact strong {
            display: block;
            color: #2c5282;
            font-weight: 700;
            font-size: 12px;
            margin-bottom: 3px;
        }
        
        .id-validity {
            background: linear-gradient(135deg, #edf2f7 0%, #e2e8f0 100%);
            color: #2c5282;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            border: 2px solid #cbd5e0;
            text-align: center;
        }
        
        .footer-info {
            background: white;
            padding: 15px;
            border-radius: 16px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
            max-width: 500px;
            width: 100%;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .footer-info i {
            color: #2c5282;
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .footer-info p {
            margin: 8px 0;
            line-height: 1.5;
        }
        
        /* Tablet screens */
        @media (min-width: 768px) {
            body {
                padding: 20px;
            }
            
            .verification-header {
                padding: 25px 20px;
            }
            
            .verification-header h1 {
                font-size: 26px;
            }
            
            .id-card-container {
                padding: 20px;
            }
            
            .back-content {
                flex-direction: row;
            }
            
            .qr-section {
                flex: 0 0 45%;
            }
            
            .info-section {
                flex: 1;
            }
        }
        
        /* Landscape orientation on phones */
        @media (max-width: 767px) and (orientation: landscape) {
            body {
                padding: 5px;
            }
            
            .verification-header {
                padding: 10px;
                margin-bottom: 10px;
            }
            
            .verification-header i {
                font-size: 36px;
            }
            
            .verification-header h1 {
                font-size: 18px;
            }
            
            .id-card-container {
                padding: 10px;
            }
        }
        
        /* Very small screens */
        @media (max-width: 360px) {
            .id-photo {
                width: 80px;
                height: 100px;
            }
            
            .id-name {
                font-size: 14px;
            }
            
            .id-info {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Verification Header -->
    <div class="verification-header">
        <i class="fas fa-shield-check"></i>
        <h1><?php echo htmlspecialchars($barangay_name); ?></h1>
        <p>Barangay ID Card Verification System</p>
        <div class="verification-badge">
            <i class="fas fa-check-circle"></i>
            <span>Verified Resident</span>
        </div>
    </div>
    
    <!-- Front of ID Card -->
    <div class="id-card-container">
        <div class="card-label">
            <i class="fas fa-id-card"></i>
            <span>Front Side</span>
        </div>
        <div class="id-card">
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
                            <p><?php echo htmlspecialchars($municipality . ', ' . $province); ?></p>
                            <p class="id-type">RESIDENT IDENTIFICATION CARD</p>
                        </div>
                        
                        <div style="width: 50px;"></div>
                    </div>
                </div>
                
                <div class="id-body">
                    <div class="id-photo">
                        <?php if (!empty($resident['profile_photo']) && file_exists(UPLOAD_DIR . 'profiles/' . $resident['profile_photo'])): ?>
                            <img src="<?php echo UPLOAD_URL; ?>profiles/<?php echo htmlspecialchars($resident['profile_photo']); ?>" alt="Photo">
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
                        <h2 class="id-name"><?php echo htmlspecialchars($resident['display_name']); ?></h2>
                        <p class="id-info">
                            <strong>Birthday:</strong> <?php echo htmlspecialchars($resident['formatted_birthdate']); ?>
                        </p>
                        <p class="id-info"><strong>Age/Sex:</strong> <?php echo htmlspecialchars($resident['age']); ?> / <?php echo htmlspecialchars(substr($resident['gender'], 0, 1)); ?></p>
                        <?php if (!empty($resident['contact_number'])): ?>
                        <p class="id-info"><strong>Contact:</strong> <?php echo htmlspecialchars($resident['contact_number']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($resident['blood_type'])): ?>
                        <p class="id-info"><strong>Blood Type:</strong> <?php echo htmlspecialchars($resident['blood_type']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="id-footer-front">
                    Property of <?php echo htmlspecialchars($barangay_name); ?> • If found, please return to Barangay Hall
                </div>
            </div>
        </div>
    </div>
    
    <!-- Back of ID Card -->
    <div class="id-card-container">
        <div class="card-label">
            <i class="fas fa-qrcode"></i>
            <span>Back Side</span>
        </div>
        <div class="id-card">
            <div class="id-back">
                <div class="id-back-title">
                    <i class="fas fa-shield-check"></i> VERIFICATION & IDENTIFICATION
                </div>
                
                <div class="back-content">
                    <div class="qr-section">
                        <?php if (!empty($resident['qr_code'])): ?>
                            <div class="qr-code">
                                <img src="<?php echo UPLOAD_URL; ?>qrcodes/<?php echo htmlspecialchars($resident['qr_code']); ?>" alt="QR Code">
                            </div>
                            <p class="qr-label">
                                <i class="fas fa-qrcode"></i> SCAN TO VERIFY
                            </p>
                        <?php else: ?>
                            <div class="qr-code" style="display: flex; align-items: center; justify-content: center; background: #f7fafc;">
                                <p style="font-size: 11px; color: #718096; margin: 0; text-align: center;">QR Code<br>Not Available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-row">
                            <div class="info-row-label">Full Name</div>
                            <div class="info-row-value"><?php echo htmlspecialchars(strtoupper($resident['full_name'])); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-row-label">Date of Birth</div>
                            <div class="info-row-value"><?php echo htmlspecialchars($resident['formatted_birthdate']); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-row-label">Sex / Civil Status</div>
                            <div class="info-row-value"><?php echo htmlspecialchars(strtoupper(substr($resident['gender'], 0, 1)) . ' / ' . strtoupper($resident['civil_status'])); ?></div>
                        </div>
                        
                        <?php if (!empty($resident['blood_type'])): ?>
                        <div class="info-row">
                            <div class="info-row-label">Blood Type</div>
                            <div class="info-row-value"><?php echo htmlspecialchars($resident['blood_type']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-row">
                            <div class="info-row-label">Address</div>
                            <div class="info-row-value"><?php echo htmlspecialchars($resident['address']); ?></div>
                        </div>
                        
                        <?php if (!empty($resident['contact_number'])): ?>
                        <div class="info-row">
                            <div class="info-row-label">Contact Number</div>
                            <div class="info-row-value"><?php echo htmlspecialchars($resident['contact_number']); ?></div>
                        </div>
                        <?php endif; ?>
                        
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
                            <div class="signature-label">SIGNATURE OF HOLDER</div>
                        </div>
                    </div>
                </div>
                
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
    
    <!-- Footer Info -->
    <div class="footer-info">
        <i class="fas fa-check-circle"></i>
        <p><strong>This ID card has been verified as authentic.</strong></p>
        <p style="margin-top: 8px; font-size: 12px;">
            <i class="fas fa-calendar"></i> Verified on: <?php echo date('F d, Y \a\t g:i A'); ?>
        </p>
        <p style="margin-top: 12px; font-size: 11px; color: #9ca3af;">
            <i class="fas fa-shield-alt"></i> Protected by <?php echo htmlspecialchars($barangay_name); ?> Verification System
        </p>
    </div>
</body>
</html>