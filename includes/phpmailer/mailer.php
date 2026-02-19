<?php
/**
 * Email Helper Functions using PHPMailer
 * Path: includes/phpmailer/mailer.php
 * 
 * Make sure PHPMailer files are in the same directory:
 * - PHPMailer.php
 * - SMTP.php
 * - Exception.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer files
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';
// Include email configuration
require_once __DIR__ . '/../../config/email.php';

/**
 * Create and configure PHPMailer instance
 * 
 * @return PHPMailer Configured PHPMailer object
 */
function getMailer() {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = MAIL_CHARSET;
        
        // Performance settings
        if (defined('MAIL_TIMEOUT')) {
            $mail->Timeout = MAIL_TIMEOUT;
        }
        if (defined('MAIL_SMTP_KEEPALIVE')) {
            $mail->SMTPKeepAlive = MAIL_SMTP_KEEPALIVE;
        }
        
        // Debug settings
        if (defined('MAIL_DEBUG') && MAIL_DEBUG > 0) {
            $mail->SMTPDebug = MAIL_DEBUG;
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP Debug: $str");
            };
        }
        
        // Default sender
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        
        // Default reply-to
        if (defined('MAIL_REPLYTO_EMAIL')) {
            $mail->addReplyTo(MAIL_REPLYTO_EMAIL, MAIL_REPLYTO_NAME);
        }
        
        // HTML emails
        $mail->isHTML(MAIL_IS_HTML);
        
        return $mail;
    } catch (Exception $e) {
        error_log("PHPMailer Configuration Error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Send a simple email
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (HTML or plain text)
 * @param string $recipientName Recipient name (optional)
 * @return bool True on success, false on failure
 */
function sendEmail($to, $subject, $body, $recipientName = '') {
    $mail = getMailer();
    
    if (!$mail) {
        return false;
    }
    
    try {
        // Recipients
        $mail->addAddress($to, $recipientName);
        
        // Content
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Plain text alternative
        $mail->AltBody = strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email Send Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Send email with template
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $template Template name
 * @param array $data Data to pass to template
 * @param string $recipientName Recipient name (optional)
 * @return bool True on success, false on failure
 */
function sendTemplateEmail($to, $subject, $template, $data = [], $recipientName = '') {
    // Load email template
    $templatePath = __DIR__ . '/../../templates/emails/' . $template . '.php';
    
    if (!file_exists($templatePath)) {
        error_log("Email template not found: {$template}");
        return false;
    }
    
    // Extract data for template
    extract($data);
    
    // Start output buffering
    ob_start();
    include $templatePath;
    $body = ob_get_clean();
    
    return sendEmail($to, $subject, $body, $recipientName);
}

/**
 * Send welcome email to new user
 * 
 * @param string $email User email
 * @param string $name User name
 * @param string $username Username
 * @param string $temporaryPassword Temporary password (optional)
 * @return bool
 */
function sendWelcomeEmail($email, $name, $username, $temporaryPassword = null) {
    $subject = "Welcome to " . APP_NAME;
    
    $body = getEmailTemplate([
        'title' => 'Welcome!',
        'greeting' => "Hello {$name},",
        'message' => "Your account has been created successfully.",
        'details' => [
            'Username' => $username,
            'Email' => $email
        ],
        'extra' => $temporaryPassword ? "<p><strong>Temporary Password:</strong> {$temporaryPassword}</p><p class='text-danger'><small>Please change your password after first login.</small></p>" : '',
        'action_url' => APP_URL . '/login.php',
        'action_text' => 'Login Now'
    ]);
    
    return sendEmail($email, $subject, $body, $name);
}

/**
 * Send password reset email
 * 
 * @param string $email User email
 * @param string $name User name
 * @param string $resetToken Reset token
 * @return bool
 */
function sendPasswordResetEmail($email, $name, $resetToken) {
    $subject = "Password Reset Request - " . APP_NAME;
    $resetLink = APP_URL . "/reset-password.php?token=" . $resetToken;
    
    $body = getEmailTemplate([
        'title' => 'Password Reset',
        'greeting' => "Hello {$name},",
        'message' => "We received a request to reset your password. Click the button below to create a new password.",
        'extra' => "<p><small class='text-muted'>This link will expire in 1 hour. If you didn't request this, please ignore this email.</small></p>",
        'action_url' => $resetLink,
        'action_text' => 'Reset Password'
    ]);
    
    return sendEmail($email, $subject, $body, $name);
}

function sendNotificationEmail($to_email, $to_name, $subject, $message, $type = 'general', $action_url = null) {
    // Validate email
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: {$to_email}");
        return false;
    }
    
    try {
        // Get configured mailer instance
        $mail = getMailer();
        
        if (!$mail) {
            error_log("Failed to initialize mailer");
            return false;
        }
        
        // Add recipient
        $mail->addAddress($to_email, $to_name);
        
        // Determine icon and color based on type
        $iconColor = '#0d6efd';
        $icon = '📢';
        
        switch($type) {
            case 'incident_reported':
            case 'incident_assignment':
                $icon = '⚠️';
                $iconColor = '#ffc107';
                break;
            case 'status_update':
                $icon = '✅';
                $iconColor = '#198754';
                break;
            case 'complaint_submitted':
            case 'complaint_assignment':
                $icon = '📝';
                $iconColor = '#0dcaf0';
                break;
            case 'announcement':
                $icon = '📣';
                $iconColor = '#6f42c1';
                break;
            case 'alert':
                $icon = '🚨';
                $iconColor = '#dc3545';
                break;
        }
        
        // Set subject
        $mail->Subject = $subject;
        
        // Generate email body
        $body = getEmailTemplate([
            'title' => $icon . ' ' . $subject,
            'greeting' => "Hello {$to_name},",
            'message' => $message,
            'extra' => $action_url ? "<p style='margin-top: 20px;'><small class='text-muted'>Click the button below to view more details.</small></p>" : '',
            'action_url' => $action_url,
            'action_text' => 'View Details',
            'footer_text' => 'Barangay Notification System - Stay Informed'
        ]);
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags($message);
        
        // Send email
        $result = $mail->send();
        
        if ($result) {
            error_log("Email sent successfully to: {$to_email}");
            return true;
        } else {
            error_log("Email send failed to: {$to_email} - No error given");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Email send exception for {$to_email}: " . $e->getMessage());
        return false;
    }
}

/**
 * Send incident assignment notification
 * 
 * @param string $email Staff email
 * @param string $name Staff name
 * @param int $incidentId Incident ID
 * @param string $incidentTitle Incident title
 * @return bool
 */
function sendIncidentAssignmentEmail($email, $name, $incidentId, $incidentTitle) {
    $subject = "New Incident Assigned - " . APP_NAME;
    $actionUrl = APP_URL . "/modules/incidents/incident-details.php?id=" . $incidentId;
    
    $body = getEmailTemplate([
        'title' => 'New Incident Assigned',
        'greeting' => "Hello {$name},",
        'message' => "You have been assigned to a new incident:",
        'details' => [
            'Incident ID' => '#' . $incidentId,
            'Title' => $incidentTitle
        ],
        'extra' => "<p>Please review and take necessary action.</p>",
        'action_url' => $actionUrl,
        'action_text' => 'View Incident'
    ]);
    
    return sendEmail($email, $subject, $body, $name);
}

/**
 * Generate HTML email template
 * 
 * @param array $data Template data
 * @return string HTML email
 */
function getEmailTemplate($data) {
    $defaults = [
        'title' => '',
        'greeting' => 'Hello,',
        'message' => '',
        'details' => [],
        'extra' => '',
        'action_url' => '',
        'action_text' => 'Click Here',
        'footer_text' => APP_NAME . ' - Automated Email',
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
    
    return '
    <!DOCTYPE html>
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
                        <!-- Header -->
                        <tr>
                            <td style="background: linear-gradient(135deg, #007bff, #0056b3); padding: 30px; text-align: center;">
                                <h1 style="margin: 0; color: #ffffff; font-size: 28px;">' . htmlspecialchars($data['title']) . '</h1>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td style="padding: 40px 30px;">
                                <p style="font-size: 16px; color: #333333; margin: 0 0 20px 0;">' . $data['greeting'] . '</p>
                                <p style="font-size: 15px; color: #555555; line-height: 1.6; margin: 0 0 20px 0;">' . nl2br(htmlspecialchars($data['message'])) . '</p>
                                
                                ' . $detailsHtml . '
                                
                                ' . $data['extra'] . '
                                
                                ' . $actionButton . '
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-top: 1px solid #dee2e6;">
                                <p style="margin: 0; font-size: 13px; color: #6c757d;">' . htmlspecialchars($data['footer_text']) . '</p>
                                <p style="margin: 5px 0 0 0; font-size: 12px; color: #adb5bd;">&copy; ' . $data['year'] . ' ' . APP_NAME . '. All rights reserved.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}

/**
 * Test email configuration
 * 
 * @param string $testEmail Email to send test to
 * @return bool
 */
function testEmailConfiguration($testEmail) {
    $subject = "Email Configuration Test - " . APP_NAME;
    
    $body = getEmailTemplate([
        'title' => 'Email Test',
        'greeting' => 'Hello,',
        'message' => 'This is a test email to verify your email configuration is working correctly.',
        'extra' => '<p style="color: #28a745; font-weight: 600;">✓ If you received this email, your configuration is working!</p>',
        'footer_text' => 'This is an automated test email'
    ]);
    
    return sendEmail($testEmail, $subject, $body, 'Test Recipient');
}

/**
 * Send notification to resident via email (LEGACY - kept for compatibility)
 * 
 * @param string $email Resident email
 * @param string $name Resident name
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param string $actionUrl Optional action URL
 * @return bool
 */
function sendResidentNotificationEmail($email, $name, $title, $message, $type = 'general', $actionUrl = null) {
    return sendNotificationEmail($email, $name, $title, $message, $type, $actionUrl);
}

/**
 * Send bulk notifications to multiple residents (LEGACY - kept for compatibility)
 * 
 * @param array $recipients Array of ['email' => '', 'name' => '']
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param string $actionUrl Optional action URL
 * @return array ['success' => count, 'failed' => count, 'errors' => []]
 */
function sendBulkNotificationEmails($recipients, $title, $message, $type = 'general', $actionUrl = null) {
    $results = [
        'success' => 0,
        'failed' => 0,
        'errors' => []
    ];
    
    foreach ($recipients as $recipient) {
        if (empty($recipient['email']) || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
            $results['failed']++;
            $results['errors'][] = "Invalid email: " . ($recipient['email'] ?? 'empty');
            continue;
        }
        
        $name = $recipient['name'] ?? 'Resident';
        
        if (sendNotificationEmail($recipient['email'], $name, $title, $message, $type, $actionUrl)) {
            $results['success']++;
        } else {
            $results['failed']++;
            $results['errors'][] = "Failed to send to: " . $recipient['email'];
        }
        
        // Small delay to avoid overwhelming the SMTP server
        usleep(50000); // 0.05 second delay (50ms)
    }
    
    return $results;
}

?>