<?php
/**
 * Email Reply Fetcher - fetch_email_replies.php
 * Path: includes/fetch_email_replies.php
 *
 * Polls Gmail via IMAP and stores resident replies into tbl_email_replies.
 * Run this via a cron job every 1-5 minutes:
 *   * * * * * php /path/to/barangaylink1/includes/fetch_email_replies.php
 *
 * Or call it via a URL (secured with a secret key):
 *   https://yoursite.com/includes/fetch_email_replies.php?key=YOUR_SECRET
 *
 * REQUIREMENTS:
 *   - PHP imap extension enabled (uncomment extension=imap in php.ini)
 *   - Gmail IMAP enabled in Google account settings
 *   - Same Gmail App Password used in email.php
 */

// ── Security: only allow CLI or secret key ────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $secret = 'kdbtphdxvmvgcpub'; // Change this!
    if (($_GET['key'] ?? '') !== $secret) {
        http_response_code(403);
        die('Forbidden');
    }
}

define('FETCH_REPLIES_LOADED', true);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$base = dirname(__DIR__);
require_once $base . '/config/config.php';
require_once $base . '/config/database.php';

if (!file_exists($base . '/config/email.php')) {
    die("Email config not found.\n");
}
require_once $base . '/config/email.php';

// ── IMAP settings (Gmail) ─────────────────────────────────────────────────────
$imap_host     = '{imap.gmail.com:993/imap/ssl}INBOX';
$imap_user     = 'sanjuanbrgycentro@gmail.com';   
$imap_pass     = 'kdbtphdxvmvgcpub';   
$max_fetch     = 50;             
$processed     = 0;
$skipped       = 0;
$errors        = 0;

// ── Check IMAP extension ──────────────────────────────────────────────────────
if (!function_exists('imap_open')) {
    $msg = "PHP IMAP extension is not enabled.\n"
         . "Edit your php.ini and uncomment: extension=imap\n"
         . "Then restart Apache/XAMPP.\n";
    error_log($msg);
    die($msg);
}

// ── Open IMAP connection ──────────────────────────────────────────────────────
$inbox = @imap_open($imap_host, $imap_user, $imap_pass, 0, 1, [
    'DISABLE_AUTHENTICATOR' => 'GSSAPI'
]);

if (!$inbox) {
    $err = imap_last_error();
    error_log("IMAP connection failed: $err");
    die("IMAP connection failed: $err\n");
}

echo "IMAP connected. Checking for new replies...\n";

// ── Search for UNSEEN emails ──────────────────────────────────────────────────
$email_ids = imap_search($inbox, 'UNSEEN');

if (!$email_ids) {
    echo "No new emails found.\n";
    imap_close($inbox);
    exit;
}

// Sort newest first
rsort($email_ids);
$email_ids = array_slice($email_ids, 0, $max_fetch);

echo "Found " . count($email_ids) . " unseen email(s).\n";

// ── Helper: decode MIME encoded header ───────────────────────────────────────
function decodeMimeStr($str) {
    if (empty($str)) return '';
    $decoded = imap_mime_header_decode($str);
    $result  = '';
    foreach ($decoded as $part) {
        $charset = strtolower($part->charset ?? 'utf-8');
        $text    = $part->text ?? '';
        if ($charset !== 'utf-8' && $charset !== 'default') {
            $text = mb_convert_encoding($text, 'UTF-8', $charset);
        }
        $result .= $text;
    }
    return trim($result);
}

// ── Helper: get plain text body ──────────────────────────────────────────────
function getEmailBody($inbox, $msg_num) {
    $structure = imap_fetchstructure($inbox, $msg_num);
    $plain     = '';
    $html      = '';

    // Simple single-part
    if (!isset($structure->parts)) {
        $body = imap_body($inbox, $msg_num);
        if ($structure->encoding == 3) $body = base64_decode($body);
        elseif ($structure->encoding == 4) $body = quoted_printable_decode($body);
        return ['plain' => trim($body), 'html' => ''];
    }

    // Multi-part
    foreach ($structure->parts as $idx => $part) {
        $partNum = $idx + 1;
        $body    = imap_fetchbody($inbox, $msg_num, $partNum);

        if ($part->encoding == 3) $body = base64_decode($body);
        elseif ($part->encoding == 4) $body = quoted_printable_decode($body);

        $subtype = strtolower($part->subtype ?? '');
        if ($subtype === 'plain' && empty($plain)) {
            $plain = trim($body);
        } elseif ($subtype === 'html' && empty($html)) {
            $html = trim($body);
        }

        // Handle nested multipart
        if (!empty($part->parts)) {
            foreach ($part->parts as $sidx => $spart) {
                $sPartNum = $partNum . '.' . ($sidx + 1);
                $sbody    = imap_fetchbody($inbox, $msg_num, $sPartNum);
                if ($spart->encoding == 3) $sbody = base64_decode($sbody);
                elseif ($spart->encoding == 4) $sbody = quoted_printable_decode($sbody);
                $ssubtype = strtolower($spart->subtype ?? '');
                if ($ssubtype === 'plain' && empty($plain)) $plain = trim($sbody);
                elseif ($ssubtype === 'html' && empty($html)) $html  = trim($sbody);
            }
        }
    }

    return ['plain' => $plain, 'html' => $html];
}

// ── Helper: extract notification_id from subject or In-Reply-To ──────────────
// The resolution email subject format is: "Re: <original title>"
// We match by looking for the notification ID in the References header,
// or fall back to matching the resident's email against tbl_notifications.
function findNotificationId($conn, $from_email, $subject, $references, $in_reply_to) {

    // 1. Try to find a notification where the user matches from_email
    //    and the subject matches the notification title
    $clean_subject = preg_replace('/^(Re:\s*|Fwd:\s*)+/i', '', $subject);
    $clean_subject = trim($clean_subject);

    if ($clean_subject) {
        $stmt = $conn->prepare(
            "SELECT n.notification_id FROM tbl_notifications n
             JOIN tbl_users u ON u.user_id = n.user_id
             WHERE u.email = ? AND n.title LIKE ?
             ORDER BY n.created_at DESC LIMIT 1"
        );
        if ($stmt) {
            $like = '%' . $clean_subject . '%';
            $stmt->bind_param('ss', $from_email, $like);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) return intval($res['notification_id']);
        }
    }

    // 2. Fall back: find any notification belonging to this email address
    $stmt = $conn->prepare(
        "SELECT n.notification_id FROM tbl_notifications n
         JOIN tbl_users u ON u.user_id = n.user_id
         WHERE u.email = ?
         ORDER BY n.created_at DESC LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('s', $from_email);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res) return intval($res['notification_id']);
    }

    return null;
}

// ── Process each email ────────────────────────────────────────────────────────
foreach ($email_ids as $msg_num) {

    $header     = imap_headerinfo($inbox, $msg_num);
    $raw_header = imap_fetchheader($inbox, $msg_num);

    // Extract Message-ID to prevent duplicates
    preg_match('/^Message-ID:\s*(.+)$/mi', $raw_header, $mid_match);
    $message_id = trim($mid_match[1] ?? '');

    // Skip if already stored
    if ($message_id) {
        $chk = $conn->prepare("SELECT id FROM tbl_email_replies WHERE message_id = ?");
        $chk->bind_param('s', $message_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            $skipped++;
            continue;
        }
        $chk->close();
    }

    // Parse from
    $from_obj   = $header->from[0] ?? null;
    $from_email = $from_obj ? ($from_obj->mailbox . '@' . $from_obj->host) : '';
    $from_name  = $from_obj ? decodeMimeStr($from_obj->personal ?? '') : '';

    // Skip our own outbound emails
    if (strtolower($from_email) === strtolower(MAIL_FROM_EMAIL)) {
        imap_setflag_full($inbox, $msg_num, '\\Seen');
        $skipped++;
        continue;
    }

    $subject     = decodeMimeStr($header->subject ?? '');
    $in_reply_to = trim($header->in_reply_to ?? '');
    preg_match('/^References:\s*(.+)$/mi', $raw_header, $ref_match);
    $references  = trim($ref_match[1] ?? '');

    // Get body
    $body        = getEmailBody($inbox, $msg_num);
    $body_plain  = $body['plain'];
    $body_html   = $body['html'];

    if (empty($body_plain) && empty($body_html)) {
        $skipped++;
        continue;
    }

    // Find which notification this reply belongs to
    $notification_id = findNotificationId($conn, $from_email, $subject, $references, $in_reply_to);

    if (!$notification_id) {
        // No matching notification — skip (not a reply to our system)
        $skipped++;
        echo "  Skipped (no match): $from_email — $subject\n";
        continue;
    }

    // Insert reply
    $stmt = $conn->prepare(
        "INSERT INTO tbl_email_replies
         (notification_id, from_email, from_name, subject, body_text, body_html, message_id, direction, is_read)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'inbound', 0)"
    );
    $stmt->bind_param(
        'issssss',
        $notification_id, $from_email, $from_name,
        $subject, $body_plain, $body_html, $message_id
    );

    if ($stmt->execute()) {
        $processed++;
        echo "  ✓ Stored reply from $from_email for notification #$notification_id\n";

        // Also create a notification for the admin so it shows in the bell
        $notif_msg  = "New reply from {$from_name} ({$from_email}): " . mb_substr($body_plain, 0, 120) . '...';
        $admin_stmt = $conn->prepare(
            "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read)
             SELECT user_id, 'email_reply', 'notification', ?, ?, ?, 0
             FROM tbl_users WHERE role IN ('Super Admin','Super Administrator') LIMIT 5"
        );
        $notif_title = "Reply: $subject";
        $admin_stmt->bind_param('iss', $notification_id, $notif_title, $notif_msg);
        $admin_stmt->execute();
        $admin_stmt->close();

        // Mark as seen in Gmail so we don't fetch it again
        imap_setflag_full($inbox, $msg_num, '\\Seen');
    } else {
        $errors++;
        error_log("DB insert error: " . $conn->error);
    }

    $stmt->close();
}

imap_close($inbox);

echo "\nDone. Processed: $processed | Skipped: $skipped | Errors: $errors\n";