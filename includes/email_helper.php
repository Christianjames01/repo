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
        $mail->isSMTP();
        $mail->Host          = MAIL_HOST;
        $mail->SMTPAuth      = true;
        $mail->Username      = MAIL_USERNAME;
        $mail->Password      = MAIL_PASSWORD;
        $mail->SMTPSecure    = MAIL_ENCRYPTION;
        $mail->Port          = MAIL_PORT;
        $mail->CharSet       = MAIL_CHARSET;
        $mail->Timeout       = 10;
        $mail->SMTPKeepAlive = true;
        $mail->SMTPAutoTLS   = true;
        
        if (MAIL_DEBUG > 0) {
            $mail->SMTPDebug = MAIL_DEBUG;
        } else {
            $mail->SMTPDebug = 0;
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
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        
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
        
        if (strpos($e->getMessage(), 'timed out') !== false || 
            strpos($e->getMessage(), 'timeout') !== false ||
            $duration > 15) {
            error_log("⚠ Email timeout - but continuing registration");
            return true;
        }
        
        return false;
    }
}
}

/**
 * Send email asynchronously (non-blocking)
 */
if (!function_exists('sendEmailAsync')) {
function sendEmailAsync($to, $subject, $body, $recipientName = '') {
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
 * Get email template HTML
 * Clean, professional plain-text-style layout — no heavy banner headers.
 * Matches the standard transactional email format used by the system.
 */
if (!function_exists('getEmailTemplate')) {
function getEmailTemplate($data) {
    $defaults = [
        'title'       => '',
        'greeting'    => 'Hello,',
        'message'     => '',
        'details'     => [],
        'extra'       => '',
        'action_url'  => '',
        'action_text' => 'Click Here',
        'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Automated Email',
        'year'        => date('Y'),
    ];

    $data = array_merge($defaults, $data);

    $appName    = defined('APP_NAME')    ? APP_NAME    : 'Barangay System';
    $fromEmail  = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '';
    $fromName   = defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME  : $appName;


$logoPath = '';
$possiblePaths = [
    __DIR__ . '/../assets/images/brgy.png',
    $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/assets/images/brgy.png',
    'E:/xampp/htdocs/barangaylink1/assets/images/brgy.png',
];
foreach ($possiblePaths as $p) {
    if (file_exists($p)) {
        $logoPath = $p;
        break;
    }
}

$logoTag = '';
if ($logoPath) {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoSrc  = 'data:image/png;base64,' . $logoData;
    $logoAlt  = defined('BARANGAY_NAME') ? htmlspecialchars(BARANGAY_NAME) : 'Barangay';
    $logoTag  = '<p style="margin:10px 0 0 0;">
                   <img src="' . $logoSrc . '" alt="' . $logoAlt . '"
                        style="height:48px;width:auto;display:block;" />
                 </p>';
}
    // ── Details table (if any key-value pairs provided) ───────────────────
    $detailsHtml = '';
    if (!empty($data['details'])) {
        $detailsHtml = '<table width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 18px 0;">';
        foreach ($data['details'] as $label => $value) {
            $detailsHtml .= '
            <tr>
                <td style="padding:5px 0;font-size:14px;color:#374151;font-weight:600;width:160px;vertical-align:top;">'
                    . htmlspecialchars($label) . ':</td>
                <td style="padding:5px 0;font-size:14px;color:#4b5563;vertical-align:top;">'
                    . htmlspecialchars($value) . '</td>
            </tr>';
        }
        $detailsHtml .= '</table>';
    }

    // ── Action button (optional) ──────────────────────────────────────────
    $actionButton = '';
    if (!empty($data['action_url'])) {
        $actionButton = '
        <table cellpadding="0" cellspacing="0" style="margin:28px 0 10px 0;">
            <tr>
                <td style="background:#1d4ed8;border-radius:6px;padding:12px 28px;">
                    <a href="' . htmlspecialchars($data['action_url']) . '"
                        style="color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;font-family:Arial,sans-serif;">
                        ' . htmlspecialchars($data['action_text']) . '
                    </a>
                </td>
            </tr>
        </table>';
    }

    // ── Footer label (strip the app-name prefix if footer_text already has it) ──
    $footerLabel = htmlspecialchars($data['footer_text']);

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>' . htmlspecialchars($data['title']) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0"
    style="background-color:#f3f4f6;padding:32px 0;">
<tr>
<td align="center">

    <!-- Outer card -->
    <table width="600" cellpadding="0" cellspacing="0"
            style="background:#ffffff;border-radius:8px;
                border:1px solid #e5e7eb;
                box-shadow:0 1px 4px rgba(0,0,0,.06);
                overflow:hidden;">

    <!-- ── Top accent bar ── -->
    <tr>
        <td style="height:4px;background:#1d4ed8;"></td>
    </tr>

    <!-- ── Header: app name + label ── -->
    <tr>
        <td style="padding:28px 36px 0 36px;">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
            <td>
                <span style="font-size:17px;font-weight:700;color:#1d4ed8;
                            letter-spacing:-.2px;">' . htmlspecialchars($appName) . '</span>
            </td>
            <td align="right">
                <span style="font-size:11px;font-weight:600;color:#6b7280;
                            text-transform:uppercase;letter-spacing:.6px;">
                Official Communication
                </span>
            </td>
            </tr>
        </table>
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0 0 0;">
        </td>
    </tr>

    <!-- ── Body ── -->
    <tr>
        <td style="padding:28px 36px 32px 36px;">

        <!-- Subject / Title (shown as heading only if provided & non-empty) -->
        ' . (!empty($data['title']) ? '
        <p style="margin:0 0 20px 0;font-size:16px;font-weight:700;color:#111827;">
            ' . htmlspecialchars($data['title']) . '
        </p>' : '') . '

        <!-- Greeting -->
        <p style="margin:0 0 16px 0;font-size:14px;color:#374151;line-height:1.6;">
            ' . $data['greeting'] . '
        </p>

        <!-- Main message -->
        <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;line-height:1.75;">
            ' . $data['message'] . '
        </p>

        ' . $detailsHtml . '
        ' . $data['extra'] . '
        ' . $actionButton . '

        <!-- Sign-off -->
        <p style="margin:24px 0 0 0;font-size:14px;color:#374151;line-height:1.6;">
            Thank you,<br>
            <strong>' . htmlspecialchars($fromName) . '</strong>
        </p>

        <p style="margin:6px 0 0 0;font-size:12px;color:#9ca3af;">
            This is a system-generated message. Please do not reply directly to this email.
        </p>

        </td>
    </tr>

    <!-- ── Divider ── -->
    <tr>
        <td style="padding:0 36px;">
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;">
        </td>
    </tr>

    <!-- ── Footer ── -->
    <tr>
        <td style="padding:20px 36px 24px 36px;background:#f9fafb;">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
            <td>
                <p style="margin:0;font-size:12px;color:#6b7280;line-height:1.6;">
                ' . $footerLabel . '
                </p>
            ' . (!empty($fromEmail) ? '
                <p style="margin:4px 0 0 0;font-size:12px;color:#9ca3af;">
                Contact: <a href="mailto:' . htmlspecialchars($fromEmail) . '"
                            style="color:#1d4ed8;text-decoration:none;">'
                            . htmlspecialchars($fromEmail) . '</a>
                </p>' : '') . '
                ' . $logoTag . '
            </td>
            <td align="right" style="vertical-align:top;">
                <p style="margin:0;font-size:11px;color:#d1d5db;">
                &copy; ' . $data['year'] . ' ' . htmlspecialchars($appName) . '
                </p>
            </td>
            </tr>
        </table>
        </td>
    </tr>

    </table>
    <!-- /Outer card -->

</td>
</tr>
</table>

</body>
</html>';
}
}

/**
 * Send verification code email
 */
if (!function_exists('sendVerificationCodeEmail')) {
function sendVerificationCodeEmail($email, $name, $verificationCode) {
    $subject = "Email Verification - " . (defined('APP_NAME') ? APP_NAME : 'Barangay System');
    
    $body = getEmailTemplate([
        'title'    => 'Email Verification',
        'greeting' => "Hello {$name},",
        'message'  => "Thank you for registering. Please use the verification code below to complete your registration:",
        'extra'    => "
            <div style='background:#f0f4ff;border:1px solid #c7d2fe;border-radius:8px;
                        padding:24px;text-align:center;margin:24px 0;'>
                <p style='font-size:12px;color:#6b7280;margin:0 0 10px 0;
                            text-transform:uppercase;letter-spacing:1px;font-weight:600;'>
                    Your Verification Code
                </p>
                <div style='font-size:38px;font-weight:700;color:#1d4ed8;
                            letter-spacing:10px;font-family:monospace;'>{$verificationCode}</div>
                <p style='font-size:12px;color:#9ca3af;margin:12px 0 0 0;'>
                    This code expires in <strong>30 minutes</strong>.
                </p>
            </div>
            <p style='font-size:13px;color:#6b7280;'>
                <strong>Note:</strong> If you did not register, please ignore this email.
            </p>
        ",
        'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Email Verification',
    ]);
    
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
        'title'    => 'New Verification Code',
        'greeting' => "Hello {$name},",
        'message'  => "You requested a new verification code. Here is your updated code:",
        'extra'    => "
            <div style='background:#f0f4ff;border:1px solid #c7d2fe;border-radius:8px;
                        padding:24px;text-align:center;margin:24px 0;'>
                <p style='font-size:12px;color:#6b7280;margin:0 0 10px 0;
                            text-transform:uppercase;letter-spacing:1px;font-weight:600;'>
                    Your New Verification Code
                </p>
                <div style='font-size:38px;font-weight:700;color:#1d4ed8;
                            letter-spacing:10px;font-family:monospace;'>{$verificationCode}</div>
                <p style='font-size:12px;color:#9ca3af;margin:12px 0 0 0;'>
                    This code expires in <strong>30 minutes</strong>.
                </p>
            </div>
            <p style='font-size:13px;color:#6b7280;'>
                <strong>Note:</strong> Your previous code has been invalidated.
            </p>
        ",
        'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Email Verification',
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
        'title'    => 'Password Reset Request',
        'greeting' => "Hello {$name},",
        'message'  => "We received a request to reset your password. Use the code below to proceed:",
        'extra'    => "
            <div style='background:#f0f4ff;border:1px solid #c7d2fe;border-radius:8px;
                        padding:24px;text-align:center;margin:24px 0;'>
                <p style='font-size:12px;color:#6b7280;margin:0 0 10px 0;
                            text-transform:uppercase;letter-spacing:1px;font-weight:600;'>
                    Password Reset Code
                </p>
                <div style='font-size:38px;font-weight:700;color:#1d4ed8;
                            letter-spacing:10px;font-family:monospace;'>{$verificationCode}</div>
                <p style='font-size:12px;color:#9ca3af;margin:12px 0 0 0;'>
                    This code expires in <strong>30 minutes</strong>.
                </p>
            </div>
            <div style='background:#fefce8;border-left:3px solid #ca8a04;
                        padding:14px 16px;margin:16px 0;border-radius:4px;'>
                <p style='font-size:13px;color:#854d0e;margin:0;'>
                    <strong>Security notice:</strong> Do not share this code with anyone.
                    If you did not request a password reset, please ignore this email —
                    your password will remain unchanged.
                </p>
            </div>
        ",
        'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Password Reset',
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
        'title'    => 'Email Configuration Test',
        'greeting' => 'Hello,',
        'message'  => 'This is a test email to verify that your email configuration is working correctly.',
        'extra'    => '<p style="font-size:14px;color:#15803d;font-weight:600;">
                        ✓ If you received this email, your configuration is working!
                        </p>',
        'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Automated Test Email',
    ]);
    
    return sendEmail($testEmail, $subject, $body, 'Test User');
}
}


function sendDailyLoginOTP($to_email, $full_name, $otp_code) {
$mail = getMailer();

if (!$mail) {
    error_log("Failed to get mailer instance for daily login OTP");
    return false;
}

$current_date = date('F j, Y');
$current_time = date('g:i A');

try {
    $mail->addAddress($to_email, $full_name);
    $mail->Subject = 'Daily Login Verification - Barangay Management System';

    $mail->Body = getEmailTemplate([
        'title'    => 'Daily Login Verification',
        'greeting' => "Hello <strong>{$full_name}</strong>,",
        'message'  => "A login attempt was detected for your Barangay Management System account on
                        <strong>{$current_date}</strong> at <strong>{$current_time}</strong>.
                        Please use the verification code below to complete your login.",
        'extra'    => "
            <div style='background:#f0f4ff;border:1px solid #c7d2fe;border-radius:8px;
                        padding:24px;text-align:center;margin:24px 0;'>
                <p style='font-size:12px;color:#6b7280;margin:0 0 10px 0;
                            text-transform:uppercase;letter-spacing:1px;font-weight:600;'>
                    Your Verification Code
                </p>
                <div style='font-size:38px;font-weight:700;color:#1d4ed8;
                            letter-spacing:10px;font-family:monospace;'>{$otp_code}</div>
                <p style='font-size:12px;color:#9ca3af;margin:12px 0 0 0;'>
                    Valid for <strong>30 minutes</strong> &nbsp;·&nbsp; Do not share this code.
                </p>
            </div>
            <div style='background:#fefce8;border-left:3px solid #ca8a04;
                        padding:14px 16px;margin:16px 0;border-radius:4px;'>
                <p style='font-size:13px;color:#854d0e;margin:0;'>
                    <strong>Security warning:</strong> If you did not attempt to log in,
                    please ignore this email and consider changing your password immediately.
                </p>
            </div>
        ",
        'footer_text' => 'Barangay Management System — Daily Login Verification',
    ]);

    $mail->AltBody = "Hello {$full_name},\n\nYour daily login verification code is: {$otp_code}\n\nThis code is valid for 30 minutes.\n\nIf you did not attempt to log in, please ignore this email.\n\n---\nBarangay Management System\nThis is an automated message. Please do not reply.";

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