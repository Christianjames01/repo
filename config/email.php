<?php
/**
 * Email Configuration - OPTIMIZED for faster sending
 */

// Email Configuration Constants
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587); // Use 587 for TLS (faster than 465 SSL)
define('MAIL_USERNAME', 'ceejayortouste@gmail.com');
define('MAIL_PASSWORD', 'lhysqcswnvodmxkr'); // Gmail App Password
define('MAIL_ENCRYPTION', 'tls'); // TLS is faster than SSL
define('MAIL_FROM_EMAIL', 'ceejayortouste@gmail.com');
define('MAIL_FROM_NAME', 'Barangay System');

// Optional: Reply-to email
define('MAIL_REPLYTO_EMAIL', 'ceejayortouste@gmail.com');
define('MAIL_REPLYTO_NAME', 'Barangay Support');

// Email Settings - OPTIMIZED
define('MAIL_IS_HTML', true);
define('MAIL_CHARSET', 'UTF-8');
define('MAIL_DEBUG', 0); // Set to 0 for production (faster)

// PERFORMANCE SETTINGS
define('MAIL_TIMEOUT', 10); // Connection timeout in seconds (default is 300!)
define('MAIL_SMTP_KEEPALIVE', true); // Reuse connections for speed

?>