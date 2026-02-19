<?php
/**
 * Helper Functions for Damage Assessment
 * Add these functions to your config/helpers.php file
 */

if (!function_exists('getSeverityBadge')) {
    /**
     * Get severity badge HTML
     * @param string $severity The severity level
     * @return string HTML badge
     */
    function getSeverityBadge($severity) {
        $severity = strtolower(trim($severity ?? ''));
        
        $badges = [
            'minor' => '<span class="badge bg-info"><i class="fas fa-info-circle me-1"></i>Minor</span>',
            'moderate' => '<span class="badge bg-warning"><i class="fas fa-exclamation-triangle me-1"></i>Moderate</span>',
            'severe' => '<span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i>Severe</span>',
            'critical' => '<span class="badge bg-dark"><i class="fas fa-skull-crossbones me-1"></i>Critical</span>'
        ];
        
        return $badges[$severity] ?? '<span class="badge bg-secondary">Unknown</span>';
    }
}

if (!function_exists('getStatusBadge')) {
    /**
     * Get status badge HTML
     * @param string $status The status
     * @return string HTML badge
     */
    function getStatusBadge($status) {
        $status = strtolower(trim($status ?? ''));
        
        $badges = [
            'pending' => '<span class="badge bg-warning"><i class="fas fa-clock me-1"></i>Pending</span>',
            'in progress' => '<span class="badge bg-info"><i class="fas fa-spinner me-1"></i>In Progress</span>',
            'completed' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Completed</span>',
            'cancelled' => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Cancelled</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }
}

if (!function_exists('formatDate')) {
    /**
     * Format date for display
     * @param string $date The date string
     * @param string $format The output format
     * @return string Formatted date
     */
    function formatDate($date, $format = 'M d, Y') {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        
        try {
            $dateTime = new DateTime($date);
            return $dateTime->format($format);
        } catch (Exception $e) {
            return htmlspecialchars($date);
        }
    }
}

if (!function_exists('validateSeverity')) {
    /**
     * Validate and sanitize severity input
     * @param string $severity Input severity
     * @return string Valid severity value
     */
    function validateSeverity($severity) {
        $valid_severities = ['Minor', 'Moderate', 'Severe', 'Critical'];
        $severity = ucfirst(strtolower(trim($severity ?? '')));
        
        return in_array($severity, $valid_severities) ? $severity : 'Minor';
    }
}

if (!function_exists('getSeverityOptions')) {
    /**
     * Get severity options for select dropdown
     * @param string $selected Currently selected value
     * @return string HTML options
     */
    function getSeverityOptions($selected = '') {
        $severities = [
            'Minor' => 'Minor - Low impact damage',
            'Moderate' => 'Moderate - Significant damage',
            'Severe' => 'Severe - Major damage',
            'Critical' => 'Critical - Catastrophic damage'
        ];
        
        $options = '';
        
        foreach ($severities as $value => $label) {
            $is_selected = (strtolower($selected) === strtolower($value)) ? 'selected' : '';
            $options .= sprintf(
                '<option value="%s" %s>%s</option>',
                htmlspecialchars($value),
                $is_selected,
                htmlspecialchars($label)
            );
        }
        
        return $options;
    }
}

function isResidentVerified() {
    // Non-residents are considered verified
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Resident') {
        return true;
    }
    
    // Check verification status from session
    if (isset($_SESSION['is_verified'])) {
        return (int)$_SESSION['is_verified'] === 1;
    }
    
    // If not in session, check database
    if (isset($_SESSION['resident_id']) && isset($GLOBALS['conn'])) {
        $stmt = $GLOBALS['conn']->prepare("SELECT is_verified FROM tbl_residents WHERE resident_id = ?");
        $stmt->bind_param("i", $_SESSION['resident_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $_SESSION['is_verified'] = $row['is_verified']; // Cache in session
            $stmt->close();
            return (int)$row['is_verified'] === 1;
        }
        $stmt->close();
    }
    
    // Default to not verified if we can't determine
    return false;
}

/**
 * Require resident verification for the current page
 * Redirects to verification notice page if not verified
 * @param string $redirect_url Optional URL to redirect after showing message
 */
function requireResidentVerification($redirect_url = null) {
    if ($_SESSION['role'] === 'Resident' && !isResidentVerified()) {
        // Store the intended destination
        if ($redirect_url) {
            $_SESSION['verification_redirect'] = $redirect_url;
        }
        
        // Show verification notice
        showVerificationNotice();
        exit();
    }
}

/**
 * Display verification notice page
 */
function showVerificationNotice() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Account Verification Required</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
            }

            .notice-container {
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 600px;
                width: 100%;
                padding: 3rem;
                text-align: center;
                animation: slideIn 0.5s ease-out;
            }

            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(-30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .icon-wrapper {
                width: 100px;
                height: 100px;
                background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 2rem;
                box-shadow: 0 10px 30px rgba(251, 191, 36, 0.3);
            }

            .icon-wrapper i {
                font-size: 3rem;
                color: white;
            }

            h1 {
                color: #1e293b;
                font-size: 2rem;
                margin-bottom: 1rem;
            }

            .message {
                color: #64748b;
                font-size: 1.1rem;
                line-height: 1.8;
                margin-bottom: 2rem;
            }

            .info-box {
                background: #f8fafc;
                border-left: 4px solid #3b82f6;
                padding: 1.5rem;
                border-radius: 8px;
                margin-bottom: 2rem;
                text-align: left;
            }

            .info-box h3 {
                color: #1e3a8a;
                font-size: 1.1rem;
                margin-bottom: 1rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .info-box ul {
                list-style: none;
                padding-left: 0;
            }

            .info-box li {
                color: #475569;
                margin-bottom: 0.75rem;
                display: flex;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .info-box li i {
                color: #10b981;
                margin-top: 0.25rem;
            }

            .btn-group {
                display: flex;
                gap: 1rem;
                justify-content: center;
                flex-wrap: wrap;
            }

            .btn {
                padding: 0.875rem 2rem;
                border: none;
                border-radius: 8px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
            }

            .btn-primary {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
            }

            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(30, 58, 138, 0.3);
            }

            .btn-secondary {
                background: #e2e8f0;
                color: #475569;
            }

            .btn-secondary:hover {
                background: #cbd5e1;
            }

            .contact-info {
                margin-top: 2rem;
                padding-top: 2rem;
                border-top: 1px solid #e2e8f0;
                color: #64748b;
                font-size: 0.9rem;
            }

            .contact-info a {
                color: #3b82f6;
                text-decoration: none;
                font-weight: 600;
            }

            .contact-info a:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="notice-container">
            <div class="icon-wrapper">
                <i class="fas fa-hourglass-half"></i>
            </div>
            
            <h1>Account Verification Pending</h1>
            
            <p class="message">
                Thank you for registering! Your account is currently pending verification by our barangay administrator. 
                You can browse the system, but access to certain features is temporarily restricted.
            </p>

            <div class="info-box">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    What You Can Do While Waiting
                </h3>
                <ul>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>View your dashboard and profile information</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Browse public announcements and updates</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Update your personal information</span>
                    </li>
                </ul>
            </div>

            <div class="info-box">
                <h3>
                    <i class="fas fa-lock"></i>
                    Features Available After Verification
                </h3>
                <ul>
                    <li>
                        <i class="fas fa-file-alt"></i>
                        <span>Request barangay certificates and clearances</span>
                    </li>
                    <li>
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Report incidents and complaints</span>
                    </li>
                    <li>
                        <i class="fas fa-calendar-check"></i>
                        <span>Book appointments and services</span>
                    </li>
                    <li>
                        <i class="fas fa-comments"></i>
                        <span>Full access to community features</span>
                    </li>
                </ul>
            </div>

            <div class="btn-group">
                <a href="/barangaylink1/modules/dashboard/index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i>
                    Go to Dashboard
                </a>
                <a href="/barangaylink1/modules/auth/logout.php" class="btn btn-secondary">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>

            <div class="contact-info">
                <p>
                    <i class="fas fa-clock"></i> 
                    Verification typically takes 1-2 business days.<br>
                    Need immediate assistance? 
                    <a href="mailto:barangay@example.com">Contact the administrator</a>
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

/**
 * Get verification status badge for display
 * @param int $is_verified Verification status (0 or 1)
 * @return string HTML badge
 */
function getVerificationBadge($is_verified) {
    if ($is_verified == 1) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Verified</span>';
    } else {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending Verification</span>';
    }
}

/**
 * Show inline verification notice (for use within pages)
 * @return string HTML notice
 */
function getInlineVerificationNotice() {
    if ($_SESSION['role'] === 'Resident' && !isResidentVerified()) {
        return '
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Account Verification Pending:</strong> 
            Some features are restricted until your account is verified by the administrator. 
            This typically takes 1-2 business days.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
    }
    return '';
}

?>