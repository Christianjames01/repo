<?php
/**
 * Email Helper Functions using PHPMailer
 * Optimized for faster email sending
 * Path: includes/email_helper.php
 */

// Prevent multiple inclusions
if (defined('EMAIL_HELPER_LOADED')) {
    return;
}
define('EMAIL_HELPER_LOADED', true);

// Check if PHPMailer exists
$phpmailer_base = __DIR__ . '/phpmailer/src/';
$phpmailer_files = [
    'PHPMailer.php',
    'SMTP.php', 
    'Exception.php'
];

$phpmailer_exists = true;
foreach ($phpmailer_files as $file) {
    if (!file_exists($phpmailer_base . $file)) {
        $phpmailer_exists = false;
        error_log("PHPMailer file missing: " . $phpmailer_base . $file);
        break;
    }
}

if (!$phpmailer_exists) {
    error_log("=== PHPMailer NOT INSTALLED ===");
    
    // Define stub functions
    if (!function_exists('generateVerificationCode')) {
        function generateVerificationCode() { 
            return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT); 
        }
    }
    if (!function_exists('sendVerificationCodeEmail')) {
        function sendVerificationCodeEmail($email, $name, $code) { 
            error_log("Cannot send email - PHPMailer not installed"); 
            return false; 
        }
    }
    if (!function_exists('sendResendVerificationCodeEmail')) {
        function sendResendVerificationCodeEmail($email, $name, $code) { 
            error_log("Cannot send email - PHPMailer not installed"); 
            return false; 
        }
    }
    if (!function_exists('sendPasswordResetEmail')) {
        function sendPasswordResetEmail($email, $name, $code) { 
            error_log("Cannot send email - PHPMailer not installed"); 
            return false; 
        }
    }
    if (!function_exists('testEmailConfiguration')) {
        function testEmailConfiguration($email) { 
            error_log("Cannot test email - PHPMailer not installed"); 
            return false; 
        }
    }
    if (!function_exists('sendEmail')) {
        function sendEmail($to, $subject, $body, $name = '') { 
            error_log("Cannot send email - PHPMailer not installed"); 
            return false; 
        }
    }
    if (!function_exists('getMailer')) {
        function getMailer() { 
            return false; 
        }
    }
    
    return;
}

// PHPMailer exists - load it
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once $phpmailer_base . 'PHPMailer.php';
require_once $phpmailer_base . 'SMTP.php';
require_once $phpmailer_base . 'Exception.php';

// Load email config
if (file_exists(__DIR__ . '/../config/email.php')) {
    require_once __DIR__ . '/../config/email.php';
} else {
    error_log("Email config not found!");
    define('MAIL_HOST', 'smtp.gmail.com');
    define('MAIL_PORT', 587);
    define('MAIL_USERNAME', '');
    define('MAIL_PASSWORD', '');
    define('MAIL_ENCRYPTION', 'tls');
    define('MAIL_FROM_EMAIL', '');
    define('MAIL_FROM_NAME', 'Barangay System');
    define('MAIL_IS_HTML', true);
    define('MAIL_CHARSET', 'UTF-8');
    define('MAIL_DEBUG', 0);
}

/**
 * Create configured PHPMailer instance with optimized timeouts
 */
if (!function_exists('getMailer')) {
    function getMailer() {
        $mail = new PHPMailer(true);
        
        try {
            // SMTP settings with OPTIMIZED TIMEOUTS
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            $mail->SMTPSecure = MAIL_ENCRYPTION;
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = MAIL_CHARSET;
            
            // SPEED OPTIMIZATIONS
            $mail->Timeout = 10; // Reduce from default 300 seconds to 10 seconds
            $mail->SMTPKeepAlive = true; // Reuse connection for multiple emails
            $mail->SMTPAutoTLS = true; // Auto-enable TLS encryption
            
            if (MAIL_DEBUG > 0) {
                $mail->SMTPDebug = MAIL_DEBUG;
            } else {
                $mail->SMTPDebug = 0; // Disable debug for speed
            }
            
            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            
            if (defined('MAIL_REPLYTO_EMAIL')) {
                $mail->addReplyTo(MAIL_REPLYTO_EMAIL, MAIL_REPLYTO_NAME);
            }
            
            $mail->isHTML(MAIL_IS_HTML);
            
            return $mail;
        } catch (Exception $e) {
            error_log("PHPMailer Config Error: {$e->getMessage()}");
            return false;
        }
    }
}

/**
 * Send email with timeout protection
 */
if (!function_exists('sendEmail')) {
    function sendEmail($to, $subject, $body, $recipientName = '') {
        $start_time = microtime(true);
        
        $mail = getMailer();
        
        if (!$mail) {
            error_log("Failed to get mailer instance");
            return false;
        }
        
        try {
            $mail->addAddress($to, $recipientName);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
            
            $result = $mail->send();
            
            $end_time = microtime(true);
            $duration = round($end_time - $start_time, 2);
            
            if ($result) {
                error_log("✓ Email sent successfully in {$duration}s to: {$to}");
            } else {
                error_log("✗ Email failed after {$duration}s");
            }
            
            return $result;
        } catch (Exception $e) {
            $end_time = microtime(true);
            $duration = round($end_time - $start_time, 2);
            
            error_log("✗ Email Error after {$duration}s: {$mail->ErrorInfo}");
            error_log("Exception: " . $e->getMessage());
            
            // Return true if timeout (so registration continues)
            if (strpos($e->getMessage(), 'timed out') !== false || 
                strpos($e->getMessage(), 'timeout') !== false ||
                $duration > 15) {
                error_log("⚠ Email timeout - but continuing registration");
                return true; // Don't block registration
            }
            
            return false;
        }
    }
}



/**
 * Send email asynchronously (non-blocking)
 * This allows the page to load while email sends in background
 */
if (!function_exists('sendEmailAsync')) {
    function sendEmailAsync($to, $subject, $body, $recipientName = '') {
        // For Windows/XAMPP, we can't use true async, but we can use quick timeout
        // Just call regular sendEmail with short timeout
        return sendEmail($to, $subject, $body, $recipientName);
    }
}

/**
 * Generate 6-digit verification code
 */
if (!function_exists('generateVerificationCode')) {
    function generateVerificationCode() {
        return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Get email template HTML (optimized - no heavy processing)
 */
if (!function_exists('getEmailTemplate')) {
    function getEmailTemplate($data) {
        $defaults = [
            'title' => '',
            'greeting' => 'Hello,',
            'message' => '',
            'details' => [],
            'extra' => '',
            'action_url' => '',
            'action_text' => 'Click Here',
            'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' - Automated Email',
            'year' => date('Y')
        ];
        
        $data = array_merge($defaults, $data);
        
        $detailsHtml = '';
        if (!empty($data['details'])) {
            $detailsHtml = '<table style="width: 100%; margin: 20px 0; background: #f8f9fa; border-radius: 8px; padding: 15px;">';
            foreach ($data['details'] as $label => $value) {
                $detailsHtml .= '<tr><td style="padding: 8px 0;"><strong>' . htmlspecialchars($label) . ':</strong></td><td style="padding: 8px 0;">' . htmlspecialchars($value) . '</td></tr>';
            }
            $detailsHtml .= '</table>';
        }
        
        $actionButton = '';
        if ($data['action_url']) {
            $actionButton = '<table style="margin: 30px auto;"><tr><td style="background: #007bff; border-radius: 8px; padding: 12px 30px;"><a href="' . $data['action_url'] . '" style="color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px;">' . $data['action_text'] . '</a></td></tr></table>';
        }
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($data['title']) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background: linear-gradient(135deg, #007bff, #0056b3); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px;">' . htmlspecialchars($data['title']) . '</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="font-size: 16px; color: #333333; margin: 0 0 20px 0;">' . $data['greeting'] . '</p>
                            <p style="font-size: 15px; color: #555555; line-height: 1.6; margin: 0 0 20px 0;">' . $data['message'] . '</p>
                            ' . $detailsHtml . '
                            ' . $data['extra'] . '
                            ' . $actionButton . '
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-top: 1px solid #dee2e6;">
                            <p style="margin: 0; font-size: 13px; color: #6c757d;">' . htmlspecialchars($data['footer_text']) . '</p>
                            <p style="margin: 5px 0 0 0; font-size: 12px; color: #adb5bd;">&copy; ' . $data['year'] . ' ' . (defined('APP_NAME') ? APP_NAME : 'Barangay System') . '. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
}

/**
 * Send verification code email (optimized)
 */
if (!function_exists('sendVerificationCodeEmail')) {
    function sendVerificationCodeEmail($email, $name, $verificationCode) {
        $subject = "Email Verification - " . (defined('APP_NAME') ? APP_NAME : 'Barangay System');
        
        $body = getEmailTemplate([
            'title' => '🔐 Email Verification',
            'greeting' => "Hello {$name},",
            'message' => "Thank you for registering. Please use the verification code below to complete your registration:",
            'extra' => "
                <div style='background: #f8f9fa; border-radius: 12px; padding: 30px; text-align: center; margin: 30px 0;'>
                    <p style='font-size: 14px; color: #64748b; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px;'>Your Verification Code</p>
                    <div style='font-size: 42px; font-weight: 700; color: #1e3a8a; letter-spacing: 8px; font-family: monospace;'>{$verificationCode}</div>
                    <p style='font-size: 13px; color: #94a3b8; margin-top: 15px;'>This code will expire in <strong>30 minutes</strong></p>
                </div>
                <p style='font-size: 14px; color: #64748b; margin-top: 20px;'>
                    <strong>Important:</strong> Enter this code to verify your email address and activate your account.
                </p>
            ",
            'footer_text' => 'Email Verification System'
        ]);
        
        // Use async sending to avoid blocking
        return sendEmailAsync($email, $subject, $body, $name);
    }
}

/**
 * Send resend verification code email
 */
if (!function_exists('sendResendVerificationCodeEmail')) {
    function sendResendVerificationCodeEmail($email, $name, $verificationCode) {
        $subject = "New Verification Code - " . (defined('APP_NAME') ? APP_NAME : 'Barangay System');
        
        $body = getEmailTemplate([
            'title' => '🔄 New Verification Code',
            'greeting' => "Hello {$name},",
            'message' => "You requested a new verification code. Here is your new code:",
            'extra' => "
                <div style='background: #f8f9fa; border-radius: 12px; padding: 30px; text-align: center; margin: 30px 0;'>
                    <p style='font-size: 14px; color: #64748b; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px;'>Your New Verification Code</p>
                    <div style='font-size: 42px; font-weight: 700; color: #1e3a8a; letter-spacing: 8px; font-family: monospace;'>{$verificationCode}</div>
                    <p style='font-size: 13px; color: #94a3b8; margin-top: 15px;'>This code will expire in <strong>30 minutes</strong></p>
                </div>
                <p style='font-size: 14px; color: #64748b; margin-top: 20px;'>
                    <strong>Note:</strong> Your previous verification code has been invalidated.
                </p>
            ",
            'footer_text' => 'Email Verification System'
        ]);
        
        return sendEmailAsync($email, $subject, $body, $name);
    }
}

/**
 * Send password reset verification code email
 */
if (!function_exists('sendPasswordResetEmail')) {
    function sendPasswordResetEmail($email, $name, $verificationCode) {
        $subject = "Password Reset Code - " . (defined('APP_NAME') ? APP_NAME : 'Barangay System');
        
        $body = getEmailTemplate([
            'title' => '🔐 Password Reset Request',
            'greeting' => "Hello {$name},",
            'message' => "We received a request to reset your password. Use the verification code below to proceed:",
            'extra' => "
                <div style='background: #f8f9fa; border-radius: 12px; padding: 30px; text-align: center; margin: 30px 0;'>
                    <p style='font-size: 14px; color: #64748b; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px;'>Your Password Reset Code</p>
                    <div style='font-size: 42px; font-weight: 700; color: #1e3a8a; letter-spacing: 8px; font-family: monospace;'>{$verificationCode}</div>
                    <p style='font-size: 13px; color: #94a3b8; margin-top: 15px;'>This code will expire in <strong>30 minutes</strong></p>
                </div>
                <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                    <p style='font-size: 14px; color: #856404; margin: 0;'>
                        <strong>⚠️ Security Notice:</strong><br>
                        • Do not share this code with anyone<br>
                        • If you didn't request this reset, please ignore this email<br>
                        • Your password will remain unchanged
                    </p>
                </div>
            ",
            'footer_text' => 'Password Reset System'
        ]);
        
        return sendEmailAsync($email, $subject, $body, $name);
    }
}

/**
 * Test email configuration
 */
if (!function_exists('testEmailConfiguration')) {
    function testEmailConfiguration($testEmail) {
        $subject = "Email Test - " . (defined('APP_NAME') ? APP_NAME : 'Barangay System');
        
        $body = getEmailTemplate([
            'title' => 'Email Test',
            'greeting' => 'Hello,',
            'message' => 'This is a test email to verify your email configuration is working correctly.',
            'extra' => '<p style="color: #28a745; font-weight: 600;">✓ If you received this email, your configuration is working!</p>',
            'footer_text' => 'Automated Test Email'
        ]);
        
        return sendEmail($testEmail, $subject, $body, 'Test User');
    }
}


function sendDailyLoginOTP($to_email, $full_name, $otp_code) {
    // Use the existing getMailer() function from your email_helper.php
    $mail = getMailer();
    
    if (!$mail) {
        error_log("Failed to get mailer instance for daily login OTP");
        return false;
    }
    
    try {
        // Add recipient
        $mail->addAddress($to_email, $full_name);
        
        // Set subject
        $mail->Subject = 'Daily Login Verification - Barangay Management System';
        
        // Get current date and time
        $current_date = date('F j, Y');
        $current_time = date('g:i A');
        
        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { background: #f9fafb; padding: 30px; border-radius: 0 0 10px 10px; }
                .security-notice { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .otp-box { background: white; border: 3px dashed #3b82f6; padding: 20px; text-align: center; margin: 25px 0; border-radius: 10px; }
                .otp-code { font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #1e3a8a; font-family: monospace; }
                .info-box { background: #e0f2fe; border-left: 4px solid #0284c7; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .warning-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 Daily Login Verification</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$full_name}</strong>,</p>
                    
                    <p>A login attempt was detected for your Barangay Management System account on <strong>{$current_date}</strong> at <strong>{$current_time}</strong>.</p>
                    
                    <div class='security-notice'>
                        <strong>🛡️ Enhanced Security Notice</strong><br>
                        For your protection, we require daily email verification to ensure your account security.
                    </div>
                    
                    <p>Please use the verification code below to complete your login:</p>
                    
                    <div class='otp-box'>
                        <div style='color: #6b7280; font-size: 14px; margin-bottom: 10px;'>YOUR VERIFICATION CODE</div>
                        <div class='otp-code'>{$otp_code}</div>
                        <div style='color: #6b7280; font-size: 12px; margin-top: 10px;'>⏱️ Valid for 30 minutes</div>
                    </div>
                    
                    <div class='info-box'>
                        <strong>ℹ️ Important Information:</strong>
                        <ul style='margin: 10px 0; padding-left: 20px;'>
                            <li>This code expires in 30 minutes</li>
                            <li>You can request a new code if this one expires</li>
                            <li>Each login requires a new verification code</li>
                            <li>Do not share this code with anyone</li>
                        </ul>
                    </div>
                    
                    <div class='warning-box'>
                        <strong>⚠️ Security Warning:</strong><br>
                        If you did not attempt to log in, please ignore this email and consider changing your password immediately.
                    </div>
                    
                    <p style='margin-top: 25px;'>
                        <strong>Why daily verification?</strong><br>
                        Daily email verification adds an extra layer of security to protect your sensitive barangay data from unauthorized access.
                    </p>
                    
                    <div class='footer'>
                        <p><strong>Barangay Management System</strong></p>
                        <p>This is an automated message. Please do not reply to this email.</p>
                        <p style='font-size: 12px; color: #9ca3af;'>
                            If you have any questions or concerns, please contact your system administrator.
                        </p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Plain text alternative
        $mail->AltBody = "
Hello {$full_name},

Daily Login Verification

A login attempt was detected for your account on {$current_date} at {$current_time}.

Your verification code is: {$otp_code}

This code is valid for 30 minutes.

For security reasons, we require daily email verification to protect your account.

If you did not attempt to log in, please ignore this email and consider changing your password.

---
Barangay Management System
This is an automated message. Please do not reply.
        ";
        
        // Send email
        $result = $mail->send();
        
        if ($result) {
            error_log("✓ Daily login OTP email sent successfully to: " . $to_email);
        } else {
            error_log("✗ Failed to send daily login OTP email to: " . $to_email);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("✗ Daily login OTP email error: " . $mail->ErrorInfo);
        error_log("Exception: " . $e->getMessage());
        return false;
    }
}

?>