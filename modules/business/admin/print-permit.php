<?php
require_once '../../../config/config.php';

$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$permit_id) {
    die("Invalid permit ID");
}

// Get permit details
$stmt = $conn->prepare("
    SELECT bp.*, r.first_name, r.last_name, bt.type_name
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    WHERE bp.permit_id = ? AND bp.status = 'approved'
");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();

if (!$permit) {
    die("Permit not found or not yet approved");
}

// Show warning if not paid yet
$show_unpaid_warning = ($permit['payment_status'] ?? 'unpaid') !== 'paid';

// Try to get officials, but don't fail if table doesn't exist
$captain_name = '___________________';
$secretary_name = '___________________';

try {
    $captain_query = $conn->query("
        SELECT CONCAT(first_name, ' ', last_name) as full_name
        FROM tbl_officials 
        WHERE position = 'Barangay Captain' 
        AND status = 'Active'
        LIMIT 1
    ");
    if ($captain_query && $captain = $captain_query->fetch_assoc()) {
        $captain_name = strtoupper($captain['full_name']);
    }
} catch (Exception $e) {
    // Table doesn't exist, use default
}

try {
    $secretary_query = $conn->query("
        SELECT CONCAT(first_name, ' ', last_name) as full_name
        FROM tbl_officials 
        WHERE position = 'Barangay Secretary' 
        AND status = 'Active'
        LIMIT 1
    ");
    if ($secretary_query && $secretary = $secretary_query->fetch_assoc()) {
        $secretary_name = strtoupper($secretary['full_name']);
    }
} catch (Exception $e) {
    // Table doesn't exist, use default
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Permit Certificate - <?php echo htmlspecialchars($permit['permit_number'] ?? 'N/A'); ?></title>
    <style>
        @page {
            size: 8.5in 11in;
            margin: 0.3in;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #000;
        }
        
        .certificate {
            width: 7.9in;
            margin: 0 auto;
            padding: 20px;
            border: 3px double #1a5490;
            position: relative;
            page-break-inside: avoid;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #1a5490;
            padding-bottom: 10px;
        }
        
        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 5px;
        }
        
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .header h1 {
            font-size: 16pt;
            font-weight: bold;
            color: #1a5490;
            margin: 3px 0;
        }
        
        .header h2 {
            font-size: 13pt;
            font-weight: normal;
            margin: 2px 0;
        }
        
        .title {
            text-align: center;
            margin: 15px 0;
        }
        
        .title h2 {
            font-size: 22pt;
            font-weight: bold;
            color: #1a5490;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .permit-number {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            color: #d9534f;
            margin-bottom: 12px;
        }
        
        .content {
            margin: 12px 0;
        }
        
        .intro-text {
            text-align: justify;
            margin-bottom: 12px;
            font-size: 10pt;
            line-height: 1.3;
        }
        
        .info-row {
            margin: 8px 0;
            display: flex;
            align-items: baseline;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 150px;
            display: inline-block;
            font-size: 10pt;
        }
        
        .info-value {
            flex: 1;
            border-bottom: 1px solid #000;
            display: inline-block;
            padding-left: 10px;
            font-size: 10pt;
        }
        
        .validity {
            margin: 15px 0;
            padding: 10px;
            background-color: #f8f9fa;
            border-left: 4px solid #1a5490;
        }
        
        .validity-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 10pt;
        }
        
        .validity-center {
            text-align: center;
            margin-top: 5px;
            font-size: 10pt;
        }
        
        .disclaimer {
            margin: 12px 0;
            font-size: 9pt;
            text-align: justify;
            line-height: 1.3;
        }
        
        .signatures {
            margin-top: 30px;
            display: flex;
            justify-content: space-around;
        }
        
        .signature-block {
            text-align: center;
            min-width: 180px;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin: 40px 0 5px 0;
            padding-top: 5px;
            min-height: 20px;
            font-size: 11pt;
            font-weight: bold;
        }
        
        .signature-title {
            font-weight: bold;
            font-size: 10pt;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #1a5490;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100pt;
            color: rgba(26, 84, 144, 0.05);
            font-weight: bold;
            z-index: -1;
            pointer-events: none;
        }
        
        .unpaid-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80pt;
            color: rgba(217, 83, 79, 0.1);
            font-weight: bold;
            z-index: -1;
            pointer-events: none;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .certificate {
                border-color: #1a5490 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        .print-buttons {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background-color: #f8f9fa;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 0 10px;
            background-color: #1a5490;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }
        
        .btn:hover {
            background-color: #154070;
        }
        
        .btn-secondary {
            background-color: #6c757d;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .alert {
            padding: 15px;
            margin: 20px auto;
            border-radius: 5px;
            max-width: 7.5in;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
    </style>
</head>
<body>
    <?php if ($show_unpaid_warning): ?>
    <div class="alert alert-warning no-print">
        <strong>Warning:</strong> This permit has not been marked as paid. This is a preview only and should not be considered official until payment is confirmed.
    </div>
    <?php endif; ?>

    <div class="print-buttons no-print">
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print"></i> Print Certificate
        </button>
        <a href="view-permit.php?id=<?php echo $permit_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Details
        </a>
    </div>

    <div class="certificate">
        <?php if ($show_unpaid_warning): ?>
        <div class="unpaid-watermark">UNPAID</div>
        <?php else: ?>
        <div class="watermark">OFFICIAL</div>
        <?php endif; ?>
        
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <?php
                // Try multiple possible logo locations
                $base_path = $_SERVER['DOCUMENT_ROOT'];
                $logo_paths = [
                    '/uploads/officials/brgy.png',
                    '/assets/images/logo.png',
                    '/assets/img/logo.png'
                ];
                $logo_found = false;
                foreach ($logo_paths as $logo_path) {
                    if (file_exists($base_path . $logo_path)) {
                        echo '<img src="' . $logo_path . '" alt="Logo">';
                        $logo_found = true;
                        break;
                    }
                }
                if (!$logo_found) {
                    // Try using base_url if available
                    if (isset($base_url)) {
                        echo '<img src="' . $base_url . '/uploads/officials/brgy.png" alt="Logo" onerror="this.style.display=\'none\'">';
                    } elseif (defined('BASE_URL')) {
                        echo '<img src="' . BASE_URL . '/assets/images/logo.png" alt="Logo" onerror="this.style.display=\'none\'">';
                    }
                }
                ?>
            </div>
            <h1>Republic of the Philippines</h1>
            <h2><?php echo defined('PROVINCE') ? PROVINCE : 'Davao del Sur'; ?></h2>
            <h2><?php echo defined('MUNICIPALITY') ? MUNICIPALITY : 'Davao City'; ?></h2>
            <h2><?php echo defined('BARANGAY_NAME') ? BARANGAY_NAME : 'Barangay'; ?></h2>
        </div>
        
        <!-- Title -->
        <div class="title">
            <h2>BUSINESS PERMIT</h2>
        </div>
        
        <div class="permit-number">
            Permit No.: <?php echo htmlspecialchars($permit['permit_number'] ?? 'PENDING'); ?>
        </div>
        
        <!-- Content -->
        <div class="content">
            <p class="intro-text">
                This is to certify that the establishment described below has been issued a Business Permit 
                to operate within the jurisdiction of <?php echo defined('BARANGAY_NAME') ? BARANGAY_NAME : 'this barangay'; ?>, 
                <?php echo defined('MUNICIPALITY') ? MUNICIPALITY : ''; ?>, in accordance with the provisions of the Local Government Code and other applicable laws.
            </p>
            
            <div class="info-row">
                <span class="info-label">Business Name:</span>
                <span class="info-value"><?php echo strtoupper(htmlspecialchars($permit['business_name'] ?? 'N/A')); ?></span>
            </div>
            
            <div class="info-row">
                <span class="info-label">Business Type:</span>
                <span class="info-value"><?php echo htmlspecialchars($permit['business_type'] ?? $permit['type_name'] ?? 'N/A'); ?></span>
            </div>
            
            <div class="info-row">
                <span class="info-label">Business Address:</span>
                <span class="info-value"><?php echo htmlspecialchars($permit['business_address'] ?? 'Not specified'); ?></span>
            </div>
            
            <div class="info-row">
                <span class="info-label">Owner/Proprietor:</span>
                <span class="info-value"><?php echo strtoupper(htmlspecialchars($permit['owner_name'] ?? 'N/A')); ?></span>
            </div>
            
            <?php if (!empty($permit['tin_number'])): ?>
            <div class="info-row">
                <span class="info-label">TIN:</span>
                <span class="info-value"><?php echo htmlspecialchars($permit['tin_number']); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($permit['dti_registration'])): ?>
            <div class="info-row">
                <span class="info-label">DTI Registration:</span>
                <span class="info-value"><?php echo htmlspecialchars($permit['dti_registration']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Validity Period -->
        <div class="validity">
            <div class="validity-row">
                <div>
                    <strong>Date Issued:</strong> 
                    <?php echo !empty($permit['issue_date']) ? date('F d, Y', strtotime($permit['issue_date'])) : 'To be determined'; ?>
                </div>
                <div>
                    <strong>Valid Until:</strong> 
                    <?php echo !empty($permit['expiry_date']) ? date('F d, Y', strtotime($permit['expiry_date'])) : 'To be determined'; ?>
                </div>
            </div>
            <?php if (!empty($permit['permit_fee'])): ?>
            <div class="validity-center">
                <strong>Permit Fee:</strong> ₱<?php echo number_format($permit['permit_fee'], 2); ?>
            </div>
            <?php endif; ?>
        </div>
        
        <p class="disclaimer">
            This permit is not transferable and must be prominently displayed at the business location. 
            The permit holder must comply with all applicable laws, regulations, and ordinances. 
            This permit is subject to renewal annually.
        </p>
        
        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line">
                    <?php echo $captain_name; ?>
                </div>
                <div class="signature-title">Punong Barangay</div>
            </div>
            
            <div class="signature-block">
                <div class="signature-line">
                    <?php echo $secretary_name; ?>
                </div>
                <div class="signature-title">Barangay Secretary</div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>Not valid without official seal and signatures</p>
            <p style="margin-top: 5px;">
                Generated on: <?php echo date('F d, Y h:i A'); ?>
            </p>
        </div>
    </div>
    
    <script>
        // Auto-print option (uncomment to enable)
        // window.onload = function() {
        //     setTimeout(function() { window.print(); }, 500);
        // }
    </script>
</body>
</html>