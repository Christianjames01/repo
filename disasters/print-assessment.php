<?php
/**
 * Print Damage Assessment
 * Path: barangaylink/disasters/print-assessment.php
 */

require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$assessment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$assessment_id) {
    die('Invalid assessment ID');
}

// Fetch assessment details
$sql = "SELECT da.*, 
        CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        r.address as resident_address,
        r.contact_number as resident_contact,
        u.username as assessed_by_name
        FROM tbl_damage_assessments da
        LEFT JOIN tbl_residents r ON da.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON da.assessed_by = u.user_id
        WHERE da.assessment_id = ?";

$assessment = fetchOne($conn, $sql, [$assessment_id], 'i');

if (!$assessment) {
    die('Assessment not found');
}

// Fetch barangay settings
$barangay_name = BARANGAY_NAME ?? 'Barangay';
$municipality = MUNICIPALITY ?? '';
$province = PROVINCE ?? '';
$logo_path = BARANGAY_LOGO ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damage Assessment Report - <?php echo $assessment_id; ?></title>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            @page {
                size: A4;
                margin: 0.5in;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            background: #fff;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }
        
        .logo {
            width: 80px;
            height: 80px;
        }
        
        .header-text h1 {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .header-text p {
            font-size: 12px;
            margin: 2px 0;
        }
        
        .document-title {
            text-align: center;
            margin: 30px 0;
        }
        
        .document-title h2 {
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .document-title p {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .info-section {
            margin: 25px 0;
        }
        
        .info-section h3 {
            font-size: 14px;
            font-weight: bold;
            background: #f0f0f0;
            padding: 8px 12px;
            margin-bottom: 15px;
            border-left: 4px solid #333;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-item.full-width {
            grid-column: 1 / -1;
        }
        
        .info-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 3px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 13px;
            font-weight: normal;
            padding: 8px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            min-height: 35px;
        }
        
        .severity-badge, .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .severity-minor { background: #17a2b8; color: white; }
        .severity-moderate { background: #ffc107; color: #000; }
        .severity-severe { background: #dc3545; color: white; }
        .severity-critical { background: #343a40; color: white; }
        
        .status-pending { background: #ffc107; color: #000; }
        .status-in-progress { background: #17a2b8; color: white; }
        .status-completed { background: #28a745; color: white; }
        .status-cancelled { background: #dc3545; color: white; }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
        }
        
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 60px;
        }
        
        .signature-block {
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin: 50px 20px 5px 20px;
        }
        
        .signature-label {
            font-size: 12px;
            font-weight: bold;
        }
        
        .signature-sublabel {
            font-size: 11px;
            color: #666;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .print-button:hover {
            background: #0056b3;
        }
        
        .assessment-id {
            text-align: right;
            font-size: 11px;
            color: #666;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        🖨️ Print Document
    </button>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <?php if (!empty($logo_path) && file_exists($logo_path)): ?>
                <img src="<?php echo $logo_path; ?>" alt="Logo" class="logo">
                <?php endif; ?>
                <div class="header-text">
                    <p>Republic of the Philippines</p>
                    <p>Province of <?php echo htmlspecialchars($province); ?></p>
                    <p>Municipality of <?php echo htmlspecialchars($municipality); ?></p>
                    <h1><?php echo htmlspecialchars($barangay_name); ?></h1>
                </div>
            </div>
        </div>

        <!-- Assessment ID -->
        <div class="assessment-id">
            Assessment ID: <strong><?php echo str_pad($assessment_id, 6, '0', STR_PAD_LEFT); ?></strong>
        </div>

        <!-- Document Title -->
        <div class="document-title">
            <h2>DAMAGE ASSESSMENT REPORT</h2>
            <p>Property Damage Evaluation and Documentation</p>
        </div>

        <!-- Resident Information -->
        <div class="info-section">
            <h3>Resident Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Full Name</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['resident_name']); ?></div>
                </div>
                <div class="info-item">
                    <span class="info-label">Contact Number</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['resident_contact'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item full-width">
                    <span class="info-label">Address</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['resident_address']); ?></div>
                </div>
            </div>
        </div>

        <!-- Assessment Details -->
        <div class="info-section">
            <h3>Assessment Details</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Assessment Date</span>
                    <div class="info-value"><?php echo date('F d, Y', strtotime($assessment['assessment_date'])); ?></div>
                </div>
                <div class="info-item">
                    <span class="info-label">Assessed By</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['assessed_by_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <span class="info-label">Disaster Type</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['disaster_type']); ?></div>
                </div>
                <div class="info-item">
                    <span class="info-label">Damage Type</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['damage_type']); ?></div>
                </div>
                <div class="info-item full-width">
                    <span class="info-label">Specific Location</span>
                    <div class="info-value"><?php echo htmlspecialchars($assessment['location']); ?></div>
                </div>
            </div>
        </div>

        <!-- Damage Assessment -->
        <div class="info-section">
            <h3>Damage Evaluation</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Severity Level</span>
                    <div class="info-value">
                        <?php 
                        $severity = strtolower($assessment['severity']);
                        $severity_class = 'severity-' . str_replace(' ', '-', $severity);
                        ?>
                        <span class="severity-badge <?php echo $severity_class; ?>">
                            <?php echo strtoupper($assessment['severity']); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <span class="info-label">Estimated Cost</span>
                    <div class="info-value">₱<?php echo number_format($assessment['estimated_cost'], 2); ?></div>
                </div>
                <div class="info-item">
                    <span class="info-label">Status</span>
                    <div class="info-value">
                        <?php 
                        $status = strtolower($assessment['status']);
                        $status_class = 'status-' . str_replace(' ', '-', $status);
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo strtoupper($assessment['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item full-width">
                    <span class="info-label">Detailed Description</span>
                    <div class="info-value" style="min-height: 100px; white-space: pre-wrap;">
                        <?php echo htmlspecialchars($assessment['description'] ?: 'No description provided.'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-label"><?php echo htmlspecialchars($assessment['assessed_by_name'] ?? '_____________________'); ?></div>
                <div class="signature-sublabel">Assessed By</div>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-label">_____________________</div>
                <div class="signature-sublabel">Barangay Captain</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p style="text-align: center; font-size: 11px; color: #666;">
                This is a computer-generated document. No signature is required.<br>
                Generated on: <?php echo date('F d, Y h:i A'); ?>
            </p>
        </div>
    </div>

    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>